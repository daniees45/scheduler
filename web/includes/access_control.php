<?php
// as/includes/access_control.php
// Role-Based Access Control Helper Functions

/**
 * Require user to have one of the specified roles
 * Redirects to login if not authenticated, or dashboard if unauthorized
 */
function requireRole($allowed_roles) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php?error=" . urlencode("Please login first"));
        exit;
    }
    
    $current_role = $_SESSION['role'] ?? 'guest';
    if (!in_array($current_role, $allowed_roles)) {
        header("Location: dashboard.php?error=" . urlencode("Access Denied: Insufficient permissions"));
        exit;
    }
}

/**
 * Check if user can access a specific department's data
 * Super admins can access all departments
 * Faculty admins can only access their own department
 */
function canAccessDepartment($target_dept) {
    if ($_SESSION['role'] === 'super_admin') {
        return true;
    }
    
    if ($_SESSION['role'] === 'faculty_admin') {
        return isset($_SESSION['department']) && $_SESSION['department'] === $target_dept;
    }
    
    return false;
}

/**
 * Check if current user has admin privileges
 */
function isAdminRole() {
    return in_array($_SESSION['role'] ?? '', ['super_admin', 'faculty_admin']);
}

/**
 * Check if current user is a super admin
 */
function isSuperAdmin() {
    return ($_SESSION['role'] ?? '') === 'super_admin';
}

/**
 * Get department filter for SQL queries
 * Returns empty string for super_admin, department filter for faculty_admin
 */
function getDepartmentFilter($table_alias = '') {
    if ($_SESSION['role'] === 'super_admin') {
        return '';
    }
    
    if ($_SESSION['role'] === 'faculty_admin' && isset($_SESSION['department'])) {
        $prefix = $table_alias ? $table_alias . '.' : '';
        return " AND {$prefix}department = '" . $_SESSION['department'] . "'";
    }
    
    return '';
}

/**
 * Block non-admin users from accessing admin features
 */
function requireAdmin() {
    requireRole(['super_admin', 'faculty_admin']);
}

/**
 * Get user's full name for display
 */
function getUserDisplayName() {
    return $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
}

/**
 * Check if user can edit a specific resource based on department
 */
function canEdit($resource_department) {
    if ($_SESSION['role'] === 'super_admin') {
        return true;
    }
    
    if ($_SESSION['role'] === 'faculty_admin') {
        return $_SESSION['department'] === $resource_department;
    }
    
    return false;
}
?>
