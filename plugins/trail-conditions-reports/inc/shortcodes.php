<?php
if (!defined('ABSPATH')) exit;

/**
 * [trail_report_form]
 * Builds the submit form and enqueues assets.
 * Uses taxonomy "tcr_area" for Areas (fallback to legacy post meta if needed).
 */
add_shortcode('trail_report_form', function () {
  // Logged out → show clickable login link that comes back to this page
  if (!is_user_logged_in()) {
    $login_url = wp_login_url(get_permalink());
    return '<p>Please <a href="'.esc_url($login_url).'">log in</a> to submit.</p>';
  }

  // -------- Areas (taxonomy-first, fallback to legacy meta) --------
  $areas = [];
  $trail_data = [];

  // Try taxonomy first
  $area_terms = function_exists('get_terms')
    ? get_terms(['taxonomy' => 'tcr_area', 'hide_empty' => false])
    : [];
  if (!is_wp_error($area_terms) && !empty($area_terms)) {
    $areas = array_map(fn($t) => $t->name, $area_terms);
  }

  // Get Trails and resolve area (taxonomy -> term name); fallback to meta
  $trails = get_posts([
    'post_type'   => 'trail',
    'numberposts' => -1,
    'orderby'     => 'title',
    'order'       => 'ASC',
    'fields'      => 'ids'
  ]);

  foreach ($trails as $tid) {
    $title = get_the_title($tid);

    // Prefer taxonomy term
    $terms = function_exists('wp_get_post_terms')
      ? wp_get_post_terms($tid, 'tcr_area')
      : [];
    $area_name = (!is_wp_error($terms) && !empty($terms)) ? $terms[0]->name : '';

    // Fallback to legacy post meta if no term found
    if ($area_name === '') {
      $legacy = get_post_meta($tid, 'tcr_area', true) ?: '';
      $area_name = $legacy;
      if ($legacy !== '') $areas[] = $legacy;
    }

    $trail_data[] = [
      'id'    => (int)$tid,
      'title' => $title,
      'area'  => $area_name,
    ];
  }

  // Normalize areas list (unique + natural sort)
  $areas = array_values(array_unique(array_filter($areas, fn($a) => $a !== '')));
  sort($areas, SORT_NATURAL);

  // -------- Assets --------
  // Maps for per-photo previews (Leaflet)
  wp_enqueue_style ('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
  wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);

  // Form styles + logic
  wp_enqueue_style('tcr-form-css', TCR_URL.'assets/form.css', [], filemtime(TCR_PATH.'assets/form.css'));
  wp_enqueue_script('tcr-form', TCR_URL.'assets/form.js', ['leaflet'], filemtime(TCR_PATH.'assets/form.js'), true);

  // REST roots + nonce + data for client-side filtering (+ loginUrl for JS)
  $redirect = get_permalink();
  wp_localize_script('tcr-form', 'TCR', [
    'root'     => esc_url_raw( rest_url('tcr/v1/') ),
    'wpRoot'   => esc_url_raw( rest_url('wp/v2/') ),
    'nonce'    => wp_create_nonce('wp_rest'),
    'trails'   => $trail_data,
    'areas'    => $areas,
    'loginUrl' => wp_login_url($redirect),
  ]);

  ob_start(); ?>
  <form id="tcr-form" novalidate>
    <div class="tcr-head">Submit Trail Report</div>

    <!-- Area / Trail -->
    <div class="tcr-card tcr-grid cols-2" style="margin-bottom:var(--gap);">
      <div class="tcr-field">
        <label class="tcr-label" for="tcr-area">Area</label>
        <select name="area" id="tcr-area">
          <option value="">All Areas</option>
          <?php foreach ($areas as $a): ?>
            <option value="<?php echo esc_attr($a); ?>"><?php echo esc_html($a); ?></option>
          <?php endforeach; ?>
        </select>
        <div class="tcr-help">Choose an Area first to narrow down the trails.</div>
      </div>

      <div class="tcr-field">
        <label class="tcr-label" for="tcr-trail">Trail <span style="color:#ef4444">*</span></label>
        <select name="trail_id" id="tcr-trail" required>
          <option value="">Select a trail…</option>
          <?php foreach ($trail_data as $t): ?>
            <option value="<?php echo esc_attr($t['id']); ?>" data-area="<?php echo esc_attr($t['area']); ?>">
              <?php echo esc_html($t['title']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Work metrics -->
    <div class="tcr-card tcr-grid cols-2" style="margin-bottom:var(--gap);">
      <div class="tcr-field">
        <label class="tcr-label" for="tcr-hours">Hours <span style="color:#ef4444">*</span></label>
        <input id="tcr-hours" name="hours_spent" type="number" step="0.01" min="0" required placeholder="e.g., 3.25">
      </div>
      <div class="tcr-field">
        <label class="tcr-label" for="tcr-trees">Trees Cleared</label>
        <input id="tcr-trees" name="trees_cleared" type="number" min="0" value="0" placeholder="e.g., 2">
      </div>

      <!-- ✅ UPDATED Other Work -->
      <div class="tcr-field" style="grid-column:1 / -1;">
        <div class="tcr-label">Other Work</div>
        <div class="tcr-checks" id="tcr-other-work">
          <label>
            <input type="checkbox" name="corridor_cleared_cb" id="tcr-corridor-cleared">
            Corridor Trimmed
          </label>
          <label>
            <input type="checkbox" name="raking_cb" id="tcr-raking">
            Raked Trail
          </label>
          <label>
            <input type="checkbox" name="installed_drains_cb" id="tcr-drains">
            Worked on Drains
          </label>
          <label>
            <input type="checkbox" name="rocks_cleared_cb" id="tcr-rocks">
            Rocks Cleared
          </label>
        </div>
      </div>
    </div>

    <!-- Conditions -->
    <div class="tcr-card" style="margin-bottom:var(--gap);">
      <div class="tcr-field">
        <div class="tcr-label">Trail Conditions</div>
        <div class="tcr-checks">
          <label>
            <span>Number of Downed Trees:</span>
            <input type="number" id="tcr-cond-trees" min="0" step="1" placeholder="e.g., 3" style="width:90px;">
          </label>
          <label><input type="checkbox" id="tcr-cond-hazards"> Safety hazards</label>
          <label><input type="checkbox" id="tcr-cond-washout"> Tread washout</label>
          <label><input type="checkbox" id="tcr-cond-overgrowth"> Corridor overgrowth</label>
          <label><input type="checkbox" id="tcr-cond-muddy"> Muddy</label>
        </div>
        <div style="margin-top:8px;">
          <label class="tcr-label" for="tcr-cond-comment">Conditions comment</label>
          <textarea id="tcr-cond-comment" rows="3" placeholder="Brief note about conditions…"></textarea>
        </div>
      </div>
    </div>

    <!-- Summary -->
    <div class="tcr-card" style="margin-bottom:var(--gap);">
      <div class="tcr-field">
        <label class="tcr-label" for="tcr-summary">Summary</label>
        <textarea id="tcr-summary" name="summary" rows="4"
          placeholder="What did you do? Where on the trail? Any notes for the next crew?"></textarea>
      </div>
    </div>

    <!-- Photos -->
    <div class="tcr-card" style="margin-bottom:var(--gap);">
      <div class="tcr-field">
        <label class="tcr-label" for="tcr-photos">Photos</label>
        <input id="tcr-photos" type="file" accept="image/*" multiple>
        <div class="tcr-help">You can select multiple images. They’ll upload first, then be attached to this report.</div>
      </div>
      <!-- The dynamic per-photo UI (preview, GPS, map, caption, delete) renders here: -->
      <div id="tcr-photo-meta"></div>
    </div>

    <button type="submit">Submit Report</button>
    <div id="tcr-msg"></div>
  </form>
  <?php
  return ob_get_clean();
});

/**
 * [trail_dashboard]
 * (unchanged; your dashboard.js can query summaries including group-by area)
 */
add_shortcode('trail_dashboard', function () {
  wp_enqueue_script('tcr-dash', TCR_URL.'assets/dashboard.js', [], filemtime(TCR_PATH.'assets/dashboard.js'), true);
  wp_localize_script('tcr-dash', 'TCRD', ['root' => esc_url_raw( rest_url('tcr/v1/') )]);
  return '<div id="tcr-dash"><table id="tcr-table"></table></div>';
});

/**
 * [tcr_analytics]
 * Renders the analytics/reporting dashboard
 */
add_shortcode('tcr_analytics', function () {
  // Enqueue assets
  $css_path = TCR_PATH.'assets/analytics.css';
  $js_path  = TCR_PATH.'assets/analytics.js';

  if (file_exists($css_path)) {
    wp_enqueue_style('tcr-analytics-css', TCR_URL.'assets/analytics.css', [], filemtime($css_path));
  } else {
    wp_enqueue_style('tcr-analytics-css', TCR_URL.'assets/analytics.css', [], TCR_VER);
  }

  if (file_exists($js_path)) {
    wp_enqueue_script('tcr-analytics', TCR_URL.'assets/analytics.js', [], filemtime($js_path), true);
  } else {
    wp_enqueue_script('tcr-analytics', TCR_URL.'assets/analytics.js', [], TCR_VER, true);
  }

  // Pass REST endpoint config
  $cfg = [
    'root' => esc_url_raw( rest_url('tcr/v1/') ),
    'nonce' => wp_create_nonce('wp_rest'),
  ];
  wp_add_inline_script('tcr-analytics', 'window.TCR=' . wp_json_encode($cfg) . ';', 'before');

  return '
  <div id="tcr-analytics" class="tcr-analytics">
    <h1 class="tcr-analytics-title">Trail Maintenance Analytics</h1>
    <div id="tcr-analytics-content">Loading...</div>
  </div>';
});

// Browser (cards + filters) shortcode: [tcr_browser]
// Renders the public browser UI and enqueues browser.js / browser.css
add_shortcode('tcr_browser', function () {
  // Ensure the dashboard assets are not active on this page
  if (wp_script_is('tcr-dash', 'enqueued') || wp_script_is('tcr-dash', 'registered')) {
    wp_dequeue_script('tcr-dash');
    wp_deregister_script('tcr-dash');
  }
  if (wp_style_is('tcr-dash-css', 'enqueued') || wp_style_is('tcr-dash-css', 'registered')) {
    wp_dequeue_style('tcr-dash-css');
    wp_deregister_style('tcr-dash-css');
  }
  // Assets
  $css_path = TCR_PATH.'assets/browser.css';
  $js_path  = TCR_PATH.'assets/browser.js';
  if (file_exists($css_path)) {
    wp_enqueue_style('tcr-browser-css', TCR_URL.'assets/browser.css', [], filemtime($css_path));
  } else {
    wp_enqueue_style('tcr-browser-css', TCR_URL.'assets/browser.css', [], TCR_VER);
  }
  if (file_exists($js_path)) {
    wp_enqueue_script('tcr-browser', TCR_URL.'assets/browser.js', [], filemtime($js_path), true);
  } else {
    wp_enqueue_script('tcr-browser', TCR_URL.'assets/browser.js', [], TCR_VER, true);
  }

  // Pass REST roots + nonce; browser.js will prefer pretty REST and fall back to index.php route if needed
  $cfg = [
    'root'      => esc_url_raw( rest_url('tcr/v1/') ),
    'rootIndex' => esc_url_raw( site_url('/index.php?rest_route=/tcr/v1/') ),
    'wpRoot'    => esc_url_raw( rest_url('wp/v2/') ),
    'nonce'     => wp_create_nonce('wp_rest'),
    'perPage'   => 10,
  ];
  // Expose as window.TCR for consistency with other scripts
  wp_add_inline_script('tcr-browser', 'window.TCR=' . wp_json_encode($cfg) . ';', 'before');

  // Minimal markup expected by browser.js
  return '
  <div id="tcr-browser" class="tcr-browser">
    <div class="tcr-filters">
      <select id="tcr-area-filter"></select>
      <select id="tcr-trail-filter"></select>
      <button id="tcr-apply" type="button">Apply</button>
      <span id="tcr-pageinfo" class="tcr-pageinfo"></span>
      <button id="tcr-prev" type="button">Prev</button>
      <button id="tcr-next" type="button">Next</button>
    </div>
    <div id="tcr-results" class="tcr-results"></div>
  </div>';
});