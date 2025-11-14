/* Trail Conditions Reports — Browser UI
 * Version 1.0.1 (OSM iframe + per-photo maps + safe filters)
 */
(() => {
  "use strict";

  // -------------------- Config --------------------
  const CFG = (window.TCR) ? window.TCR : {
    root: (window.wp?.apiSettings?.root) || "",  // may be empty
    rootIndex: null,
    wpMedia: "/wp-json/wp/v2/media",
    nonce: null,
    perPage: 10,
  };

  function indexBase(namespace = "tcr/v1/") {
    const base = `${location.origin}/index.php?rest_route=/`;
    return base + (namespace.endsWith("/") ? namespace : namespace + "/");
  }

  let WORKING_BASE = null;

  async function tryFetch(url, opts = {}) {
    const res = await fetch(url, opts);
    let body = null;
    try { body = await res.json(); } catch (_) {}
    return { ok: res.ok, status: res.status, json: body };
  }

  async function ensureBase() {
    if (WORKING_BASE) return WORKING_BASE;

    const candidates = [];
    if (CFG.root) {
      // If CFG.root already points at /tcr/v1/ use it; else add that route
      if (CFG.root.endsWith("/tcr/v1/")) candidates.push(CFG.root);
      candidates.push(CFG.root.replace(/\/+$/,"") + "/tcr/v1/");
    }
    if (CFG.rootIndex) candidates.push(CFG.rootIndex);
    candidates.push(`${location.origin}/wp-json/tcr/v1/`);
    candidates.push(indexBase("tcr/v1/"));

    for (const base of candidates) {
      const probe = base.endsWith("/") ? base : base + "/";
      try {
        const { ok, json } = await tryFetch(probe, { credentials: "same-origin" });
        if (ok && (json?.namespace || json?.routes)) {
          WORKING_BASE = probe;
          console.debug("[TCR] Using REST base:", WORKING_BASE);
          return WORKING_BASE;
        }
      } catch (_) {}
    }
    WORKING_BASE = indexBase("tcr/v1/");
    console.warn("[TCR] Falling back to index.php REST base:", WORKING_BASE);
    return WORKING_BASE;
  }

  async function apiUrl(path = "") {
    const base = await ensureBase();
    // Allow absolute URLs to pass through unchanged
    if (typeof path === "string" && /^https?:\/\//i.test(path)) return path;
    // If no path provided, return the discovered base (ends with "/")
    if (!path) return base;
    // If caller passes a leading "?" treat it as query onto the base (no extra slash)
    if (typeof path === "string" && path.startsWith("?")) {
      return base.replace(/\/+$/,"") + path;
    }
    const clean = String(path).replace(/^\/+/, "");
    return base + clean;
  }

  function nonceHeader() {
    return CFG.nonce ? { "X-WP-Nonce": CFG.nonce } : {};
  }

  function withQuery(u, paramsObj) {
    const uo = new URL(u, location.origin);
    const sp = uo.searchParams;
    for (const [k, v] of Object.entries(paramsObj || {})) {
      if (v === undefined || v === null || v === "" || Number.isNaN(v)) continue;
      sp.set(k, typeof v === "boolean" ? (v ? "1" : "0") : String(v));
    }
    return uo.toString();
  }

  // -------------------- Small DOM helpers --------------------
  const $ = (sel) => document.querySelector(sel);
  function escapeHtml(s) {
    // First decode any existing HTML entities, then escape for safety
    const txt = document.createElement('textarea');
    txt.innerHTML = s ?? "";
    const decoded = txt.value;
    return String(decoded).replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    })[c]);
  }
  function h(html) {
    const d = document.createElement("div");
    d.innerHTML = html.trim();
    return d.firstChild;
  }

  // -------------------- State --------------------
  const state = {
    page: 1,
    per_page: Number(CFG.perPage || 10),
    area_id: "",
    trail_id: "",
    trailsById: {},      // Filtered trails for dropdown
    allTrailsById: {},   // All trails for lookups (so names don't become "Unknown")
    areasById: {},
  };

  // -------------------- Thumbnails --------------------
  const thumbCache = new Map();

  async function getThumbUrlFromMedia(attId) {
    if (!attId) return "";
    const base = (CFG.wpMedia || "/wp-json/wp/v2/media").replace(/\/+$/,"");
    const url = `${base}/${attId}`;
    const res = await fetch(url, { credentials: "same-origin", headers: nonceHeader() });
    if (!res.ok) return "";
    let data = null;
    try { data = await res.json(); } catch (_) {}
    const sizes = data?.media_details?.sizes;
    return sizes?.thumbnail?.source_url
        || sizes?.medium?.source_url
        || data?.source_url
        || "";
  }

  async function getThumbUrl(photo) {
    const key = photo?.attachment_id || 0;
    if (!key) return "";
    if (thumbCache.has(key)) return thumbCache.get(key);

    if (photo?.thumb_url) {
      thumbCache.set(key, photo.thumb_url);
      return photo.thumb_url;
    }
    const u = await getThumbUrlFromMedia(key);
    thumbCache.set(key, u);
    return u;
  }

  // -------------------- Fetchers --------------------
  async function fetchAreas() {
    const url = await apiUrl("areas");
    const res = await fetch(url, { credentials: "same-origin", headers: nonceHeader() });
    if (!res.ok) throw new Error(`Areas HTTP ${res.status}`);
    return res.json();
  }
  async function fetchTrails(areaId) {
    const url = withQuery(await apiUrl("trails"), { per_page: 500, area: areaId || "" });
    const res = await fetch(url, { credentials: "same-origin", headers: nonceHeader() });
    if (!res.ok) throw new Error(`Trails HTTP ${res.status}`);
    return res.json();
  }
  async function fetchReports() {
    const rawParams = {
      page: state.page,
      per_page: state.per_page,
      trail_id: state.trail_id || $("#tcr-trail-filter")?.value || "",
      area_id:  state.area_id  || $("#tcr-area-filter")?.value  || "",
      date_min: $("#tcr-date-min")?.value || "",
      date_max: $("#tcr-date-max")?.value || "",
      min_trees: parseInt($("#tcr-min-trees")?.value || "0", 10),
      orderby: $("#tcr-orderby")?.value || "created_at",
      order: $("#tcr-order")?.value || "desc",
    };

    // Only include optional flags if checked (omit sending 0)
    if ($("#tcr-has-photos")?.checked) rawParams.has_photos = 1;
    if ($("#tcr-cond-hazards")?.checked) rawParams.cond_hazards = 1;
    if ($("#tcr-cond-washout")?.checked) rawParams.cond_washout = 1;
    if ($("#tcr-cond-overgrowth")?.checked) rawParams.cond_overgrowth = 1;
    if ($("#tcr-cond-muddy")?.checked) rawParams.cond_muddy = 1;

    let url = withQuery(await apiUrl("reports"), rawParams);
    let res = await fetch(url, { credentials: "same-origin", headers: nonceHeader() });

    if (res.status === 404) {
      // Force index fallback if pretty base was cached but stopped working
      WORKING_BASE = indexBase("tcr/v1/");
      url = withQuery(await apiUrl("reports"), rawParams);
      console.warn("[TCR] 404 on pretty base, retry with index:", url);
      res = await fetch(url, { credentials: "same-origin", headers: nonceHeader() });
    }

    if (!res.ok) throw new Error(`Reports HTTP ${res.status}`);
    return res.json();
  }

  // -------------------- Map (OSM iframe) --------------------
  function makeOsmIframe(lat, lng) {
    const pad = 0.005;
    const minLon = (lng - pad).toFixed(6);
    const minLat = (lat - pad).toFixed(6);
    const maxLon = (lng + pad).toFixed(6);
    const maxLat = (lat + pad).toFixed(6);
    const src = `https://www.openstreetmap.org/export/embed.html?bbox=${minLon}%2C${minLat}%2C${maxLon}%2C${maxLat}&layer=mapnik&marker=${lat}%2C${lng}`;
    const link = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=15/${lat}/${lng}`;
    return `
      <div class="tcr-map">
        <iframe
          title="Map"
          width="100%" height="180" frameborder="0" scrolling="no" marginheight="0" marginwidth="0"
          src="${src}"
          loading="lazy"
        ></iframe>
        <small><a href="${link}" target="_blank" rel="noopener">View larger map</a></small>
      </div>
    `;
  }

  function firstValidGpsFromPhotos(photos = []) {
    for (const p of photos) {
      const lat = Number(p.gps_lat);
      const lng = Number(p.gps_lng);
      if (Number.isFinite(lat) && Number.isFinite(lng)) return { lat, lng };
    }
    return null;
  }

  function llFromReportFallback(r) {
    // If backend also provides top-level lat/lng or a WKT "POINT(lon lat)" string
    let lat = null, lng = null;
    const set = (a,b) => { lat = Number(a); lng = Number(b); };
    if (r.lat != null && (r.lng != null || r.lon != null || r.long != null)) set(r.lat, r.lng ?? r.lon ?? r.long);
    else if (r.latitude != null && (r.longitude != null || r.long != null)) set(r.latitude, r.longitude ?? r.long);
    else if (typeof r.geom === "string") {
      const m = r.geom.match(/POINT\s*\(\s*([-\d.]+)\s+([-\d.]+)\s*\)/i);
      if (m) set(m[2], m[1]);
    }
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
    return { lat, lng };
  }

  // -------------------- Index helpers --------------------
  function indexById(arr, idKey, nameKey) {
    const map = {};
    for (const x of (arr || [])) {
      const id =
        x[idKey] ??
        x.id ??
        x.trail_id ??
        x.term_id ??
        x.area_id ??
        x.trailId ??
        x.ID;
      if (id == null) continue;
      const nm =
        x[nameKey] ??
        x.name ??
        x.trail_name ??
        (x.title && (x.title.rendered ?? x.title)) ??
        x.post_title ??
        x.slug ??
        (`Trail ${id}`);
      map[id] = { id, name: nm, raw: x };
    }
    return map;
  }

  // Resolve a trail name via core WP REST API (by CPT id)
  async function resolveTrailName(id) {
    const tid = String(id).trim();
    if (!tid) return `Trail ${id}`;
    try {
      const r = await fetch(`/wp-json/wp/v2/trail/${encodeURIComponent(tid)}`, { credentials: "same-origin", headers: nonceHeader() });
      if (!r.ok) return `Trail ${id}`;
      const j = await r.json();
      return j?.title?.rendered || j?.name || j?.post_title || `Trail ${id}`;
    } catch (_) {
      return `Trail ${id}`;
    }
  }

  // -------------------- Render --------------------
  function badge(label) { return `<span class="tcr-badge">${escapeHtml(label)}</span>`; }

  function trailAreaLine(row) {
    const trail =
      row.trail_name ||
      row.trail?.name ||
      (state.allTrailsById[row.trail_id]?.name) ||
      (state.trailsById[row.trail_id]?.name) ||
      "Unknown trail";
    const areaId =
      row.area_id ||
      row.area?.id ||
      state.allTrailsById[row.trail_id]?.raw?.area_id ||
      state.trailsById[row.trail_id]?.raw?.area_id;
    const area = (areaId && state.areasById[areaId]?.name) || row.area_name || "";
    return [trail, area].filter(Boolean).join(" • ");
  }

  function perPhotoBlock(p) {
    const safeAlt = escapeHtml(p.caption || "");
    const src = escapeHtml(p.thumb_url || "");
    const att = p.attachment_id ? Number(p.attachment_id) : null;

    // If we don't have a thumb yet, try to fill it asynchronously
    let imgHtml = `<img loading="lazy" alt="${safeAlt}" src="${src}"${att ? ` data-att="${att}"` : ""}>`;
    if (!src && att) {
      // Will be replaced later (after first paint)
      imgHtml = `<img loading="lazy" alt="${safeAlt}" data-att="${att}">`;
    }

    let mapHtml = "";
    const lat = Number(p.gps_lat), lng = Number(p.gps_lng);
    if (Number.isFinite(lat) && Number.isFinite(lng)) {
      mapHtml = makeOsmIframe(lat, lng);
    }

    const editHref = att ? `/wp-admin/post.php?post=${att}&amp;action=edit` : "#";
    return `
      <div class="tcr-photo">
        <a href="${editHref}" target="_blank" rel="noopener">
          ${imgHtml}
        </a>
        ${mapHtml}
      </div>
    `;
  }

  async function backfillThumbImages(container) {
    // For any <img data-att="ID" src missing>, fetch a thumb and set it.
    const imgs = container.querySelectorAll('img[data-att]');
    for (const img of imgs) {
      if (img.getAttribute('src')) continue;
      const id = Number(img.getAttribute('data-att'));
      if (!Number.isFinite(id)) continue;
      try {
        const u = await getThumbUrlFromMedia(id);
        if (u) img.setAttribute('src', u);
      } catch {}
    }
  }

  async function renderRow(r) {
    const photos = Array.isArray(r.photos) ? r.photos.slice(0, 6) : [];

    // Work performed badges
    const workBadges = [];
    const trees = Number(r.trees_cleared ?? r.trees ?? 0);
    if (trees > 0) workBadges.push(badge(`Trees cleared: ${trees}`));
    if (+r.corridor_cleared) workBadges.push(badge("Corridor trimmed"));
    if (+r.raking) workBadges.push(badge("Raked trail"));
    if (+r.installed_drains) workBadges.push(badge("Worked on drains"));
    if (+r.rocks_cleared) workBadges.push(badge("Rocks cleared"));

    // Condition badges
    const condBadges = [];
    if (+r.cond_hazards)     condBadges.push(badge("⚠️ Hazards"));
    if (+r.cond_washout)     condBadges.push(badge("🌊 Washout"));
    if (+r.cond_overgrowth)  condBadges.push(badge("🌿 Overgrowth"));
    if (+r.cond_muddy)       condBadges.push(badge("🥾 Muddy"));

    // Format dates
    const workDate = r.work_date || '';
    const workDateFormatted = workDate ? new Date(workDate + 'T00:00:00').toLocaleDateString() : '';
    const created = new Date(String(r.created_at || "").replace(" ", "T")).toLocaleString();

    // User and hours info
    const userName = escapeHtml(r.user_name || 'Unknown');
    const hours = Number(r.hours_spent || 0);
    // Format hours to remove trailing zeros (3.00 -> 3, 3.50 -> 3.5, 3.25 -> 3.25)
    const hoursFormatted = hours > 0 ? parseFloat(hours.toFixed(2)) : 0;
    const hoursText = hoursFormatted > 0 ? `${hoursFormatted} hour${hoursFormatted !== 1 ? 's' : ''}` : '';

    // Build metadata line (user • hours • work date)
    const metaParts = [userName];
    if (hoursText) metaParts.push(hoursText);
    if (workDateFormatted) metaParts.push(workDateFormatted);
    const metaLine = metaParts.join(' • ');

    // Photos, each with its own optional map
    let mediaHtml = `<div class="tcr-no-photos">No photos</div>`;
    if (photos.length) {
      mediaHtml =
        `<div class="tcr-thumbs">` +
        photos.map(perPhotoBlock).join("") +
        `</div>`;
    }

    // Fallback single map if row-level lat/lng present (when photos lack GPS)
    let mapHtml = "";
    const gps = llFromReportFallback(r) || firstValidGpsFromPhotos(photos);
    if (gps && !photos.length) {
      mapHtml = makeOsmIframe(gps.lat, gps.lng);
    }

    // Build work performed section
    const workSection = workBadges.length > 0
      ? `<div class="tcr-section">
           <div class="tcr-section-title">Work Performed</div>
           <div class="tcr-badges">${workBadges.join(" ")}</div>
         </div>`
      : '';

    // Build conditions section
    const condSection = (condBadges.length > 0 || r.cond_comment)
      ? `<div class="tcr-section">
           <div class="tcr-section-title">Trail Conditions</div>
           ${condBadges.length > 0 ? `<div class="tcr-badges">${condBadges.join(" ")}</div>` : ''}
           ${r.cond_comment ? `<p class="tcr-comment">${escapeHtml(r.cond_comment)}</p>` : ''}
         </div>`
      : '';

    // Build summary section
    const summarySection = r.summary
      ? `<div class="tcr-section">
           <div class="tcr-section-title">Summary</div>
           <p class="tcr-summary">${escapeHtml(r.summary)}</p>
         </div>`
      : '';

    const card = h(`
      <article class="tcr-card">
        <header class="tcr-card-head">
          <div class="tcr-card-title">${escapeHtml(trailAreaLine(r))}</div>
          <div class="tcr-card-meta">${metaLine}</div>
          <div class="tcr-card-sub">Submitted: ${escapeHtml(created)}</div>
        </header>
        <div class="tcr-card-body">
          ${workSection}
          ${condSection}
          ${summarySection}
          ${mapHtml}
          ${mediaHtml}
        </div>
      </article>
    `);

    // Backfill any images that need async thumb lookup
    backfillThumbImages(card);
    return card;
  }

  function normalizeListPayload(payload) {
    // Accept either {rows, page, total_pages, total} or a plain array
    const rows = Array.isArray(payload) ? payload : (payload?.rows || payload?.items || payload?.data || []);
    const page = Number(payload?.page || 1);
    const total_pages = Number(payload?.total_pages || 1);
    const total = Number(payload?.total ?? rows.length);
    return { rows, page, total_pages, total };
  }

  async function load() {
    const results = $("#tcr-results");
    const pageInfo = $("#tcr-pageinfo");
    const prev = $("#tcr-prev");
    const next = $("#tcr-next");

    results.innerHTML = `<div class="tcr-loading">Loading…</div>`;
    try {
      const data = normalizeListPayload(await fetchReports());
      results.innerHTML = "";

      if (!data.rows.length) {
        results.innerHTML = `<div class="tcr-empty">No reports found.</div>`;
      } else {
        for (const row of data.rows) {
          const card = await renderRow(row);
          results.appendChild(card);
        }
      }

      pageInfo.textContent = `Page ${data.page} of ${data.total_pages}${data.total ? ` (${data.total} total)` : ""}`;
      if (prev) prev.disabled = (data.page <= 1);
      if (next) next.disabled = (data.page >= data.total_pages);
    } catch (e) {
      results.innerHTML = `<div class="tcr-error">Error loading reports: ${escapeHtml(e.message)}</div>`;
      pageInfo.textContent = "";
      if (prev) prev.disabled = true;
      if (next) next.disabled = true;
    }
  }

  // -------------------- Trail IDs with Reports Helper --------------------
  async function trailIdsWithReports(areaId) {
    // Prefer summary (grouped by trail); fallback to aggregating reports pages
    try {
      const sumUrl = withQuery(await apiUrl("summary"), { group_by: "trail", area: areaId || "" });
      let res = await fetch(sumUrl, { credentials: "same-origin", headers: nonceHeader() });
      if (res.status !== 404 && res.ok) {
        const payload = await res.json();
        const list = Array.isArray(payload) ? payload : (payload?.rows || payload?.items || []);
        const ids = new Set();
        for (const x of (list || [])) {
          const id = x.key_id ?? x.trail_id ?? x.trail ?? x.id ?? x.trailId ?? x.ID;
          if (id != null) ids.add(String(id));
        }
        return ids;
      }
    } catch (_) {}

    // Fallback: fetch reports and aggregate ids (cap pages for safety)
    const ids = new Set();
    let page = 1;
    const per_page = 100;
    for (let tries = 0; tries < 10; tries++) { // hard cap 10 pages
      try {
        const repUrl = withQuery(await apiUrl("reports"), {
          page, per_page, area_id: areaId || ""
        });
        const r = await fetch(repUrl, { credentials: "same-origin", headers: nonceHeader() });
        if (!r.ok) break;
        const payload = await r.json();
        const rows = Array.isArray(payload) ? payload : (payload?.rows || payload?.items || []);
        for (const item of (rows || [])) {
          const tid = item.trail_id ?? item.trail ?? item.trailId ?? item.key_id ?? item.id ?? item.ID;
          if (tid != null) ids.add(String(tid));
        }
        const total_pages = Number(payload?.total_pages || 1);
        if (!rows.length || page >= total_pages) break;
        page++;
      } catch { break; }
    }
    return ids;
  }

  // -------------------- Filters: Areas & Trails --------------------
  async function populateAreas() {
    const sel = $("#tcr-area-filter");
    if (!sel) return;
    try {
      const areas = await fetchAreas();
      // Some installs return {rows:[...]}
      const list = Array.isArray(areas) ? areas : (areas?.rows || areas?.items || []);
      sel.innerHTML = `<option value="">All Areas</option>` + (list || []).map(a =>
        `<option value="${String(a.id)}">${escapeHtml(a.name || `Area ${a.id}`)}</option>`
      ).join("");
      state.areasById = indexById(list, "id", "name");
    } catch (e) {
      console.warn("[TCR] Areas load failed", e);
      sel.innerHTML = `<option value="">All Areas</option>`;
      state.areasById = {};
    }
  }

  async function loadAllTrails() {
    // Load ALL trails once for lookups (so trail names are always available)
    try {
      const allTrails = await fetchTrails(""); // No area filter
      const list = Array.isArray(allTrails) ? allTrails : (allTrails?.rows || allTrails?.items || []);

      for (const trail of (list || [])) {
        const id = String(trail.id);
        const name = trail.title || trail.name || `Trail ${id}`;
        state.allTrailsById[id] = { id, name, raw: trail };
      }
    } catch (e) {
      console.warn("[TCR] Failed to load all trails for lookups", e);
    }
  }

  async function populateTrails() {
    const areaSel  = $("#tcr-area-filter");
    const trailSel = $("#tcr-trail-filter");
    if (!trailSel) return;

    const areaId = areaSel?.value || "";
    try {
      // Fetch trails filtered by area for the dropdown
      const trails = await fetchTrails(areaId);

      // Handle both array and {rows:[...]} response formats
      const list = Array.isArray(trails) ? trails : (trails?.rows || trails?.items || []);

      // If no trails exist, reset and bail
      if (!list || list.length === 0) {
        trailSel.innerHTML = `<option value="">All Trails</option>`;
        state.trailsById = {};
        return;
      }

      // Build options and state map for dropdown
      const options = [];
      const map = {};
      for (const trail of list) {
        const id = String(trail.id);
        const name = trail.title || trail.name || `Trail ${id}`;
        options.push(`<option value="${id}">${escapeHtml(name)}</option>`);
        map[id] = { id, name, raw: trail };
      }

      trailSel.innerHTML = `<option value="">All Trails</option>` + options.join("");
      state.trailsById = map;
    } catch (e) {
      console.warn("[TCR] Trails load failed", e);
      trailSel.innerHTML = `<option value="">All Trails</option>`;
      state.trailsById = {};
    }
  }

  async function populateAreasAndTrails() {
    await loadAllTrails();      // Load all trails for lookups first
    await populateAreas();       // Load areas
    await populateTrails();      // Load filtered trails for dropdown
  }

  // -------------------- Bind --------------------
  function bind() {
    $("#tcr-apply")?.addEventListener("click", () => {
      state.page = 1;
      state.area_id = $("#tcr-area-filter")?.value || "";
      state.trail_id = $("#tcr-trail-filter")?.value || "";
      load();
    });

    $("#tcr-prev")?.addEventListener("click", () => {
      if (state.page > 1) { state.page--; load(); }
    });
    $("#tcr-next")?.addEventListener("click", () => {
      state.page++; load();
    });

    $("#tcr-area-filter")?.addEventListener("change", async () => {
      await populateTrails();
    });
  }

  // -------------------- Init --------------------
  document.addEventListener("DOMContentLoaded", async () => {
    bind();
    await populateAreasAndTrails();
    await load();
  });
})();