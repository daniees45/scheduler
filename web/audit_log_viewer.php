<?php
/**
 * Audit Log Viewer UI
 * 
 * Displays system audit logs with:
 * - Filtering by user, action, date range
 * - Search functionality
 * - Log export
 * - Admin-only access
 */

session_start();
require_once 'api/db.php';

// Admin access check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('<div class="alert alert-danger" style="margin: 20px;">Access denied. Admin privileges required.</div>');
}

// Initialize audit log table
$conn->query("CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(255),
    action VARCHAR(100),
    resource VARCHAR(255),
    resource_id INT,
    status VARCHAR(50),
    details TEXT,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_time (log_time),
    INDEX idx_status (status)
)");

// Get filter parameters
$action_filter = $_GET['action'] ?? '';
$user_filter = $_GET['user'] ?? '';
$status_filter = $_GET['status'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = [];
$params = [];
$types = '';

if ($action_filter) {
    $where_conditions[] = "action LIKE ?";
    $params[] = '%' . $action_filter . '%';
    $types .= 's';
}

if ($user_filter) {
    $where_conditions[] = "username LIKE ?";
    $params[] = '%' . $user_filter . '%';
    $types .= 's';
}

if ($status_filter) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($from_date) {
    $where_conditions[] = "DATE(log_time) >= ?";
    $params[] = $from_date;
    $types .= 's';
}

if ($to_date) {
    $where_conditions[] = "DATE(log_time) <= ?";
    $params[] = $to_date;
    $types .= 's';
}

// Count total records
$count_query = "SELECT COUNT(*) as total FROM audit_log";
if (!empty($where_conditions)) {
    $count_query .= " WHERE " . implode(" AND ", $where_conditions);
}

$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);

// Get logs
$query = "SELECT * FROM audit_log";
if (!empty($where_conditions)) {
    $query .= " WHERE " . implode(" AND ", $where_conditions);
}
$query .= " ORDER BY log_time DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($query);
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs = $stmt->get_result();

// Get available actions
$actions_query = "SELECT DISTINCT action FROM audit_log ORDER BY action";
$actions = [];
foreach ($conn->query($actions_query) as $row) {
    $actions[] = $row['action'];
}

