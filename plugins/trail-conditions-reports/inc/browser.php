<?php
/**
 * Trail Conditions Reports — Shortcodes
 * Path: /wp-content/plugins/trail-conditions-reports/inc/shortcodes.php
 */
if (!defined('ABSPATH')) exit;

add_shortcode('tcr_submit', function($atts){
  $atts = shortcode_atts(['areas' => '', 'trails' => ''], $atts, 'tcr_submit');

  ob_start(); ?>
  <form id="tcr-form" method="post" enctype="multipart/form-data">
    <label for="tcr-area" class="tcr-field">
      Area
      <select name="area_id" id="tcr-area" required>
        <option value="">Select area</option>
        <?php foreach (explode(',', $atts['areas']) as $area): ?>
          <option value="<?php echo esc_attr(trim($area)); ?>"><?php echo esc_html(trim($area)); ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label for="tcr-trail" class="tcr-field">
      Trail
      <select name="trail_id" id="tcr-trail" required>
        <option value="">Select trail</option>
        <?php foreach (explode(',', $atts['trails']) as $trail): ?>
          <option value="<?php echo esc_attr(trim($trail)); ?>"><?php echo esc_html(trim($trail)); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label for="tcr-work-date" class="tcr-field">
      Date of work
      <input
        type="date"
        id="tcr-work-date"
        name="work_date"
        value="<?php echo esc_attr( current_time('Y-m-d') ); ?>"
        max="<?php echo esc_attr( current_time('Y-m-d') ); ?>"
      />
      <small class="tcr-help">Defaults to today; change if the work was done earlier.</small>
    </label>

    <!-- rest of the form fields -->

  </form>
  <?php
  return ob_get_clean();
});