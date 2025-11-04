<?php
if (!defined('ABSPATH')) exit;

function tcr_run_migrations() {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $charset = $wpdb->get_charset_collate();
  $rpt = "{$wpdb->prefix}trail_reports";
  $pho = "{$wpdb->prefix}trail_report_photos";

  // Helper: does column exist?
  $col_exists = function($table, $column) use ($wpdb) {
    return (bool) $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $column) );
  };
  // Helper: does index exist?
  $idx_exists = function($table, $index) use ($wpdb) {
    return (bool) $wpdb->get_var( $wpdb->prepare("SHOW INDEX FROM $table WHERE Key_name = %s", $index) );
  };

  // --- Ensure base tables exist (dbDelta is idempotent) ---
  dbDelta("CREATE TABLE $rpt (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    trail_id BIGINT UNSIGNED NOT NULL,
    work_date DATE NULL,
    gps_lat DECIMAL(9,6) NULL,
    gps_lng DECIMAL(9,6) NULL,
    hours_spent DECIMAL(5,2) NOT NULL DEFAULT 0,
    trees_cleared INT UNSIGNED NOT NULL DEFAULT 0,
    brush_cleared INT UNSIGNED NOT NULL DEFAULT 0,
    rocks_cleared INT UNSIGNED NOT NULL DEFAULT 0,
    cond_obstructions TINYINT(1) NOT NULL DEFAULT 0,
    cond_hazards TINYINT(1) NOT NULL DEFAULT 0,
    cond_washout TINYINT(1) NOT NULL DEFAULT 0,
    cond_overgrowth TINYINT(1) NOT NULL DEFAULT 0,
    cond_muddy TINYINT(1) NOT NULL DEFAULT 0,
    cond_comment TEXT NULL,
    summary TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY idx_trail (trail_id),
    KEY idx_user (user_id),
    KEY idx_created (created_at),
    KEY idx_trail_created (trail_id, created_at),
    KEY idx_work_date (work_date)
  ) $charset;");

  dbDelta("CREATE TABLE $pho (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    attachment_id BIGINT UNSIGNED NOT NULL,
    photo_type VARCHAR(24) NOT NULL DEFAULT 'work',
    caption VARCHAR(255) NULL,
    gps_lat DECIMAL(9,6) NULL,
    gps_lng DECIMAL(9,6) NULL,
    PRIMARY KEY  (id),
    KEY idx_report (report_id),
    KEY idx_type (photo_type),
    KEY idx_photo_gps (gps_lat, gps_lng)
  ) $charset;");

  // --- Upgrades to match the new front-end ---

  // A) Add work_date if missing, then backfill + index
  if (!$col_exists($rpt, 'work_date')) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN work_date DATE NULL AFTER trail_id
    ");
    // Backfill existing rows to the created date
    $wpdb->query("UPDATE $rpt SET work_date = DATE(created_at) WHERE work_date IS NULL");
  }
  if (!$idx_exists($rpt, 'idx_work_date')) {
    $wpdb->query("ALTER TABLE $rpt ADD INDEX idx_work_date (work_date)");
  }

  // 1) brush_cleared -> corridor_cleared (rename or add if fresh)
  $has_brush    = $col_exists($rpt, 'brush_cleared');
  $has_corridor = $col_exists($rpt, 'corridor_cleared');

  if ($has_brush && !$has_corridor) {
    $wpdb->query("ALTER TABLE $rpt
      CHANGE brush_cleared corridor_cleared TINYINT(1) NOT NULL DEFAULT 0
    ");
  } elseif (!$has_corridor) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN corridor_cleared TINYINT(1) NOT NULL DEFAULT 0
      AFTER trees_cleared
    ");
  }

  // 2) Add raking, installed_drains (if missing)
  if (!$col_exists($rpt, 'raking')) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN raking TINYINT(1) NOT NULL DEFAULT 0
      AFTER corridor_cleared
    ");
  }
  if (!$col_exists($rpt, 'installed_drains')) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN installed_drains TINYINT(1) NOT NULL DEFAULT 0
      AFTER raking
    ");
  }

  // 3) Ensure rocks_cleared exists and is tinyint
  if (!$col_exists($rpt, 'rocks_cleared')) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN rocks_cleared TINYINT(1) NOT NULL DEFAULT 0
      AFTER installed_drains
    ");
  } else {
    $wpdb->query("ALTER TABLE $rpt
      MODIFY rocks_cleared TINYINT(1) NOT NULL DEFAULT 0
    ");
  }

  // 4) cond_obstructions (checkbox) -> cond_trees (INT)
  $has_cond_trees   = $col_exists($rpt, 'cond_trees');
  $has_obstructions = $col_exists($rpt, 'cond_obstructions');

  if (!$has_cond_trees) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN cond_trees INT UNSIGNED NOT NULL DEFAULT 0
      AFTER rocks_cleared
    ");
  }

  if ($has_obstructions) {
    $wpdb->query("UPDATE $rpt SET cond_trees = GREATEST(cond_trees, 1) WHERE cond_obstructions = 1");
    $wpdb->query("ALTER TABLE $rpt DROP COLUMN cond_obstructions");
  }

  // Ensure remaining condition columns exist with expected types
  if (!$col_exists($rpt, 'cond_hazards')) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN cond_hazards TINYINT(1) NOT NULL DEFAULT 0
      AFTER cond_trees
    ");
  } else {
    $wpdb->query("ALTER TABLE $rpt
      MODIFY cond_hazards TINYINT(1) NOT NULL DEFAULT 0
    ");
  }

  if (!$col_exists($rpt, 'cond_washout')) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN cond_washout TINYINT(1) NOT NULL DEFAULT 0
      AFTER cond_hazards
    ");
  } else {
    $wpdb->query("ALTER TABLE $rpt
      MODIFY cond_washout TINYINT(1) NOT NULL DEFAULT 0
    ");
  }

  if (!$col_exists($rpt, 'cond_overgrowth')) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN cond_overgrowth TINYINT(1) NOT NULL DEFAULT 0
      AFTER cond_washout
    ");
  } else {
    $wpdb->query("ALTER TABLE $rpt
      MODIFY cond_overgrowth TINYINT(1) NOT NULL DEFAULT 0
    ");
  }

  if (!$col_exists($rpt, 'cond_muddy')) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN cond_muddy TINYINT(1) NOT NULL DEFAULT 0
      AFTER cond_overgrowth
    ");
  } else {
    $wpdb->query("ALTER TABLE $rpt
      MODIFY cond_muddy TINYINT(1) NOT NULL DEFAULT 0
    ");
  }

  if (!$col_exists($rpt, 'cond_comment')) {
    $wpdb->query("ALTER TABLE $rpt
      ADD COLUMN cond_comment TEXT NULL
      AFTER cond_muddy
    ");
  }

  // --- Photos table GPS (guard for legacy) ---
  if (!$col_exists($pho, 'gps_lat')) {
    $wpdb->query("ALTER TABLE $pho
      ADD COLUMN gps_lat DECIMAL(9,6) NULL AFTER photo_type,
      ADD COLUMN gps_lng DECIMAL(9,6) NULL AFTER gps_lat,
      ADD INDEX idx_photo_gps (gps_lat, gps_lng)
    ");
  }
}