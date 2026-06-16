<?php
// feedback.php — Student feedback form for reporting course clashes
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? '';
$user_level = $_SESSION['level'] ?? '';
$user_semester = $_SESSION['semester'] ?? '';
require_once __DIR__ . '/api/db.php';
$academic_year = '2026/2027';

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
// Course list will be loaded via AJAX (paginated)
$courses = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report Course Clash — Scheduler</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .feedback-form {
            max-width: 440px;
            margin: 2.5rem auto;
            background: rgba(255,255,255,0.10);
            padding: 2.25rem 2rem 2rem 2rem;
            border-radius: 18px;
            box-shadow: 0 4px 32px 0 rgba(0,0,0,0.07);
            display: flex;
            flex-direction: column;
            gap: 1.1rem;
            border: 1.5px solid rgba(100,116,139,0.10);
        }
        .feedback-form h2 {
            text-align: center;
            font-size: 1.45rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            text-fill-color: transparent;
        }
        .feedback-form label {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--primary-color);
            letter-spacing: 0.01em;
        }
        .feedback-form select, .feedback-form textarea, .feedback-form input[type="text"] {
            width: 100%;
            margin-bottom: 0.2rem;
            padding: 0.65rem 0.7rem;
            border-radius: 8px;
            border: 1.5px solid #cbd5e1;
            background: rgba(255,255,255,0.7);
            font-size: 1rem;
            transition: border 0.2s;
        }
        .feedback-form select:focus, .feedback-form textarea:focus, .feedback-form input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            background: #fff;
        }
        .feedback-form textarea {
            min-height: 60px;
            resize: vertical;
        }
        .feedback-form button {
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            color: #fff;
            border: none;
            padding: 0.85rem 1.5rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1.08rem;
            cursor: pointer;
            box-shadow: 0 2px 8px 0 rgba(100,116,139,0.07);
            margin-top: 0.5rem;
            transition: background 0.2s, box-shadow 0.2s;
        }
        .feedback-form button:hover {
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
            box-shadow: 0 4px 16px 0 rgba(100,116,139,0.10);
        }
        .feedback-success {
            background: #e0ffe0;
            color: #166534;
            padding: 0.85rem 1.1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            border: 1.5px solid #bbf7d0;
            font-size: 1.02rem;
            text-align: center;
        }
        @media (max-width: 600px) {
            .feedback-form {
                padding: 1.2rem 0.5rem 1.2rem 0.5rem;
                max-width: 98vw;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <div class="main-content">
        <form class="feedback-form" id="feedbackForm" autocomplete="off">
            <h2>Report Course Clash</h2>
            <div id="feedbackStatus"></div>
            <label for="course1">First Course</label>
            <select name="course1" id="course1" required>
                <option value="">Select course</option>
            </select>
            <label for="course2">Second Course</label>
            <select name="course2" id="course2" required>
                <option value="">Select course</option>
            </select>
            <label for="timetable_file">Timetable</label>
            <select name="timetable_file" id="timetable_file" required>
                <option value="">Select timetable<?php echo $academic_year !== '' ? ' (' . htmlspecialchars($academic_year) . ')' : ''; ?></option>
                <?php
                $dept = $_SESSION['department'] ?? '';
                $keywords = [];
                $dept_lower = strtolower($dept);
                
                if (strpos($dept_lower, 'computing') !== false) $keywords = ['comp', 'cs', 'it', 'bbis'];
                elseif (strpos($dept_lower, 'nursing') !== false) $keywords = ['nurs'];
                elseif (strpos($dept_lower, 'theology') !== false) $keywords = ['theo'];
                elseif (strpos($dept_lower, 'business') !== false) $keywords = ['bus', 'admin'];
                elseif (strpos($dept_lower, 'development') !== false) $keywords = ['dev'];
                elseif (strpos($dept_lower, 'education') !== false) $keywords = ['edu'];
                elseif (strpos($dept_lower, 'biomedical') !== false) $keywords = ['biom', 'bio', 'bme', 'bo', 'bi'];
                
                $matched_options = [];

                // Source timetable options from saved schedules in DB, then map to B2 keys.
                if (isset($conn) && $conn instanceof mysqli) {
                    $has_ay_col = false;
                    $col_check = $conn->query("SHOW COLUMNS FROM generated_schedules LIKE 'academic_year'");
                    if ($col_check && $col_check->num_rows > 0) {
                        $has_ay_col = true;
                    }

                    if ($has_ay_col) {
                        $sched_stmt = $conn->prepare("SELECT schedule_name, department, semester, created_at FROM generated_schedules WHERE academic_year = ? ORDER BY created_at DESC LIMIT 500");
                        if ($sched_stmt) {
                            $sched_stmt->bind_param('s', $academic_year);
                            $sched_stmt->execute();
                            $sched_res = $sched_stmt->get_result();
                        } else {
                            $sched_res = false;
                        }
                    } else {
                        // Legacy fallback if academic_year column is unavailable.
                        $sched_stmt = $conn->prepare("SELECT schedule_name, department, semester, created_at FROM generated_schedules WHERE schedule_data LIKE ? ORDER BY created_at DESC LIMIT 500");
                        if ($sched_stmt) {
                            $like_ay = '%"academic_year":"' . $academic_year . '"%';
                            $sched_stmt->bind_param('s', $like_ay);
                            $sched_stmt->execute();
                            $sched_res = $sched_stmt->get_result();
                        } else {
                            $sched_res = false;
                        }
                    }

                    if ($sched_res) {
                        while ($sched = $sched_res->fetch_assoc()) {
                            $schedule_name = trim((string)($sched['schedule_name'] ?? ''));
                            if ($schedule_name === '') {
                                continue;
                            }

                            $schedule_name_lower = strtolower($schedule_name);
                            $schedule_dept = strtolower(trim((string)($sched['department'] ?? '')));

                            $is_match = empty($keywords);
                            if (!$is_match && $schedule_dept !== '' && $dept_lower !== '' && strpos($schedule_dept, $dept_lower) !== false) {
                                $is_match = true;
                            }
                            if (!$is_match && $schedule_dept !== '' && $dept_lower !== '') {
                                foreach ($keywords as $kw) {
                                    if (strpos($schedule_dept, $kw) !== false) {
                                        $is_match = true;
                                        break;
                                    }
                                }
                            }
                            if (!$is_match) {
                                foreach ($keywords as $kw) {
                                    if (strpos($schedule_name_lower, $kw) !== false) {
                                        $is_match = true;
                                        break;
                                    }
                                }
                            }
                            if (!$is_match && $dept && strpos($schedule_name_lower, strtolower(str_replace(' ', '_', $dept))) !== false) {
                                $is_match = true;
                            }

                            if (!$is_match) {
                                continue;
                            }

                            $b2_key = $schedule_name;
                            if (stripos($b2_key, 'csv/') !== 0) {
                                $b2_key = 'csv/final/' . ltrim($b2_key, '/');
                            }
                            if (strtolower(substr($b2_key, -4)) !== '.csv') {
                                $b2_key .= '.csv';
                            }

                            $label = $schedule_name;
                            $created_at = trim((string)($sched['created_at'] ?? ''));
                            if ($created_at !== '') {
                                $label .= ' (' . $created_at . ')';
                            }
                            $matched_options[$b2_key] = $label;
                        }
                    }
                }

                foreach ($matched_options as $opt_value => $opt_label) {
                    echo "<option value=\"" . htmlspecialchars((string)$opt_value) . "\">" . htmlspecialchars((string)$opt_label) . "</option>";
                }
                if (empty($matched_options)) {
                    echo '<option value="" disabled>No saved schedules found for 2026/2027</option>';
                }
                ?>
            </select>
            <label for="semester">Semester</label>
            <input type="text" name="semester" id="semester" value="<?php echo htmlspecialchars($user_semester); ?>" required>
            <label for="reason">Describe the clash (optional)</label>
            <textarea name="reason" id="reason" rows="2" placeholder="E.g. Both courses scheduled at the same time..."></textarea>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <button type="submit">Submit Feedback</button>
        </form>
        <script>
        // Paginated course loader with cache
        const courseCache = { data: [], page: 0, total_pages: 1 };
        async function loadCourses(page = 1, per_page = 50) {
            if (page > courseCache.total_pages) return [];
            const res = await fetch('api/course_list.php?page=' + page + '&per_page=' + per_page);
            const data = await res.json();
            if (data.status === 'success') {
                courseCache.page = data.page;
                courseCache.total_pages = data.total_pages;
                courseCache.data = courseCache.data.concat(data.data);
                return data.data;
            }
            return [];
        }
        function renderCourseOptions(select, courses) {
            for (const c of courses) {
                const opt = document.createElement('option');
                opt.value = c;
                opt.textContent = c;
                select.appendChild(opt);
            }
        }
        async function initCourseDropdowns() {
            const selects = [document.getElementById('course1'), document.getElementById('course2')];
            let page = 1;
            let loading = false;
            async function loadAndRender() {
                if (loading || page > courseCache.total_pages) return;
                loading = true;
                const courses = await loadCourses(page);
                for (const sel of selects) renderCourseOptions(sel, courses);
                page++;
                loading = false;
            }
            await loadAndRender();
            // Infinite scroll for long lists
            for (const sel of selects) {
                sel.addEventListener('scroll', async function() {
                    if (sel.scrollTop + sel.clientHeight >= sel.scrollHeight - 2) {
                        await loadAndRender();
                    }
                });
            }
        }
        document.addEventListener('DOMContentLoaded', initCourseDropdowns);

        document.getElementById('feedbackForm').onsubmit = async function(e) {
            e.preventDefault();
            const form = e.target;
            const statusDiv = document.getElementById('feedbackStatus');
            statusDiv.innerHTML = '';
            const fd = new FormData(form);
            try {
                const res = await fetch('api/feedback_db.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (data.status === 'success') {
                    statusDiv.innerHTML = '<div class="feedback-success">Thank you! Your feedback has been recorded and will be used to improve the timetable.</div>';
                    form.reset();
                } else if (data.status === 'duplicate') {
                    statusDiv.innerHTML = '<div class="feedback-success" style="background:#fef9c3;color:#92400e;border-color:#fde68a;">Duplicate: You have already reported this clash for this semester.</div>';
                } else {
                    statusDiv.innerHTML = '<div class="feedback-success" style="background:#fee2e2;color:#991b1b;border-color:#fecaca;">' + (data.message || 'Error submitting feedback') + '</div>';
                }
            } catch (err) {
                statusDiv.innerHTML = '<div class="feedback-success" style="background:#fee2e2;color:#991b1b;border-color:#fecaca;">Network error. Please try again.</div>';
            }
        };
        </script>
    </div>
    <?php include 'includes/footer.php'; ?>
</body>
</html>
