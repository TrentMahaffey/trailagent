<?php
if (!defined('ABSPATH')) exit;

function tcr_install_roles(){
  add_role('trail_agent', 'Trail Agent', ['read' => true]);
  $admin = get_role('administrator');
  if ($admin && !$admin->has_cap('approve_trail_reports')) {
    $admin->add_cap('manage_trails');
    $admin->add_cap('approve_trail_reports');
    $admin->add_cap('read_private_trail_reports');
    $admin->add_cap('export_trail_reports');
  }
  $agent = get_role('trail_agent');
  if ($agent) {
    $agent->add_cap('submit_trail_reports');
    $agent->add_cap('read_trail_dashboard');
  }
}