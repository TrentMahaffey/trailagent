<?php
/**
 * User Registration for Trail Agents
 */
if (!defined('ABSPATH')) exit;

/**
 * [trail_register] shortcode
 * Displays registration form and handles user registration
 */
add_shortcode('trail_register', function() {
  // If already logged in, show message
  if (is_user_logged_in()) {
    return '<div class="tcr-message tcr-info">
      <p>You are already logged in as <strong>' . wp_get_current_user()->user_login . '</strong>.</p>
      <p><a href="' . wp_logout_url(home_url()) . '">Logout</a> | <a href="' . home_url() . '">Home</a></p>
    </div>';
  }

  // Enqueue styles
  wp_enqueue_style('tcr-registration', TCR_URL.'assets/registration.css', [], filemtime(TCR_PATH.'assets/registration.css'));

  ob_start();
  ?>
  <div id="tcr-register-form">
    <div class="tcr-register-header">
      <h2>Register as a Trail Agent</h2>
      <p>Join our community of trail volunteers and help keep our trails safe and well-maintained.</p>
    </div>

    <?php
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tcr_register_nonce'])) {
      if (!wp_verify_nonce($_POST['tcr_register_nonce'], 'tcr_register_action')) {
        echo '<div class="tcr-message tcr-error">Security verification failed. Please try again.</div>';
      } else {
        $result = tcr_process_registration($_POST);
        if (is_wp_error($result)) {
          echo '<div class="tcr-message tcr-error">' . $result->get_error_message() . '</div>';
        } else {
          echo '<div class="tcr-message tcr-success">
            <h3>Registration Successful!</h3>
            <p>Welcome to the Trail Agent community, <strong>' . esc_html($_POST['username']) . '</strong>!</p>
            <p>You can now <a href="' . wp_login_url() . '">login</a> with your credentials.</p>
          </div>';
          return ob_get_clean();
        }
      }
    }
    ?>

    <form method="POST" action="" class="tcr-register-form">
      <?php wp_nonce_field('tcr_register_action', 'tcr_register_nonce'); ?>

      <div class="tcr-form-row">
        <div class="tcr-form-group">
          <label for="username">Username *</label>
          <input type="text" id="username" name="username" required
                 value="<?php echo isset($_POST['username']) ? esc_attr($_POST['username']) : ''; ?>"
                 placeholder="Choose a username">
          <small>Only letters, numbers, and underscores</small>
        </div>

        <div class="tcr-form-group">
          <label for="email">Email Address *</label>
          <input type="email" id="email" name="email" required
                 value="<?php echo isset($_POST['email']) ? esc_attr($_POST['email']) : ''; ?>"
                 placeholder="your@email.com">
          <small>We'll send your account details here</small>
        </div>
      </div>

      <div class="tcr-form-row">
        <div class="tcr-form-group">
          <label for="first_name">First Name *</label>
          <input type="text" id="first_name" name="first_name" required
                 value="<?php echo isset($_POST['first_name']) ? esc_attr($_POST['first_name']) : ''; ?>"
                 placeholder="First name">
        </div>

        <div class="tcr-form-group">
          <label for="last_name">Last Name *</label>
          <input type="text" id="last_name" name="last_name" required
                 value="<?php echo isset($_POST['last_name']) ? esc_attr($_POST['last_name']) : ''; ?>"
                 placeholder="Last name">
        </div>
      </div>

      <div class="tcr-form-row">
        <div class="tcr-form-group">
          <label for="password">Password *</label>
          <input type="password" id="password" name="password" required
                 placeholder="Choose a strong password">
          <small>At least 8 characters</small>
        </div>

        <div class="tcr-form-group">
          <label for="password_confirm">Confirm Password *</label>
          <input type="password" id="password_confirm" name="password_confirm" required
                 placeholder="Re-enter your password">
        </div>
      </div>

      <div class="tcr-form-group">
        <label>
          <input type="checkbox" name="terms" required>
          I agree to the terms and conditions and privacy policy
        </label>
      </div>

      <button type="submit" class="tcr-register-button">
        Create My Account
      </button>

      <p class="tcr-register-footer">
        Already have an account? <a href="<?php echo wp_login_url(); ?>">Login here</a>
      </p>
    </form>
  </div>
  <?php
  return ob_get_clean();
});

/**
 * Process user registration
 */
function tcr_process_registration($data) {
  // Validate required fields
  $username = sanitize_user($data['username']);
  $email = sanitize_email($data['email']);
  $first_name = sanitize_text_field($data['first_name']);
  $last_name = sanitize_text_field($data['last_name']);
  $password = $data['password'];
  $password_confirm = $data['password_confirm'];

  // Validation
  if (empty($username) || empty($email) || empty($first_name) || empty($last_name) || empty($password)) {
    return new WP_Error('missing_fields', 'All fields are required.');
  }

  if (!validate_username($username)) {
    return new WP_Error('invalid_username', 'Invalid username. Use only letters, numbers, and underscores.');
  }

  if (username_exists($username)) {
    return new WP_Error('username_exists', 'This username is already taken.');
  }

  if (!is_email($email)) {
    return new WP_Error('invalid_email', 'Please enter a valid email address.');
  }

  if (email_exists($email)) {
    return new WP_Error('email_exists', 'An account with this email already exists.');
  }

  if ($password !== $password_confirm) {
    return new WP_Error('password_mismatch', 'Passwords do not match.');
  }

  if (strlen($password) < 8) {
    return new WP_Error('weak_password', 'Password must be at least 8 characters long.');
  }

  if (empty($data['terms'])) {
    return new WP_Error('terms_required', 'You must agree to the terms and conditions.');
  }

  // Create user
  $user_id = wp_create_user($username, $password, $email);

  if (is_wp_error($user_id)) {
    return $user_id;
  }

  // Update user meta
  wp_update_user([
    'ID' => $user_id,
    'first_name' => $first_name,
    'last_name' => $last_name,
    'display_name' => $first_name . ' ' . $last_name,
    'role' => 'trail_agent' // Custom role from roles.php
  ]);

  // Send welcome email
  $subject = 'Welcome to Trail Agent!';
  $message = "Hi $first_name,\n\n";
  $message .= "Welcome to the Trail Agent community!\n\n";
  $message .= "Your account has been created successfully.\n\n";
  $message .= "Username: $username\n";
  $message .= "Email: $email\n\n";
  $message .= "You can now login at: " . wp_login_url() . "\n\n";
  $message .= "Thank you for joining us in keeping our trails safe!\n\n";
  $message .= "Best regards,\nThe Trail Agent Team";

  wp_mail($email, $subject, $message);

  return $user_id;
}
