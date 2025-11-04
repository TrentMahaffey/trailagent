<?php
/**
 * Plugin Name: Trail Conditions Reports (TCR)
 * Description: Trail Agent v2.0 — custom tables, REST API, form & dashboard.
 * Version: 1.0.1
 */
if (!defined('ABSPATH')) exit;

define('TCR_VER', '1.0.1');
define('TCR_PATH', plugin_dir_path(__FILE__));
define('TCR_URL', plugin_dir_url(__FILE__));

// Core includes
require_once TCR_PATH . 'inc/cpt.php';// custom post type registration
require_once TCR_PATH . 'inc/roles.php';      // custom roles/caps
require_once TCR_PATH . 'inc/schema.php';     // tcr_run_migrations() lives here
require_once TCR_PATH . 'inc/emails.php';
require_once TCR_PATH . 'inc/shortcodes.php';
require_once TCR_PATH . 'inc/meta.php';
require_once TCR_PATH . 'inc/taxonomy.php';
require_once TCR_PATH . 'inc/rest.php';       // REST API routes
require_once TCR_PATH . 'inc/browser.php';    // front-end browser/shortcode UI

add_action('init', 'tcr_register_trail_cpt');

// Register activation hook ONCE
register_activation_hook(__FILE__, function(){
  tcr_register_trail_cpt();
  tcr_install_roles();
  tcr_run_migrations();
});

// Versioned auto-upgrade
add_action('plugins_loaded', function(){
  $stored = get_option('tcr_db_version', '0');
  if (version_compare($stored, TCR_VER, '<')) {
    tcr_run_migrations();
    update_option('tcr_db_version', TCR_VER);
  }
});