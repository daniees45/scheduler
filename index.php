<?php
require_once __DIR__ . '/web/includes/branding.php';

function landing_normalize_hex_color($hex, $fallback = '#2563eb') {
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

function landing_hex_to_rgb($hex) {
    $hex = landing_normalize_hex_color($hex);
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r, $g, $b";
}

function landing_adjust_hex_brightness($hex, $steps) {
    $hex = landing_normalize_hex_color($hex);
    $hex = ltrim($hex, '#');
    $steps = max(-255, min(255, (int)$steps));

    $r = max(0, min(255, hexdec(substr($hex, 0, 2)) + $steps));
    $g = max(0, min(255, hexdec(substr($hex, 2, 2)) + $steps));
    $b = max(0, min(255, hexdec(substr($hex, 4, 2)) + $steps));

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

function landing_apply_hex_strength($hex, $strengthPercent) {
    $hex = landing_normalize_hex_color($hex);
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

$site_primary_base = landing_normalize_hex_color($branding['site_color'] ?? '#2563eb', '#2563eb');
$site_secondary_base = landing_normalize_hex_color($branding['site_secondary_color'] ?? '', landing_adjust_hex_brightness($site_primary_base, 32));
$site_bg = landing_normalize_hex_color($branding['site_bg_color'] ?? '#0f172a', '#0f172a');
$color_strength = max(50, min(150, (int)($branding['site_color_strength'] ?? 100)));
$site_primary = landing_apply_hex_strength($site_primary_base, $color_strength);
$site_secondary = landing_apply_hex_strength($site_secondary_base, $color_strength);
$site_primary_rgb = landing_hex_to_rgb($site_primary);
$site_secondary_rgb = landing_hex_to_rgb($site_secondary);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($branding['site_title'] ?? 'VVU AI Scheduler'); ?> - Central Hub</title>
    <?php if (!empty($branding['site_icon'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($branding['site_icon']); ?>">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="web/assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --site-primary: <?php echo $site_primary; ?>;
            --site-secondary: <?php echo $site_secondary; ?>;
            --site-bg: <?php echo $site_bg; ?>;
            --site-primary-rgb: <?php echo $site_primary_rgb; ?>;
            --site-secondary-rgb: <?php echo $site_secondary_rgb; ?>;

            --primary: var(--site-primary);
            --secondary: var(--site-secondary);
            --bg-dark: var(--site-bg);
            --card-bg: rgba(30, 41, 59, 0.7);
        }
        body {
            margin: 0;
            padding: 0;
            background-color: var(--bg-dark);
            color: #f8fafc;
            line-height: 1.6;
            overflow-x: hidden;
        }
        .hero-section {
            min-height: 70vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 2rem;
            background: radial-gradient(circle at center, rgba(var(--site-primary-rgb), 0.1) 0%, transparent 70%);
            position: relative;
        }
        .hero-section h1 {
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 1rem;
            background: linear-gradient(to right, var(--site-primary), var(--site-secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -1px;
        }
        @media (max-width: 768px) {
            .hero-section h1 { font-size: 2.5rem; }
        }
        .hero-subtitle {
            font-size: 1.25rem;
            color: var(--text-muted);
            max-width: 700px;
            margin-bottom: 3rem;
        }
        .main-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: -100px auto 100px;
            padding: 0 2rem;
        }
        .feature-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 2.5rem;
            text-align: left;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            text-decoration: none;
            color: inherit;
        }
        .feature-card:hover {
            transform: translateY(-10px);
            border-color: rgba(var(--site-primary-rgb), 0.5);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            background: rgba(30, 41, 59, 0.9);
        }
        .icon {
            width: 60px;
            height: 60px;
            background: rgba(var(--site-primary-rgb), 0.1);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--site-primary);
        }
        .feature-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        .feature-card p {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin: 0;
        }
        .nav-header {
            width: 100%;
            padding: 1.5rem 0;
            display: flex;
            justify-content: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--site-primary), var(--site-secondary));
            color: white;
            padding: 1rem 2.5rem;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 10px 20px rgba(var(--site-primary-rgb), 0.3);
        }
        .btn-primary:hover {
            transform: scale(1.05);
            box-shadow: 0 15px 30px rgba(var(--site-primary-rgb), 0.4);
        }
        .footer {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
            font-size: 0.9rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            background: rgba(var(--site-primary-rgb), 0.1);
            color: var(--site-primary);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>

<div class="nav-header">
    <div style="font-weight: 800; font-size: 1.2rem; color: #f8fafc;">
        <i class="fa-solid fa-brain" style="color: var(--site-primary); margin-right: 8px;"></i> <?php echo strtoupper(htmlspecialchars($branding['site_title'] ?? 'VVU AI SCHEDULER')); ?>
    </div>
</div>

<header class="hero-section">
    <div class="badge">Next Generation Timetabling</div>
    <h1>Effortless Scheduling,<br>Powered by Intelligence.</h1>
    <p class="hero-subtitle">
        Automate complex academic scheduling with our advanced CSP-based AI engine. 
        Optimize lecturer assignments, room utilization, and student paths in seconds.
    </p>
    <a href="web/login.php" class="btn-primary">Access Dashboard <i class="fa-solid fa-arrow-right" style="margin-left: 10px;"></i></a>
</header>

<main class="main-grid">
    <!-- Public/Common Features -->
    <a href="web/view_schedule.php" class="feature-card">
        <div class="icon"><i class="fa-solid fa-calendar-days"></i></div>
        <h3>View Schedule</h3>
        <p>Check the latest finalized course timetables for the current semester.</p>
        <div style="color: var(--site-primary); font-weight: 600; font-size: 0.85rem;">Browse Now <i class="fa-solid fa-chevron-right" style="margin-left: 5px;"></i></div>
    </a>

    <a href="web/student_view.php" class="feature-card">
        <div class="icon"><i class="fa-solid fa-graduation-cap"></i></div>
        <h3>Student Portal</h3>
        <p>Login to view your personalized schedule based on your enrolled courses.</p>
        <div style="color: var(--site-primary); font-weight: 600; font-size: 0.85rem;">Personal View <i class="fa-solid fa-chevron-right" style="margin-left: 5px;"></i></div>
    </a>

    <!-- Admin/Lecturer Features -->
    <a href="web/generate.php" class="feature-card">
        <div class="icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
        <h3>AI Generation</h3>
        <p>Configure and run the AI engine to generate optimized conflict-free schedules.</p>
        <div style="color: var(--site-primary); font-weight: 600; font-size: 0.85rem;">Admin Only <i class="fa-solid fa-lock" style="margin-left: 5px;"></i></div>
    </a>

    <a href="web/lecturers.php" class="feature-card">
        <div class="icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <h3>Lecturer Availability</h3>
        <p>Manage your teaching preferences and available time slots for the semester.</p>
        <div style="color: var(--site-primary); font-weight: 600; font-size: 0.85rem;">Update Prefs <i class="fa-solid fa-chevron-right" style="margin-left: 5px;"></i></div>
    </a>

    <a href="web/import_data.php" class="feature-card">
        <div class="icon"><i class="fa-solid fa-database"></i></div>
        <h3>Data Management</h3>
        <p>Import CSV datasets for rooms, courses, and lecturers into the central database.</p>
        <div style="color: var(--site-primary); font-weight: 600; font-size: 0.85rem;">Bulk Tools <i class="fa-solid fa-chevron-right" style="margin-left: 5px;"></i></div>
    </a>

    <a href="web/courses.php" class="feature-card">
        <div class="icon"><i class="fa-solid fa-book-open"></i></div>
        <h3>Curriculum Control</h3>
        <p>Define course levels, credit hours, and departmental specializations.</p>
        <div style="color: var(--site-primary); font-weight: 600; font-size: 0.85rem;">Manage Courses <i class="fa-solid fa-chevron-right" style="margin-left: 5px;"></i></div>
    </a>
</main>

<!-- AI Features Showcase -->
<section style="background: rgba(var(--site-primary-rgb), 0.05); padding: 4rem 2rem; margin-top: 2rem;">
    <div style="max-width: 1200px; margin: 0 auto;">
        <div style="text-align: center; margin-bottom: 3rem;">
            <div style="display: inline-block; padding: 8px 16px; background: rgba(var(--site-primary-rgb), 0.15); border-radius: 50px; color: var(--site-primary); font-size: 0.85rem; font-weight: 700; margin-bottom: 1rem;">
                <i class="fa-solid fa-sparkles"></i> AI-POWERED INTELLIGENCE
            </div>
            <h2 style="font-size: 2.5rem; font-weight: 800; background: linear-gradient(to right, var(--site-primary), var(--site-secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 1rem;">
                Advanced AI Technologies
            </h2>
            <p style="color: var(--text-muted); font-size: 1.1rem; max-width: 600px; margin: 0 auto;">
                Our scheduler leverages cutting-edge machine learning and constraint solving to deliver optimal academic timetables.
            </p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 2rem;">
            <!-- CSP Solver Card -->
            <div class="feature-card">
                <div class="icon" style="background: rgba(var(--site-primary-rgb), 0.15); color: var(--site-primary);">
                    <i class="fa-solid fa-cube"></i>
                </div>
                <h3>Constraint Solving</h3>
                <p>Next-generation Constraint Satisfaction Problem (CSP) solver with backtracking search and heuristic optimization for conflict-free schedules.</p>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Core Algorithm: MRV Heuristic</div>
            </div>

            <!-- Deep Learning Card -->
            <div class="feature-card">
                <div class="icon" style="background: rgba(var(--site-secondary-rgb), 0.15); color: var(--site-secondary);">
                    <i class="fa-solid fa-brain"></i>
                </div>
                <h3>Neural Networks</h3>
                <p>Deep learning classifier predicts schedule quality, identifies conflicts, and optimizes time distribution across your academic calendar.</p>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Quality Score: 0-100%</div>
            </div>

            <!-- Q-Learning Card -->
            <div class="feature-card">
                <div class="icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <h3>Reinforcement Learning</h3>
                <p>Q-Learning system that adapts to user preferences, learning from schedule adjustments to continuously improve future recommendations.</p>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Bidirectional Feedback</div>
            </div>

            <!-- Feasibility Card -->
            <div class="feature-card">
                <div class="icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                    <i class="fa-solid fa-shield-check"></i>
                </div>
                <h3>Feasibility Prediction</h3>
                <p>Machine learning classifier pre-validates scheduling assignments using historical patterns, preventing impossible configurations before computation.</p>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Random Forest Classifier</div>
            </div>

            <!-- Explainability Card -->
            <div class="feature-card">
                <div class="icon" style="background: rgba(236, 72, 153, 0.15); color: #ec4899;">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <h3>AI Explainability</h3>
                <p>Transparent AI decisions with feature importance analysis. Understand exactly why courses are scheduled at specific times and venues.</p>
                <div style="font-size: 0.75rem; color: var(--text-muted);">SHAP-Based Insights</div>
            </div>

            <!-- Analytics Card -->
            <div class="feature-card">
                <div class="icon" style="background: rgba(34, 197, 94, 0.15); color: #22c55e;">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <h3>Performance Analytics</h3>
                <p>Real-time optimization metrics including room utilization rates, lecturer satisfaction scores, and conflict resolution success rates.</p>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Live Dashboard</div>
            </div>
        </div>

        <!-- Key Metrics Section -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2rem; margin-top: 4rem; padding-top: 2rem; border-top: 1px solid rgba(255, 255, 255, 0.05);">
            <div style="text-align: center;">
                <div style="font-size: 2.5rem; font-weight: 800; background: linear-gradient(to right, var(--site-primary), var(--site-secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 0.5rem;">
                    92%
                </div>
                <p style="color: var(--text-muted); margin: 0;">Average Success Rate</p>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2.5rem; font-weight: 800; background: linear-gradient(to right, var(--site-primary), var(--site-secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 0.5rem;">
                    <i class="fa-solid fa-bolt"></i> 45s
                </div>
                <p style="color: var(--text-muted); margin: 0;">Average Generation Time</p>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2.5rem; font-weight: 800; background: linear-gradient(to right, var(--site-primary), var(--site-secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 0.5rem;">
                    +35%
                </div>
                <p style="color: var(--text-muted); margin: 0;">Conflict Resolution</p>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2.5rem; font-weight: 800; background: linear-gradient(to right, var(--site-primary), var(--site-secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 0.5rem;">
                    +28%
                </div>
                <p style="color: var(--text-muted); margin: 0;">Room Utilization</p>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section style="padding: 4rem 2rem; text-align: center;">
    <h2 style="font-size: 2rem; font-weight: 800; margin-bottom: 1rem;">Ready to Revolutionize Your Scheduling?</h2>
    <p style="color: var(--text-muted); margin-bottom: 2rem; max-width: 600px; margin-left: auto; margin-right: auto;">
        From conflict-free timetables to AI-optimized resource allocation, our platform handles it all with intelligent automation.
    </p>
    <a href="web/login.php" class="btn-primary">Get Started Now <i class="fa-solid fa-arrow-right" style="margin-left: 10px;"></i></a>
</section>

<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> Valley View University AI Scheduling Team.</p>
    <p style="font-size: 0.75rem; margin-top: 10px;">
        Powered by Advanced AI Technologies: CSP Solver • Deep Learning • Q-Learning • Neural Networks
    </p>
</footer>

</body>
</html>
