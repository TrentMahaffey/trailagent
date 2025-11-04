<?php
/**
 * Trail Conditions Reports — REST API
 * File: /wp-content/plugins/trail-conditions-reports/inc/rest.php
 */
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {

  // ---- sanity ping
  register_rest_route('tcr/v1', '/ping', [
    'methods'             => 'GET',
    'permission_callback' => '__return_true',
    'callback'            => function(){ return new WP_REST_Response(['ok'=>true,'ts'=>time()], 200); }
  ]);

  // ---- CREATE: POST /tcr/v1/report
  register_rest_route('tcr/v1', '/report', [
    'methods'  => 'POST',
    'callback' => 'tcr_api_create_report',
    'permission_callback' => function(WP_REST_Request $req){
      return is_user_logged_in() && wp_verify_nonce($req->get_header('X-WP-Nonce'), 'wp_rest');
    }
  ]);

  // ---- LIST: GET /tcr/v1/reports
  register_rest_route('tcr/v1', '/reports', [
    'methods'             => 'GET',
    'permission_callback' => '__return_true',
    'args' => [
      'page'        => ['type'=>'integer','default'=>1,'minimum'=>1],
      'per_page'    => ['type'=>'integer','default'=>10,'minimum'=>1,'maximum'=>50],
      'trail_id'    => ['type'=>'integer','required'=>false],
      'area_id'     => ['type'=>'integer','required'=>false],
      'date_min'    => ['type'=>'string','required'=>false],
      'date_max'    => ['type'=>'string','required'=>false],
      'has_photos'  => ['type'=>'boolean','required'=>false],
      'min_trees'   => ['type'=>'integer','minimum'=>0,'required'=>false],
      'cond_hazards'=> ['type'=>'boolean','required'=>false],
      'cond_washout'=> ['type'=>'boolean','required'=>false],
      'cond_overgrowth'=> ['type'=>'boolean','required'=>false],
      'cond_muddy'  => ['type'=>'boolean','required'=>false],
      'orderby'     => ['type'=>'string','enum'=>['created_at','trees_cleared'],'default'=>'created_at'],
      'order'       => ['type'=>'string','enum'=>['asc','desc'],'default'=>'desc'],
      'sanity'      => ['type'=>'boolean','required'=>false],
    ],
    'callback' => 'tcr_api_list_reports'
  ]);

  // ---- trails list
  register_rest_route('tcr/v1', '/trails', [
    'methods'             => 'GET',
    'permission_callback' => '__return_true',
    'args' => [
      'per_page' => ['type'=>'integer','default'=>200,'minimum'=>1,'maximum'=>500],
      'search'   => ['type'=>'string','required'=>false],
      'area'     => ['type'=>'integer','required'=>false],
    ],
    'callback' => 'tcr_api_list_trails'
  ]);

  // ---- areas list
  register_rest_route('tcr/v1', '/areas', [
    'methods'             => 'GET',
    'permission_callback' => '__return_true',
    'callback' => 'tcr_api_list_areas'
  ]);

  // ---- analytics/reporting
  register_rest_route('tcr/v1', '/analytics', [
    'methods'             => 'GET',
    'permission_callback' => '__return_true',
    'callback' => 'tcr_api_analytics'
  ]);
});

/**
 * Create a new trail report (matches front-end form.js payload).
 * Inserts into wp_trail_reports and wp_trail_report_photos.
 */
