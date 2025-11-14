/* Trail Conditions Reports — Analytics Dashboard */
(() => {
  "use strict";

  const CFG = window.TCR || {};
  const API_URL = (CFG.root || '/wp-json/tcr/v1/') + 'analytics';

  function escapeHtml(s) {
    // First decode any existing HTML entities, then escape for safety
    const txt = document.createElement('textarea');
    txt.innerHTML = s ?? "";
    const decoded = txt.value;
    return String(decoded).replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    })[c]);
  }

  function formatNumber(n) {
    return Number(n || 0).toLocaleString();
  }

  function formatHours(h) {
    const hours = Number(h || 0);
    return hours.toFixed(1);
  }

  async function fetchAnalytics() {
    const headers = {};
    if (CFG.nonce) headers['X-WP-Nonce'] = CFG.nonce;

    const res = await fetch(API_URL, { credentials: 'same-origin', headers });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  }

  function renderOverallStats(data) {
    const overall = data.overall || {};
    const recent = data.recent || {};

    return `
      <div class="tcr-stats-grid">
        <div class="tcr-stat-card">
          <div class="tcr-stat-value">${formatNumber(overall.total_reports)}</div>
          <div class="tcr-stat-label">Total Reports</div>
          <div class="tcr-stat-sub">${formatNumber(recent.reports_30d)} in last 30 days</div>
        </div>
        <div class="tcr-stat-card">
          <div class="tcr-stat-value">${formatHours(overall.total_hours)}</div>
          <div class="tcr-stat-label">Total Hours</div>
          <div class="tcr-stat-sub">${formatHours(recent.hours_30d)} in last 30 days</div>
        </div>
        <div class="tcr-stat-card">
          <div class="tcr-stat-value">${formatNumber(overall.total_trees)}</div>
          <div class="tcr-stat-label">Trees Cleared</div>
          <div class="tcr-stat-sub">${formatNumber(recent.trees_30d)} in last 30 days</div>
        </div>
        <div class="tcr-stat-card">
          <div class="tcr-stat-value">${formatNumber(overall.trails_maintained)}</div>
          <div class="tcr-stat-label">Trails Maintained</div>
          <div class="tcr-stat-sub">${formatNumber(overall.total_volunteers)} volunteers</div>
        </div>
      </div>
    `;
  }

  function renderByArea(areas) {
    if (!areas || areas.length === 0) {
      return '<div class="tcr-empty">No area data available</div>';
    }

    const rows = areas.map(a => `
      <tr>
        <td>${escapeHtml(a.area_name)}</td>
        <td>${formatNumber(a.total_reports)}</td>
        <td>${formatHours(a.total_hours)}</td>
        <td>${formatNumber(a.total_trees)}</td>
      </tr>
    `).join('');

    return `
      <table class="tcr-table">
        <thead>
          <tr>
            <th>Area</th>
            <th>Reports</th>
            <th>Hours</th>
            <th>Trees Cleared</th>
          </tr>
        </thead>
        <tbody>
          ${rows}
        </tbody>
      </table>
    `;
  }

  function renderByMonth(months) {
    if (!months || months.length === 0) {
      return '<div class="tcr-empty">No monthly data available</div>';
    }

    const rows = months.map(m => {
      // Format YYYY-MM to Month Year
      const [year, month] = m.month.split('-');
      const date = new Date(year, month - 1);
      const monthName = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

      return `
        <tr>
          <td>${monthName}</td>
          <td>${formatNumber(m.total_reports)}</td>
          <td>${formatHours(m.total_hours)}</td>
          <td>${formatNumber(m.total_trees)}</td>
        </tr>
      `;
    }).join('');

    return `
      <table class="tcr-table">
        <thead>
          <tr>
            <th>Month</th>
            <th>Reports</th>
            <th>Hours</th>
            <th>Trees Cleared</th>
          </tr>
        </thead>
        <tbody>
          ${rows}
        </tbody>
      </table>
    `;
  }

  function renderHazardTrails(trails) {
    if (!trails || trails.length === 0) {
      return '<div class="tcr-empty">No hazard data available</div>';
    }

    const rows = trails.map(t => {
      const hazards = [];
      if (Number(t.safety_hazards) > 0) hazards.push(`⚠️ Safety (${t.safety_hazards})`);
      if (Number(t.washouts) > 0) hazards.push(`🌊 Washout (${t.washouts})`);
      if (Number(t.overgrowth) > 0) hazards.push(`🌿 Overgrowth (${t.overgrowth})`);
      if (Number(t.downed_trees) > 0) hazards.push(`🌲 Downed Trees (${t.downed_trees})`);

      return `
        <tr>
          <td>
            <div class="tcr-trail-name">${escapeHtml(t.trail_name)}</div>
            ${t.area_name ? `<div class="tcr-trail-area">${escapeHtml(t.area_name)}</div>` : ''}
          </td>
          <td>${formatNumber(t.hazard_count)}</td>
          <td><div class="tcr-hazard-list">${hazards.join('<br>')}</div></td>
        </tr>
      `;
    }).join('');

    return `
      <table class="tcr-table">
        <thead>
          <tr>
            <th>Trail</th>
            <th>Reports with Hazards</th>
            <th>Hazard Types</th>
          </tr>
        </thead>
        <tbody>
          ${rows}
        </tbody>
      </table>
    `;
  }

  function renderResolutionStats(stats) {
    if (!stats) {
      return '<div class="tcr-empty">No resolution data available</div>';
    }

    return `
      <div class="tcr-stats-grid">
        <div class="tcr-stat-card resolution">
          <div class="tcr-stat-value">${formatNumber(stats.total_resolved)}</div>
          <div class="tcr-stat-label">Total Resolved</div>
          <div class="tcr-stat-sub">${formatNumber(stats.resolved_30d)} in last 30 days</div>
        </div>
        <div class="tcr-stat-card active">
          <div class="tcr-stat-value">${formatNumber(stats.currently_active)}</div>
          <div class="tcr-stat-label">Currently Active</div>
          <div class="tcr-stat-sub">${formatNumber(stats.resolved_7d)} resolved this week</div>
        </div>
        <div class="tcr-stat-card">
          <div class="tcr-stat-value">${formatNumber(stats.total_outstanding)}</div>
          <div class="tcr-stat-label">Total Outstanding Items</div>
          <div class="tcr-stat-sub">All time</div>
        </div>
      </div>
    `;
  }

  function renderTopResolvers(resolvers) {
    if (!resolvers || resolvers.length === 0) {
      return '<div class="tcr-empty">No resolution activity yet</div>';
    }

    const rows = resolvers.map((r, index) => {
      const medal = index === 0 ? '🥇' : index === 1 ? '🥈' : index === 2 ? '🥉' : '';
      return `
        <tr>
          <td class="rank">${medal} ${index + 1}</td>
          <td class="resolver-name">${escapeHtml(r.display_name)}</td>
          <td>${formatNumber(r.resolutions)}</td>
          <td>${formatNumber(r.trails_helped)}</td>
          <td class="date">${new Date(r.latest_resolution).toLocaleDateString()}</td>
        </tr>
      `;
    }).join('');

    return `
      <table class="tcr-table leaderboard">
        <thead>
          <tr>
            <th>Rank</th>
            <th>Trail Agent</th>
            <th>Items Resolved</th>
            <th>Trails Helped</th>
            <th>Latest Resolution</th>
          </tr>
        </thead>
        <tbody>
          ${rows}
        </tbody>
      </table>
    `;
  }

  function renderRecentResolutions(resolutions) {
    if (!resolutions || resolutions.length === 0) {
      return '<div class="tcr-empty">No recent resolutions</div>';
    }

    const items = resolutions.map(r => `
      <div class="resolution-item">
        ${r.image_url ? `<img src="${r.image_url}" alt="Trail photo">` : ''}
        <div class="resolution-details">
          <div class="resolution-trail">${escapeHtml(r.trail_name)}</div>
          ${r.caption ? `<div class="resolution-caption">${escapeHtml(r.caption)}</div>` : ''}
          <div class="resolution-meta">
            Resolved by <strong>${escapeHtml(r.resolved_by_name)}</strong>
            on ${new Date(r.resolved_at).toLocaleDateString()}
          </div>
        </div>
      </div>
    `).join('');

    return `<div class="resolution-list">${items}</div>`;
  }

  function renderResolutionsByMonth(months) {
    if (!months || months.length === 0) {
      return '<div class="tcr-empty">No monthly resolution data available</div>';
    }

    const rows = months.map(m => {
      const [year, month] = m.month.split('-');
      const monthName = new Date(year, month - 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

      return `
        <tr>
          <td>${monthName}</td>
          <td>${formatNumber(m.resolutions)}</td>
          <td>${formatNumber(m.unique_resolvers)}</td>
        </tr>
      `;
    }).join('');

    return `
      <table class="tcr-table">
        <thead>
          <tr>
            <th>Month</th>
            <th>Items Resolved</th>
            <th>Unique Agents</th>
          </tr>
        </thead>
        <tbody>
          ${rows}
        </tbody>
      </table>
    `;
  }

  async function render() {
    const container = document.getElementById('tcr-analytics-content');
    if (!container) return;

    try {
      container.innerHTML = '<div class="tcr-loading">Loading analytics data...</div>';

      const data = await fetchAnalytics();

      const html = `
        <section class="tcr-section">
          <h2>Overview</h2>
          ${renderOverallStats(data)}
        </section>

        <div class="tcr-grid-2col">
          <section class="tcr-section">
            <h2>Reports by Area</h2>
            ${renderByArea(data.by_area)}
          </section>

          <section class="tcr-section">
            <h2>Activity by Month</h2>
            ${renderByMonth(data.by_month)}
          </section>
        </div>

        <section class="tcr-section">
          <h2>Trails with Reported Hazards</h2>
          <p class="tcr-help">Top 10 trails with the most reported hazards or conditions</p>
          ${renderHazardTrails(data.hazard_trails)}
        </section>

        <section class="tcr-section tcr-resolution-section">
          <h2>Outstanding Maintenance Resolutions</h2>
          ${renderResolutionStats(data.resolution_stats)}
        </section>

        <section class="tcr-section">
          <h2>🏆 Resolution Leaderboard</h2>
          <p class="tcr-help">Top trail agents who have resolved the most outstanding maintenance items</p>
          ${renderTopResolvers(data.top_resolvers)}
        </section>

        <div class="tcr-grid-2col">
          <section class="tcr-section">
            <h2>Resolutions by Month</h2>
            ${renderResolutionsByMonth(data.resolutions_by_month)}
          </section>

          <section class="tcr-section">
            <h2>Recent Resolutions</h2>
            ${renderRecentResolutions(data.recent_resolutions)}
          </section>
        </div>
      `;

      container.innerHTML = html;
    } catch (err) {
      container.innerHTML = `<div class="tcr-error">Error loading analytics: ${escapeHtml(err.message)}</div>`;
      console.error('[TCR Analytics] Error:', err);
    }
  }

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
