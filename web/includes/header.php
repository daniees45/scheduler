<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/bootstrap.php';

// Include access control functions
require_once __DIR__ . '/access_control.php';
// Include branding
require_once __DIR__ . '/branding.php';

// Redirect if not logged in (unless on login page)
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'login.php' && basename($_SERVER['PHP_SELF']) != 'install.php') {
    header("Location: login.php");
    exit;
}

$page_title = isset($page_title) ? $page_title : 'VVU Scheduler';
$page_css = isset($page_css) ? $page_css : null;
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Guest';
$user_name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : ($_SESSION['username'] ?? '');
$user_dept = isset($_SESSION['department']) ? $_SESSION['department'] : null;
$user_level = isset($_SESSION['level']) ? $_SESSION['level'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars($branding['site_title']); ?></title>
    
    <?php if ($branding['site_icon']): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($branding['site_icon']); ?>">
    <?php endif; ?>

    <?php
    // Helpers for branding color handling
    function normalizeHexColor($hex, $fallback = '#2563eb') {
        $hex = trim((string)$hex);
        if (!preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $hex)) {
            return $fallback;
        }
        if (strlen($hex) === 4) {
            return '#'
                . $hex[1] . $hex[1]
                . $hex[2] . $hex[2]
                . $hex[3] . $hex[3];
        }
        return strtolower($hex);
    }

    function hexToRgb($hex) {
        $hex = normalizeHexColor($hex);
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "$r, $g, $b";
    }

    function adjustHexBrightness($hex, $steps) {
        $hex = normalizeHexColor($hex);
        $hex = ltrim($hex, '#');
        $steps = max(-255, min(255, (int)$steps));

        $r = max(0, min(255, hexdec(substr($hex, 0, 2)) + $steps));
        $g = max(0, min(255, hexdec(substr($hex, 2, 2)) + $steps));
        $b = max(0, min(255, hexdec(substr($hex, 4, 2)) + $steps));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    function applyHexStrength($hex, $strengthPercent) {
        $hex = normalizeHexColor($hex);
        $hex = ltrim($hex, '#');
        $strengthPercent = max(50, min(150, (int)$strengthPercent));

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        if ($strengthPercent < 100) {
            $factor = $strengthPercent / 100;
            $r = (int)round($r * $factor);
            $g = (int)round($g * $factor);
            $b = (int)round($b * $factor);
        } elseif ($strengthPercent > 100) {
            $factor = ($strengthPercent - 100) / 50;
            $r = (int)round($r + (255 - $r) * $factor);
            $g = (int)round($g + (255 - $g) * $factor);
            $b = (int)round($b + (255 - $b) * $factor);
        }

        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }

    $site_primary_base = normalizeHexColor($branding['site_color'] ?? '#2563eb', '#2563eb');
    $site_bg = normalizeHexColor($branding['site_bg_color'] ?? '#0f172a', '#0f172a');
    $color_strength = max(50, min(150, (int)($branding['site_color_strength'] ?? 100)));
    $derived_secondary = adjustHexBrightness($site_primary_base, 32);
    $site_secondary_base = normalizeHexColor($branding['site_secondary_color'] ?? '', $derived_secondary);
    $site_primary = applyHexStrength($site_primary_base, $color_strength);
    $site_secondary = applyHexStrength($site_secondary_base, $color_strength);
    $site_primary_hover = adjustHexBrightness($site_primary, -18);
    $primary_rgb = hexToRgb($site_primary);
    $secondary_rgb = hexToRgb($site_secondary);
    ?>
    <style>
        :root {
            --site-primary: <?php echo $site_primary; ?>;
            --site-primary-hover: <?php echo $site_primary_hover; ?>;
            --site-secondary: <?php echo $site_secondary; ?>;
            --site-primary-rgb: <?php echo $primary_rgb; ?>;
            --site-secondary-rgb: <?php echo $secondary_rgb; ?>;
            --site-bg: <?php echo $site_bg; ?>;

            --primary-color: var(--site-primary);
            --primary: var(--site-primary);
            --primary-hover: var(--site-primary-hover);
            --primary-rgb: <?php echo $primary_rgb; ?>;
            --secondary-color: var(--site-secondary);
            --secondary: var(--site-secondary);
            --bg-dark: var(--site-bg);
        }
        body {
            background-color: var(--site-bg);
            background-image: radial-gradient(circle at top right, rgba(var(--site-primary-rgb), 0.15), transparent),
                              radial-gradient(circle at bottom left, rgba(var(--site-secondary-rgb), 0.1), transparent);
            background-attachment: fixed;
        }
    </style>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/custom-modals.css">
    <?php if ($page_css): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($page_css); ?>">
    <?php endif; ?>
    
    <!-- Custom Modal System -->
    <script src="assets/custom-modals.js"></script>
    <script>
        window.SCHEDULER_CONFIG = <?php echo json_encode([
            'ENVIRONMENT' => scheduler_config('app.environment', 'development'),
            'AI_BASE_URL' => scheduler_browser_ai_base_url(),
            'AI_PROXY_URL' => 'api/ai_proxy.php',
            'APP_BASE_URL' => scheduler_public_base_url(),
        ], JSON_UNESCAPED_SLASHES); ?>;
    </script>
</head>
<body>

<div class="app-container">
    <?php if (basename($_SERVER['PHP_SELF']) != 'login.php'): ?>
    <!-- Mobile Menu Toggle -->
    <div style="display: none; position: fixed; top: 1rem; right: 1rem; z-index: 1001; background: var(--primary-color); border: none; color: white; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-size: 1.2rem;" id="mobileMenuToggle">
        <i class="fa-solid fa-bars"></i>
    </div>
    
    <!-- Sidebar -->
    <nav class="sidebar glass-panel" id="navigation">
        <div class="sidebar-brand">
            <h2>
                <?php if ($branding['site_logo']): ?>
                    <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" style="height: 32px; margin-right: 10px; vertical-align: middle;">
                <?php else: ?>
                    <i class="fa-solid fa-calendar-check"></i>
                <?php endif; ?>
                <?php echo htmlspecialchars($branding['site_title']); ?>
            </h2>
            <button id="mobileMenuClose" style="display: none; background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.2rem; position: absolute; right: 1rem; top: 1rem;">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>
        
        <ul class="nav-links" id="navLinks">
            <li class="nav-section-label">Dashboard</li>
            <li class="nav-item">
                <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
            </li>
            
            <?php if ($user_role == 'student' || $user_role == 'lecturer'): ?>
            <li class="nav-item">
                <a href="my_schedule.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my_schedule.php' ? 'active' : ''; ?>"><i class="fa-solid fa-calendar-user"></i> My Schedule</a>
            </li>
            <?php endif; ?>

            <?php if ($user_role == 'student'): ?>
            <li class="nav-item">
                <a href="my_courses.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my_courses.php' ? 'active' : ''; ?>"><i class="fa-solid fa-book"></i> My Courses</a>
            </li>
            <?php endif; ?>

            <?php if ($user_role == 'super_admin' || $user_role == 'faculty_admin'): ?>
            <li class="nav-section-label">Academic Management</li>
            <li class="nav-item">
                <a href="courses.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'courses.php' ? 'active' : ''; ?>"><i class="fa-solid fa-book-open"></i> Courses</a>
            </li>
            <li class="nav-item">
                <a href="rooms.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'rooms.php' ? 'active' : ''; ?>"><i class="fa-solid fa-building"></i> Rooms</a>
            </li>
            <li class="nav-item">
                <a href="special_rooms.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'special_rooms.php' ? 'active' : ''; ?>"><i class="fa-solid fa-door-open"></i> Special Rooms</a>
            </li>
            <li class="nav-item">
                <a href="lecturers.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'lecturers.php' ? 'active' : ''; ?>"><i class="fa-solid fa-chalkboard-user"></i> Lecturers</a>
            </li>
            <li class="nav-item">
                <a href="templates.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'templates.php' ? 'active' : ''; ?>"><i class="fa-solid fa-layer-group"></i> Templates</a>
            </li>
            <li class="nav-item">
                <a href="import_data.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'import_data.php' ? 'active' : ''; ?>"><i class="fa-solid fa-database"></i> Manage Data</a>
            </li>
            
            <li class="nav-section-label">Scheduling & AI</li>
            <li class="nav-item">
                <a href="view_schedule.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'view_schedule.php' ? 'active' : ''; ?>"><i class="fa-solid fa-calendar-days"></i> Master Schedule</a>
            </li>
            <li class="nav-item">
                <a href="schedules.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'schedules.php' ? 'active' : ''; ?>"><i class="fa-solid fa-folder-open"></i> Saved Schedules</a>
            </li>
            <li class="nav-item">
                <a href="generate.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'generate.php' ? 'active' : ''; ?>"><i class="fa-solid fa-microchip"></i> AI Generator</a>
            </li>
            <li class="nav-item">
                <a href="conflicts.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'conflicts.php' ? 'active' : ''; ?>"><i class="fa-solid fa-triangle-exclamation"></i> Conflicts</a>
            </li>
            <li class="nav-item">
                <a href="conflict_resolution.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'conflict_resolution.php' ? 'active' : ''; ?>"><i class="fa-solid fa-wrench"></i> AI Resolution</a>
            </li>
            <li class="nav-item">
                <a href="ai_analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'ai_analytics.php' ? 'active' : ''; ?>"><i class="fa-solid fa-brain"></i> AI Analytics</a>
            </li>
            <li class="nav-item">
                <a href="performance_benchmarking.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'performance_benchmarking.php' ? 'active' : ''; ?>"><i class="fa-solid fa-chart-line"></i> Benchmarking</a>
            </li>
            <?php endif; ?>

            <li class="nav-section-label">Settings & Admin</li>
            <?php if ($user_role == 'super_admin' || $user_role == 'faculty_admin'): ?>
            <li class="nav-item">
                <a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>"><i class="fa-solid fa-users"></i> Users</a>
            </li>
            <li class="nav-item">
                <a href="audit_trail.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'audit_trail.php' ? 'active' : ''; ?>"><i class="fa-solid fa-clipboard-list"></i> Audit Trail</a>
            </li>
            <?php endif; ?>

            <li class="nav-item">
                <a href="settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>"><i class="fa-solid fa-cog"></i> My Settings</a>
            </li>

            <li class="nav-item" style="margin-top: 1rem;">
                <a href="api/auth.php?logout=true" style="color: var(--danger);"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </li>
        </ul>
        
        <div class="user-info" style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 1rem; margin-top: 1rem;">
            <p style="font-size: 0.9rem; font-weight: 600;"><?php echo htmlspecialchars($user_name); ?></p>
            <p style="font-size: 0.8rem; color: var(--text-muted);"><?php echo ucwords(str_replace('_', ' ', $user_role)); ?></p>
        </div>
    </nav>
    
    <div class="main-content">
        <div class="header-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($page_title); ?></h1>
            </div>
        </div>
    <?php endif; ?>

<!-- Mobile Menu Toggle JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mobileMenuClose = document.getElementById('mobileMenuClose');
    const navigation = document.getElementById('navigation');
    const navLinks = document.getElementById('navLinks');
    
    // Show/hide mobile menu button based on screen size
    function updateMenuButton() {
        if (window.innerWidth <= 768) {
            if (mobileMenuToggle) mobileMenuToggle.style.display = 'block';
            if (mobileMenuClose) mobileMenuClose.style.display = 'block';
        } else {
            if (mobileMenuToggle) mobileMenuToggle.style.display = 'none';
            if (mobileMenuClose) mobileMenuClose.style.display = 'none';
            if (navLinks) navLinks.classList.remove('active');
        }
    }
    
    // Toggle menu
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function() {
            if (navLinks) navLinks.classList.add('active');
        });
    }
    
    if (mobileMenuClose) {
        mobileMenuClose.addEventListener('click', function() {
            if (navLinks) navLinks.classList.remove('active');
        });
    }
    
    // Close menu when link is clicked
    if (navLinks) {
        navLinks.addEventListener('click', function(e) {
            if (e.target.tagName === 'A') {
                navLinks.classList.remove('active');
            }
        });
    }
    
    // Update on window resize
    window.addEventListener('resize', updateMenuButton);
    
    // Initial check
    updateMenuButton();
});
</script>