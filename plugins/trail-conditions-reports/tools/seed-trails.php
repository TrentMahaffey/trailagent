<?php
// Usage:
//   docker compose exec --user www-data wordpress \
//     wp eval-file wp-content/plugins/trail-conditions-reports/tools/seed-trails.php [--reset]

if (!defined('WP_CLI') || !WP_CLI) { fwrite(STDERR,"Run this with WP-CLI.\n"); exit(1); }

$taxonomy = 'tcr_area';

// -----------------------------------------------------
// helpers
// -----------------------------------------------------
function tcr_norm_name($name){ return trim(str_replace('*','',(string)$name)); }
function tcr_norm_diff($label){
  $l=strtolower(trim($label));
  if(str_contains($l,'double'))return'double_black';
  if(str_contains($l,'very'))return'double_black';
  if(str_contains($l,'black'))return'black';
  if(str_contains($l,'difficult'))return'black';
  if(str_contains($l,'intermediate'))return'blue';
  if(str_contains($l,'easy'))return'green';
  return'blue';
}
function tcr_to_km($m){ return $m?round($m*1.60934,2):null; }
function tcr_parse_miles($s){ $s=trim((string)$s); if($s===''||strtoupper($s)==='N/S')return null; return(float)$s; }
function tcr_parse_elev($s){
  $s=trim((string)$s); if($s===''||strtoupper($s)==='N/S')return[null,null];
  if(!str_contains($s,'/'))return[null,null];
  [$g,$l]=array_map('trim',explode('/',$s,2));
  $gain=$g?intval(str_replace(['+','ft',' '],'',$g)):null;
  $loss=$l?intval(str_replace(['-','ft',' '],'',$l)):null;
  if($loss<0)$loss=-$loss;
  return[$gain?:null,$loss?:null];
}
function tcr_get_flag($n){ foreach($GLOBALS['argv']??[]as$a)if($a==="--$n")return true; return false; }

// -----------------------------------------------------
// data (abbreviated here for brevity – same as before)
// -----------------------------------------------------
require __DIR__.'/seed-data.php'; // put your big $DATA array in this separate file to keep tidy

// -----------------------------------------------------
// exclude Snowmass Bike Park runs
// -----------------------------------------------------
$skip_patterns=[
  'valhalla','verde','viking','vapor','battle axe','gonzo','seven star','animal crackers'
];
foreach($DATA['Snowmass']??[]as$i=>$r){
  $n=strtolower(tcr_norm_name($r[0]));
  foreach($skip_patterns as$p){
    if(str_contains($n,$p)){ unset($DATA['Snowmass'][$i]); break; }
  }
}

// -----------------------------------------------------
// reset mode (delete existing)
$RESET=tcr_get_flag('reset');
if($RESET){
  \WP_CLI::log('Reset mode: deleting existing matching trails…');
  foreach($DATA as$area=>$trails){
    foreach($trails as$t){
      $name=tcr_norm_name($t[0]);
      if($p=get_page_by_title($name,OBJECT,'trail')){
        wp_delete_post($p->ID,true);
        \WP_CLI::log("  Deleted: $name");
      }
    }
  }
}

// -----------------------------------------------------
// seed
$created=0;$updated=0;$skipped=0;
foreach($DATA as$area=>$trails){
  $term=term_exists($area,$taxonomy);
  if(!$term){
    $term=wp_insert_term($area,$taxonomy,['slug'=>sanitize_title($area)]);
    if(is_wp_error($term)){\WP_CLI::warning("Area term fail $area");continue;}
  }
  $term_id=is_array($term)?(int)$term['term_id']:(int)$term->term_id;

  foreach($trails as$t){
    [$raw,$mi,$diff,$elev,$desc]=$t;
    $name=tcr_norm_name($raw);
    if($existing=get_page_by_title($name,OBJECT,'trail')){
      $pid=$existing->ID;
      if($desc&&!$existing->post_content)wp_update_post(['ID'=>$pid,'post_content'=>$desc]);
      $updated++;
    }else{
      $pid=wp_insert_post([
        'post_type'=>'trail','post_status'=>'publish','post_title'=>$name,
        'post_content'=>$desc,'post_author'=>get_current_user_id()?:1
      ],true);
      if(is_wp_error($pid)){\WP_CLI::warning("Trail fail: $name");$skipped++;continue;}
      $created++;
    }

    wp_set_post_terms($pid,[$term_id],$taxonomy,false);
    [$gain,$loss]=tcr_parse_elev($elev);
    update_post_meta($pid,'tcr_status','open');
    update_post_meta($pid,'tcr_difficulty',tcr_norm_diff($diff));
    if($mi=tcr_parse_miles($mi)){update_post_meta($pid,'tcr_length_mi',$mi);update_post_meta($pid,'tcr_length_km',tcr_to_km($mi));}
    if($gain!==null)update_post_meta($pid,'tcr_elev_gain_ft',$gain);
    if($loss!==null)update_post_meta($pid,'tcr_elev_loss_ft',$loss);
  }
}

\WP_CLI::success("Seeding done. Created:$created Updated:$updated Skipped:$skipped");