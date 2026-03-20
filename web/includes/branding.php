<?php
/**
 * Branding Helper
 * Fetches site-wide branding from the database
 */
require_once __DIR__ . '/../api/db.php';

function get_branding($conn) {
    $default = [
        'site_title' => 'VVU Scheduler AI',
        'site_color' => '#2563eb',
        'site_secondary_color' => '#3f83f8',
        'site_color_strength' => 100,
        'site_bg_color' => '#0f172a',
        'site_logo' => null,
        'site_icon' => null
    ];

    try {
        $result = $conn->query("SELECT * FROM branding_settings LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            return array_merge($default, $row);
        }
    } catch (Exception $e) {
        error_log("Branding Error: " . $e->getMessage());
    }

    return $default;
}

// Global connection variable $conn should be available from db.php (included via header.php or directly)
if (!isset($conn)) {
    require_once __DIR__ . '/../api/db.php';
}

$branding = get_branding($conn);

/**
 * Helper to ensure branding constants are available globally
 */
if (!defined('SITE_TITLE')) define('SITE_TITLE', $branding['site_title']);
if (!defined('PRIMARY_COLOR')) define('PRIMARY_COLOR', $branding['site_color']);
if (!defined('SECONDARY_COLOR')) define('SECONDARY_COLOR', $branding['site_secondary_color'] ?? '#3f83f8');
if (!defined('COLOR_STRENGTH')) define('COLOR_STRENGTH', (int)($branding['site_color_strength'] ?? 100));

// Set global constants if needed or just use $branding
?>