function tcr_api_create_report(WP_REST_Request $req) {
  global $wpdb;
  $rpt = "{$wpdb->prefix}trail_reports";
  $pho = "{$wpdb->prefix}trail_report_photos";

  $user_id  = get_current_user_id();
  $trail_id = (int) $req->get_param('trail_id');
  if (!$trail_id || get_post_type($trail_id) !== 'trail') {
    return new WP_Error('bad_request', 'Invalid trail_id', ['status' => 400]);
  }

  // Work metrics
  $hours_spent   = max(0, (float) ($req->get_param('hours_spent') ?? 0));
  $trees_cleared = max(0, (int) ($req->get_param('trees_cleared') ?? 0));
  $brush_cleared = max(0, (int) ($req->get_param('brush_cleared') ?? 0));

  // Other work (tinyint flags)
  $corridor_cleared = (int) !empty($req->get_param('corridor_cleared'));
  $raking           = (int) !empty($req->get_param('raking'));
  $installed_drains = (int) !empty($req->get_param('installed_drains'));
  $rocks_cleared    = (int) !empty($req->get_param('rocks_cleared'));

  // Conditions
  $cond_trees      = max(0, (int) ($req->get_param('cond_trees') ?? 0)); // “Number of downed trees”
  $cond_hazards    = (int) !empty($req->get_param('cond_hazards'));
  $cond_washout    = (int) !empty($req->get_param('cond_washout'));
  $cond_overgrowth = (int) !empty($req->get_param('cond_overgrowth'));
  $cond_muddy      = (int) !empty($req->get_param('cond_muddy'));
  $cond_comment    = sanitize_textarea_field($req->get_param('cond_comment'));
  $summary         = sanitize_textarea_field($req->get_param('summary'));

  // Work date (YYYY-MM-DD). Default to today's site-local date if missing/invalid.
  $work_date_raw = trim((string) ($req->get_param('work_date') ?? ''));
  $work_date = current_time('Y-m-d');
  if ($work_date_raw !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $work_date_raw);
    $errors = DateTime::getLastErrors();
    if ($dt && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
      // Extra guard with wp_checkdate style validation
      $y = (int) $dt->format('Y');
      $m = (int) $dt->format('m');
      $d = (int) $dt->format('d');
      if (checkdate($m, $d, $y)) {
        $work_date = $dt->format('Y-m-d');
      }
    }
  }

  // Optional report-level GPS (columns exist in your schema, allow null)
  $gps_lat = $req->get_param('gps_lat');
  $gps_lng = $req->get_param('gps_lng');
  $gps_lat = is_null($gps_lat) || $gps_lat === '' ? null : (float)$gps_lat;
  $gps_lng = is_null($gps_lng) || $gps_lng === '' ? null : (float)$gps_lng;

  $now = current_time('mysql');

  // Insert report
  $ok = $wpdb->insert($rpt, [
    'user_id'          => $user_id,
    'trail_id'         => $trail_id,
    'work_date'        => $work_date,
    'gps_lat'          => $gps_lat,          // nullable
    'gps_lng'          => $gps_lng,          // nullable
    'hours_spent'      => $hours_spent,
    'trees_cleared'    => $trees_cleared,
    'corridor_cleared' => $corridor_cleared,
    'raking'           => $raking,
    'installed_drains' => $installed_drains,
    'rocks_cleared'    => $rocks_cleared,
    'cond_trees'       => $cond_trees,
    'summary'          => $summary,
    'status'           => 'pending',
    'created_at'       => $now,
    'updated_at'       => $now,
    'cond_hazards'     => $cond_hazards,
    'cond_washout'     => $cond_washout,
    'cond_overgrowth'  => $cond_overgrowth,
    'cond_muddy'       => $cond_muddy,
    'cond_comment'     => $cond_comment,
    'brush_cleared'    => $brush_cleared,
  ], [
    // 22 formats — must match the 22 columns above in order:
    '%d',    // user_id
    '%d',    // trail_id
    '%s',    // work_date
    '%f',    // gps_lat (NULL ok)
    '%f',    // gps_lng (NULL ok)
    '%f',    // hours_spent
    '%d',    // trees_cleared
    '%d',    // corridor_cleared
    '%d',    // raking
    '%d',    // installed_drains
    '%d',    // rocks_cleared
    '%d',    // cond_trees
    '%s',    // summary
    '%s',    // status
    '%s',    // created_at
    '%s',    // updated_at
    '%d',    // cond_hazards
    '%d',    // cond_washout
    '%d',    // cond_overgrowth
    '%d',    // cond_muddy
    '%s',    // cond_comment
    // brush_cleared
    '%d',
  ]);

  if ($ok === false) {
    return new WP_Error('db', 'Insert failed: ' . $wpdb->last_error, ['status' => 500]);
  }

  $report_id = (int) $wpdb->insert_id;

  // Photos (attachment_id + optional gps & caption)
  $photos = (array) $req->get_param('photos');
  foreach ($photos as $ph) {
    $aid  = isset($ph['attachment_id']) ? (int)$ph['attachment_id'] : 0;
    if (!$aid) continue;

    $type = sanitize_key($ph['photo_type'] ?? 'work');
    $cap  = sanitize_text_field($ph['caption'] ?? '');
    $plat = isset($ph['gps_lat']) ? (float)$ph['gps_lat'] : null;
    $plng = isset($ph['gps_lng']) ? (float)$ph['gps_lng'] : null;

    // optional EXIF fallback if not provided
    if (($plat === null || $plng === null) && $aid) {
      $gps = tcr_extract_attachment_gps($aid);
      if ($gps) {
        if ($plat === null) $plat = $gps['lat'];
        if ($plng === null) $plng = $gps['lng'];
      }
    }

    $wpdb->insert($pho, [
      'report_id'     => $report_id,
      'attachment_id' => $aid,
      'photo_type'    => $type,
      'caption'       => $cap,
      'gps_lat'       => $plat,
      'gps_lng'       => $plng,
    ], [
      '%d','%d','%s','%s','%f','%f'
    ]);
  }

  do_action('tcr_report_created', $report_id);
  return new WP_REST_Response(['ok'=>true, 'id'=>$report_id], 201);
}

