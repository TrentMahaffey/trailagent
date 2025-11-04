<?php
if (!defined('ABSPATH')) exit;

/**
 * Register typed meta for the `trail` CPT (REST-exposed).
 */
add_action('init', function () {
  $fields = [
    'tcr_difficulty'    => ['type'=>'string', 'single'=>true, 'default'=>'moderate'], // easy|moderate|advanced|expert
    'tcr_status'        => ['type'=>'string', 'single'=>true, 'default'=>'open'],     // open|closed|seasonal
    'tcr_region'        => ['type'=>'string', 'single'=>true, 'default'=>'' ],
    'tcr_area'          => ['type'=>'string', 'single'=>true, 'default'=>'' ],
    'tcr_land_manager'  => ['type'=>'string', 'single'=>true, 'default'=>'' ],
    'tcr_trailhead_lat' => ['type'=>'number', 'single'=>true, 'default'=>0.0],
    'tcr_trailhead_lng' => ['type'=>'number', 'single'=>true, 'default'=>0.0],
    'tcr_map_url'       => ['type'=>'string', 'single'=>true, 'default'=>'' ],
  ];

  foreach ($fields as $key => $args) {
    register_post_meta('trail', $key, [
      'type'         => $args['type'],
      'single'       => true,
      'default'      => $args['default'],
      'show_in_rest' => true,
      'auth_callback'=> function() { return current_user_can('edit_posts'); },
      'sanitize_callback' => function($val) use ($args, $key) {
        switch ($key) {
          case 'tcr_trailhead_lat':
          case 'tcr_trailhead_lng':
            return is_numeric($val) ? (float)$val : null;
          case 'tcr_difficulty':
            $allowed = ['easy','moderate','advanced','expert'];
            return in_array($val, $allowed, true) ? $val : 'moderate';
          case 'tcr_status':
            $allowed = ['open','closed','seasonal'];
            return in_array($val, $allowed, true) ? $val : 'open';
          case 'tcr_map_url':
            return esc_url_raw($val);
          default:
            return sanitize_text_field($val);
        }
      }
    ]);
  }
});

/**
 * Meta box UI in Trail editor.
 */
add_action('add_meta_boxes', function () {
  add_meta_box(
    'tcr_trail_meta',
    'Trail Details',
    'tcr_render_trail_meta_box',
    'trail',
    'normal',
    'default'
  );
});

function tcr_render_trail_meta_box($post) {
  wp_nonce_field('tcr_save_trail_meta', 'tcr_trail_meta_nonce');

  $vals = [];
  foreach ([
    'tcr_difficulty','tcr_status','tcr_region','tcr_area',
    'tcr_land_manager','tcr_trailhead_lat','tcr_trailhead_lng','tcr_map_url'
  ] as $f) {
    $vals[$f] = get_post_meta($post->ID, $f, true);
  }

  ?>
  <style>
    .tcr-grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    .tcr-grid label { display:block; font-weight:600; margin-bottom:4px; }
    .tcr-field { display:flex; flex-direction:column; }
  </style>

  <div class="tcr-grid">

    <div class="tcr-field">
      <label for="tcr_region">Region</label>
      <input type="text" id="tcr_region" name="tcr_region" value="<?php echo esc_attr($vals['tcr_region']); ?>">
    </div>

    <div class="tcr-field">
      <label for="tcr_area">Area</label>
      <input type="text" id="tcr_area" name="tcr_area" value="<?php echo esc_attr($vals['tcr_area']); ?>">
    </div>

    <div class="tcr-field">
      <label for="tcr_land_manager">Land Manager</label>
      <input type="text" id="tcr_land_manager" name="tcr_land_manager" value="<?php echo esc_attr($vals['tcr_land_manager']); ?>">
    </div>

    <div class="tcr-field">
      <label for="tcr_difficulty">Difficulty</label>
      <select id="tcr_difficulty" name="tcr_difficulty">
        <?php
          $opts = ['easy'=>'Easy','moderate'=>'Moderate','advanced'=>'Advanced','expert'=>'Expert'];
          foreach ($opts as $val=>$label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($val), selected($vals['tcr_difficulty'],$val,false), esc_html($label));
          }
        ?>
      </select>
    </div>

    <div class="tcr-field">
      <label for="tcr_status">Status</label>
      <select id="tcr_status" name="tcr_status">
        <?php
          $sopts = ['open'=>'Open','closed'=>'Closed','seasonal'=>'Seasonal'];
          foreach ($sopts as $val=>$label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($val), selected($vals['tcr_status'],$val,false), esc_html($label));
          }
        ?>
      </select>
    </div>

    <div class="tcr-field">
      <label for="tcr_trailhead_lat">Trailhead Lat</label>
      <input type="number" step="0.000001" id="tcr_trailhead_lat" name="tcr_trailhead_lat" value="<?php echo esc_attr($vals['tcr_trailhead_lat']); ?>">
    </div>

    <div class="tcr-field">
      <label for="tcr_trailhead_lng">Trailhead Lng</label>
      <input type="number" step="0.000001" id="tcr_trailhead_lng" name="tcr_trailhead_lng" value="<?php echo esc_attr($vals['tcr_trailhead_lng']); ?>">
    </div>

    <div class="tcr-field" style="grid-column:1 / -1;">
      <label for="tcr_map_url">Map URL (e.g., Trailforks/Google/OSM)</label>
      <input type="url" id="tcr_map_url" name="tcr_map_url" value="<?php echo esc_url($vals['tcr_map_url']); ?>">
    </div>
  </div>
  <?php
}

