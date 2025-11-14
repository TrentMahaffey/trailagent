<?php
/**
 * Outstanding Trail Maintenance Management
 * Allows admins to mark photos for outstanding maintenance needs
 * and agents to view/resolve them
 */
if (!defined('ABSPATH')) exit;

/**
 * [trail_outstanding_admin] - Admin interface to manage outstanding photos
 */
add_shortcode('trail_outstanding_admin', function() {
  if (!current_user_can('manage_options')) {
    return '<div class="tcr-message tcr-error">You do not have permission to access this page.</div>';
  }

  wp_enqueue_style('tcr-outstanding-css', TCR_URL.'assets/outstanding.css', [], filemtime(TCR_PATH.'assets/outstanding.css'));
  wp_enqueue_script('tcr-outstanding-admin', TCR_URL.'assets/outstanding-admin.js', [], filemtime(TCR_PATH.'assets/outstanding-admin.js'), true);

  wp_localize_script('tcr-outstanding-admin', 'TCR_OUTSTANDING', [
    'root' => esc_url_raw(rest_url('tcr/v1/')),
    'nonce' => wp_create_nonce('wp_rest')
  ]);

  ob_start();
  ?>
  <div id="tcr-outstanding-admin">
    <div class="tcr-admin-header">
      <h1>Manage Outstanding Trail Maintenance</h1>
      <p>Review all trail report photos and mark items that need attention from trail agents.</p>
    </div>

    <div class="tcr-admin-filters">
      <div class="filter-row">
        <label>
          <input type="radio" name="filter" value="unreviewed" checked> 🆕 Unreviewed
        </label>
        <label>
          <input type="radio" name="filter" value="outstanding"> ⚠️ Outstanding
        </label>
        <label>
          <input type="radio" name="filter" value="dismissed"> ✓ Dismissed
        </label>
        <label>
          <input type="radio" name="filter" value="resolved"> ✅ Resolved
        </label>
        <label>
          <input type="radio" name="filter" value="all"> All Photos
        </label>
      </div>

      <div class="advanced-filters">
        <select id="filter-trail" class="filter-select">
          <option value="">All Trails</option>
        </select>

        <select id="filter-area" class="filter-select">
          <option value="">All Areas</option>
        </select>

        <select id="filter-user" class="filter-select">
          <option value="">All Users</option>
        </select>

        <input type="date" id="filter-date-start" class="filter-input" placeholder="Start Date">
        <input type="date" id="filter-date-end" class="filter-input" placeholder="End Date">

        <button id="tcr-apply-filters" class="filter-btn">Apply Filters</button>
        <button id="tcr-clear-filters" class="filter-btn secondary">Clear</button>
        <button id="tcr-refresh" class="filter-btn">Refresh</button>
      </div>
    </div>

    <div id="tcr-admin-photos" class="tcr-photo-grid">
      <div class="tcr-loading">Loading photos...</div>
    </div>
  </div>
  <?php
  return ob_get_clean();
});

/**
 * [trail_outstanding_maintenance] - Public view for trail agents
 */
add_shortcode('trail_outstanding_maintenance', function() {
  if (!is_user_logged_in()) {
    return '<div class="tcr-message tcr-info">
      <p>Please <a href="' . wp_login_url(get_permalink()) . '">login</a> to view outstanding trail maintenance needs.</p>
    </div>';
  }

  wp_enqueue_style('tcr-outstanding-css', TCR_URL.'assets/outstanding.css', [], filemtime(TCR_PATH.'assets/outstanding.css'));
  wp_enqueue_script('tcr-outstanding', TCR_URL.'assets/outstanding.js', [], filemtime(TCR_PATH.'assets/outstanding.js'), true);

  wp_localize_script('tcr-outstanding', 'TCR_OUTSTANDING', [
    'root' => esc_url_raw(rest_url('tcr/v1/')),
    'nonce' => wp_create_nonce('wp_rest'),
    'isAdmin' => current_user_can('manage_options')
  ]);

  ob_start();
  ?>
  <div id="tcr-outstanding-maintenance">
    <div class="tcr-maintenance-header">
      <h1>Outstanding Trail Maintenance Needs</h1>
      <p>These items require attention. Click "Mark as Resolved" after completing the work.</p>
    </div>

    <div class="tcr-maintenance-stats">
      <div class="stat-card">
        <div class="stat-number" id="outstanding-count">-</div>
        <div class="stat-label">Active Items</div>
      </div>
      <div class="stat-card">
        <div class="stat-number" id="resolved-count">-</div>
        <div class="stat-label">Resolved This Month</div>
      </div>
    </div>

    <div id="tcr-maintenance-items" class="tcr-maintenance-grid">
      <div class="tcr-loading">Loading maintenance items...</div>
    </div>

    <div id="tcr-no-items" style="display:none;" class="tcr-message tcr-success">
      <h3>All Clear!</h3>
      <p>There are no outstanding maintenance items at this time. Great work!</p>
    </div>
  </div>
  <?php
  return ob_get_clean();
});

