#!/bin/bash
set -e

# This script runs AFTER WordPress is initialized by the base image entrypoint
# It sets up the Trail Agent site automatically

SETUP_FLAG="/var/www/html/.trail-agent-setup-done"

# Function to check if WordPress is ready
wait_for_wordpress() {
    echo "Waiting for WordPress to be ready..."

    # Wait for wp-config.php to exist (means WordPress files are in place)
    until [ -f /var/www/html/wp-config.php ]; do
        sleep 2
    done

    # Check if WordPress is installed, if not, install it
    if ! wp --allow-root core is-installed 2>/dev/null; then
        echo "WordPress not installed yet. Installing..."
        # Use WORDPRESS_URL env var if set, otherwise default to localhost:8082
        WP_URL="${WORDPRESS_URL:-http://localhost:8082}"
        wp --allow-root core install \
            --url="$WP_URL" \
            --title="Trail Agent" \
            --admin_user="admin" \
            --admin_password="admin" \
            --admin_email="admin@example.com" \
            --skip-email
    fi

    echo "WordPress is ready!"
}

# Run setup only if not already done
if [ ! -f "$SETUP_FLAG" ]; then
    echo "========================================="
    echo "Trail Agent: First-time setup starting..."
    echo "========================================="

    # Wait for WordPress to be fully initialized
    wait_for_wordpress

    echo "1. Activating trail-conditions-reports plugin..."
    wp --allow-root plugin activate trail-conditions-reports || true

    echo "2. Setting permalink structure..."
    wp --allow-root rewrite structure '/%postname%/' --hard || true

    echo "3. Creating pages..."
    wp --allow-root post create \
        --post_type=page \
        --post_title="Home" \
        --post_name="home" \
        --post_content="[trail_dashboard_nav]" \
        --post_status=publish || true

    wp --allow-root post create \
        --post_type=page \
        --post_title="Submit" \
        --post_name="submit" \
        --post_content="[trail_report_form]" \
        --post_status=publish || true

    wp --allow-root post create \
        --post_type=page \
        --post_title="Reports" \
        --post_name="reports" \
        --post_content="[tcr_browser]" \
        --post_status=publish || true

    wp --allow-root post create \
        --post_type=page \
        --post_title="Outstanding Maintenance" \
        --post_name="outstanding-maintenance" \
        --post_content="[trail_outstanding_maintenance]" \
        --post_status=publish || true

    wp --allow-root post create \
        --post_type=page \
        --post_title="Maintenance Admin" \
        --post_name="maintenance-admin" \
        --post_content="[trail_outstanding_admin]" \
        --post_status=publish || true

    wp --allow-root post create \
        --post_type=page \
        --post_title="Analytics" \
        --post_name="analytics" \
        --post_content="[tcr_analytics]" \
        --post_status=publish || true

    wp --allow-root post create \
        --post_type=page \
        --post_title="Register" \
        --post_name="register" \
        --post_content="[trail_register]" \
        --post_status=publish || true

    wp --allow-root post create \
        --post_type=page \
        --post_title="Trail Map" \
        --post_name="trail-map" \
        --post_content="[trail_map]" \
        --post_status=publish || true

    echo "4. Setting Home as front page..."
    # Get the ID of the Home page (should be the first one created, but let's be safe)
    HOME_ID=$(wp --allow-root post list --post_type=page --name=home --field=ID --format=csv)
    if [ -n "$HOME_ID" ]; then
        wp --allow-root option update show_on_front page
        wp --allow-root option update page_on_front "$HOME_ID"
    fi

    echo "5. Writing .htaccess file..."
    cat > /var/www/html/.htaccess << 'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF

    echo "6. Flushing rewrite rules..."
    wp --allow-root rewrite flush --hard || true

    # Mark setup as complete
    touch "$SETUP_FLAG"

    echo "========================================="
    echo "Trail Agent setup complete!"
    echo "========================================="
else
    echo "Trail Agent already configured (remove $SETUP_FLAG to re-run setup)"
fi