/**
 * Save handler.
 */
add_action('save_post_trail', function ($post_id) {
  if (!isset($_POST['tcr_trail_meta_nonce']) || !wp_verify_nonce($_POST['tcr_trail_meta_nonce'], 'tcr_save_trail_meta')) return;
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (!current_user_can('edit_post', $post_id)) return;

  $fields = [
    'tcr_difficulty','tcr_status','tcr_region','tcr_area',
    'tcr_land_manager','tcr_trailhead_lat','tcr_trailhead_lng','tcr_map_url'
  ];

  foreach ($fields as $key) {
    if (!isset($_POST[$key])) continue;
    $val = $_POST[$key];
    switch ($key) {
      case 'tcr_trailhead_lat':
      case 'tcr_trailhead_lng':
        $val = is_numeric($val) ? (float)$val : null;
        break;
      case 'tcr_difficulty':
        $allowed = ['easy','moderate','advanced','expert'];
        $val = in_array($val, $allowed, true) ? $val : 'moderate';
        break;
      case 'tcr_status':
        $allowed = ['open','closed','seasonal'];
        $val = in_array($val, $allowed, true) ? $val : 'open';
        break;
      case 'tcr_map_url':
        $val = esc_url_raw($val);
        break;
      default:
        $val = sanitize_text_field($val);
    }
    update_post_meta($post_id, $key, $val);
  }
}, 10, 1);

/**
 * Admin list columns for Trails.
 */
add_filter('manage_trail_posts_columns', function ($cols) {
  $new = [];
  foreach ($cols as $k => $v) {
    $new[$k] = $v;
    if ($k === 'title') {
      $new['tcr_region']        = 'Region';
      $new['tcr_area']          = 'Area';
      $new['tcr_land_manager']  = 'Land Manager';
      $new['tcr_difficulty']    = 'Difficulty';
      $new['tcr_status']        = 'Status';
    }
  }
  return $new;
});

add_action('manage_trail_posts_custom_column', function ($col, $post_id) {
  switch ($col) {
    case 'tcr_region':
    case 'tcr_area':
    case 'tcr_land_manager':
      echo esc_html(get_post_meta($post_id, $col, true));
      break;
    case 'tcr_difficulty':
      echo esc_html(ucfirst(get_post_meta($post_id, 'tcr_difficulty', true) ?: 'moderate'));
      break;
    case 'tcr_status':
      echo esc_html(ucfirst(get_post_meta($post_id, 'tcr_status', true) ?: 'open'));
      break;
  }
}, 10, 2);