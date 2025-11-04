<?php
if (!defined('ABSPATH')) exit;

// Immediate email on create
add_action('tcr_report_created', function($report_id){
  global $wpdb; $tbl = $wpdb->prefix.'trail_reports';
  $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $report_id));
  if (!$r) return;
  $trail = get_the_title($r->trail_id);
  $user  = get_userdata($r->user_id);
  $to = get_option('admin_email');
  $subj = "[TCR] New report: $trail";
  $msg = "Trail: $trail\nAgent: {$user->display_name}\nHours: $r->hours_spent\nTrees: $r->trees_cleared\nBrush: $r->brush_cleared\nRocks: $r->rocks_cleared\nSummary: $r->summary\n";
  wp_mail($to, $subj, $msg);
});

// Daily digest (local dev: view at http://localhost:8025)
if (!wp_next_scheduled('tcr_daily_digest')) {
  wp_schedule_event(time()+3600, 'daily', 'tcr_daily_digest');
}
add_action('tcr_daily_digest', function(){
  global $wpdb; $tbl = $wpdb->prefix.'trail_reports';
  $y = date('Y-m-d 00:00:00', time()-86400);
  $t = date('Y-m-d 00:00:00');
  $rows = $wpdb->get_results($wpdb->prepare("
    SELECT trail_id,
           COUNT(*) reports,
           SUM(hours_spent) hours,
           SUM(trees_cleared) trees,
           SUM(brush_cleared) brush,
           SUM(rocks_cleared) rocks
    FROM $tbl
    WHERE created_at >= %s AND created_at < %s
    GROUP BY trail_id
    ORDER BY hours DESC
  ", [$y,$t]));
  if (!$rows) return;
  $lines = ["Trail Agent digest ($y → $t)"];
  foreach ($rows as $r) {
    $lines[] = sprintf("- %s: %d reports, %.2f hrs, trees %d, brush %d, rocks %d",
      get_the_title($r->trail_id), $r->reports, $r->hours, $r->trees, $r->brush, $r->rocks);
  }
  wp_mail(get_option('admin_email'), '[TCR] Daily digest', implode("\n",$lines));
});