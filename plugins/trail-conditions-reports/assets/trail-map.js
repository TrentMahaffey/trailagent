/**
 * Interactive Trail Map with GPX data and status indicators
 */
(function() {
  const { trails, center, zoom, apiUrl } = TCR_MAP;

  let map;
  let trailLayers = {};
  let areaGroups = {};
  let allTrailsBounds = null;

  // Status colors
  const STATUS_COLORS = {
    open: '#10b981',      // Green
    seasonal: '#3b82f6',  // Blue
    muddy: '#fbbf24',     // Yellow/Amber
    hazardous: '#ef4444'  // Red
  };

  const STATUS_LABELS = {
    open: '✅ Open',
    seasonal: '❄️ Seasonally Closed',
    muddy: '🟡 Muddy',
    hazardous: '⚠️ Hazardous'
  };

  /**
   * Initialize the map
   */
  function initMap() {
    // Create map centered on provided coordinates
    map = L.map('tcr-trail-map').setView(center, zoom);

    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors',
      maxZoom: 18
    }).addTo(map);

    // Group trails by area
    const trailsByArea = {};
    trails.forEach(trail => {
      if (!trailsByArea[trail.area]) {
        trailsByArea[trail.area] = [];
      }
      trailsByArea[trail.area].push(trail);
    });

    // Create layer groups for each area and calculate bounds
    Object.keys(trailsByArea).forEach(areaName => {
      const layerGroup = L.layerGroup();
      const areaCoordinates = [];

      // Collect all coordinates for this area
      trailsByArea[areaName].forEach(trail => {
        if (trail.gpx_data) {
          try {
            const gpxData = typeof trail.gpx_data === 'string'
              ? JSON.parse(trail.gpx_data)
              : trail.gpx_data;
            if (gpxData.coordinates) {
              gpxData.coordinates.forEach(coord => {
                areaCoordinates.push([coord[0], coord[1]]);
              });
            }
          } catch (e) {
            // Skip invalid data
          }
        }
      });

      const areaBounds = areaCoordinates.length > 0
        ? L.latLngBounds(areaCoordinates)
        : null;

      areaGroups[areaName] = {
        group: layerGroup,
        trails: trailsByArea[areaName],
        bounds: areaBounds
      };
      layerGroup.addTo(map);
    });

    // Render all trails
    renderTrails();

    // Build area filters
    buildAreaFilters(trailsByArea);
  }

  /**
   * Render all trails on the map
   */
  function renderTrails() {
    trails.forEach(trail => {
      if (!trail.gpx_data) return;

      let gpxData;
      try {
        gpxData = typeof trail.gpx_data === 'string'
          ? JSON.parse(trail.gpx_data)
          : trail.gpx_data;
      } catch (e) {
        console.error(`Failed to parse GPX data for trail ${trail.name}:`, e);
        return;
      }

      if (!gpxData.coordinates || gpxData.coordinates.length === 0) {
        return;
      }

      // Convert coordinates to Leaflet format [lat, lng]
      const latlngs = gpxData.coordinates.map(coord => [coord[0], coord[1]]);

      // Create polyline with status color
      const color = STATUS_COLORS[trail.status] || STATUS_COLORS.open;
      const polyline = L.polyline(latlngs, {
        color: color,
        weight: 4,
        opacity: 0.8,
        smoothFactor: 1
      });

      // Add popup with trail info
      polyline.bindPopup(createTrailPopup(trail));

      // Add to area group
      if (areaGroups[trail.area]) {
        polyline.addTo(areaGroups[trail.area].group);
      }

      // Store reference
      trailLayers[trail.id] = {
        layer: polyline,
        trail: trail
      };
    });

    // Fit map to show all trails and store bounds
    if (trails.length > 0) {
      const allCoordinates = [];
      trails.forEach(trail => {
        if (trail.gpx_data) {
          try {
            const gpxData = typeof trail.gpx_data === 'string'
              ? JSON.parse(trail.gpx_data)
              : trail.gpx_data;
            if (gpxData.coordinates) {
              gpxData.coordinates.forEach(coord => {
                allCoordinates.push([coord[0], coord[1]]);
              });
            }
          } catch (e) {
            // Skip invalid data
          }
        }
      });

      if (allCoordinates.length > 0) {
        allTrailsBounds = L.latLngBounds(allCoordinates);
        map.fitBounds(allTrailsBounds, { padding: [50, 50] });
      }
    }
  }

  /**
   * Create popup content for a trail
   */
  function createTrailPopup(trail) {
    const statusLabel = STATUS_LABELS[trail.status] || trail.status;
    const statusColor = STATUS_COLORS[trail.status] || '#6b7280';

    // Format seasonal dates if they exist
    let seasonalInfo = '';
    if (trail.close_date || trail.open_date) {
      seasonalInfo = '<div class="trail-popup-seasonal">';

      if (trail.close_date) {
        const closeDate = new Date(trail.close_date);
        seasonalInfo += `<div class="seasonal-date">❄️ Closes: ${closeDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</div>`;
      }

      if (trail.open_date) {
        const openDate = new Date(trail.open_date);
        seasonalInfo += `<div class="seasonal-date">🌞 Opens: ${openDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</div>`;
      }

      seasonalInfo += '</div>';
    }

    return `
      <div class="trail-popup">
        <h3 class="trail-popup-title">${escapeHtml(trail.name)}</h3>
        <div class="trail-popup-area">📍 ${escapeHtml(trail.area)}</div>
        <div class="trail-popup-status" style="background: ${statusColor}20; color: ${statusColor}; border-left: 3px solid ${statusColor};">
          ${statusLabel}
        </div>
        ${seasonalInfo}
      </div>
    `;
  }

  /**
   * Build area filter checkboxes
   */
  function buildAreaFilters(trailsByArea) {
    const filterContainer = document.getElementById('area-filters');
    if (!filterContainer) return;

    const sortedAreas = Object.keys(trailsByArea).sort();

    filterContainer.innerHTML = sortedAreas.map(areaName => {
      const trailCount = trailsByArea[areaName].length;
      return `
        <div class="area-filter-item">
          <label>
            <input type="checkbox" checked data-area="${escapeHtml(areaName)}">
            <span>${escapeHtml(areaName)}</span>
            <span class="trail-count">(${trailCount})</span>
          </label>
          <div class="zoom-icon" data-area="${escapeHtml(areaName)}" title="Zoom to ${escapeHtml(areaName)}">
            🔍
          </div>
        </div>
      `;
    }).join('');

    // Add event listeners for checkboxes
    filterContainer.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
      checkbox.addEventListener('change', handleAreaFilterChange);
    });

    // Add event listeners for zoom icons
    filterContainer.querySelectorAll('.zoom-icon').forEach(icon => {
      icon.addEventListener('click', handleZoomClick);
    });
  }

  /**
   * Handle area filter checkbox changes
   */
  function handleAreaFilterChange(e) {
    const areaName = e.target.dataset.area;
    const areaGroup = areaGroups[areaName];

    if (!areaGroup) return;

    if (e.target.checked) {
      areaGroup.group.addTo(map);
    } else {
      map.removeLayer(areaGroup.group);
    }
  }

  /**
   * Handle zoom icon click
   */
  function handleZoomClick(e) {
    const areaName = e.currentTarget.dataset.area;
    const areaGroup = areaGroups[areaName];

    if (!areaGroup || !areaGroup.bounds) return;

    // Zoom to the selected area
    map.fitBounds(areaGroup.bounds, {
      padding: [50, 50],
      animate: true,
      duration: 0.5
    });
  }

  /**
   * Update trail status color
   */
  function updateTrailStatus(trailId, newStatus) {
    const trailData = trailLayers[trailId];
    if (!trailData) return;

    const color = STATUS_COLORS[newStatus] || STATUS_COLORS.open;
    trailData.layer.setStyle({ color: color });
    trailData.trail.status = newStatus;

    // Update popup
    trailData.layer.setPopupContent(createTrailPopup(trailData.trail));
  }

  /**
   * Escape HTML to prevent XSS
   */
  function escapeHtml(s) {
    const txt = document.createElement('textarea');
    txt.innerHTML = s ?? "";
    const decoded = txt.value;
    return String(decoded).replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    })[c]);
  }

  // Initialize map when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      initMap();
      // Force Leaflet to recalculate map size
      setTimeout(() => {
        if (map) map.invalidateSize();
      }, 100);
    });
  } else {
    initMap();
    // Force Leaflet to recalculate map size
    setTimeout(() => {
      if (map) map.invalidateSize();
    }, 100);
  }

  // Expose functions globally for admin use
  window.TCR_TrailMap = {
    updateTrailStatus: updateTrailStatus,
    map: () => map,
    trails: () => trails
  };
})();