/**
 * Try to read GPS from EXIF if available.
 */
function tcr_extract_attachment_gps($attachment_id) {
  $file = get_attached_file($attachment_id);
  if (!$file || !file_exists($file)) return null;

  if (!function_exists('wp_read_image_metadata')) {
    require_once ABSPATH . 'wp-admin/includes/image.php';
  }
  $meta = wp_read_image_metadata($file);
  if (!empty($meta['gps']) && isset($meta['gps']['latitude'], $meta['gps']['longitude'])) {
    return ['lat' => (float)$meta['gps']['latitude'], 'lng' => (float)$meta['gps']['longitude']];
  }
  return null;
}

/**
 * List reports (with optional filters).
 */
function tcr_api_list_reports(WP_REST_Request $req) {
  global $wpdb;
  $rpt = "{$wpdb->prefix}trail_reports";
  $pho = "{$wpdb->prefix}trail_report_photos";

  if ($req->get_param('sanity')) {
    return new WP_REST_Response([
      'page'=>1,'per_page'=>1,'total'=>1,'total_pages'=>1,'rows'=>[[
        'id'=>999,'trail_id'=>0,'user_id'=>0,'work_date'=>current_time('Y-m-d'),'trees_cleared'=>3,
        'cond_hazards'=>1,'cond_washout'=>0,'cond_overgrowth'=>1,'cond_muddy'=>0,
        'cond_comment'=>'REST sanity payload','created_at'=>current_time('mysql'),
        'photo_count'=>0,'sample_attachment_id'=>0,'photos'=>[]
      ]],
    ], 200);
  }

  $page      = max(1, (int)$req->get_param('page'));
  $per_page  = min(50, max(1, (int)$req->get_param('per_page')));
  $offset    = ($page-1) * $per_page;

  $orderby   = $req->get_param('orderby') ?: 'created_at';
  $order     = strtolower($req->get_param('order') ?: 'desc') === 'asc' ? 'ASC' : 'DESC';

  $wheres = ['1=1'];
  $params = [];
  $joins = [];

  if ($trail_id = (int) $req->get_param('trail_id')) {
    $wheres[] = "$rpt.trail_id = %d"; $params[] = $trail_id;
  }

  // Filter by area_id (via taxonomy relationship)
  if ($area_id = (int) $req->get_param('area_id')) {
    $joins[] = "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = $rpt.trail_id";
    $joins[] = "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                AND tt.taxonomy = 'tcr_area' AND tt.term_id = %d";
    $params[] = $area_id;
  }
  if (($min_trees = $req->get_param('min_trees')) !== null && $min_trees !== '') {
    $wheres[] = "$rpt.trees_cleared >= %d"; $params[] = (int)$min_trees;
  }
  foreach (['cond_hazards','cond_washout','cond_overgrowth','cond_muddy'] as $flag) {
    $val = $req->get_param($flag);
    if ($val !== null && $val !== '') {
      $wheres[] = "$rpt.$flag = %d"; $params[] = $val ? 1 : 0;
    }
  }
  if ($date_min = $req->get_param('date_min')) {
    $wheres[] = "COALESCE($rpt.work_date, DATE($rpt.created_at)) >= %s"; $params[] = $date_min;
  }
  if ($date_max = $req->get_param('date_max')) {
    $wheres[] = "COALESCE($rpt.work_date, DATE($rpt.created_at)) <= %s"; $params[] = $date_max;
  }

  $join_photos = "";
  if ($req->get_param('has_photos')) {
    $join_photos = "INNER JOIN $pho p0 ON p0.report_id = $rpt.id";
  }

  $order_col = ($orderby === 'trees_cleared') ? "$rpt.trees_cleared" : "$rpt.created_at";

  // Build joins string
  $joins_sql = implode(' ', $joins);

  // count
  $sql_count = "SELECT COUNT(DISTINCT $rpt.id)
                FROM $rpt
                $joins_sql
                $join_photos
                WHERE ".implode(' AND ', $wheres);
  $total = (int) $wpdb->get_var($wpdb->prepare($sql_count, $params));

  // page query
  $sql = "SELECT
            $rpt.id,
            $rpt.trail_id,
            $rpt.work_date,
            $rpt.user_id,
            $rpt.hours_spent,
            $rpt.trees_cleared,
            $rpt.raking,
            $rpt.corridor_cleared,
            $rpt.installed_drains,
            $rpt.rocks_cleared,
            $rpt.cond_hazards,
            $rpt.cond_washout,
            $rpt.cond_overgrowth,
            $rpt.cond_muddy,
            $rpt.cond_comment,
            $rpt.summary,
            $rpt.created_at,
            COALESCE(pp.photo_count, 0) AS photo_count,
            COALESCE(pp.sample_attachment_id, 0) AS sample_attachment_id
          FROM $rpt
          $joins_sql
          LEFT JOIN (
            SELECT report_id,
                   COUNT(*) AS photo_count,
                   MIN(attachment_id) AS sample_attachment_id
            FROM $pho
            GROUP BY report_id
          ) pp ON pp.report_id = $rpt.id
          $join_photos
          WHERE ".implode(' AND ', $wheres)."
          GROUP BY $rpt.id
          ORDER BY $order_col $order, $rpt.id DESC
          LIMIT %d OFFSET %d";

  $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$per_page, $offset])), ARRAY_A) ?: [];

  // photos per report (attach up to 6)
  $ids = array_column($rows, 'id');
  $photos_by_report = [];
  if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $sql_ph = "SELECT id, report_id, attachment_id, caption, gps_lat, gps_lng
               FROM $pho
               WHERE report_id IN ($placeholders)
               ORDER BY id ASC";
    $ph_rows = $wpdb->get_results($wpdb->prepare($sql_ph, $ids), ARRAY_A) ?: [];

    foreach ($ph_rows as $ph) {
      $rid = (int)$ph['report_id'];
      if (!isset($photos_by_report[$rid])) $photos_by_report[$rid] = [];
      if (count($photos_by_report[$rid]) < 6) {
        $att_id = (int)$ph['attachment_id'];
        $full_url  = wp_get_attachment_url($att_id) ?: '';
        $thumb_url = wp_get_attachment_image_url($att_id, 'thumbnail') ?: (
                      wp_get_attachment_image_url($att_id, 'medium') ?: $full_url
                    );
        $photos_by_report[$rid][] = [
          'attachment_id' => $att_id,
          'caption'       => $ph['caption'],
          'gps_lat'       => ($ph['gps_lat'] !== null && $ph['gps_lat'] !== '') ? (float)$ph['gps_lat'] : null,
          'gps_lng'       => ($ph['gps_lng'] !== null && $ph['gps_lng'] !== '') ? (float)$ph['gps_lng'] : null,
          'thumb_url'     => $thumb_url ?: '',
          'full_url'      => $full_url ?: '',
        ];
      }
    }
  }

  foreach ($rows as &$r) {
    $rid = (int)$r['id'];
    $r['photos'] = $photos_by_report[$rid] ?? [];

    // Add user display name
    $user_id = (int)$r['user_id'];
    if ($user_id > 0) {
      $user = get_userdata($user_id);
      $r['user_name'] = $user ? $user->display_name : "User #{$user_id}";
    } else {
      $r['user_name'] = 'Unknown';
    }
  }

  return new WP_REST_Response([
    'page'        => $page,
    'per_page'    => $per_page,
    'total'       => $total,
    'total_pages' => (int)ceil($total / max(1,$per_page)),
    'rows'        => $rows,
  ], 200);
}

