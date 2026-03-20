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
    <link rel="stylesheet" href="assets/student_view.css">

</head>

<body>

    <div class="student-container">
        <div class="header">
            <div class="student-view-branding">
                <div class="student-view-brand-icon-wrap">
                    <i class="fa-solid fa-graduation-cap student-view-brand-icon"></i>
                </div>
                <div>
                    <h1 class="student-view-title">VVU Student Portal</h1>
                    <p class="student-view-subtitle">View your semester timetable</p>
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
            'level' => $row[7] ?? '100',
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
    $data = array_filter($data, function ($item) use ($filter_level) {
        return $item['level'] == $filter_level; // Strict check? 
    // Often levels are just 100, 200.

    });
}
if ($filter_dept) {
    $data = array_filter($data, function ($item) use ($filter_dept) {
        return stripos($item['code'], $filter_dept) === 0; // Starts with
    });
}
?>

        <div class="glass-panel student-view-filter-panel">
            <form method="GET" class="student-view-filter-form">
                <div>
                    <label class="student-view-filter-label">Department (Course
                        Code)</label>
                    <select name="dept" class="glass-input student-view-filter-select">
                        <option value="">All Departments</option>
                        <option value="COSC" <?php if ($filter_dept=='COSC' )
    echo 'selected' ; ?>>Computer Science
                            (COSC)</option>
                        <option value="NURS" <?php if ($filter_dept=='NURS' )
    echo 'selected' ; ?>>Nursing (NURS)
                        </option>
                        <option value="THEO" <?php if ($filter_dept=='THEO' )
    echo 'selected' ; ?>>Theology (THEO)
                        </option>
                        <option value="MATH" <?php if ($filter_dept=='MATH' )
    echo 'selected' ; ?>>Mathematics (MATH)
                        </option>
                        <option value="GNED" <?php if ($filter_dept=='GNED' )
    echo 'selected' ; ?>>General Education
                            (GNED)</option>
                    </select>
                </div>
                <div>
                    <label class="student-view-filter-label">Level</label>
                    <select name="level" class="glass-input student-view-filter-select">
                        <option value="">All Levels</option>
                        <option value="100" <?php if ($filter_level=='100' )
    echo 'selected' ; ?>>Level 100</option>
                        <option value="200" <?php if ($filter_level=='200' )
    echo 'selected' ; ?>>Level 200</option>
                        <option value="300" <?php if ($filter_level=='300' )
    echo 'selected' ; ?>>Level 300</option>
                        <option value="400" <?php if ($filter_level=='400' )
    echo 'selected' ; ?>>Level 400</option>
                    </select>
                </div>
                <div class="student-view-filter-action">
                    <button type="submit" class="glass-btn primary student-view-filter-button">
                        <i class="fa-solid fa-filter"></i> Filter
                    </button>
                </div>
            </form>
        </div>

        <div class="glass-panel student-view-table-panel">
            <table class="student-view-table">
                <thead>
                    <tr class="student-view-table-head-row">
                        <th class="student-view-cell-pad">Day & Time</th>
                        <th class="student-view-cell-pad">Course</th>
                        <th class="student-view-cell-pad">Room</th>
                        <th class="student-view-cell-pad">Lecturer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                    <tr>
                        <td colspan="4" class="student-view-empty-state">No classes found.
                        </td>
                    </tr>
                    <?php
else:
    $days = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5];
    usort($data, function ($a, $b) use ($days) {
        $da = $days[$a['day']] ?? 99;
        $db = $days[$b['day']] ?? 99;
        if ($da != $db)
            return $da - $db;
        return strcmp($a['time'], $b['time']);
    });
?>
                    <?php foreach ($data as $row): ?>
                    <tr class="student-view-table-row">
                        <td class="student-view-cell-pad">
                            <div class="student-view-day">
                                <?php echo htmlspecialchars($row['day']); ?>
                            </div>
                            <div class="student-view-time">
                                <?php echo htmlspecialchars($row['time']); ?>
                            </div>
                        </td>
                        <td class="student-view-cell-pad">
                            <div class="student-view-course-code">
                                <?php echo htmlspecialchars($row['code']); ?>
                            </div>
                            <div class="student-view-course-title">
                                <?php echo htmlspecialchars($row['title']); ?>
                            </div>
                        </td>
                        <td class="student-view-cell-pad">
                            <span class="student-view-room-badge">
                                <?php echo htmlspecialchars($row['room']); ?>
                            </span>
                        </td>
                        <td class="student-view-cell-pad student-view-lecturer">
                            <?php echo htmlspecialchars($row['lecturer']); ?>
                        </td>
                    </tr>
                    <?php
    endforeach; ?>
                    <?php
endif; ?>
                </tbody>
            </table>
        </div>

        <div class="student-view-footer-note">
            &copy;
            <?php echo date('Y'); ?> Valley View University Scheduling System
        </div>
    </div>

</body>

</html>