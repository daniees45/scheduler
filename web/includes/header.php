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
$current_dir = basename(str_replace('\\', '/', dirname((string)($_SERVER['PHP_SELF'] ?? ''))));
$asset_prefix = ($current_dir === 'admin') ? '../assets' : 'assets';
$route_prefix = ($current_dir === 'admin') ? '../' : '';
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
    $site_text = normalizeHexColor($branding['site_text_color'] ?? '#f8fafc', '#f8fafc');
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
            --site-text: <?php echo $site_text; ?>;

            --primary-color: var(--site-primary);
            --primary: var(--site-primary);
            --primary-hover: var(--site-primary-hover);
            --primary-rgb: <?php echo $primary_rgb; ?>;
            --secondary-color: var(--site-secondary);
            --secondary: var(--site-secondary);
            --bg-dark: var(--site-bg);
            --text-main: var(--site-text);
        }
        body {
            background-color: var(--site-bg);
            color: var(--site-text);
            background-image: radial-gradient(circle at top right, rgba(var(--site-primary-rgb), 0.15), transparent),
                              radial-gradient(circle at bottom left, rgba(var(--site-secondary-rgb), 0.1), transparent);
            background-attachment: fixed;
        }
    </style>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet"></noscript>

    <!-- Icons -->
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>

    <!-- Custom CSS -->
    <link rel="preload" as="style" href="<?php echo htmlspecialchars($asset_prefix . '/style.css'); ?>" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars($asset_prefix . '/style.css'); ?>"></noscript>
    <link rel="preload" as="style" href="<?php echo htmlspecialchars($asset_prefix . '/custom-modals.css'); ?>" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars($asset_prefix . '/custom-modals.css'); ?>"></noscript>
    <?php if ($page_css): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($page_css); ?>">
    <?php endif; ?>
    
    <!-- Page loader -->
    <style>
        #page-loader {
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: var(--site-bg, #0f172a);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1.25rem;
            transition: opacity 0.35s ease, visibility 0.35s ease;
        }
        #page-loader.loader-hidden {
            opacity: 0;
            visibility: hidden;
        }
        .page-loader-ring {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.08);
            border-top-color: var(--primary-color, #6366f1);
            animation: page-loader-spin 0.75s linear infinite;
        }
        @keyframes page-loader-spin {
            to { transform: rotate(360deg); }
        }
        .page-loader-label {
            font-size: 0.82rem;
            color: rgba(255, 255, 255, 0.35);
            letter-spacing: 0.06em;
            font-family: 'Inter', sans-serif;
        }
        .page-loader-logo {
            max-height: 52px;
            max-width: 160px;
            width: auto;
            height: auto;
            object-fit: contain;
            opacity: 0.9;
            margin-bottom: 0.25rem;
        }
    </style>

    <!-- Custom Modal System -->
    <script defer src="<?php echo htmlspecialchars($asset_prefix . '/custom-modals.js'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/custom-modals.js'); ?>"></script>
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

<div id="page-loader" aria-hidden="true">
    <?php if (!empty($branding['site_logo'])): ?>
    <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="" class="page-loader-logo" aria-hidden="true">
    <?php endif; ?>
    <div class="page-loader-ring"></div>
    <span class="page-loader-label">Loading&hellip;</span>
</div>
<script>
    function hidePageLoader() {
        var loader = document.getElementById('page-loader');
        if (loader) {
            loader.classList.add('loader-hidden');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        window.requestAnimationFrame(hidePageLoader);
    });

    // Safety fallback so overlay never blocks first paint for long.
    window.setTimeout(hidePageLoader, 900);

    window.addEventListener('load', hidePageLoader);
</script>

