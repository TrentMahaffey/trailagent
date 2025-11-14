<?php
/**
 * Interactive Trail Map
 * Displays all trails from GPX files with status overlays
 */
if (!defined('ABSPATH')) exit;

/**
 * [trail_map] - Interactive trail map shortcode
 */
add_shortcode('trail_map', function($atts) {
  $atts = shortcode_atts([
    'height' => '600px',
    'center_lat' => '39.1911',
    'center_lng' => '-106.8175', // Aspen, CO
    'zoom' => '11'
  ], $atts);

  // Enqueue Leaflet library
  wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
  wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);

  // Enqueue trail map assets
  wp_enqueue_style('tcr-trail-map-css', TCR_URL.'assets/trail-map.css', ['leaflet-css'], filemtime(TCR_PATH.'assets/trail-map.css'));
  wp_enqueue_script('tcr-trail-map', TCR_URL.'assets/trail-map.js', ['leaflet-js'], filemtime(TCR_PATH.'assets/trail-map.js'), true);

  // Get all trails with their status
  $trails = get_posts([
    'post_type' => 'trail',
    'posts_per_page' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
  ]);

  $trail_data = [];
  foreach ($trails as $trail) {
    $area_terms = wp_get_post_terms($trail->ID, 'tcr_area');
    $area_name = !empty($area_terms) ? $area_terms[0]->name : 'Other';

    $trail_data[] = [
      'id' => $trail->ID,
      'name' => $trail->post_title,
      'status' => get_post_meta($trail->ID, 'trail_status', true) ?: 'open',
      'area' => $area_name,
      'gpx_data' => get_post_meta($trail->ID, 'trail_gpx_data', true),
      'close_date' => get_post_meta($trail->ID, 'trail_seasonal_close_date', true),
      'open_date' => get_post_meta($trail->ID, 'trail_seasonal_open_date', true)
    ];
  }

  wp_localize_script('tcr-trail-map', 'TCR_MAP', [
    'trails' => $trail_data,
    'center' => [$atts['center_lat'], $atts['center_lng']],
    'zoom' => intval($atts['zoom']),
    'apiUrl' => rest_url('tcr/v1/')
  ]);

  ob_start();
  ?>
  <div class="tcr-trail-map-wrapper">
    <div class="map-legend">
      <h3>Trail Status</h3>
      <div class="legend-items">
        <div class="legend-item">
          <span class="legend-color open"></span>
          <span>✅ Open</span>
        </div>
        <div class="legend-item">
          <span class="legend-color seasonal"></span>
          <span>❄️ Seasonally Closed</span>
        </div>
        <div class="legend-item">
          <span class="legend-color muddy"></span>
          <span>🟡 Muddy</span>
        </div>
        <div class="legend-item">
          <span class="legend-color hazardous"></span>
          <span>⚠️ Hazardous</span>
        </div>
      </div>
    </div>

    <div class="map-with-filters">
      <div id="tcr-trail-map" style="height: <?php echo esc_attr($atts['height']); ?>"></div>

      <div class="map-filters">
        <h3>Filter by Area</h3>
        <div id="area-filters"></div>
      </div>
    </div>
  </div>
  <?php
  return ob_get_clean();
});

/**
 * Admin page for trail management
 */
add_action('admin_menu', function() {
  add_submenu_page(
    'edit.php?post_type=trail',
    'Manage Trail Map',
    'Trail Map Manager',
    'manage_options',
    'trail-map-manager',
    'tcr_trail_map_admin_page'
  );
});

