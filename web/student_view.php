<?php
$page_title = 'Student Schedule View';
// Custom header for students (simplified, no sidebar)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Schedule - VVU Scheduler</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/style.css">

</head>
<body>

<div class="student-container">
    <div class="header">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #6366f1, #a855f7); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                <i class="fa-solid fa-graduation-cap" style="font-size: 1.5rem; color: white;"></i>
            </div>
            <div>
                <h1 style="font-size: 1.5rem; font-weight: 700; margin: 0;">VVU Student Portal</h1>
                <p style="color: #94a3b8; margin: 0; font-size: 0.9rem;">View your semester timetable</p>
            </div>
        </div>
        <div>
            <a href="login.php" class="glass-btn secondary small"><i class="fa-solid fa-lock"></i> Staff Login</a>
        </div>
    </div>

    <?php
    $csv_file = realpath('../') . '/csv/final/final_web_schedule.csv';
    $data = [];
    if (file_exists($csv_file) && ($handle = fopen($csv_file, "r")) !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $data[] = [
                'code' => $row[0] ?? '',
                'title' => $row[1] ?? '',
                'level' => $row[9] ?? '100', // Assuming level is col 9 based on typical structure, or need to verify from sync.php. 
                // Wait, view_schedule.php uses numeric indices. Let's re-verify sync.php export order.
                // sync.php: fputcsv($fp, [$row['course_code'], $row['course_title'], $row['credit_hours'], $row['lecturer_name'], $row['room_name'], $row['assigned_day'], $start_time . ' - ' . $end_time, $row['stream']??'Main', $row['semester'], $row['level']]);
                // Indices: 0:Code, 1:Title, 2:Credits, 3:Lecturer, 4:Room, 5:Day, 6:Time, 7:Stream, 8:Sem, 9:Level
                'lecturer' => $row[3] ?? '',
                'room' => $row[4] ?? '',
                'day' => $row[5] ?? '',
                'time' => $row[6] ?? ''
            ];
        }
        fclose($handle);
    }

    $filter_level = $_GET['level'] ?? '';
    $filter_dept = $_GET['dept'] ?? ''; // Filter by code prefix (e.g. COSC)

    if ($filter_level) {
        $data = array_filter($data, function($item) use ($filter_level) {
            return $item['level'] == $filter_level; // Strict check? 
            // Often levels are just 100, 200.
            return stripos($item['level'], $filter_level) !== false;
        });
    }
    if ($filter_dept) {
        $data = array_filter($data, function($item) use ($filter_dept) {
            return stripos($item['code'], $filter_dept) === 0; // Starts with
        });
    }
    ?>

    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <form method="GET" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 1rem;">
            <div>
                <label style="display: block; margin-bottom: 0.5rem; color: #94a3b8;">Department (Course Code)</label>
                <select name="dept" class="glass-input" style="background: rgba(15, 23, 42, 0.8); color: white; width: 100%; padding: 10px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                    <option value="">All Departments</option>
                    <option value="COSC" <?php if($filter_dept == 'COSC') echo 'selected'; ?>>Computer Science (COSC)</option>
                    <option value="NURS" <?php if($filter_dept == 'NURS') echo 'selected'; ?>>Nursing (NURS)</option>
                    <option value="THEO" <?php if($filter_dept == 'THEO') echo 'selected'; ?>>Theology (THEO)</option>
                    <option value="MATH" <?php if($filter_dept == 'MATH') echo 'selected'; ?>>Mathematics (MATH)</option>
                    <option value="GNED" <?php if($filter_dept == 'GNED') echo 'selected'; ?>>General Education (GNED)</option>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 0.5rem; color: #94a3b8;">Level</label>
                <select name="level" class="glass-input" style="background: rgba(15, 23, 42, 0.8); color: white; width: 100%; padding: 10px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                    <option value="">All Levels</option>
                    <option value="100" <?php if($filter_level == '100') echo 'selected'; ?>>Level 100</option>
                    <option value="200" <?php if($filter_level == '200') echo 'selected'; ?>>Level 200</option>
                    <option value="300" <?php if($filter_level == '300') echo 'selected'; ?>>Level 300</option>
                    <option value="400" <?php if($filter_level == '400') echo 'selected'; ?>>Level 400</option>
                </select>
            </div>
            <div style="display: flex; align-items: flex-end;">
                <button type="submit" class="glass-btn primary" style="padding: 10px 20px; background: #6366f1; color: white; border: none; border-radius: 8px; cursor: pointer;">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <div class="glass-panel" style="padding: 0;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: rgba(255,255,255,0.05); text-align: left;">
                    <th style="padding: 1rem;">Day & Time</th>
                    <th style="padding: 1rem;">Course</th>
                    <th style="padding: 1rem;">Room</th>
                    <th style="padding: 1rem;">Lecturer</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($data)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 2rem; color: #94a3b8;">No classes found.</td></tr>
                <?php else: 
                     $days = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5];
                     usort($data, function($a, $b) use ($days) {
                        $da = $days[$a['day']] ?? 99;
                        $db = $days[$b['day']] ?? 99;
                        if ($da != $db) return $da - $db;
                        return strcmp($a['time'], $b['time']);
                     });
                ?>
                    <?php foreach ($data as $row): ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <td style="padding: 1rem;">
                            <div style="font-weight: 600; color: #818cf8;"><?php echo htmlspecialchars($row['day']); ?></div>
                            <div style="font-size: 0.9rem; color: #fbbf24; font-family: monospace;"><?php echo htmlspecialchars($row['time']); ?></div>
                        </td>
                        <td style="padding: 1rem;">
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($row['code']); ?></div>
                            <div style="font-size: 0.9rem; color: #94a3b8;"><?php echo htmlspecialchars($row['title']); ?></div>
                        </td>
                        <td style="padding: 1rem;">
                            <span style="background: rgba(255,255,255,0.1); padding: 4px 8px; border-radius: 4px; font-size: 0.9rem;">
                                <?php echo htmlspecialchars($row['room']); ?>
                            </span>
                        </td>
                        <td style="padding: 1rem; color: #cbd5e1;"><?php echo htmlspecialchars($row['lecturer']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="text-align: center; margin-top: 2rem; color: #64748b; font-size: 0.9rem;">
        &copy; <?php echo date('Y'); ?> Valley View University Scheduling System
    </div>
</div>

</body>
</html>