<div class="app-container">
    <?php if (basename($_SERVER['PHP_SELF']) != 'login.php'): ?>
    <!-- Mobile Menu Toggle -->
    <div style="display: none; position: fixed; top: 1rem; right: 1rem; z-index: 1001; background: var(--primary-color); border: none; color: white; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-size: 1.2rem;" id="mobileMenuToggle">
        <i class="fa-solid fa-bars"></i>
    </div>
    
    <!-- Sidebar -->
    <nav class="sidebar glass-panel" id="navigation">
        <div class="sidebar-brand">
            <div class="sidebar-brand-inner">
                <?php if (!empty($branding['site_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>"
                         alt="<?php echo htmlspecialchars($branding['site_title']); ?> logo"
                         class="sidebar-logo">
                <?php else: ?>
                    <span class="sidebar-brand-icon"><i class="fa-solid fa-calendar-check"></i></span>
                <?php endif; ?>
                <span class="sidebar-brand-title"><?php echo htmlspecialchars($branding['site_title']); ?></span>
            </div>
            <button id="mobileMenuClose" style="display: none; background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.2rem; position: absolute; right: 1rem; top: 1rem;">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>

        
        <ul class="nav-links" id="navLinks">
            <li class="nav-section-label">Home</li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . '../index.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>"><i class="fa-solid fa-chart-pie"></i> Home</a>
            </li>
            <li class="nav-section-label">Dashboard</li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'dashboard.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
            </li>
            
            <?php if ($user_role == 'student' || $user_role == 'lecturer'): ?>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'my_schedule.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my_schedule.php' ? 'active' : ''; ?>"><i class="fa-solid fa-calendar-user"></i> My Schedule</a>
            </li>
            <?php endif; ?>

            <?php if ($user_role == 'student'): ?>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'my_courses.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my_courses.php' ? 'active' : ''; ?>"><i class="fa-solid fa-book"></i> My Courses</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'my_feedback.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my_feedback.php' ? 'active' : ''; ?>"><i class="fa-solid fa-comments"></i> My Feedback</a>
            </li>
            <?php endif; ?>

            <?php if ($user_role == 'super_admin' || $user_role == 'faculty_admin'): ?>
            <li class="nav-section-label">Academic Management</li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'courses.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'courses.php' ? 'active' : ''; ?>"><i class="fa-solid fa-book-open"></i> Courses</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'departments.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'departments.php' ? 'active' : ''; ?>"><i class="fa-solid fa-book-open"></i> Department</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'rooms.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'rooms.php' ? 'active' : ''; ?>"><i class="fa-solid fa-building"></i> Rooms</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'special_rooms.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'special_rooms.php' ? 'active' : ''; ?>"><i class="fa-solid fa-door-open"></i> Special Rooms</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'lecturers.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'lecturers.php' ? 'active' : ''; ?>"><i class="fa-solid fa-chalkboard-user"></i> Lecturers</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'templates.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'templates.php' ? 'active' : ''; ?>"><i class="fa-solid fa-layer-group"></i> Templates</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'admin/feedback_admin.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'feedback_admin.php' ? 'active' : ''; ?>"><i class="fa-solid fa-comments"></i> Feedback Admin</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'import_data.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'import_data.php' ? 'active' : ''; ?>"><i class="fa-solid fa-database"></i> Manage Data</a>
            </li>
            
            <li class="nav-section-label">Scheduling & AI</li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'view_schedule.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'view_schedule.php' ? 'active' : ''; ?>"><i class="fa-solid fa-calendar-days"></i> Master Schedule</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'schedules.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'schedules.php' ? 'active' : ''; ?>"><i class="fa-solid fa-folder-open"></i> Saved Schedules</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'generate.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'generate.php' ? 'active' : ''; ?>"><i class="fa-solid fa-microchip"></i> AI Generator</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'ai_training.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'ai_training.php' ? 'active' : ''; ?>"><i class="fa-solid fa-robot"></i> AI Model Training</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'conflicts.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'conflicts.php' ? 'active' : ''; ?>"><i class="fa-solid fa-triangle-exclamation"></i> Conflicts</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'conflict_resolution.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'conflict_resolution.php' ? 'active' : ''; ?>"><i class="fa-solid fa-wrench"></i> AI Resolution</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'ai_analytics.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'ai_analytics.php' ? 'active' : ''; ?>"><i class="fa-solid fa-brain"></i> AI Analytics</a>
            </li>
            <!-- <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'performance_benchmarking.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'performance_benchmarking.php' ? 'active' : ''; ?>"><i class="fa-solid fa-chart-line"></i> Benchmarking</a>
            </li> -->
            <?php endif; ?>

            <li class="nav-section-label">Settings & Admin</li>
            <?php if ($user_role == 'super_admin' || $user_role == 'faculty_admin'): ?>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'users.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>"><i class="fa-solid fa-users"></i> Users</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'audit_trail.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'audit_trail.php' ? 'active' : ''; ?>"><i class="fa-solid fa-clipboard-list"></i> Audit Trail</a>
            </li>
            <?php endif; ?>


            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'feedback.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'feedback.php' ? 'active' : ''; ?>"><i class="fa-solid fa-comment-dots"></i> Report Clash</a>
            </li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars($route_prefix . 'settings.php'); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>"><i class="fa-solid fa-cog"></i> <?php echo ($user_role == 'super_admin' || $user_role == 'faculty_admin') ? 'Site Settings' : 'My Settings'; ?></a>
            </li>

            <li class="nav-item" style="margin-top: 1rem;">
                <a href="<?php echo htmlspecialchars($route_prefix . 'api/auth.php?logout=true'); ?>" style="color: var(--danger);"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
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
            if (navLinks) {
                const isOpen = navLinks.classList.toggle('active');
                mobileMenuToggle.innerHTML = isOpen
                    ? '<i class="fa-solid fa-times"></i>'
                    : '<i class="fa-solid fa-bars"></i>';
            }
        });
    }
    
    if (mobileMenuClose) {
        mobileMenuClose.addEventListener('click', function() {
            if (navLinks) navLinks.classList.remove('active');
            if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
        });
    }
    
    // Close menu when link is clicked
    if (navLinks) {
        navLinks.addEventListener('click', function(e) {
            if (e.target.closest('a')) {
                navLinks.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
            }
        });
    }
    
    // Update on window resize
    window.addEventListener('resize', updateMenuButton);
    
    // Initial check
    updateMenuButton();
});
</script>