// Get available users
$users_query = "SELECT DISTINCT username FROM audit_log WHERE username IS NOT NULL ORDER BY username";
$users = [];
foreach ($conn->query($users_query) as $row) {
    $users[] = $row['username'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log Viewer - AI Scheduler</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background-color: #f5f7fa;
            padding: 20px;
        }
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px 8px 0 0;
            padding: 15px;
        }
        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .status-badge {
            font-size: 11px;
            padding: 4px 8px;
        }
        .log-table {
            font-size: 13px;
        }
        .log-table td {
            vertical-align: middle;
        }
        .timestamp {
            color: #6c757d;
            font-size: 12px;
        }
        .action-badge {
            font-size: 11px;
        }
        .details-cell {
            max-width: 300px;
            word-break: break-word;
            font-size: 12px;
        }
        .pagination {
            margin-top: 20px;
            justify-content: center;
        }
        .export-controls {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-8">
                <h1><i class="fas fa-clipboard-list"></i> Audit Log Viewer</h1>
                <p class="text-muted">System activity and access logs</p>
            </div>
            <div class="col-md-4 text-right">
                <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <!-- Filters -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filters & Search</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="filter-section">
                    <div class="row">
                        <div class="col-md-3">
                            <label>User</label>
                            <select name="user" class="form-control form-control-sm">
                                <option value="">All Users</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo htmlspecialchars($u); ?>"
                                        <?php echo ($user_filter === $u) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($u); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Action</label>
                            <select name="action" class="form-control form-control-sm">
                                <option value="">All Actions</option>
                                <?php foreach ($actions as $a): ?>
                                    <option value="<?php echo htmlspecialchars($a); ?>"
                                        <?php echo ($action_filter === $a) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($a); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Status</label>
                            <select name="status" class="form-control form-control-sm">
                                <option value="">All</option>
                                <option value="success" <?php echo ($status_filter === 'success') ? 'selected' : ''; ?>>Success</option>
                                <option value="error" <?php echo ($status_filter === 'error') ? 'selected' : ''; ?>>Error</option>
                                <option value="warning" <?php echo ($status_filter === 'warning') ? 'selected' : ''; ?>>Warning</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>From</label>
                            <input type="date" name="from_date" class="form-control form-control-sm"
                                value="<?php echo htmlspecialchars($from_date); ?>">
                        </div>
                        <div class="col-md-2">
                            <label>To</label>
                            <input type="date" name="to_date" class="form-control form-control-sm"
                                value="<?php echo htmlspecialchars($to_date); ?>">
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="audit_log_viewer.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                            <button type="button" class="btn btn-info btn-sm" onclick="exportToCsv()">
                                <i class="fas fa-download"></i> Export to CSV
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5><strong><?php echo number_format($total_records); ?></strong></h5>
                        <small class="text-muted">Total Records</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5><strong><?php echo count($users); ?></strong></h5>
                        <small class="text-muted">Active Users</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5><strong><?php echo count($actions); ?></strong></h5>
                        <small class="text-muted">Action Types</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5><strong><?php echo $page; ?> / <?php echo $total_pages; ?></strong></h5>
                        <small class="text-muted">Page</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Audit Log Records</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped log-table">
                        <thead class="table-light">
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Resource</th>
                                <th>Status</th>
                                <th>IP Address</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($logs->num_rows === 0): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox"></i> No logs found
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php while ($log = $logs->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <span class="timestamp">
                                            <?php echo date('M d, Y', strtotime($log['log_time'])); ?>
                                            <br>
                                            <?php echo date('H:i:s', strtotime($log['log_time'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge action-badge bg-info">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($log['resource'] ?? 'N/A'); ?>
                                        <?php if ($log['resource_id']): ?>
                                            <small class="text-muted">#<?php echo $log['resource_id']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $status_class = 'success';
                                        if ($log['status'] === 'error') $status_class = 'danger';
                                        elseif ($log['status'] === 'warning') $status_class = 'warning';
                                        elseif ($log['status'] === 'info') $status_class = 'info';
                                        ?>
                                        <span class="badge status-badge bg-<?php echo $status_class; ?>">
                                            <?php echo ucfirst($log['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="details-cell" title="<?php echo htmlspecialchars($log['details'] ?? ''); ?>">
                                            <?php 
                                            $details = $log['details'] ?? '';
                                            echo htmlspecialchars(substr($details, 0, 60));
                                            if (strlen($details) > 60) echo '...';
                                            ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav>
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=1<?php echo http_build_query(array_filter(['action' => $action_filter, 'user' => $user_filter, 'status' => $status_filter, 'from_date' => $from_date, 'to_date' => $to_date])); ?>">
                                    First
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo http_build_query(array_filter(['action' => $action_filter, 'user' => $user_filter, 'status' => $status_filter, 'from_date' => $from_date, 'to_date' => $to_date])); ?>">
                                    Previous
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php 
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++): 
                        ?>
                            <?php if ($i === $page): ?>
                                <li class="page-item active">
                                    <span class="page-link"><?php echo $i; ?></span>
                                </li>
                            <?php else: ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo http_build_query(array_filter(['action' => $action_filter, 'user' => $user_filter, 'status' => $status_filter, 'from_date' => $from_date, 'to_date' => $to_date])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo http_build_query(array_filter(['action' => $action_filter, 'user' => $user_filter, 'status' => $status_filter, 'from_date' => $from_date, 'to_date' => $to_date])); ?>">
                                    Next
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo http_build_query(array_filter(['action' => $action_filter, 'user' => $user_filter, 'status' => $status_filter, 'from_date' => $from_date, 'to_date' => $to_date])); ?>">
                                    Last
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function exportToCsv() {
            // Build query string with current filters
            const params = new URLSearchParams({
                action: '<?php echo addslashes($action_filter); ?>',
                user: '<?php echo addslashes($user_filter); ?>',
                status: '<?php echo addslashes($status_filter); ?>',
                from_date: '<?php echo addslashes($from_date); ?>',
                to_date: '<?php echo addslashes($to_date); ?>',
                export: 'csv'
            });
            
            window.location.href = 'audit_log_viewer.php?' + params.toString();
        }
    </script>
</body>
</html>
