<?php
// Run with: wp eval-file wp-content/plugins/trail-conditions-reports/tools/dedupe-trails.php [--dry]

// ---- config ----
$taxonomy = 'tcr_area';
$dry = in_array('--dry', $GLOBALS['argv'] ?? [], true);

// ---- helpers ----
function norm_title($s){ return trim(str_replace('*','', (string)$s)); }
function has_star($s){ return strpos((string)$s, '*') !== false; }

// ---- collect all trails ----
$all = get_posts([
  'post_type'   => 'trail',
  'post_status' => 'any',
  'numberposts' => -1,
  'fields'      => 'ids',
]);

$groups = []; // norm_title => [post_ids...]
foreach ($all as $pid) {
  $title = get_the_title($pid);
  $key = mb_strtolower(norm_title($title));
  $groups[$key] = $groups[$key] ?? [];
  $groups[$key][] = $pid;
}

global $wpdb;
$reports_tbl = $wpdb->prefix.'trail_reports';

$deleted = 0; $updated_reports = 0; $retitled = 0; $meta_merged = 0; $terms_merged = 0;

foreach ($groups as $key => $ids) {
  if (count($ids) < 2) {
    // Single item; still ensure no stray '*' in title
    $pid = $ids[0];
    $title = get_the_title($pid);
    $clean = norm_title($title);
    if ($title !== $clean) {
      if (!$dry) {
        wp_update_post(['ID'=>$pid,'post_title'=>$clean]);
      }
      $retitled++;
    }
    continue;
  }

  // Pick canonical: prefer a title WITHOUT '*'
  $canonical = null; $dups = [];
  foreach ($ids as $pid) {
    $t = get_the_title($pid);
    if (!has_star($t) && $canonical === null) $canonical = $pid;
  }
  if ($canonical === null) {
    // all starred; pick first as canonical
    $canonical = $ids[0];
  }
  foreach ($ids as $pid) {
    if ($pid !== $canonical) $dups[] = $pid;
  }

  // Ensure canonical title has no '*'
  $ct = get_the_title($canonical);
  $clean = norm_title($ct);
  if ($ct !== $clean) {
    if (!$dry) wp_update_post(['ID'=>$canonical,'post_title'=>$clean]);
    $retitled++;
  }

  // Merge terms (union)
  $canon_terms = wp_get_post_terms($canonical, $taxonomy, ['fields'=>'ids']);
  foreach ($dups as $dup) {
    $dup_terms = wp_get_post_terms($dup, $taxonomy, ['fields'=>'ids']);
    $merged = array_values(array_unique(array_merge($canon_terms ?: [], $dup_terms ?: [])));
    if (!$dry) wp_set_post_terms($canonical, $merged, $taxonomy, false);
    if ($merged !== ($canon_terms ?: [])) { $terms_merged++; $canon_terms = $merged; }
  }

  // Merge meta (fill only missing on canonical)
  $canon_meta = array_map(function($m){ return $m[0]; }, get_post_meta($canonical));
  $wanted_keys = ['tcr_status','tcr_difficulty','tcr_length_mi','tcr_length_km','tcr_elev_gain_ft','tcr_elev_loss_ft'];
  foreach ($dups as $dup) {
    foreach ($wanted_keys as $k) {
      if (empty($canon_meta[$k])) {
        $v = get_post_meta($dup, $k, true);
        if ($v !== '' && $v !== null) {
          if (!$dry) update_post_meta($canonical, $k, $v);
          $meta_merged++;
        }
      }
    }
  }

  // Repoint custom table reports to canonical
  foreach ($dups as $dup) {
    $num = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $reports_tbl WHERE trail_id=%d", $dup));
    if ($num > 0) {
      if (!$dry) {
        $wpdb->update($reports_tbl, ['trail_id'=>$canonical], ['trail_id'=>$dup], ['%d'], ['%d']);
      }
      $updated_reports += $num;
    }
  }

  // Delete duplicates
  foreach ($dups as $dup) {
    $title = get_the_title($dup);
    if (!$dry) wp_delete_post($dup, true); // force delete
    $deleted++;
  }
}

WP_CLI::success("Done. Deleted dup posts: $deleted, Retitled: $retitled, Reports re-pointed: $updated_reports, Meta merged: $meta_merged, Terms merged: $terms_merged");
if ($dry) WP_CLI::log("(Dry run only — no changes were made)");