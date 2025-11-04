/* Trail Conditions Reports — Analytics Dashboard */
(() => {
  "use strict";

  const CFG = window.TCR || {};
  const API_URL = (CFG.root || '/wp-json/tcr/v1/') + 'analytics';

  function escapeHtml(s) {
    return String(s ?? "").replace(/[&<>"']/g, (c) => ({
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
