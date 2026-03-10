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

// Absolute path to your Sendy installation's config.php
// This file contains your database credentials ($dbHost, $dbUser, $dbPass, $dbName)
define('SENDY_CONFIG_PATH', '/home/user/public_html/sendy/includes/config.php');

// Absolute path to Sendy's short.php helper (usually in same includes/helpers/ folder)
define('SENDY_SHORT_PATH', '/home/user/public_html/sendy/includes/helpers/short.php');

// Optional: specify which autoresponder sequence to track opens for.
// Set to 0 to auto-detect (uses the sequence with the most emails).
// To find your ares_id, check your Sendy DB: SELECT id, title FROM ares;
define('TRACK_ARES_ID', 0);

// Optional: add custom campaign IDs to the Source filter dropdown.
// Format: ['campaign_id' => 'Display Label']
// These appear as additional options in the Source filter.
// Example: ['12345678901' => 'Spring 2026 Campaign', '98765432101' => 'Brand Campaign']
$CUSTOM_CAMPAIGNS = [];
