<?php
if (!defined('ABSPATH')) exit;

function tcr_register_trail_cpt() {
  register_post_type('trail', [
    'label' => 'Trails',
    'labels' => [
      'name' => 'Trails',
      'singular_name' => 'Trail',
      'add_new_item' => 'Add New Trail',
      'edit_item' => 'Edit Trail',
      'new_item' => 'New Trail',
      'view_item' => 'View Trail',
      'search_items' => 'Search Trails',
    ],
    'public' => true,
    'show_in_rest' => true,
    'menu_icon' => 'dashicons-location-alt',
    'supports' => ['title','editor','excerpt','thumbnail','custom-fields'],
    'has_archive' => true,
    'rewrite' => ['slug' => 'trails'],
  ]);
}
add_action('init', 'tcr_register_trail_cpt', 0); // register ASAP

// Ensure theme allows thumbnails for this CPT
add_action('after_setup_theme', function () {
  add_theme_support('post-thumbnails', ['trail']);
});

// In case another plugin/theme removed supports, add them back late.
add_action('init', function () {
  foreach (['title','editor','excerpt','thumbnail','custom-fields'] as $f) {
    add_post_type_support('trail', $f);
  }
}, 100);