<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include access control functions
require_once __DIR__ . '/access_control.php';

// Redirect if not logged in (unless on login page)
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'login.php' && basename($_SERVER['PHP_SELF']) != 'install.php') {
    header("Location: login.php");
    exit;
}

$page_title = isset($page_title) ? $page_title : 'VVU Scheduler';
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
    <title><?php echo htmlspecialchars($page_title); ?> - VVU Scheduler</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="app-container">
    <?php if (basename($_SERVER['PHP_SELF']) != 'login.php'): ?>
    <!-- Sidebar -->
    <nav class="sidebar glass-panel">
        <div class="sidebar-brand">
            <h2><i class="fa-solid fa-calendar-check"></i> Scheduler AI</h2>
        </div>
        
        <ul class="nav-links">
            <li class="nav-item">
                <a href="dashboard.php"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
            </li>
            
            <?php if ($user_role == 'super_admin' || $user_role == 'faculty_admin'): ?>
            <li class="nav-item">
                <a href="courses.php"><i class="fa-solid fa-book-open"></i> Courses</a>
            </li>
            <li class="nav-item">
                <a href="rooms.php"><i class="fa-solid fa-building"></i> Rooms</a>
            </li>
            <li class="nav-item">
                <a href="special_rooms.php"><i class="fa-solid fa-door-open"></i> Special Rooms</a>
            </li>
            <li class="nav-item">
                <a href="lecturers.php"><i class="fa-solid fa-chalkboard-user"></i> Lecturers</a>
            </li>
            <?php endif; ?>

            <?php if ($user_role == 'student'): ?>
            <li class="nav-item">
                <a href="my_schedule.php"><i class="fa-solid fa-calendar-user"></i> My Schedule</a>
            </li>
            <li class="nav-item">
                <a href="my_courses.php"><i class="fa-solid fa-book"></i> My Courses</a>
            </li>
            <?php endif; ?>
            
            <?php if ($user_role == 'lecturer'): ?>
            <li class="nav-item">
                <a href="my_schedule.php"><i class="fa-solid fa-calendar-user"></i> My Schedule</a>
            </li>
            <?php endif; ?>
            
            <?php if ($user_role == 'super_admin' || $user_role == 'faculty_admin'): ?>
            <li class="nav-item">
                <a href="view_schedule.php"><i class="fa-solid fa-calendar-days"></i> Schedule</a>
            </li>
            <li class="nav-item">
                <a href="schedules.php"><i class="fa-solid fa-folder-open"></i> Generated Schedules</a>
            </li>
            <?php endif; ?>
            <?php if ($user_role == 'super_admin' || $user_role == 'faculty_admin'): ?>
            <li class="nav-item">
                <a href="conflicts.php"><i class="fa-solid fa-triangle-exclamation"></i> Conflicts</a>
            </li>
            <?php endif; ?>

            <?php if ($user_role == 'super_admin' || $user_role == 'faculty_admin'): ?>
             <li class="nav-item">
                <a href="generate.php"><i class="fa-solid fa-microchip"></i> AI Generator</a>
            </li>
             <li class="nav-item">
                <a href="users.php"><i class="fa-solid fa-users"></i> Users</a>
            </li>
            <li class="nav-item">
                <a href="import_data.php"><i class="fa-solid fa-database"></i> Manage Data</a>
            </li>
            <li class="nav-item">
                <a href="pdf_to_csv.php"><i class="fa-solid fa-wand-magic-sparkles"></i> PDF to CSV</a>
            </li>
            <?php endif; ?>

            <li class="nav-item" style="margin-top: auto;">
                <a href="api/auth.php?logout=true"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
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
            <!-- Header Actions -->
            <div style="display: flex; gap: 10px;">
                <!-- <button class="glass-btn secondary"><i class="fa-solid fa-bell"></i></button> -->
            </div>
        </div>
    <?php endif; ?>
