/* plugins/trail-conditions-reports/assets/form.js */
console.log("[tcr] form.js loaded");
window.addEventListener("DOMContentLoaded", () => console.log("[tcr] DOM ready"));

(function () {
  "use strict";

  // --------------------------
  // Debug logging
  // --------------------------
  var DEBUG_TCR = true;
  function tlog() {
    if (!DEBUG_TCR) return;
    var args = Array.prototype.slice.call(arguments);
    args.unshift("[TCR]");
    // eslint-disable-next-line no-console
    console.debug.apply(console, args);
  }

  // --------------------------
  // Helpers
  // --------------------------
  function clampLat(n) { return Math.max(-90,  Math.min(90,  n)); }
  function clampLng(n) { return Math.max(-180, Math.min(180, n)); }
  function fmt(n) { return (typeof n === "number" && isFinite(n)) ? n.toFixed(6) : ""; }
  function lockInput(el)   { if (!el) return; el.readOnly = true;  el.style.background = "#f3f4f6"; el.style.pointerEvents = "none"; }
  function unlockInput(el) { if (!el) return; el.readOnly = false; el.style.background = "";       el.style.pointerEvents = ""; }

  function loginLinkHTML() {
    var href = (window.TCR && TCR.loginUrl)
      ? TCR.loginUrl
      : "/wp-login.php?redirect_to=" + encodeURIComponent(window.location.href);
    return 'Please <a href="' + href + '">log in</a> to submit.';
  }

  // Returns today's date as YYYY-MM-DD
  function todayStr() {
    try {
      var d = new Date();
      var m = (d.getMonth() + 1).toString().padStart(2, "0");
      var day = d.getDate().toString().padStart(2, "0");
      return d.getFullYear() + "-" + m + "-" + day;
    } catch (e) {
      return "";
    }
  }

  // --------------------------
  // DOM refs
  // --------------------------
  var f        = document.getElementById("tcr-form");
  var msg      = document.getElementById("tcr-msg");
  var areaSel  = document.getElementById("tcr-area");
  var trailSel = document.getElementById("tcr-trail");
  var photoInp = document.getElementById("tcr-photos");
var workDate = document.getElementById("tcr-work-date") || document.querySelector('[name="work_date"]');

  // If the date input isn't present in the markup, create it dynamically
  if (!workDate && f) {
    var row = document.createElement('div');
    row.className = 'tcr-row tcr-work-date-row';
    row.innerHTML =
      '<label for="tcr-work-date">Work date</label>' +
      '<input type="date" id="tcr-work-date" name="work_date" value="">' +
      '<small class="tcr-hint">Defaults to today; change if logging past work.</small>';

    // Try to position this after the hours field, otherwise near the top of the form
    var anchor = f.querySelector('#tcr-hours') || f.querySelector('input[name="hours_spent"]');
    if (anchor) {
      var anchorRow = (anchor.closest && anchor.closest('.tcr-row')) ? anchor.closest('.tcr-row') : anchor;
      if (anchorRow.parentNode) {
        anchorRow.parentNode.insertBefore(row, anchorRow.nextSibling);
      } else {
        f.insertBefore(row, f.firstChild);
      }
    } else {
      f.insertBefore(row, f.firstChild);
    }

    workDate = row.querySelector('#tcr-work-date');
  }

  // If there is a message box and no nonce, hint with a login link right away
  if (msg && (!window.TCR || !TCR.nonce)) {
    msg.innerHTML = loginLinkHTML();
  }

  // Default date input to today if it exists and is empty
  if (workDate && !workDate.value) {
    workDate.value = todayStr();
  }

  // Conditions (present if markup includes them; they use IDs not names)
  var condTrees = document.getElementById("tcr-cond-trees");
  var condHaz  = document.getElementById("tcr-cond-hazards");
  var condWash = document.getElementById("tcr-cond-washout");
  var condOver = document.getElementById("tcr-cond-overgrowth");
  var condMud  = document.getElementById("tcr-cond-muddy");
  var condText = document.getElementById("tcr-cond-comment");

  // Dynamic rows container (create if missing)
  var photoMetaWrap = document.getElementById("tcr-photo-meta");
  if (!photoMetaWrap && photoInp && photoInp.parentNode) {
    photoMetaWrap = document.createElement("div");
    photoMetaWrap.id = "tcr-photo-meta";
    photoInp.parentNode.appendChild(photoMetaWrap);
  }

  // Files we manage (support delete / reindex)
  var photoList = [];
  var rowMaps   = new Map(); // idx -> { map, marker }

  // --------------------------
  // Area → Trail filtering
  // --------------------------
  function filterTrails() {
    if (!trailSel) return;
    var area = (areaSel && areaSel.value ? areaSel.value : "").trim();
    var opts = Array.prototype.slice.call(trailSel.options || []);
    for (var i = 0; i < opts.length; i++) {
      var opt = opts[i];
      if (!opt.value) continue;
      var a = opt.getAttribute("data-area") || "";
      opt.hidden = !!(area && a !== area);
    }
    if (trailSel.selectedOptions && trailSel.selectedOptions[0] && trailSel.selectedOptions[0].hidden) {
      trailSel.value = "";
    }
  }
  if (areaSel) areaSel.addEventListener("change", filterTrails);
  filterTrails();

  // -----------------------------------------------------
  // Robust EXIF GPS reader for JPEGs (scan ALL APP1)
  // -----------------------------------------------------
  // Returns {lat, lng} or null. Uses magic bytes to detect JPEG (ignores MIME/extension).
  function readJpegExifGPS(file) {
    return new Promise(function (resolve) {
      try {
        if (!file || !file.arrayBuffer) { tlog("readJpegExifGPS: no file or no arrayBuffer"); return resolve(null); }

        file.arrayBuffer().then(function (buf) {
          var dv = new DataView(buf);
          // JPEG SOI 0xFFD8
          var isJPEG = dv.byteLength >= 4 && dv.getUint16(0, false) === 0xFFD8;
          if (!isJPEG) { tlog("not JPEG by magic bytes"); return resolve(null); }

          var off = 2;
          var len = dv.byteLength;
          tlog("start JPEG scan", { name: file.name, type: file.type || "(none)", size: file.size, len: len });

          while (off + 4 <= len) {
            if (dv.getUint8(off) !== 0xFF) { tlog("invalid marker prefix at", off); return resolve(null); }
            var marker = dv.getUint8(off + 1);
            off += 2;

            // Standalone markers (RSTn 0xD0-0xD7, TEM 0x01)
            if ((marker >= 0xD0 && marker <= 0xD7) || marker === 0x01) {
              tlog("RST/TEM marker", "FF" + marker.toString(16).toUpperCase());
              continue;
            }

            if (marker === 0xDA) { // Start of Scan
              tlog("Reached SOS (FFDA); stop metadata scan");
              break;
            }

            if (off + 2 > len) { tlog("truncated before size"); return resolve(null); }
            var size = dv.getUint16(off, false);
            off += 2;

            var segStart = off;
            var segEnd   = off + size - 2;
            if (segEnd > len) { tlog("segment beyond EOF", { off: off, size: size, len: len }); return resolve(null); }

            if (marker === 0xE1) {
              // APP1: check "Exif\0\0" header
              var isExif =
                dv.getUint8(segStart + 0) === 0x45 &&
                dv.getUint8(segStart + 1) === 0x78 &&
                dv.getUint8(segStart + 2) === 0x69 &&
                dv.getUint8(segStart + 3) === 0x66 &&
                dv.getUint8(segStart + 4) === 0x00 &&
                dv.getUint8(segStart + 5) === 0x00;

              var headStr = String.fromCharCode(
                dv.getUint8(segStart + 0), dv.getUint8(segStart + 1),
                dv.getUint8(segStart + 2), dv.getUint8(segStart + 3),
                dv.getUint8(segStart + 4), dv.getUint8(segStart + 5)
              );

              tlog("APP1 @ " + segStart + " size=" + size + " header=" + headStr + " isExif=" + (isExif ? "Y" : "N"));

              if (isExif) {
                var tiffStart = segStart + 6;
                var gps = parseTiffForGPS(dv, tiffStart);
                if (gps) { tlog("GPS found", gps); return resolve(gps); }
                tlog("Exif present but no GPS in this APP1, continue");
              } else {
                tlog("APP1 is not Exif (likely XMP), continue");
              }
            } else {
              tlog("Marker FF" + marker.toString(16).toUpperCase(), "size=" + size);
            }

            off = segEnd;
          }

          tlog("No EXIF GPS found after scanning all APP1 segments");
          resolve(null);
        }).catch(function (e) {
          console.error("[TCR] readJpegExifGPS arrayBuffer error:", e);
          resolve(null);
        });
      } catch (err) {
        console.error("[TCR] readJpegExifGPS error:", err);
        resolve(null);
      }
    });
  }

  function parseTiffForGPS(dv, tiffStart) {
    try {
      var endian = dv.getUint16(tiffStart, false);
      var isBE   = endian === 0x4D4D; // "MM"
      function U16(o) { return dv.getUint16(o, !isBE); }
      function U32(o) { return dv.getUint32(o, !isBE); }

      var magic = U16(tiffStart + 2);
      if (magic !== 0x002A) { tlog("Bad TIFF magic", magic.toString(16)); return null; }

      var ifd0Off = U32(tiffStart + 4);
      var ifd0    = tiffStart + ifd0Off;

      var dirCount = U16(ifd0);
      tlog("TIFF", isBE ? "BE" : "LE", "IFD0 entries=" + dirCount);

      var gpsIFDOffset = 0;
      for (var i = 0; i < dirCount; i++) {
        var ent = ifd0 + 2 + i * 12;
        var tag = U16(ent);
        if (tag === 0x8825) { // GPSInfoIFDPointer
          gpsIFDOffset = U32(ent + 8);
          tlog("GPS IFD pointer entry=", i, "offset=", gpsIFDOffset);
          break;
        }
      }
      if (!gpsIFDOffset) { tlog("No GPS IFD pointer in IFD0"); return null; }

      var gpsIFD = tiffStart + gpsIFDOffset;
      var gpsCount = U16(gpsIFD);
      tlog("GPS IFD entries=", gpsCount);

      // GPS tags
      var TAG_GPS_LAT_REF = 0x0001;
      var TAG_GPS_LAT     = 0x0002;
      var TAG_GPS_LON_REF = 0x0003;
      var TAG_GPS_LON     = 0x0004;

      var TYPE_ASCII = 2, TYPE_RATIONAL = 5;
      function typeSize(t){ return (t === TYPE_RATIONAL) ? 8 : 1; }

      var latRef, lonRef, latVals, lonVals;

      function readRational(ptr) {
        var num = dv.getUint32(ptr + 0, !isBE);
        var den = dv.getUint32(ptr + 4, !isBE);
        return den ? num / den : 0;
      }

      for (var j = 0; j < gpsCount; j++) {
        var e   = gpsIFD + 2 + j * 12;
        var tag = U16(e);
        var typ = U16(e + 2);
        var cnt = U32(e + 4);
        var valOrOff = e + 8;

        var totalBytes = cnt * typeSize(typ);
        var valuePtr   = (totalBytes > 4) ? (tiffStart + U32(valOrOff)) : valOrOff;

        if (tag === TAG_GPS_LAT_REF && typ === TYPE_ASCII) {
          latRef = String.fromCharCode(dv.getUint8(valuePtr));
        } else if (tag === TAG_GPS_LON_REF && typ === TYPE_ASCII) {
          lonRef = String.fromCharCode(dv.getUint8(valuePtr));
        } else if (tag === TAG_GPS_LAT && typ === TYPE_RATIONAL && cnt === 3) {
          latVals = [ readRational(valuePtr + 0), readRational(valuePtr + 8), readRational(valuePtr + 16) ];
        } else if (tag === TAG_GPS_LON && typ === TYPE_RATIONAL && cnt === 3) {
          lonVals = [ readRational(valuePtr + 0), readRational(valuePtr + 8), readRational(valuePtr + 16) ];
        }
      }

      if (!latVals || !lonVals || !latRef || !lonRef) { return null; }

      function toDec(d, m, s, ref) {
        var v = d + (m / 60) + (s / 3600);
        if (ref === "S" || ref === "W") v = -v;
        return +v.toFixed(6);
      }

      var lat = toDec(latVals[0], latVals[1], latVals[2], latRef);
      var lng = toDec(lonVals[0], lonVals[1], lonVals[2], lonRef);

      if (isFinite(lat) && isFinite(lng)) return { lat: lat, lng: lng };
      return null;
    } catch (err) {
      console.error("[TCR] parseTiffForGPS error:", err);
      return null;
    }
  }

  // -------------------------
  // Row rendering per photo
  // -------------------------
  function renderPhotoRow(file, idx){
    var row = document.createElement('div');
    row.className = 'tcr-photo-row';
    row.setAttribute('data-index', String(idx));

    // --- preview ---
    var url = (window.URL && URL.createObjectURL) ? URL.createObjectURL(file) : '';
    var preview = document.createElement('div');
    preview.className = 'tcr-photo-preview';
    preview.innerHTML = '<img src="'+url+'" alt="">';

    // --- fields (coords + caption + hint + geolocate) ---
    var fields = document.createElement('div');
    fields.className = 'tcr-photo-fields';
    fields.innerHTML =
      '<div class="tcr-coords">'+
        '<input class="tcr-lat" data-gps-lat-index="'+idx+'" type="number" step="0.000001" placeholder="Latitude">'+
        '<input class="tcr-lng" data-gps-lng-index="'+idx+'" type="number" step="0.000001" placeholder="Longitude">'+
      '</div>'+
      '<textarea class="tcr-caption" data-caption-index="'+idx+'" rows="2" placeholder="Photo caption (optional)"></textarea>'+
      '<div class="tcr-exif-hint">Reading EXIF…</div>'+
      '<button type="button" class="tcr-geolocate" data-geo-index="'+idx+'">Use my location</button>';

    // --- map ---
    var mapWrap = document.createElement('div');
    mapWrap.className = 'tcr-photo-map';
    mapWrap.innerHTML = '<div id="tcr-map-'+idx+'" class="tcr-map"></div>';

    // --- delete ---
    var del = document.createElement('button');
    del.type = 'button';
    del.className = 'tcr-photo-del';
    del.setAttribute('aria-label','Remove photo');
    del.textContent = '×';
    del.addEventListener('click', function(){ removePhotoAt(idx); });

    row.appendChild(preview);
    row.appendChild(fields);
    row.appendChild(del);
    row.appendChild(mapWrap);
    photoMetaWrap && photoMetaWrap.appendChild(row);

    var latEl = row.querySelector('.tcr-lat');
    var lngEl = row.querySelector('.tcr-lng');
    var hint  = row.querySelector('.tcr-exif-hint');

    // EXIF → coords
    readJpegExifGPS(file).then(function(gps){
      if(gps){
        latEl && (latEl.value=fmt(gps.lat));
        lngEl && (lngEl.value=fmt(gps.lng));
        lockInput(latEl); lockInput(lngEl);
        hint && (hint.textContent='GPS from EXIF ✓ ('+fmt(gps.lat)+', '+fmt(gps.lng)+')');
      }else{
        hint && (hint.textContent='No readable EXIF GPS — use device location or enter manually.');
      }
    }).catch(function(){
      hint && (hint.textContent='EXIF read failed — use device location or enter manually.');
    });

    // Geolocate
    var geoBtn = row.querySelector('.tcr-geolocate');
    if(geoBtn){
      geoBtn.addEventListener('click', function(){
        if(!navigator.geolocation){ if(hint) hint.textContent='Geolocation not supported.'; return; }
        hint && (hint.textContent='Locating…');
        navigator.geolocation.getCurrentPosition(
          function(pos){
            var latitude=pos.coords.latitude, longitude=pos.coords.longitude;
            if(latEl) latEl.value=fmt(latitude);
            if(lngEl) lngEl.value=fmt(longitude);
            unlockInput(latEl); unlockInput(lngEl);
            hint && (hint.textContent='Using device location ✓ ('+fmt(latitude)+', '+fmt(longitude)+')');
          },
          function(){ hint && (hint.textContent='Could not get device location.'); },
          {enableHighAccuracy:true,timeout:8000,maximumAge:0}
        );
      });
    }
  }

  // -----------------------
  // Add / remove photos
  // -----------------------
  function addFiles(fileList) {
    if (!fileList || !fileList.length) return;
    for (var i = 0; i < fileList.length; i++) {
      var file = fileList[i];
      var idx  = photoList.length;
      photoList.push(file);
      renderPhotoRow(file, idx);
    }
  }

  function removePhotoAt(idx) {
    photoList[idx] = undefined;

    // Remove row
    var row = photoMetaWrap ? photoMetaWrap.querySelector('.tcr-photo-row[data-index="' + idx + '"]') : null;
    if (row) {
      var img = row.querySelector("img");
      if (img && img.src && img.src.indexOf("blob:") === 0 && window.URL && URL.revokeObjectURL) {
        URL.revokeObjectURL(img.src);
      }
      row.remove();
    }
    rowMaps.delete(idx);

    // (We’re not reindexing here because we maintain order only for photos kept)
  }

  if (photoInp) {
    photoInp.addEventListener("change", function () {
      if (photoInp.files && photoInp.files.length) addFiles(photoInp.files);
      // clear so re-selecting the same file triggers change again
      photoInp.value = "";
    });
  }

  // --------------------------
  // WordPress media upload
  // --------------------------
  function uploadPhoto(file) {
    return fetch(TCR.wpRoot + "media", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "X-WP-Nonce": TCR.nonce,
        "Content-Disposition": 'attachment; filename="' + file.name + '"'
      },
      body: file
    }).then(function (res) {
      if (!res.ok) {
        return res.text().then(function (t) {
          throw new Error("Media upload failed: " + res.status + " " + t);
        });
      }
      return res.json();
    }).then(function (json) {
      tlog("media uploaded id=", json && json.id);
      return json.id;
    });
  }

  // ----------------
  // Submit handler
  // ----------------
  if (f) {
    f.addEventListener("submit", function (e) {
      e.preventDefault();
      if (msg) msg.textContent = "Submitting…";
      var btn = f.querySelector('button[type="submit"]');
      if (btn) btn.setAttribute("disabled", "true");

      var formData = new FormData(f);
      var data = {};
      formData.forEach(function (v, k) { data[k] = v; });

      var payload = {
        trail_id: parseInt(data.trail_id ? data.trail_id : 0, 10),
        hours_spent: Number(data.hours_spent ? data.hours_spent : 0),
        trees_cleared: parseInt(data.trees_cleared ? data.trees_cleared : 0, 10),
        work_date: (workDate && workDate.value) ? String(workDate.value) : todayStr(),
        // ---- Other Work (matches shortcodes.php) ----
        corridor_cleared: data.corridor_cleared_cb ? 1 : 0,
        raking:           data.raking_cb ? 1 : 0,
        installed_drains: data.installed_drains_cb ? 1 : 0,
        rocks_cleared:    data.rocks_cleared_cb ? 1 : 0,

        summary: data.summary ? data.summary : "",

        // conditions (from IDs, not names)
        cond_trees: condTrees && condTrees.value ? parseInt(condTrees.value, 10) : 0,
        cond_hazards:      condHaz && condHaz.checked ? 1 : 0,
        cond_washout:      condWash && condWash.checked ? 1 : 0,
        cond_overgrowth:   condOver && condOver.checked ? 1 : 0,
        cond_muddy:        condMud && condMud.checked ? 1 : 0,
        cond_comment:      condText && condText.value ? condText.value.trim() : ""
      };

      if (!payload.trail_id) {
        if (msg) msg.textContent = "Please select a trail.";
        if (btn) btn.removeAttribute("disabled");
        return;
      }

      // Upload media sequentially
      var ids = [];
      var files = photoList.filter(function (x) { return !!x; });
      tlog("uploading", files.length, "photos");

      function uploadNext(i) {
        if (i >= files.length) return Promise.resolve();
        return uploadPhoto(files[i]).then(function (id) {
          ids.push(id);
          return uploadNext(i + 1);
        });
      }

      uploadNext(0).then(function () {
        // Per-photo meta (same order as uploaded)
        var photos = ids.map(function (id, idx) {
          var latEl = document.querySelector('[data-gps-lat-index="' + idx + '"]');
          var lngEl = document.querySelector('[data-gps-lng-index="' + idx + '"]');
          var capEl = document.querySelector('[data-caption-index="' + idx + '"]');
          var gps_lat = (latEl && latEl.value) ? Number(latEl.value) : undefined;
          var gps_lng = (lngEl && lngEl.value) ? Number(lngEl.value) : undefined;
          var caption = (capEl && capEl.value) ? capEl.value.trim() : "";
          return { attachment_id: id, photo_type: "work", gps_lat: gps_lat, gps_lng: gps_lng, caption: caption };
        });
        tlog("photos meta", photos);

        // Submit report
        return fetch(TCR.root + "report", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json", "X-WP-Nonce": TCR.nonce },
          body: JSON.stringify(Object.assign({}, payload, { photos: photos }))
        });
      }).then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, json: j, status: r.status }; });
      }).then(function (res) {
        if (!res.ok) {
          // If auth-related, show login link and a friendly error
          if (res.status === 401 || res.status === 403) {
            if (msg) msg.innerHTML = loginLinkHTML();
            throw new Error("You must be logged in to submit.");
          }
          throw new Error((res.json && res.json.message) ? res.json.message : ("HTTP " + res.status));
        }
        if (msg) msg.textContent = "Report submitted! ID: " + res.json.id;
        f.reset();
        if (workDate) {
          workDate.value = todayStr();
        }
        if (photoMetaWrap) photoMetaWrap.innerHTML = "";
        photoList = [];
        rowMaps.clear();
        filterTrails();
      }).catch(function (err) {
        console.error(err);
        if (msg) msg.innerHTML = (err && /logged in/i.test(err.message)) ? loginLinkHTML() : ("Error: " + err.message);
      }).then(function () {
        if (btn) btn.removeAttribute("disabled");
      });
    });
  }
})();