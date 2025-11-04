(async function(){
  const root = TCRD.root;
  const table = document.getElementById('tcr-table');
  table.innerHTML = `<thead><tr>
    <th>Trail</th><th>Reports</th><th>Hours</th><th>Trees</th><th>Brush</th><th>Rocks</th>
  </tr></thead><tbody id="tcr-body"></tbody>`;
  const body = document.getElementById('tcr-body');

  const qs = new URLSearchParams({ group_by: 'trail' });
  const sep = root.includes('?') ? '&' : '?';

  // Prefer /reports (always exists). If it 404s, try /summary.
  let res = await fetch(`${root}reports${sep}${qs.toString()}`);
  if (res.status === 404) {
    res = await fetch(`${root}summary${sep}${qs.toString()}`);
  }

  let data;
  try {
    data = await res.json();
  } catch (e) {
    data = [];
  }

  // Normalize to summary-like rows: { key_id, reports, hours, trees, brush, rocks }
  function asSummaryRows(input) {
    if (!Array.isArray(input)) return [];
    // If items already look like summary rows (have key_id), return as-is.
    if (input.length > 0 && Object.prototype.hasOwnProperty.call(input[0], 'key_id')) {
      return input;
    }
    // Otherwise, aggregate reports by trail id.
    const byTrail = new Map();
    for (const r of input) {
      const trailId = r.trail_id ?? r.trail ?? r.trailId ?? r.key_id; // be forgiving on field names
      if (trailId == null) continue;
      const hours = Number(r.hours ?? r.time_hours ?? 0) || 0;
      const trees = Number(r.trees_cleared ?? r.trees ?? 0) || 0;
      const brush = Number(r.brush_cleared ?? r.brush ?? 0) || 0;
      const rocks = Number(r.rocks_moved ?? r.rocks ?? 0) || 0;

      if (!byTrail.has(trailId)) {
        byTrail.set(trailId, { key_id: trailId, reports: 0, hours: 0, trees: 0, brush: 0, rocks: 0 });
      }
      const agg = byTrail.get(trailId);
      agg.reports += 1;
      agg.hours += hours;
      agg.trees += trees;
      agg.brush += brush;
      agg.rocks += rocks;
    }
    // Sort by trail id for stable output
    return Array.from(byTrail.values()).sort((a, b) => String(a.key_id).localeCompare(String(b.key_id)));
  }

  const summaryRows = asSummaryRows(data);

  const rows = await Promise.all(
    summaryRows.map(async (r) => `
      <tr>
        <td>${await trailName(r.key_id)}</td>
        <td>${r.reports}</td>
        <td>${Number(r.hours).toFixed(2)}</td>
        <td>${r.trees || 0}</td>
        <td>${r.brush || 0}</td>
        <td>${r.rocks || 0}</td>
      </tr>`
    )
  );

  body.innerHTML = rows.length
    ? rows.join('')
    : '<tr><td colspan="6">No data available.</td></tr>';

  async function trailName(id){
    // Use core REST to resolve trail title
    const r = await fetch(`/wp-json/wp/v2/trail/${id}`);
    if (!r.ok) return `Trail ${id}`;
    const j = await r.json();
    return j.title?.rendered || `Trail ${id}`;
  }
})();