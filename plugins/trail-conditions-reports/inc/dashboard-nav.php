<?php
/**
 * Home page dashboard navigation
 * Quick access to key features
 */
if (!defined('ABSPATH')) exit;

/**
 * [trail_dashboard_nav] - Navigation panel for home page
 */
add_shortcode('trail_dashboard_nav', function($atts) {
  $atts = shortcode_atts([
    'show_admin' => 'yes',
    'show_register' => 'yes'
  ], $atts);

  wp_enqueue_style('tcr-dashboard-nav', TCR_URL.'assets/dashboard-nav.css', [], filemtime(TCR_PATH.'assets/dashboard-nav.css'));

  $is_logged_in = is_user_logged_in();
  $is_admin = current_user_can('manage_options'); // Correct capability for administrators

  // Get page URLs - using your actual WordPress page slugs
  $submit_url = home_url('/submit/'); // "Trail Report Form" page
  $browse_url = home_url('/reports/'); // "TCR Browser" page
  $outstanding_url = home_url('/outstanding-maintenance/'); // Public outstanding page
  $admin_url = home_url('/maintenance-admin/'); // Admin management page
  $analytics_url = home_url('/analytics/'); // Analytics/Reporting dashboard
  $register_url = home_url('/register/'); // Registration page
  $login_url = wp_login_url();

  ob_start();
  ?>
  <div class="tcr-dashboard-nav">
    <?php if ($is_logged_in): ?>
      <div class="nav-card primary">
        <div class="nav-icon">📝</div>
        <h3>Submit Trail Report</h3>
        <p>Log your trail work and upload photos</p>
        <a href="<?php echo esc_url($submit_url); ?>" class="nav-button">Submit Report</a>
      </div>

      <div class="nav-card highlight">
        <div class="nav-icon">⚠️</div>
        <h3>Outstanding Maintenance</h3>
        <p>View and resolve trail issues that need attention</p>
        <a href="<?php echo esc_url($outstanding_url); ?>" class="nav-button warning">View Needs</a>
      </div>

      <div class="nav-card">
        <div class="nav-icon">🔍</div>
        <h3>Browse Reports</h3>
        <p>View all trail condition reports</p>
        <a href="<?php echo esc_url($browse_url); ?>" class="nav-button">Browse</a>
      </div>

      <div class="nav-card analytics">
        <div class="nav-icon">📊</div>
        <h3>Analytics & Statistics</h3>
        <p>View trail maintenance reports and leaderboard</p>
        <a href="<?php echo esc_url($analytics_url); ?>" class="nav-button analytics">View Analytics</a>
      </div>

      <?php if ($is_admin && $atts['show_admin'] === 'yes'): ?>
        <div class="nav-card admin">
          <div class="nav-icon">⚙️</div>
          <h3>Admin: Manage Outstanding</h3>
          <p>Mark photos for outstanding maintenance</p>
          <a href="<?php echo esc_url($admin_url); ?>" class="nav-button admin">Manage</a>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <div class="nav-card primary">
        <div class="nav-icon">🏔️</div>
        <h3>Welcome to Trail Agent</h3>
        <p>Join our community of trail volunteers to help maintain and monitor trail conditions.</p>
      </div>

      <div class="nav-card">
        <div class="nav-icon">🔑</div>
        <h3>Login</h3>
        <p>Access your trail agent dashboard</p>
        <a href="<?php echo esc_url($login_url); ?>" class="nav-button">Login</a>
      </div>

      <?php if ($atts['show_register'] === 'yes'): ?>
        <div class="nav-card">
          <div class="nav-icon">✨</div>
          <h3>Become a Trail Agent</h3>
          <p>Register to start reporting trail conditions</p>
          <a href="<?php echo esc_url($register_url); ?>" class="nav-button">Register Now</a>
        </div>
      <?php endif; ?>

      <div class="nav-card highlight">
        <div class="nav-icon">⚠️</div>
        <h3>Current Trail Needs</h3>
        <p>See what maintenance work is needed</p>
        <a href="<?php echo esc_url($outstanding_url); ?>" class="nav-button warning">View Needs</a>
      </div>
    <?php endif; ?>
  </div>
  <?php
  return ob_get_clean();
});
