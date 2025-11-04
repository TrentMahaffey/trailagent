<?php
/**
 * Import Fake Trail Reports
 *
 * Usage:
 *   docker exec trailagent-wordpress-1 wp eval-file /var/www/html/wp-content/plugins/trail-conditions-reports/tools/import-fake-reports.php --allow-root
 */

if (!defined('ABSPATH') && !defined('WP_CLI')) {
    // Allow WP-CLI to run this
    define('WP_CLI', true);
}

// Configuration
$num_reports = 200;
$import_photos_path = '/var/www/html/import_photos';

echo "🚀 Starting import of {$num_reports} fake trail reports...\n\n";

global $wpdb;
$rpt_table = "{$wpdb->prefix}trail_reports";
$pho_table = "{$wpdb->prefix}trail_report_photos";

// Get all trails
$trails = $wpdb->get_results("
    SELECT p.ID, p.post_title
    FROM {$wpdb->posts} p
    WHERE p.post_type = 'trail' AND p.post_status = 'publish'
    ORDER BY p.ID ASC
");

if (empty($trails)) {
    echo "❌ No trails found. Please create some trails first.\n";
    exit(1);
}

echo "✅ Found " . count($trails) . " trails\n";

// Get all users (for assigning random reporters)
$users = get_users(['fields' => 'ID']);
if (empty($users)) {
    echo "❌ No users found.\n";
    exit(1);
}

echo "✅ Found " . count($users) . " users\n";

// Get all photos from import_photos directory
$photo_files = glob($import_photos_path . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
if (empty($photo_files)) {
    echo "❌ No photos found in {$import_photos_path}\n";
    exit(1);
}

echo "✅ Found " . count($photo_files) . " photos\n\n";

// Sample data for randomization
$summaries = [
    "Cleared trail of debris and trimmed overgrown vegetation along the corridor.",
    "Removed several downed trees blocking the path. Trail is now clear.",
    "Worked on drainage to prevent washout. Installed several water bars.",
    "Raked loose rocks off the trail surface. Improved tread quality.",
    "Trimmed back overgrowth that was encroaching on the trail corridor.",
    "Fixed erosion damage from recent storms. Trail is in good shape now.",
    "Cleared brush and small trees that had grown into the trail.",
    "Removed hazardous branches and improved sight lines around corners.",
    "Trail maintenance focused on water management and clearing debris.",
    "General upkeep - raking, trimming, and minor clearing work.",
    "Significant tree removal after recent windstorm. Multiple large trees down.",
    "Focus on corridor maintenance and vegetation management today.",
    "Improved trail surface by raking and removing loose material.",
    "Cleaned drainage features and cleared debris from water crossings.",
    "Trail in excellent condition after today's work session.",
];

$conditions_comments = [
    "Trail is in great shape overall.",
    "Some muddy sections after recent rain.",
    "Noticed a few potential hazards that need attention.",
    "Trail surface is generally good with minor erosion.",
    "Significant washout in one section needs addressing.",
    "Overgrowth is moderate, will need trimming soon.",
    "Trail is dry and in excellent condition.",
    "Several downed trees still present beyond our work area.",
    "Good tread quality throughout most of the trail.",
    "Water bars are functioning well after maintenance.",
    "Some loose rocks on steep sections.",
    "Corridor is clear and well-maintained now.",
    "",
    "",
    "", // Some reports have no conditions comment
];

// GPS coordinates for Glenwood Springs area (random points)
function random_gps_coords() {
    $base_lat = 39.55;
    $base_lng = -107.32;
    $lat_offset = (mt_rand(-50000, 50000) / 1000000); // ~0.05 degrees
    $lng_offset = (mt_rand(-50000, 50000) / 1000000);
    return [
        'lat' => round($base_lat + $lat_offset, 6),
        'lng' => round($base_lng + $lng_offset, 6)
    ];
}

// Random date within the last year
function random_date() {
    $days_ago = mt_rand(1, 365);
    return date('Y-m-d', strtotime("-{$days_ago} days"));
}

echo "📝 Importing reports...\n";

$imported = 0;
$failed = 0;

for ($i = 0; $i < $num_reports; $i++) {
    // Random trail
    $trail = $trails[array_rand($trails)];
    $trail_id = $trail->ID;

    // Random user
    $user_id = $users[array_rand($users)];

    // Random work metrics
    $hours_spent = round(mt_rand(1, 80) / 10, 2); // 0.1 to 8.0 hours
    $trees_cleared = mt_rand(0, 100) < 40 ? mt_rand(0, 8) : 0; // 40% chance of clearing trees

    // Random work performed flags (each has 30% chance)
    $corridor_cleared = mt_rand(0, 100) < 30 ? 1 : 0;
    $raking = mt_rand(0, 100) < 30 ? 1 : 0;
    $installed_drains = mt_rand(0, 100) < 20 ? 1 : 0;
    $rocks_cleared = mt_rand(0, 100) < 25 ? 1 : 0;

    // Random conditions (each has 15% chance)
    $cond_trees = mt_rand(0, 100) < 20 ? mt_rand(1, 5) : 0;
    $cond_hazards = mt_rand(0, 100) < 15 ? 1 : 0;
    $cond_washout = mt_rand(0, 100) < 10 ? 1 : 0;
    $cond_overgrowth = mt_rand(0, 100) < 25 ? 1 : 0;
    $cond_muddy = mt_rand(0, 100) < 20 ? 1 : 0;

    // Random text
    $summary = $summaries[array_rand($summaries)];
    $cond_comment = $conditions_comments[array_rand($conditions_comments)];

    // Random work date
    $work_date = random_date();

    // Random GPS (for report level)
    $gps = random_gps_coords();

    // Insert report
    $now = current_time('mysql');
    $created_at = date('Y-m-d H:i:s', strtotime($work_date . ' ' . sprintf('%02d:%02d:00', mt_rand(6, 18), mt_rand(0, 59))));

    $result = $wpdb->insert($rpt_table, [
        'user_id' => $user_id,
        'trail_id' => $trail_id,
        'work_date' => $work_date,
        'gps_lat' => $gps['lat'],
        'gps_lng' => $gps['lng'],
        'hours_spent' => $hours_spent,
        'trees_cleared' => $trees_cleared,
        'corridor_cleared' => $corridor_cleared,
        'raking' => $raking,
        'installed_drains' => $installed_drains,
        'rocks_cleared' => $rocks_cleared,
        'cond_trees' => $cond_trees,
        'cond_hazards' => $cond_hazards,
        'cond_washout' => $cond_washout,
        'cond_overgrowth' => $cond_overgrowth,
        'cond_muddy' => $cond_muddy,
        'cond_comment' => $cond_comment,
        'summary' => $summary,
        'status' => 'approved',
        'created_at' => $created_at,
        'updated_at' => $created_at,
    ], [
        '%d', '%d', '%s', '%f', '%f', '%f', '%d', '%d', '%d', '%d', '%d',
        '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s'
    ]);

    if ($result === false) {
        $failed++;
        echo "❌ Failed to insert report " . ($i + 1) . ": " . $wpdb->last_error . "\n";
        continue;
    }

    $report_id = $wpdb->insert_id;

    // Add random photos (0-4 photos per report, 60% have at least one photo)
    $num_photos = mt_rand(0, 100) < 60 ? mt_rand(1, 4) : 0;

    for ($p = 0; $p < $num_photos; $p++) {
        // Pick random photo
        $photo_file = $photo_files[array_rand($photo_files)];

        // Import to WordPress media library
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $filename = basename($photo_file);
        $upload_file = wp_upload_bits($filename, null, file_get_contents($photo_file));

        if (!$upload_file['error']) {
            $wp_filetype = wp_check_filetype($filename, null);

            $attachment = [
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
                'post_content' => '',
                'post_status' => 'inherit'
            ];

            $attachment_id = wp_insert_attachment($attachment, $upload_file['file']);

            if (!is_wp_error($attachment_id)) {
                $attach_data = wp_generate_attachment_metadata($attachment_id, $upload_file['file']);
                wp_update_attachment_metadata($attachment_id, $attach_data);

                // Random GPS for photo
                $photo_gps = random_gps_coords();

                // Insert photo record
                $wpdb->insert($pho_table, [
                    'report_id' => $report_id,
                    'attachment_id' => $attachment_id,
                    'photo_type' => 'work',
                    'caption' => '',
                    'gps_lat' => $photo_gps['lat'],
                    'gps_lng' => $photo_gps['lng'],
                ], ['%d', '%d', '%s', '%s', '%f', '%f']);
            }
        }
    }

    $imported++;

    if ($imported % 20 == 0) {
        echo "  ✓ Imported {$imported} reports...\n";
    }
}

echo "\n✅ Import complete!\n";
echo "  - Successfully imported: {$imported} reports\n";
echo "  - Failed: {$failed} reports\n";
echo "\n🎉 Done!\n";
