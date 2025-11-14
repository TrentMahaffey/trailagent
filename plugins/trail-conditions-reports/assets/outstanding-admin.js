/**
 * Admin interface for managing outstanding trail maintenance
 */
(function() {
  const { root, nonce } = TCR_OUTSTANDING;
  let currentFilter = 'unreviewed';
  let advancedFilters = {};

  function decodeEntities(s) {
    const txt = document.createElement('textarea');
    txt.innerHTML = s ?? "";
    return txt.value;
  }

  function loadFilterOptions() {
    // Load trails, areas, and users for filters
    fetch(`${root}outstanding/filter-options`, {
      headers: { 'X-WP-Nonce': nonce }
    })
    .then(r => r.json())
    .then(data => {
      // Populate trail filter
      const trailSelect = document.getElementById('filter-trail');
      data.trails.forEach(trail => {
        const opt = document.createElement('option');
        opt.value = trail.id;
        opt.textContent = decodeEntities(trail.name);
        trailSelect.appendChild(opt);
      });

      // Populate area filter
      const areaSelect = document.getElementById('filter-area');
      data.areas.forEach(area => {
        const opt = document.createElement('option');
        opt.value = area.id;
        opt.textContent = decodeEntities(area.name);
        areaSelect.appendChild(opt);
      });

      // Populate user filter
      const userSelect = document.getElementById('filter-user');
      data.users.forEach(user => {
        const opt = document.createElement('option');
        opt.value = user.id;
        opt.textContent = decodeEntities(user.name);
        userSelect.appendChild(opt);
      });
    })
    .catch(err => console.error('Error loading filter options:', err));
  }

  function loadPhotos() {
    const container = document.getElementById('tcr-admin-photos');
    container.innerHTML = '<div class="tcr-loading">Loading photos...</div>';

    // Build query string with all filters
    const params = new URLSearchParams({ filter: currentFilter });
    if (advancedFilters.trail) params.append('trail_id', advancedFilters.trail);
    if (advancedFilters.area) params.append('area_id', advancedFilters.area);
    if (advancedFilters.user) params.append('user_id', advancedFilters.user);
    if (advancedFilters.dateStart) params.append('date_start', advancedFilters.dateStart);
    if (advancedFilters.dateEnd) params.append('date_end', advancedFilters.dateEnd);

    fetch(`${root}outstanding/photos?${params.toString()}`, {
      headers: { 'X-WP-Nonce': nonce }
    })
    .then(r => r.json())
    .then(photos => {
      if (!photos || photos.length === 0) {
        container.innerHTML = '<div class="tcr-no-results">No photos found.</div>';
        return;
      }

      container.innerHTML = photos.map(photo => {
        // IMPORTANT: Convert to proper boolean (database returns 0 or 1 as strings)
        const isOutstanding = parseInt(photo.is_outstanding) === 1;
        const isResolved = photo.resolved_at !== null;
        const isReviewed = photo.reviewed_at !== null;

        // Determine status badge
        let statusBadge = '';
        let statusClass = '';
        if (isResolved) {
          statusBadge = '✅ Resolved';
          statusClass = 'status-resolved';
        } else if (isOutstanding) {
          statusBadge = '⚠️ Outstanding';
          statusClass = 'status-outstanding';
        } else if (isReviewed) {
          statusBadge = '✓ Dismissed';
          statusClass = 'status-dismissed';
        } else {
          statusBadge = '🆕 Unreviewed';
          statusClass = 'status-unreviewed';
        }

        // Store photo data for modals
        window.photoData = window.photoData || {};
        window.photoData[photo.id] = {
          imageUrl: photo.thumb_url || photo.image_url,
          trailName: photo.trail_name,
          caption: photo.caption,
          workDate: photo.work_date,
          condComment: photo.cond_comment,
          gpsLat: photo.gps_lat,
          gpsLng: photo.gps_lng,
          userName: photo.user_name
        };

        // Build work done list
        const workDone = [];
        if (photo.trees_cleared) workDone.push(`${photo.trees_cleared} trees cleared`);
        if (photo.corridor_cleared) workDone.push('Corridor cleared');
        if (photo.raking) workDone.push('Raking');
        if (photo.installed_drains) workDone.push('Drains installed');
        if (photo.rocks_cleared) workDone.push('Rocks cleared');

        // Build conditions list
        const conditions = [];
        if (photo.cond_trees) conditions.push(`${photo.cond_trees} trees down`);
        if (photo.cond_hazards) conditions.push('Hazards');
        if (photo.cond_washout) conditions.push('Washout');
        if (photo.cond_overgrowth) conditions.push('Overgrowth');
        if (photo.cond_muddy) conditions.push('Muddy');

        // GPS map if available
        const hasGPS = photo.gps_lat && photo.gps_lng;
        const mapHTML = hasGPS ? `
          <div class="photo-map-container">
            <div class="photo-map" id="map-${photo.id}"></div>
            <a href="https://www.google.com/maps?q=${photo.gps_lat},${photo.gps_lng}" target="_blank" class="map-link">
              Open in Google Maps
            </a>
          </div>
        ` : '';

        return `
        <div class="tcr-photo-item ${isOutstanding && !isResolved ? 'outstanding' : ''} ${isResolved ? 'resolved' : ''}" data-id="${photo.id}">
          <div class="photo-image">
            <img src="${photo.thumb_url || photo.image_url}" alt="${decodeEntities(photo.caption || 'Trail photo')}">
            <span class="photo-status-badge ${statusClass}">${statusBadge}</span>
          </div>
          <div class="photo-details">
            <div class="photo-trail">${decodeEntities(photo.trail_name)}</div>
            <div class="photo-date">📅 ${photo.work_date || 'No date'} | ⏱️ ${photo.hours_spent || 0}hrs</div>

            ${photo.caption ? `<div class="photo-caption"><strong>Photo:</strong> ${decodeEntities(photo.caption)}</div>` : ''}

            ${workDone.length > 0 ? `<div class="photo-work"><strong>Work Done:</strong> ${workDone.join(', ')}</div>` : ''}

            ${conditions.length > 0 ? `<div class="photo-conditions"><strong>Conditions:</strong> ${conditions.join(', ')}</div>` : ''}

            ${photo.cond_comment ? `<div class="photo-comment"><strong>Comments:</strong> ${decodeEntities(photo.cond_comment)}</div>` : ''}

            ${photo.summary ? `<div class="photo-summary"><strong>Summary:</strong> ${decodeEntities(photo.summary)}</div>` : ''}

            ${hasGPS ? `<div class="photo-gps">📍 ${photo.gps_lat}, ${photo.gps_lng}</div>` : ''}

            ${mapHTML}

            ${isResolved ? `
              <div class="photo-resolved">
                <div class="resolved-header">✓ Resolved ${photo.resolution_date ? new Date(photo.resolution_date).toLocaleDateString() : new Date(photo.resolved_at).toLocaleDateString()} by ${decodeEntities(photo.resolved_by_name)}</div>
                ${photo.resolution_notes ? `<div class="resolved-notes">${decodeEntities(photo.resolution_notes)}</div>` : ''}
              </div>
            ` : ''}
          </div>
          <div class="photo-actions">
            <button class="action-btn ${isOutstanding ? 'active' : ''}" data-id="${photo.id}" data-action="outstanding">
              ⚠️ Outstanding
            </button>
            <button class="action-btn ${isResolved ? 'active' : ''}" data-id="${photo.id}" data-action="resolved" ${!isOutstanding ? 'disabled' : ''}>
              ✅ Resolved
            </button>
            <button class="action-btn" data-id="${photo.id}" data-action="dismiss">
              ✓ Dismiss
            </button>
            <a href="${photo.image_url}" target="_blank" class="view-full">View Full</a>
          </div>
        </div>
      `;
      }).join('');

      // Attach click handlers
      document.querySelectorAll('.action-btn').forEach(btn => {
        btn.addEventListener('click', handleAction);
      });

      // Initialize maps for photos with GPS
      photos.forEach(photo => {
        if (photo.gps_lat && photo.gps_lng) {
          const mapEl = document.getElementById(`map-${photo.id}`);
          if (mapEl && typeof L !== 'undefined') {
            const map = L.map(`map-${photo.id}`).setView([photo.gps_lat, photo.gps_lng], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
              attribution: '© OpenStreetMap'
            }).addTo(map);
            L.marker([photo.gps_lat, photo.gps_lng]).addTo(map);
          }
        }
      });
    })
    .catch(err => {
      container.innerHTML = '<div class="tcr-error">Error loading photos.</div>';
      console.error(err);
    });
  }

  function showResolutionModal(photoId, btn, originalText) {
    const photoInfo = window.photoData[photoId];

    // Create modal
    const modal = document.createElement('div');
    modal.className = 'tcr-modal';
    modal.innerHTML = `
      <div class="tcr-modal-content">
        <h2>Mark as Resolved</h2>
        <p>Document how this issue was resolved:</p>

        ${photoInfo ? `
          <div class="modal-photo-preview">
            <img src="${photoInfo.imageUrl}" alt="Trail photo">
            <div class="modal-photo-info">
              <strong>${decodeEntities(photoInfo.trailName)}</strong>
              ${photoInfo.caption ? `<p class="photo-caption">${decodeEntities(photoInfo.caption)}</p>` : ''}
              <div class="photo-meta">
                ${photoInfo.userName ? `<span>👤 ${decodeEntities(photoInfo.userName)}</span>` : ''}
                ${photoInfo.workDate ? `<span>📅 ${photoInfo.workDate}</span>` : ''}
              </div>
              ${photoInfo.condComment ? `<div class="photo-conditions">💬 ${decodeEntities(photoInfo.condComment)}</div>` : ''}
              ${photoInfo.gpsLat && photoInfo.gpsLng ? `
                <div class="photo-location">
                  <a href="https://www.google.com/maps?q=${photoInfo.gpsLat},${photoInfo.gpsLng}" target="_blank">
                    📍 View on Map
                  </a>
                </div>
              ` : ''}
            </div>
          </div>
        ` : ''}

        <label for="resolution-notes">
          <strong>Resolution Notes:</strong>
          <textarea id="resolution-notes" rows="4" placeholder="What work was completed to resolve this issue?"></textarea>
        </label>

        <label for="resolution-date">
          <strong>Resolution Date:</strong>
          <input type="date" id="resolution-date" value="${new Date().toISOString().split('T')[0]}">
        </label>

        <div class="modal-actions">
          <button class="modal-btn cancel">Cancel</button>
          <button class="modal-btn submit">Mark as Resolved</button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    // Handle cancel
    modal.querySelector('.cancel').addEventListener('click', () => {
      modal.remove();
      btn.disabled = false;
      btn.textContent = originalText;
    });

    // Handle submit
    modal.querySelector('.submit').addEventListener('click', () => {
      const notes = document.getElementById('resolution-notes').value;
      const date = document.getElementById('resolution-date').value;

      if (!notes.trim()) {
        alert('Please add resolution notes');
        return;
      }

      fetch(`${root}outstanding/resolve/${photoId}`, {
        method: 'POST',
        headers: {
          'X-WP-Nonce': nonce,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          notes: notes,
          date: date
        })
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          modal.remove();
          loadPhotos();
        } else {
          alert('Failed to mark as resolved');
        }
      })
      .catch(err => {
        console.error(err);
        alert('Error marking as resolved');
      });
    });

    // Close on background click
    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        modal.remove();
        btn.disabled = false;
        btn.textContent = originalText;
      }
    });
  }

  function showConfirmationModal(photoId, action, btn, originalText) {
    const photoInfo = window.photoData[photoId];

    const messages = {
      outstanding: {
        title: 'Mark as Outstanding?',
        message: 'This will flag this photo as needing attention and add it to the Outstanding Maintenance list for trail agents.',
        confirmBtn: '⚠️ Mark Outstanding',
        confirmClass: 'outstanding'
      },
      dismiss: {
        title: 'Dismiss this Photo?',
        message: 'This will mark the photo as reviewed but not requiring action. It will be removed from your unreviewed queue.',
        confirmBtn: '✓ Dismiss',
        confirmClass: 'dismiss'
      }
    };

    const config = messages[action];
    if (!config) return;

    const modal = document.createElement('div');
    modal.className = 'tcr-modal';
    modal.innerHTML = `
      <div class="tcr-modal-content">
        <h2>${config.title}</h2>
        <p>${config.message}</p>

        ${photoInfo ? `
          <div class="modal-photo-preview">
            <img src="${photoInfo.imageUrl}" alt="Trail photo">
            <div class="modal-photo-info">
              <strong>${decodeEntities(photoInfo.trailName)}</strong>
              ${photoInfo.caption ? `<p class="photo-caption">${decodeEntities(photoInfo.caption)}</p>` : ''}
              <div class="photo-meta">
                ${photoInfo.userName ? `<span>👤 ${decodeEntities(photoInfo.userName)}</span>` : ''}
                ${photoInfo.workDate ? `<span>📅 ${photoInfo.workDate}</span>` : ''}
              </div>
              ${photoInfo.condComment ? `<div class="photo-conditions">💬 ${decodeEntities(photoInfo.condComment)}</div>` : ''}
              ${photoInfo.gpsLat && photoInfo.gpsLng ? `
                <div class="photo-location">
                  <a href="https://www.google.com/maps?q=${photoInfo.gpsLat},${photoInfo.gpsLng}" target="_blank">
                    📍 View on Map
                  </a>
                </div>
              ` : ''}
            </div>
          </div>
        ` : ''}

        <div class="modal-actions">
          <button class="modal-btn cancel">Cancel</button>
          <button class="modal-btn confirm ${config.confirmClass}">${config.confirmBtn}</button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    // Handle cancel
    modal.querySelector('.cancel').addEventListener('click', () => {
      modal.remove();
    });

    // Handle confirm
    modal.querySelector('.confirm').addEventListener('click', () => {
      modal.remove();
      btn.disabled = true;
      btn.textContent = 'Processing...';

      let endpoint;
      if (action === 'outstanding') {
        endpoint = `${root}outstanding/set-status/${photoId}?status=outstanding`;
      } else if (action === 'dismiss') {
        endpoint = `${root}outstanding/dismiss/${photoId}`;
      }

      fetch(endpoint, {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce }
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          loadPhotos();
        } else {
          alert('Failed to update');
          btn.disabled = false;
          btn.textContent = originalText;
        }
      })
      .catch(err => {
        console.error(err);
        alert('Error updating photo');
        btn.disabled = false;
        btn.textContent = originalText;
      });
    });

    // Close on background click
    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        modal.remove();
      }
    });
  }

  function handleAction(e) {
    const btn = e.target;
    if (btn.disabled) return;

    const photoId = btn.dataset.id;
    const action = btn.dataset.action;
    const originalText = btn.textContent;

    // Show resolution modal for resolved action
    if (action === 'resolved') {
      showResolutionModal(photoId, btn, originalText);
      return;
    }

    // Show confirmation modal for outstanding and dismiss
    if (action === 'outstanding' || action === 'dismiss') {
      showConfirmationModal(photoId, action, btn, originalText);
      return;
    }
  }

  // Event listeners
  document.addEventListener('DOMContentLoaded', () => {
    // Load filter options first
    loadFilterOptions();

    // Load Leaflet for maps
    if (!document.getElementById('leaflet-css')) {
      const link = document.createElement('link');
      link.id = 'leaflet-css';
      link.rel = 'stylesheet';
      link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
      document.head.appendChild(link);

      const script = document.createElement('script');
      script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
      script.onload = loadPhotos;
      document.head.appendChild(script);
    } else {
      loadPhotos();
    }

    // Filter radio buttons
    document.querySelectorAll('input[name="filter"]').forEach(radio => {
      radio.addEventListener('change', (e) => {
        currentFilter = e.target.value;
        loadPhotos();
      });
    });

    // Apply filters button
    const applyBtn = document.getElementById('tcr-apply-filters');
    if (applyBtn) {
      applyBtn.addEventListener('click', () => {
        advancedFilters = {
          trail: document.getElementById('filter-trail').value,
          area: document.getElementById('filter-area').value,
          user: document.getElementById('filter-user').value,
          dateStart: document.getElementById('filter-date-start').value,
          dateEnd: document.getElementById('filter-date-end').value
        };
        loadPhotos();
      });
    }

    // Clear filters button
    const clearBtn = document.getElementById('tcr-clear-filters');
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        document.getElementById('filter-trail').value = '';
        document.getElementById('filter-area').value = '';
        document.getElementById('filter-user').value = '';
        document.getElementById('filter-date-start').value = '';
        document.getElementById('filter-date-end').value = '';
        advancedFilters = {};
        loadPhotos();
      });
    }

    // Refresh button
    const refreshBtn = document.getElementById('tcr-refresh');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', loadPhotos);
    }
  });
})();