/**
 * REST API: Get all photos with outstanding status
 */
add_action('rest_api_init', function() {
  register_rest_route('tcr/v1', '/outstanding/photos', [
    'methods' => 'GET',
    'callback' => 'tcr_get_outstanding_photos',
    'permission_callback' => function() {
      return is_user_logged_in();
    }
  ]);

  register_rest_route('tcr/v1', '/outstanding/toggle/(?P<id>\d+)', [
    'methods' => 'POST',
    'callback' => 'tcr_toggle_outstanding',
    'permission_callback' => function() {
      return current_user_can('manage_options');
    }
  ]);

  register_rest_route('tcr/v1', '/outstanding/dismiss/(?P<id>\d+)', [
    'methods' => 'POST',
    'callback' => 'tcr_dismiss_photo',
    'permission_callback' => function() {
      return current_user_can('manage_options');
    }
  ]);

  register_rest_route('tcr/v1', '/outstanding/set-status/(?P<id>\d+)', [
    'methods' => 'POST',
    'callback' => 'tcr_set_photo_status',
    'permission_callback' => function() {
      return current_user_can('manage_options');
    }
  ]);

  register_rest_route('tcr/v1', '/outstanding/resolve/(?P<id>\d+)', [
    'methods' => 'POST',
    'callback' => 'tcr_resolve_outstanding',
    'permission_callback' => function() {
      return current_user_can('submit_trail_reports') || current_user_can('manage_options');
    }
  ]);

  register_rest_route('tcr/v1', '/outstanding/stats', [
    'methods' => 'GET',
    'callback' => 'tcr_outstanding_stats',
    'permission_callback' => function() {
      return is_user_logged_in();
    }
  ]);

  register_rest_route('tcr/v1', '/outstanding/filter-options', [
    'methods' => 'GET',
    'callback' => 'tcr_get_filter_options',
    'permission_callback' => function() {
      return current_user_can('manage_options');
    }
  ]);
});

/**
 * Get all photos with their outstanding status
 */