function tcr_trail_map_admin_page() {
  wp_enqueue_style('tcr-trail-admin', TCR_URL.'assets/trail-admin.css', [], filemtime(TCR_PATH.'assets/trail-admin.css'));
  wp_enqueue_script('tcr-trail-admin', TCR_URL.'assets/trail-admin.js', [], filemtime(TCR_PATH.'assets/trail-admin.js'), true);

  wp_localize_script('tcr-trail-admin', 'TCR_TRAIL_ADMIN', [
    'apiUrl' => rest_url('tcr/v1/'),
    'nonce' => wp_create_nonce('wp_rest')
  ]);

  // Get all trails
  $trails = get_posts([
    'post_type' => 'trail',
    'posts_per_page' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
  ]);

  ?>
  <div class="wrap tcr-trail-admin-wrap">
    <h1>Trail Map Manager</h1>

    <div class="tcr-admin-section">
      <div class="tcr-admin-card">
        <h2>📥 Import GPX Files</h2>
        <p>Import trails from GPX files in the <code>gpx_files</code> directory.</p>
        <button id="import-gpx-btn" class="button button-primary button-large">
          Import GPX Files
        </button>
        <div id="import-status" class="import-status"></div>
      </div>

      <div class="tcr-admin-card">
        <h2>📊 Trail Statistics</h2>
        <div class="trail-stats">
          <div class="stat-item">
            <div class="stat-number"><?php echo count($trails); ?></div>
            <div class="stat-label">Total Trails</div>
          </div>
          <?php
          $status_counts = [
            'open' => 0,
            'seasonal' => 0,
            'muddy' => 0,
            'hazardous' => 0
          ];
          foreach ($trails as $trail) {
            $status = get_post_meta($trail->ID, 'trail_status', true) ?: 'open';
            if (isset($status_counts[$status])) {
              $status_counts[$status]++;
            }
          }
          ?>
          <div class="stat-item open">
            <div class="stat-number"><?php echo $status_counts['open']; ?></div>
            <div class="stat-label">Open</div>
          </div>
          <div class="stat-item seasonal">
            <div class="stat-number"><?php echo $status_counts['seasonal']; ?></div>
            <div class="stat-label">Seasonal</div>
          </div>
          <div class="stat-item muddy">
            <div class="stat-number"><?php echo $status_counts['muddy']; ?></div>
            <div class="stat-label">Muddy</div>
          </div>
          <div class="stat-item hazardous">
            <div class="stat-number"><?php echo $status_counts['hazardous']; ?></div>
            <div class="stat-label">Hazardous</div>
          </div>
        </div>
      </div>
    </div>

    <div class="tcr-admin-section">
      <div class="tcr-admin-card full-width">
        <h2>🗺️ Manage Trail Status</h2>
        <div class="trail-filter">
          <label>
            <strong>Filter by Area:</strong>
            <select id="area-filter">
              <option value="">All Areas</option>
              <?php
              $areas = get_terms(['taxonomy' => 'tcr_area', 'hide_empty' => false]);
              foreach ($areas as $area) {
                echo '<option value="' . esc_attr($area->term_id) . '">' . esc_html($area->name) . '</option>';
              }
              ?>
            </select>
          </label>
          <label>
            <strong>Filter by Status:</strong>
            <select id="status-filter">
              <option value="">All Statuses</option>
              <option value="open">Open</option>
              <option value="seasonal">Seasonally Closed</option>
              <option value="muddy">Muddy</option>
              <option value="hazardous">Hazardous</option>
            </select>
          </label>
        </div>

        <table class="widefat striped trail-status-table">
          <thead>
            <tr>
              <th>Trail Name</th>
              <th>Area</th>
              <th>Current Status</th>
              <th>Change Status</th>
              <th>Seasonal Close Date</th>
              <th>Seasonal Open Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="trail-list">
            <?php
            foreach ($trails as $trail) {
              $area_terms = wp_get_post_terms($trail->ID, 'tcr_area');
              $area_name = !empty($area_terms) ? $area_terms[0]->name : 'Uncategorized';
              $area_id = !empty($area_terms) ? $area_terms[0]->term_id : '';
              $status = get_post_meta($trail->ID, 'trail_status', true) ?: 'open';
              $has_gpx = get_post_meta($trail->ID, 'trail_gpx_data', true);
              $close_date = get_post_meta($trail->ID, 'trail_seasonal_close_date', true);
              $open_date = get_post_meta($trail->ID, 'trail_seasonal_open_date', true);

              $status_labels = [
                'open' => '✅ Open',
                'seasonal' => '❄️ Seasonally Closed',
                'muddy' => '🟡 Muddy',
                'hazardous' => '⚠️ Hazardous'
              ];
              ?>
              <tr class="trail-row" data-area="<?php echo esc_attr($area_id); ?>" data-status="<?php echo esc_attr($status); ?>">
                <td>
                  <strong><?php echo esc_html($trail->post_title); ?></strong>
                  <?php if (!$has_gpx): ?>
                    <span class="no-gpx-badge">No GPX</span>
                  <?php endif; ?>
                </td>
                <td><?php echo esc_html($area_name); ?></td>
                <td>
                  <span class="status-badge status-<?php echo esc_attr($status); ?>">
                    <?php echo esc_html($status_labels[$status]); ?>
                  </span>
                </td>
                <td>
                  <select class="status-select" data-trail-id="<?php echo $trail->ID; ?>">
                    <option value="open" <?php selected($status, 'open'); ?>>Open</option>
                    <option value="seasonal" <?php selected($status, 'seasonal'); ?>>Seasonally Closed</option>
                    <option value="muddy" <?php selected($status, 'muddy'); ?>>Muddy</option>
                    <option value="hazardous" <?php selected($status, 'hazardous'); ?>>Hazardous</option>
                  </select>
                </td>
                <td>
                  <input type="date"
                         class="seasonal-close-date"
                         data-trail-id="<?php echo $trail->ID; ?>"
                         value="<?php echo esc_attr($close_date); ?>"
                         placeholder="MM-DD">
                </td>
                <td>
                  <input type="date"
                         class="seasonal-open-date"
                         data-trail-id="<?php echo $trail->ID; ?>"
                         value="<?php echo esc_attr($open_date); ?>"
                         placeholder="MM-DD">
                </td>
                <td>
                  <button class="button update-status-btn" data-trail-id="<?php echo $trail->ID; ?>">
                    Update
                  </button>
                  <a href="<?php echo get_edit_post_link($trail->ID); ?>" class="button">Edit</a>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php
}

/**
 * REST API: Update trail status
 */
add_action('rest_api_init', function() {
  register_rest_route('tcr/v1', '/trails/(?P<id>\d+)/status', [
    'methods' => 'POST',
    'callback' => 'tcr_update_trail_status',
    'permission_callback' => function() {
      return current_user_can('manage_options');
    }
  ]);

  register_rest_route('tcr/v1', '/trails/import-gpx', [
    'methods' => 'POST',
    'callback' => 'tcr_import_gpx_files',
    'permission_callback' => function() {
      return current_user_can('manage_options');
    }
  ]);
});

/**
 * Update trail status
 */
function tcr_update_trail_status($request) {
  $trail_id = (int) $request['id'];
  $status = sanitize_text_field($request->get_param('status'));
  $close_date = sanitize_text_field($request->get_param('close_date'));
  $open_date = sanitize_text_field($request->get_param('open_date'));

  $allowed_statuses = ['open', 'seasonal', 'muddy', 'hazardous'];
  if (!in_array($status, $allowed_statuses)) {
    return new WP_Error('invalid_status', 'Invalid trail status', ['status' => 400]);
  }

  update_post_meta($trail_id, 'trail_status', $status);

  // Update seasonal dates
  if ($close_date !== null && $close_date !== '') {
    update_post_meta($trail_id, 'trail_seasonal_close_date', $close_date);
  } else {
    delete_post_meta($trail_id, 'trail_seasonal_close_date');
  }

  if ($open_date !== null && $open_date !== '') {
    update_post_meta($trail_id, 'trail_seasonal_open_date', $open_date);
  } else {
    delete_post_meta($trail_id, 'trail_seasonal_open_date');
  }

  return rest_ensure_response([
    'success' => true,
    'trail_id' => $trail_id,
    'status' => $status,
    'close_date' => $close_date,
    'open_date' => $open_date
  ]);
}

/**
 * Import GPX files from gpx_files directory
 */
function tcr_import_gpx_files($request) {
  $gpx_dir = ABSPATH . 'gpx_files';

  if (!is_dir($gpx_dir)) {
    return new WP_Error('no_gpx_dir', 'GPX directory not found', ['status' => 404]);
  }

  $imported = 0;
  $errors = [];

  // Get all ZIP files
  $zip_files = glob($gpx_dir . '/*.zip');

  foreach ($zip_files as $zip_file) {
    $area_name = str_replace('_GPX.zip', '', basename($zip_file));
    $area_name = str_replace('_', ' ', $area_name);

    // Extract ZIP to temp directory
    $temp_dir = sys_get_temp_dir() . '/tcr_gpx_' . time();
    mkdir($temp_dir);

    $zip = new ZipArchive;
    if ($zip->open($zip_file) === TRUE) {
      $zip->extractTo($temp_dir);
      $zip->close();

      // Process each GPX file
      $gpx_files = glob($temp_dir . '/*.gpx');
      foreach ($gpx_files as $gpx_file) {
        $trail_name = str_replace('.gpx', '', basename($gpx_file));

        // Parse GPX data
        $gpx_data = tcr_parse_gpx_file($gpx_file);

        if ($gpx_data) {
          // Check if trail already exists
          $existing = get_posts([
            'post_type' => 'trail',
            'title' => $trail_name,
            'posts_per_page' => 1,
            'fields' => 'ids'
          ]);
          $existing = !empty($existing) ? get_post($existing[0]) : null;

          if (!$existing) {
            // Create new trail
            $trail_id = wp_insert_post([
              'post_title' => $trail_name,
              'post_type' => 'trail',
              'post_status' => 'publish'
            ]);

            if ($trail_id) {
              // Assign area taxonomy
              $term = get_term_by('name', $area_name, 'tcr_area');
              if (!$term) {
                $term = wp_insert_term($area_name, 'tcr_area');
                if (!is_wp_error($term)) {
                  $term = get_term($term['term_id'], 'tcr_area');
                }
              }

              if ($term && !is_wp_error($term)) {
                wp_set_object_terms($trail_id, $term->term_id, 'tcr_area');
              }

              // Save GPX data
              update_post_meta($trail_id, 'trail_gpx_data', json_encode($gpx_data));
              update_post_meta($trail_id, 'trail_status', 'open');

              $imported++;
            }
          } else {
            // Update existing trail's GPX data
            update_post_meta($existing->ID, 'trail_gpx_data', json_encode($gpx_data));
            $imported++;
          }
        }
      }

      // Clean up temp directory
      array_map('unlink', glob("$temp_dir/*"));
      rmdir($temp_dir);
    } else {
      $errors[] = "Failed to open $zip_file";
    }
  }

  return rest_ensure_response([
    'success' => true,
    'imported' => $imported,
    'errors' => $errors
  ]);
}

/**
 * Parse GPX file and extract coordinates
 */
function tcr_parse_gpx_file($file_path) {
  $xml = simplexml_load_file($file_path);
  if (!$xml) return null;

  $coordinates = [];

  // Handle different GPX namespaces
  $xml->registerXPathNamespace('gpx', 'http://www.topografix.com/GPX/1/1');
  $xml->registerXPathNamespace('gpx10', 'http://www.topografix.com/GPX/1/0');

  // Try GPX 1.1 format
  $track_points = $xml->xpath('//gpx:trkpt');

  // Try GPX 1.0 format if no points found
  if (empty($track_points)) {
    $track_points = $xml->xpath('//gpx10:trkpt');
  }

  // Try without namespace
  if (empty($track_points)) {
    $track_points = $xml->xpath('//trkpt');
  }

  foreach ($track_points as $point) {
    $lat = (float) $point['lat'];
    $lon = (float) $point['lon'];
    $coordinates[] = [$lat, $lon];
  }

  return [
    'coordinates' => $coordinates,
    'bounds' => tcr_calculate_bounds($coordinates)
  ];
}

/**
 * Calculate bounding box for coordinates
 */
function tcr_calculate_bounds($coordinates) {
  if (empty($coordinates)) return null;

  $lats = array_column($coordinates, 0);
  $lons = array_column($coordinates, 1);

  return [
    'min_lat' => min($lats),
    'max_lat' => max($lats),
    'min_lon' => min($lons),
    'max_lon' => max($lons)
  ];
}