/**
 * Trails list from CPT "trail".
 */
function tcr_api_list_trails(WP_REST_Request $req) {
  $pp = min(500, max(1, (int)$req->get_param('per_page')));
  $args = [
    'post_type'      => 'trail',
    'post_status'    => 'publish',
    'posts_per_page' => $pp,
    'orderby'        => 'title',
    'order'          => 'ASC',
    's'              => $req->get_param('search') ?: '',
    'no_found_rows'  => true,
  ];
  if ($area = (int)$req->get_param('area')) {
    $args['tax_query'] = [[
      'taxonomy' => 'tcr_area',
      'field'    => 'term_id',
      'terms'    => [$area],
    ]];
  }

  $q = new WP_Query($args);
  $out = [];
  foreach ($q->posts as $p) {
    $areas = [];
    $terms = get_the_terms($p->ID, 'tcr_area');
    if ($terms && !is_wp_error($terms)) {
      foreach ($terms as $t) $areas[] = ['id'=>$t->term_id, 'name'=>$t->name, 'slug'=>$t->slug];
    }
    $out[] = ['id'=>$p->ID, 'title'=>get_the_title($p), 'areas'=>$areas];
  }
  return new WP_REST_Response(['rows'=>$out], 200);
}

/**
 * Areas list from taxonomy "tcr_area".
 */