function tcr_get_outstanding_photos($request) {
  global $wpdb;

  $filter = $request->get_param('filter') ?: 'unreviewed';
  $trail_id = $request->get_param('trail_id');
  $area_id = $request->get_param('area_id');
  $user_id = $request->get_param('user_id');
  $date_start = $request->get_param('date_start');
  $date_end = $request->get_param('date_end');

  $pho_table = $wpdb->prefix . 'trail_report_photos';
  $rpt_table = $wpdb->prefix . 'trail_reports';

  $where = '';
  if ($filter === 'unreviewed') {
    $where = 'AND p.reviewed_at IS NULL AND p.resolved_at IS NULL';
  } elseif ($filter === 'outstanding') {
    $where = 'AND p.is_outstanding = 1 AND p.resolved_at IS NULL';
  } elseif ($filter === 'resolved') {
    $where = 'AND p.is_outstanding = 1 AND p.resolved_at IS NOT NULL';
  } elseif ($filter === 'dismissed') {
    $where = 'AND p.reviewed_at IS NOT NULL AND p.is_outstanding = 0';
  }

  // Add advanced filters
  if ($trail_id) {
    $where .= $wpdb->prepare(' AND r.trail_id = %d', $trail_id);
  }
  if ($user_id) {
    $where .= $wpdb->prepare(' AND r.user_id = %d', $user_id);
  }
  if ($date_start) {
    $where .= $wpdb->prepare(' AND r.work_date >= %s', $date_start);
  }
  if ($date_end) {
    $where .= $wpdb->prepare(' AND r.work_date <= %s', $date_end);
  }
  if ($area_id) {
    // Need to join with trails taxonomy
    $where .= $wpdb->prepare(' AND EXISTS (
      SELECT 1 FROM ' . $wpdb->term_relationships . ' tr
      WHERE tr.object_id = r.trail_id AND tr.term_taxonomy_id = %d
    )', $area_id);
  }

  $sql = "SELECT
    p.id,
    p.attachment_id,
    p.caption,
    p.is_outstanding,
    p.resolved_at,
    p.resolved_by,
    p.reviewed_at,
    p.resolution_notes,
    p.resolution_date,
    p.gps_lat,
    p.gps_lng,
    p.photo_type,
    r.id as report_id,
    r.work_date,
    r.cond_comment,
    r.trail_id,
    r.user_id,
    r.hours_spent,
    r.trees_cleared,
    r.corridor_cleared,
    r.raking,
    r.installed_drains,
    r.rocks_cleared,
    r.cond_trees,
    r.cond_hazards,
    r.cond_washout,
    r.cond_overgrowth,
    r.cond_muddy,
    r.summary,
    r.status,
    r.created_at
  FROM {$pho_table} p
  INNER JOIN {$rpt_table} r ON p.report_id = r.id
  WHERE 1=1 {$where}
  ORDER BY p.is_outstanding DESC, r.work_date DESC
  LIMIT 200";

  $photos = $wpdb->get_results($sql);

  // Enrich with image URLs and trail names
  foreach ($photos as &$photo) {
    $photo->image_url = wp_get_attachment_image_url($photo->attachment_id, 'large');
    $photo->thumb_url = wp_get_attachment_image_url($photo->attachment_id, 'medium');

    $trail = get_post($photo->trail_id);
    $photo->trail_name = $trail ? $trail->post_title : 'Unknown Trail';

    // Get submitter user name
    if ($photo->user_id) {
      $user = get_userdata($photo->user_id);
      $photo->user_name = $user ? $user->display_name : 'Unknown';
    }

    // Get resolver user name
    if ($photo->resolved_by) {
      $user = get_userdata($photo->resolved_by);
      $photo->resolved_by_name = $user ? $user->display_name : 'Unknown';
    }
  }

  return rest_ensure_response($photos);
}

/**
 * Toggle outstanding status for a photo
 */
function tcr_toggle_outstanding($request) {
  global $wpdb;

  $photo_id = (int) $request['id'];
  $pho_table = $wpdb->prefix . 'trail_report_photos';

  // Get current status
  $current = $wpdb->get_var($wpdb->prepare(
    "SELECT is_outstanding FROM {$pho_table} WHERE id = %d",
    $photo_id
  ));

  if ($current === null) {
    return new WP_Error('not_found', 'Photo not found', ['status' => 404]);
  }

  $new_status = $current ? 0 : 1;

  // If setting to not outstanding, also clear resolved status but mark as reviewed
  if ($new_status === 0) {
    $wpdb->update(
      $pho_table,
      [
        'is_outstanding' => 0,
        'resolved_at' => null,
        'resolved_by' => null,
        'resolution_notes' => null,
        'resolution_date' => null,
        'reviewed_at' => current_time('mysql')
      ],
      ['id' => $photo_id]
    );
  } else {
    // Mark as outstanding and reviewed, clear any resolved status
    $wpdb->update(
      $pho_table,
      [
        'is_outstanding' => 1,
        'reviewed_at' => current_time('mysql'),
        'resolved_at' => null,
        'resolved_by' => null,
        'resolution_notes' => null,
        'resolution_date' => null
      ],
      ['id' => $photo_id]
    );
  }

  return rest_ensure_response([
    'success' => true,
    'is_outstanding' => $new_status
  ]);
}

/**
 * Set photo status (outstanding, not outstanding)
 */
function tcr_set_photo_status($request) {
  global $wpdb;

  $photo_id = (int) $request['id'];
  $status = $request->get_param('status');
  $pho_table = $wpdb->prefix . 'trail_report_photos';

  if ($status === 'outstanding') {
    // Mark as outstanding and reviewed, clear any resolved status
    $wpdb->update(
      $pho_table,
      [
        'is_outstanding' => 1,
        'reviewed_at' => current_time('mysql'),
        'resolved_at' => null,
        'resolved_by' => null,
        'resolution_notes' => null,
        'resolution_date' => null
      ],
      ['id' => $photo_id]
    );
  } else {
    // Mark as not outstanding, clear resolved status, mark as reviewed
    $wpdb->update(
      $pho_table,
      [
        'is_outstanding' => 0,
        'resolved_at' => null,
        'resolved_by' => null,
        'resolution_notes' => null,
        'resolution_date' => null,
        'reviewed_at' => current_time('mysql')
      ],
      ['id' => $photo_id]
    );
  }

  if ($wpdb->rows_affected === 0) {
    return new WP_Error('not_found', 'Photo not found', ['status' => 404]);
  }

  return rest_ensure_response([
    'success' => true,
    'status' => $status
  ]);
}

