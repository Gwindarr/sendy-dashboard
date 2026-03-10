<?php
/**
 * Sendy Dashboard Configuration
 *
 * Copy this file to config.php and update the values below.
 * For best security, place config.php OUTSIDE your web root.
 *
 * Example:
 *   Web root:  /home/user/public_html/
 *   Config:    /home/user/private/sendy-dashboard-config.php
 *   Dashboard: /home/user/public_html/sendy-dashboard/index.php
 */

// Dashboard login password
define('DASHBOARD_PASSWORD', 'change-this-to-something-secure');

// Database credentials (same values as in your Sendy config)
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');
// define('DB_PORT', 3306); // Uncomment if using a non-default port
// define('DB_CHARSET', 'utf8'); // Default: utf8

// Optional: specify which autoresponder sequence to track opens for.
// Set to 0 to auto-detect (uses the sequence with the most emails).
// To find your ares_id, check your Sendy DB: SELECT id, title FROM ares;
define('TRACK_ARES_ID', 0);

// Optional: add custom campaign IDs to the Source filter dropdown.
// Format: ['campaign_id' => 'Display Label']
// These appear as additional options in the Source filter.
// Example: ['12345678901' => 'Spring 2026 Campaign', '98765432101' => 'Brand Campaign']
$CUSTOM_CAMPAIGNS = [];

// Optional: Google Ads offline conversion settings.
// GADS_CONVERSION_NAME must match the conversion action name in your Google Ads account.
// GADS_CONVERSION_VALUE is the value assigned to each qualified subscriber (optional).
// GADS_QUALIFY_HOURS is how many hours after signup before a subscriber is evaluated (default: 24).
define('GADS_CONVERSION_NAME', 'Engaged Subscriber');
// define('GADS_CONVERSION_VALUE', 5.00);
// define('GADS_QUALIFY_HOURS', 24);
