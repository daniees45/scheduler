<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RBAC Implementation Test - VVU Scheduler</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2rem;
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            color: #667eea;
            margin-bottom: 2rem;
            text-align: center;
        }
        h2 {
            color: #555;
            margin: 2rem 0 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #667eea;
        }
        .test-section {
            background: #f8f9fa;
            padding: 1.5rem;
            margin: 1rem 0;
            border-radius: 0.5rem;
            border-left: 4px solid #667eea;
        }
        .status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-weight: 600;
            font-size: 0.9rem;
            margin-left: 1rem;
        }
        .status.pass { background: #d4edda; color: #155724; }
        .status.fail { background: #f8d7da; color: #721c24; }
        .status.pending { background: #fff3cd; color: #856404; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        th {
            background: #667eea;
            color: white;
            font-weight: 600;
        }
        tr:hover { background: #f8f9fa; }
        .code {
            background: #f8f9fa;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            color: #e83e8c;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 0.25rem;
        }
        .success-box {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 0.25rem;
        }
        .error-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 0.25rem;
        }
        ul { margin-left: 2rem; line-height: 1.8; }
        .file-list { list-style: none; margin-left: 0; }
        .file-list li { padding: 0.5rem; margin: 0.25rem 0; background: white; border-radius: 0.25rem; }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 0.5rem;
            margin: 0.5rem 0.5rem 0.5rem 0;
            transition: all 0.3s;
        }
        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎓 RBAC Implementation Test Page</h1>
        
        <div class="info-box">
            <strong>📋 System Status:</strong> Testing Role-Based Access Control Implementation
        </div>

        <?php
        session_start();
        require_once 'api/db.php';

        $tests = [];
        $all_pass = true;

        // Test 1: Database Connection
        $test_db = false;
        try {
            $result = $conn->query("SELECT 1");
            $test_db = $result !== false;
        } catch (Exception $e) {
            $test_db = false;
        }
        $tests[] = ['name' => 'Database Connection', 'status' => $test_db];
        $all_pass = $all_pass && $test_db;

        // Test 2: Check users table columns
        $cols_exist = false;
        try {
            $result = $conn->query("DESCRIBE users");
            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            $cols_exist = in_array('department', $columns) && in_array('level', $columns) && in_array('lecturer_id', $columns);
        } catch (Exception $e) {
            $cols_exist = false;
        }
        $tests[] = ['name' => 'Users Table - RBAC Columns', 'status' => $cols_exist];
        $all_pass = $all_pass && $cols_exist;

        // Test 3: Check student_enrollments table
        $enroll_table = false;
        try {
            $result = $conn->query("SHOW TABLES LIKE 'student_enrollments'");
            $enroll_table = $result->num_rows > 0;
        } catch (Exception $e) {
            $enroll_table = false;
        }
        $tests[] = ['name' => 'Student Enrollments Table', 'status' => $enroll_table];
        $all_pass = $all_pass && $enroll_table;

        // Test 4: Check lecturers table department column
        $lect_dept = false;
        try {
            $result = $conn->query("DESCRIBE lecturers");
            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            $lect_dept = in_array('department', $columns);
        } catch (Exception $e) {
            $lect_dept = false;
        }
        $tests[] = ['name' => 'Lecturers Table - Department Column', 'status' => $lect_dept];
        $all_pass = $all_pass && $lect_dept;

        // Test 5: Check files exist
        $files_check = [
            'student_dashboard.php' => file_exists('student_dashboard.php'),
            'my_courses.php' => file_exists('my_courses.php'),
            'api/enrollment.php' => file_exists('api/enrollment.php'),
            'lecturer_dashboard.php' => file_exists('lecturer_dashboard.php'),
        ];
        $files_exist = !in_array(false, $files_check);
        $tests[] = ['name' => 'Required Files Present', 'status' => $files_exist];
        $all_pass = $all_pass && $files_exist;

        // Test 6: Check access control function
        $access_func = function_exists('requireRole');
        $tests[] = ['name' => 'Access Control Functions', 'status' => $access_func];
        $all_pass = $all_pass && $access_func;
        ?>

        <h2>🧪 Test Results</h2>
        
        <?php if ($all_pass): ?>
            <div class="success-box">
                <strong>✅ All Tests Passed!</strong> RBAC implementation is ready to use.
            </div>
        <?php else: ?>
            <div class="error-box">
                <strong>❌ Some Tests Failed</strong> - Please check the issues below and run the database migration.
            </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Test Name</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tests as $test): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($test['name']); ?></td>
                        <td>
                            <span class="status <?php echo $test['status'] ? 'pass' : 'fail'; ?>">
                                <?php echo $test['status'] ? '✅ PASS' : '❌ FAIL'; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>📊 Database Statistics</h2>
        <div class="test-section">
            <?php
            try {
                $stat = $conn->query("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as students,
                        SUM(CASE WHEN role = 'lecturer' THEN 1 ELSE 0 END) as lecturers,
                        SUM(CASE WHEN role IN ('super_admin', 'faculty_admin') THEN 1 ELSE 0 END) as admins
                    FROM users
                ")->fetch_assoc();
                
                echo "<p><strong>Total Users:</strong> " . $stat['total'] . "</p>";
                echo "<p><strong>Students:</strong> " . $stat['students'] . "</p>";
                echo "<p><strong>Lecturers:</strong> " . $stat['lecturers'] . "</p>";
                echo "<p><strong>Admins:</strong> " . $stat['admins'] . "</p>";

                if ($enroll_table) {
                    $enroll_count = $conn->query("SELECT COUNT(*) as cnt FROM student_enrollments")->fetch_assoc()['cnt'];
                    echo "<p><strong>Total Enrollments:</strong> " . $enroll_count . "</p>";
                }
            } catch (Exception $e) {
                echo "<p class='error-box'>Error fetching stats: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            ?>
        </div>

        <h2>📁 Implementation Files</h2>
        <div class="test-section">
            <h3>New Files Created:</h3>
            <ul class="file-list">
                <?php foreach ($files_check as $file => $exists): ?>
                    <li>
                        <?php echo $exists ? '✅' : '❌'; ?> 
                        <span class="code"><?php echo htmlspecialchars($file); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <h3>Modified Files:</h3>
            <ul class="file-list">
                <li>✓ <span class="code">dashboard.php</span> - Role routing</li>
                <li>✓ <span class="code">login.php</span> - Department/level fields</li>
                <li>✓ <span class="code">api/auth.php</span> - Session data</li>
                <li>✓ <span class="code">api/register_public.php</span> - Validation</li>
                <li>✓ <span class="code">view_schedule.php</span> - Auto-filtering</li>
                <li>✓ <span class="code">courses.php</span> - Access control</li>
                <li>✓ <span class="code">rooms.php</span> - Access control</li>
                <li>✓ <span class="code">lecturers.php</span> - Access control</li>
            </ul>
        </div>

        <h2>🚀 Quick Actions</h2>
        <div class="test-section">
            <?php if (!$cols_exist || !$enroll_table): ?>
                <div class="error-box">
                    <strong>⚠️ Database Migration Required!</strong><br>
                    Run this command in terminal:<br>
                    <code style="display: block; margin: 1rem 0; padding: 1rem; background: #f8f9fa; border-radius: 0.25rem;">
                        mysql -uroot -p vvu_scheduler &lt; web/db/setup_rbac_safe.sql
                    </code>
                    Or use the migrate_rbac.sql file if you prefer.
                </div>
            <?php endif; ?>

            <a href="login.php" class="btn">🔐 Go to Login</a>
            <a href="dashboard.php" class="btn">📊 Dashboard</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="api/auth.php?logout=true" class="btn" style="background: #dc3545;">🚪 Logout</a>
            <?php endif; ?>
        </div>

        <h2>📖 Documentation</h2>
        <div class="test-section">
            <p>Read the complete implementation guide:</p>
            <a href="../RBAC_IMPLEMENTATION_COMPLETE.md" class="btn">📄 View Full Documentation</a>
        </div>

        <h2>✨ Features Overview</h2>
        <div class="test-section">
            <h3>For Students:</h3>
            <ul>
                <li>Personalized dashboard with today's classes</li>
                <li>Course enrollment system</li>
                <li>Auto-filtered schedule (only enrolled courses)</li>
                <li>Department and level-based course browsing</li>
            </ul>

            <h3>For Lecturers:</h3>
            <ul>
                <li>Teaching dashboard with assigned courses</li>
                <li>Student enrollment counts</li>
                <li>Auto-filtered schedule (only teaching assignments)</li>
                <li>Quick access to availability management</li>
            </ul>

            <h3>For Admins:</h3>
            <ul>
                <li>Full system access maintained</li>
                <li>User management with departments/levels</li>
                <li>Protected admin pages</li>
                <li>No auto-filtering on schedules</li>
            </ul>
        </div>

        <?php if (isset($_SESSION['user_id'])): ?>
            <h2>👤 Current Session</h2>
            <div class="test-section">
                <p><strong>User:</strong> <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?></p>
                <p><strong>Role:</strong> <?php echo htmlspecialchars($_SESSION['role'] ?? 'Unknown'); ?></p>
                <p><strong>Department:</strong> <?php echo htmlspecialchars($_SESSION['department'] ?? 'Not Set'); ?></p>
                <?php if ($_SESSION['role'] === 'student'): ?>
                    <p><strong>Level:</strong> <?php echo htmlspecialchars($_SESSION['level'] ?? 'Not Set'); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>