/**
 * Dismiss a photo (mark as reviewed but not outstanding)
 */
function tcr_dismiss_photo($request) {
  global $wpdb;

  $photo_id = (int) $request['id'];
  $pho_table = $wpdb->prefix . 'trail_report_photos';

  $wpdb->update(
    $pho_table,
    [
      'is_outstanding' => 0,
      'resolved_at' => null,
      'resolved_by' => null,
      'reviewed_at' => current_time('mysql')
    ],
    ['id' => $photo_id]
  );

  if ($wpdb->rows_affected === 0) {
    return new WP_Error('not_found', 'Photo not found', ['status' => 404]);
  }

  return rest_ensure_response([
    'success' => true,
    'reviewed_at' => current_time('mysql')
  ]);
}

/**
 * Mark an outstanding item as resolved
 */
function tcr_resolve_outstanding($request) {
  global $wpdb;

  $photo_id = (int) $request['id'];
  $user_id = get_current_user_id();
  $pho_table = $wpdb->prefix . 'trail_report_photos';

  // Get resolution data from request body
  $body = $request->get_json_params();
  $resolution_notes = isset($body['notes']) ? sanitize_textarea_field($body['notes']) : '';
  $resolution_date = isset($body['date']) ? sanitize_text_field($body['date']) : current_time('Y-m-d');

  $wpdb->update(
    $pho_table,
    [
      'resolved_at' => current_time('mysql'),
      'resolved_by' => $user_id,
      'resolution_notes' => $resolution_notes,
      'resolution_date' => $resolution_date,
      'reviewed_at' => current_time('mysql')
    ],
    [
      'id' => $photo_id,
      'is_outstanding' => 1
    ]
  );

  if ($wpdb->rows_affected === 0) {
    return new WP_Error('not_found', 'Outstanding item not found', ['status' => 404]);
  }

  return rest_ensure_response([
    'success' => true,
    'resolved_at' => current_time('mysql'),
    'resolved_by' => $user_id,
    'resolution_notes' => $resolution_notes,
    'resolution_date' => $resolution_date
  ]);
}

/**
 * Get outstanding maintenance statistics
 */
function tcr_outstanding_stats() {
  global $wpdb;

  $pho_table = $wpdb->prefix . 'trail_report_photos';

  $active_count = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$pho_table}
     WHERE is_outstanding = 1 AND resolved_at IS NULL"
  );

  $resolved_this_month = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$pho_table}
     WHERE is_outstanding = 1
     AND resolved_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
  );

  return rest_ensure_response([
    'active' => (int) $active_count,
    'resolved_this_month' => (int) $resolved_this_month
  ]);
}

/**
 * Get filter options for admin interface
 */
function tcr_get_filter_options() {
  global $wpdb;

  // Get all trails
  $trails = get_posts([
    'post_type' => 'trail',
    'posts_per_page' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
  ]);

  $trail_options = array_map(function($trail) {
    return [
      'id' => $trail->ID,
      'name' => $trail->post_title
    ];
  }, $trails);

  // Get all trail areas
  $areas = get_terms([
    'taxonomy' => 'trail_area',
    'hide_empty' => false,
    'orderby' => 'name',
    'order' => 'ASC'
  ]);

  $area_options = array_map(function($area) {
    return [
      'id' => $area->term_id,
      'name' => $area->name
    ];
  }, $areas);

  // Get all users who have submitted reports
  $users = $wpdb->get_results("
    SELECT DISTINCT u.ID as id, u.display_name as name
    FROM {$wpdb->users} u
    INNER JOIN {$wpdb->prefix}trail_reports r ON u.ID = r.user_id
    ORDER BY u.display_name ASC
  ");

  return rest_ensure_response([
    'trails' => $trail_options,
    'areas' => $area_options,
    'users' => $users
  ]);
}