function tcr_api_list_areas() {
  $terms = get_terms(['taxonomy'=>'tcr_area','hide_empty'=>false]);
  if (is_wp_error($terms)) $terms = [];
  $rows = array_map(function($t){
    return ['id'=>$t->term_id, 'name'=>$t->name, 'slug'=>$t->slug, 'count'=>$t->count];
  }, $terms);
  return new WP_REST_Response(['rows'=>$rows], 200);
}

/**
 * Analytics/Reporting data
 */
function tcr_api_analytics() {
  global $wpdb;
  $rpt = "{$wpdb->prefix}trail_reports";

  // Reports by Area
  $by_area = $wpdb->get_results("
    SELECT
      tt.term_id as area_id,
      t.name as area_name,
      COUNT(r.id) as total_reports,
      SUM(r.hours_spent) as total_hours,
      SUM(r.trees_cleared) as total_trees
    FROM $rpt r
    INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = r.trail_id
    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'tcr_area'
    INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
    GROUP BY tt.term_id, t.name
    ORDER BY total_reports DESC
  ", ARRAY_A);

  // Reports by Month (last 12 months)
  $by_month = $wpdb->get_results("
    SELECT
      DATE_FORMAT(COALESCE(r.work_date, DATE(r.created_at)), '%Y-%m') as month,
      COUNT(r.id) as total_reports,
      SUM(r.hours_spent) as total_hours,
      SUM(r.trees_cleared) as total_trees
    FROM $rpt r
    WHERE COALESCE(r.work_date, DATE(r.created_at)) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month DESC
  ", ARRAY_A);

  // Top trails by hazards
  $hazard_trails = $wpdb->get_results("
    SELECT
      r.trail_id,
      COUNT(r.id) as hazard_count,
      SUM(CASE WHEN r.cond_hazards = 1 THEN 1 ELSE 0 END) as safety_hazards,
      SUM(CASE WHEN r.cond_washout = 1 THEN 1 ELSE 0 END) as washouts,
      SUM(CASE WHEN r.cond_overgrowth = 1 THEN 1 ELSE 0 END) as overgrowth,
      SUM(r.cond_trees) as downed_trees
    FROM $rpt r
    WHERE r.cond_hazards = 1
       OR r.cond_washout = 1
       OR r.cond_overgrowth = 1
       OR r.cond_trees > 0
    GROUP BY r.trail_id
    ORDER BY hazard_count DESC
    LIMIT 10
  ", ARRAY_A);

  // Resolve trail names
  foreach ($hazard_trails as &$ht) {
    $trail_id = $ht['trail_id'];
    $ht['trail_name'] = get_the_title($trail_id);

    // Get area for this trail
    $terms = wp_get_post_terms($trail_id, 'tcr_area');
    $ht['area_name'] = (!is_wp_error($terms) && !empty($terms)) ? $terms[0]->name : '';
  }

  // Overall statistics
  $overall = $wpdb->get_row("
    SELECT
      COUNT(id) as total_reports,
      COUNT(DISTINCT trail_id) as trails_maintained,
      COUNT(DISTINCT user_id) as total_volunteers,
      SUM(hours_spent) as total_hours,
      SUM(trees_cleared) as total_trees,
      SUM(CASE WHEN corridor_cleared = 1 THEN 1 ELSE 0 END) as corridor_work,
      SUM(CASE WHEN raking = 1 THEN 1 ELSE 0 END) as raking_work,
      SUM(CASE WHEN installed_drains = 1 THEN 1 ELSE 0 END) as drain_work
    FROM $rpt
  ", ARRAY_A);

  // Recent activity (last 30 days)
  $recent = $wpdb->get_row("
    SELECT
      COUNT(id) as reports_30d,
      SUM(hours_spent) as hours_30d,
      SUM(trees_cleared) as trees_30d
    FROM $rpt
    WHERE COALESCE(work_date, DATE(created_at)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  ", ARRAY_A);

  return new WP_REST_Response([
    'by_area' => $by_area ?: [],
    'by_month' => $by_month ?: [],
    'hazard_trails' => $hazard_trails ?: [],
    'overall' => $overall ?: [],
    'recent' => $recent ?: [],
  ], 200);
}