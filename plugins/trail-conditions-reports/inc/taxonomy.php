<?php
if (!defined('ABSPATH')) exit;

function tcr_register_area_taxonomy() {
  register_taxonomy('tcr_area', ['trail'], [
    'label'        => 'Areas',
    'labels'       => [
      'name'          => 'Areas',
      'singular_name' => 'Area',
      'add_new_item'  => 'Add New Area',
      'edit_item'     => 'Edit Area',
      'search_items'  => 'Search Areas',
      'all_items'     => 'All Areas',
    ],
    'public'       => true,
    'hierarchical' => true,           // Allows parent/child (e.g., Region > Area)
    'show_ui'      => true,
    'show_in_rest' => true,           // Expose via WP REST
    'show_admin_column' => true,
    'rewrite'      => ['slug' => 'area'],
  ]);
}
add_action('init', 'tcr_register_area_taxonomy');

// Seed default areas on activation (idempotent)
function tcr_seed_default_areas() {
  $areas = [
    'Aspen',
    'Snowmass',
    'The Crown',
    'Red Hill',
    'Glenwood Springs',
    'South Canyon',
    'New Castle',
  ];
  foreach ($areas as $name) {
    if (!term_exists($name, 'tcr_area')) {
      wp_insert_term($name, 'tcr_area', ['slug' => sanitize_title($name)]);
    }
  }
}
register_activation_hook(TCR_PATH.'trail-conditions-reports.php', 'tcr_seed_default_areas');