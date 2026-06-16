<?php
require_once __DIR__ . '/web/includes/branding.php';

$is_logged_in = !empty($_SESSION['user_id']);

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

$site_primary_base  = landing_normalize_hex_color($branding['site_color'] ?? '#2563eb', '#2563eb');
$site_secondary_base = landing_normalize_hex_color($branding['site_secondary_color'] ?? '', landing_adjust_hex_brightness($site_primary_base, 48));
$site_bg            = landing_normalize_hex_color($branding['site_bg_color'] ?? '#0a0f1e', '#0a0f1e');
$color_strength     = max(50, min(150, (int)($branding['site_color_strength'] ?? 100)));
$site_primary       = landing_apply_hex_strength($site_primary_base, $color_strength);
$site_secondary     = landing_apply_hex_strength($site_secondary_base, $color_strength);
$site_text          = landing_normalize_hex_color($branding['site_text_color'] ?? '#f1f5f9', '#f1f5f9');
$site_primary_rgb   = landing_hex_to_rgb($site_primary);
$site_secondary_rgb = landing_hex_to_rgb($site_secondary);

$site_title = htmlspecialchars($branding['site_title'] ?? 'VVU AI Scheduler');
$site_logo  = !empty($branding['site_logo'])  ? htmlspecialchars($branding['site_logo'])  : null;
$site_icon  = !empty($branding['site_icon'])  ? htmlspecialchars($branding['site_icon'])  : null;

// ── LIVE SCHEDULE PREVIEW: pull real rows from saved schedules ──────────────
$hero_rows = [];
$hero_meta = '';
try {
    $pconn = @new mysqli(
        'localhost', 'root', '', 'vvu_scheduler',
        3306, '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock'
    );
    if ($pconn && !$pconn->connect_error) {
        $pres = $pconn->query(
            "SELECT schedule_data, department, semester FROM generated_schedules
             ORDER BY created_at DESC, id DESC LIMIT 5"
        );
        if ($pres) {
            $pill_cycle  = ['pill-g','pill-p','pill-v','pill-y','pill-g'];
            $stat_cycle  = ['Placed','Scheduled','Optimized','Finalizing','Confirmed'];
            $pill_idx    = 0;
            $depts_seen  = [];
            while ($srow = $pres->fetch_assoc()) {
                $raw = json_decode($srow['schedule_data'], true);
                if (!is_array($raw) || count($raw) < 2) continue;
                $header   = array_flip($raw[0]);
                $code_i   = $header['Course Code']  ?? null;
                $title_i  = $header['Course Title'] ?? null;
                $day_i    = $header['Day']           ?? null;
                $time_i   = $header['Time']          ?? null;
                $room_i   = $header['Room Name']     ?? null;
                if ($code_i === null || $day_i === null || $time_i === null) continue;
                if (!empty($srow['department'])) $depts_seen[] = $srow['department'];
                for ($ri = 1; $ri < count($raw); $ri++) {
                    $r = $raw[$ri];
                    $code  = trim($r[$code_i]  ?? '');
                    $title = $title_i !== null ? trim($r[$title_i] ?? '') : '';
                    $day   = trim($r[$day_i]   ?? '');
                    $time  = trim($r[$time_i]  ?? '');
                    $room  = $room_i !== null  ? trim($r[$room_i]  ?? '') : '—';
                    if (!$code || !$day || !$time) continue;
                    // strip section suffixes like " [Sec A]" from title
                    $title = preg_replace('/\s*\[Sec\s*[A-Z0-9]+\]/i', '', $title);
                    $label = $code . ($title ? ' – ' . $title : '');
                    $hero_rows[] = [
                        htmlspecialchars($label, ENT_QUOTES),
                        htmlspecialchars($day,   ENT_QUOTES),
                        htmlspecialchars($time,  ENT_QUOTES),
                        htmlspecialchars($room ?: '—', ENT_QUOTES),
                        $pill_cycle[$pill_idx % 5],
                        $stat_cycle[$pill_idx % 5],
                    ];
                    $pill_idx++;
                }
            }
            $pres->free();
            if (!empty($depts_seen)) {
                $unique_depts = array_unique($depts_seen);
                $hero_meta = implode(' · ', array_slice($unique_depts, 0, 3));
            }
        }
        $pconn->close();
    }
} catch (Throwable $_e) {
    // fail silently — fallback rows used below
}
shuffle($hero_rows);
$hero_rows = array_slice($hero_rows, 0, 40);  // cap cycling pool at 40
$initial_rows = array_slice($hero_rows, 0, 5);
// Fallback if DB returned nothing
if (empty($initial_rows)) {
    $initial_rows = [
        ['COCS 201 – Data Structures', 'Monday',    '8:00 AM - 10:00 AM',  'LH1', 'pill-g', 'Placed'],
        ['INFT 301 – Networks',        'Tuesday',   '10:00 AM - 12:00 PM', 'LH3', 'pill-g', 'Placed'],
        ['MATH 101 – Calculus',        'Wednesday', '2:00 PM - 4:00 PM',   'LH2', 'pill-p', 'Scheduled'],
        ['COCS 364 – AI Systems',      'Thursday',  '8:00 AM - 10:00 AM',  'LH4', 'pill-v', 'Optimized'],
        ['INFT 201 – Web Dev',         'Friday',    '12:00 PM - 2:00 PM',  'LH1', 'pill-y', 'Finalizing'],
    ];
    $hero_rows = $initial_rows;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_title; ?> – Intelligent Academic Scheduling</title>
    <?php if ($site_icon): ?><link rel="icon" href="<?php echo $site_icon; ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
        --p:   <?php echo $site_primary; ?>;
        --s:   <?php echo $site_secondary; ?>;
        --bg:  <?php echo $site_bg; ?>;
        --pr:  <?php echo $site_primary_rgb; ?>;
        --sr:  <?php echo $site_secondary_rgb; ?>;
        --surface:  rgba(255,255,255,0.04);
        --border:   rgba(255,255,255,0.09);
        --text:     <?php echo $site_text; ?>;
        --muted:    #94a3b8;
        --radius:   18px;
    }

    html { scroll-behavior: smooth; }

    body {
        font-family: 'Inter', sans-serif;
        background: var(--bg);
        color: var(--text);
        overflow-x: hidden;
        -webkit-font-smoothing: antialiased;
    }

    /* ── NOISE OVERLAY ─────────────────────────────────────────── */
    body::before {
        content: '';
        position: fixed; inset: 0; z-index: 0; pointer-events: none;
        background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
        opacity: .5;
    }

    section, nav, footer, header { position: relative; z-index: 1; }

    /* ── NAV ────────────────────────────────────────────────────── */
    .nav {
        position: sticky; top: 0; z-index: 100;
        display: flex; align-items: center; justify-content: space-between;
        padding: .9rem 2rem;
        background: rgba(10,15,30,.7);
        backdrop-filter: blur(20px);
        border-bottom: 1px solid var(--border);
    }
    .nav-brand {
        display: flex; align-items: center; gap: .6rem;
        font-weight: 800; font-size: 1.1rem; text-decoration: none; color: var(--text);
    }
    .nav-brand img { height: 32px; border-radius: 6px; }
    .nav-brand-icon {
        width: 34px; height: 34px; border-radius: 9px;
        background: linear-gradient(135deg, var(--p), var(--s));
        display: flex; align-items: center; justify-content: center;
        font-size: .95rem; color: #fff;
    }
    .nav-links { display: flex; gap: .25rem; align-items: center; }
    .nav-links a {
        color: var(--muted); text-decoration: none; font-size: .88rem; font-weight: 500;
        padding: .45rem .85rem; border-radius: 8px; transition: all .2s;
    }
    .nav-links a:hover { color: var(--text); background: var(--surface); }
    .nav-cta {
        background: linear-gradient(135deg, var(--p), var(--s));
        color: #fff !important; border-radius: 10px !important;
        padding: .5rem 1.1rem !important; font-weight: 600 !important;
        box-shadow: 0 4px 14px rgba(var(--pr),.35);
        transition: transform .2s, box-shadow .2s !important;
    }
    .nav-cta:hover { transform: translateY(-1px) !important; box-shadow: 0 6px 20px rgba(var(--pr),.45) !important; }
    .nav-hamburger { display: none; font-size: 1.3rem; cursor: pointer; color: var(--text); background: none; border: none; }
    @media (max-width: 700px) {
        .nav-links { display: none; flex-direction: column; position: absolute; top: 100%; left: 0; right: 0;
            background: rgba(10,15,30,.97); border-bottom: 1px solid var(--border); padding: 1rem; gap: .5rem; }
        .nav-links.open { display: flex; }
        .nav-hamburger { display: block; }
    }

    /* ── ORBS ──────────────────────────────────────────────────── */
    .orb {
        position: absolute; border-radius: 50%; filter: blur(80px);
        pointer-events: none; animation: orb-drift 14s ease-in-out infinite alternate;
    }
    @keyframes orb-drift { from { transform: translate(0,0) scale(1); } to { transform: translate(30px,-20px) scale(1.06); } }

    /* ── HERO ──────────────────────────────────────────────────── */
    .hero {
        position: relative; overflow: hidden;
        min-height: 92vh;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        text-align: center; padding: 5rem 1.5rem 4rem;
    }
    .hero-orb1 { width: 520px; height: 520px; top: -140px; left: -120px;
        background: rgba(var(--pr),.18); animation-duration: 16s; }
    .hero-orb2 { width: 400px; height: 400px; bottom: -100px; right: -80px;
        background: rgba(var(--sr),.14); animation-duration: 12s; animation-delay: -4s; }
    .hero-orb3 { width: 280px; height: 280px; top: 40%; left: 50%; transform: translate(-50%,-50%);
        background: rgba(var(--pr),.08); animation-duration: 20s; }

    .hero-pill {
        display: inline-flex; align-items: center; gap: .5rem;
        background: rgba(var(--pr),.12); border: 1px solid rgba(var(--pr),.3);
        border-radius: 50px; padding: .35rem 1rem;
        font-size: .78rem; font-weight: 700; color: var(--p);
        letter-spacing: .06em; text-transform: uppercase;
        margin-bottom: 1.8rem;
        animation: fade-up .6s ease both;
    }
    .hero-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--p); animation: pulse 1.4s ease infinite; }
    @keyframes pulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:.5; transform:scale(.7); } }

    .hero h1 {
        font-size: clamp(2.4rem, 6vw, 5rem);
        font-weight: 900; line-height: 1.08; letter-spacing: -.03em;
        margin-bottom: 1.4rem;
        animation: fade-up .6s .1s ease both;
    }
    .hero h1 .grad {
        background: linear-gradient(135deg, var(--p) 0%, var(--s) 60%, #a78bfa 100%);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .hero-sub {
        font-size: clamp(.95rem, 2vw, 1.2rem); color: var(--muted);
        max-width: 600px; line-height: 1.7;
        margin-bottom: 2.8rem;
        animation: fade-up .6s .2s ease both;
    }
    .hero-actions {
        display: flex; flex-wrap: wrap; gap: 1rem; justify-content: center;
        animation: fade-up .6s .3s ease both;
    }
    @keyframes fade-up { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }

    .btn-glow {
        display: inline-flex; align-items: center; gap: .6rem;
        background: linear-gradient(135deg, var(--p), var(--s));
        color: #fff; text-decoration: none;
        padding: .85rem 2rem; border-radius: 14px; font-weight: 700; font-size: 1rem;
        box-shadow: 0 8px 24px rgba(var(--pr),.4);
        transition: transform .22s, box-shadow .22s;
    }
    .btn-glow:hover { transform: translateY(-3px); box-shadow: 0 14px 32px rgba(var(--pr),.5); }
    .btn-ghost {
        display: inline-flex; align-items: center; gap: .6rem;
        background: var(--surface); border: 1px solid var(--border);
        color: var(--text); text-decoration: none;
        padding: .85rem 2rem; border-radius: 14px; font-weight: 600; font-size: 1rem;
        transition: background .2s, border-color .2s, transform .22s;
        backdrop-filter: blur(8px);
    }
    .btn-ghost:hover { background: rgba(255,255,255,.08); border-color: rgba(var(--pr),.4); transform: translateY(-2px); }

    /* floating schedule preview */
    .hero-visual {
        margin-top: 4rem; width: 100%; max-width: 780px;
        position: relative; animation: fade-up .7s .45s ease both;
    }
    .hero-card {
        background: rgba(15,23,42,.85);
        backdrop-filter: blur(24px);
        border: 1px solid var(--border);
        border-radius: 20px; overflow: hidden;
        box-shadow: 0 30px 80px rgba(0,0,0,.5), 0 0 0 1px rgba(var(--pr),.1);
    }
    .hero-card-bar {
        background: rgba(255,255,255,.04);
        border-bottom: 1px solid var(--border);
        padding: .65rem 1rem; display: flex; align-items: center; gap: .5rem;
    }
    .hc-dot { width: 11px; height: 11px; border-radius: 50%; }
    .hero-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
    .hero-table th {
        background: rgba(var(--pr),.1); color: var(--p);
        padding: .55rem .9rem; text-align: left; font-weight: 600;
        border-bottom: 1px solid var(--border);
    }
    .hero-table td {
        padding: .55rem .9rem; border-bottom: 1px solid rgba(255,255,255,.04);
        color: var(--muted);
    }
    .hero-table tr:hover td { background: rgba(255,255,255,.03); color: var(--text); }
    .td-pill {
        display: inline-block; padding: 2px 9px; border-radius: 20px;
        font-size: .72rem; font-weight: 600;
    }
    .pill-p { background: rgba(var(--pr),.15); color: var(--p); }
    .pill-g { background: rgba(16,185,129,.15); color: #10b981; }
    .pill-y { background: rgba(245,158,11,.15); color: #f59e0b; }
    .pill-v { background: rgba(167,139,250,.15); color: #a78bfa; }

    /* ── STATS BAR ─────────────────────────────────────────────── */
    .stats-bar {
        display: flex; flex-wrap: wrap; justify-content: center; gap: 0;
        border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);
        background: rgba(255,255,255,.02);
    }
    .stat-item {
        flex: 1; min-width: 140px;
        display: flex; flex-direction: column; align-items: center;
        padding: 2rem 1rem; border-right: 1px solid var(--border);
        opacity: 0; transform: translateY(16px); transition: opacity .5s, transform .5s;
    }
    .stat-item:last-child { border-right: none; }
    .stat-item.visible { opacity: 1; transform: translateY(0); }
    .stat-num {
        font-size: 2.2rem; font-weight: 900; line-height: 1;
        background: linear-gradient(135deg, var(--p), var(--s));
        -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .stat-label { font-size: .78rem; color: var(--muted); margin-top: .4rem; text-align: center; }

    /* ── SECTION WRAPPER ───────────────────────────────────────── */
    .section { padding: 6rem 1.5rem; }
    .section-inner { max-width: 1200px; margin: 0 auto; }
    .section-tag {
        display: inline-flex; align-items: center; gap: .4rem;
        background: rgba(var(--pr),.1); border: 1px solid rgba(var(--pr),.2);
        color: var(--p); border-radius: 50px; padding: .3rem .9rem;
        font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
        margin-bottom: 1rem;
    }
    .section-title {
        font-size: clamp(1.6rem, 4vw, 2.6rem); font-weight: 800; line-height: 1.2;
        margin-bottom: .9rem;
    }
    .section-sub { color: var(--muted); font-size: 1.05rem; max-width: 540px; line-height: 1.7; }

    /* ── QUICK ACCESS CARDS ────────────────────────────────────── */
    .cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 1.2rem; margin-top: 3rem;
    }
    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius); padding: 1.8rem;
        text-decoration: none; color: inherit;
        display: flex; flex-direction: column; gap: .9rem;
        opacity: 0; transform: translateY(20px); transition: opacity .45s, transform .45s, border-color .25s, box-shadow .25s, background .25s;
    }
    .card.visible { opacity: 1; transform: translateY(0); }
    .card:hover {
        transform: translateY(-6px) !important;
        border-color: rgba(var(--pr),.4);
        background: rgba(255,255,255,.06);
        box-shadow: 0 16px 40px rgba(0,0,0,.3), 0 0 0 1px rgba(var(--pr),.1);
    }
    .card-icon {
        width: 48px; height: 48px; border-radius: 13px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem;
    }
    .card h3 { font-size: 1.05rem; font-weight: 700; }
    .card p { font-size: .85rem; color: var(--muted); line-height: 1.55; flex: 1; }
    .card-arrow {
        font-size: .8rem; font-weight: 700; color: var(--p);
        display: flex; align-items: center; gap: .3rem;
    }
    .card-badge {
        display: inline-flex; align-items: center; gap: .3rem;
        font-size: .7rem; font-weight: 700; padding: 2px 8px; border-radius: 6px;
        text-transform: uppercase; letter-spacing: .04em; align-self: flex-start;
    }
    .badge-admin { background: rgba(239,68,68,.12); color: #f87171; }
    .badge-open  { background: rgba(16,185,129,.12); color: #34d399; }

    /* ── AI FEATURE STRIPS ─────────────────────────────────────── */
    .ai-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.2rem; margin-top: 3rem;
    }
    .ai-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: 1.6rem 1.8rem;
        display: flex; gap: 1.1rem; align-items: flex-start;
        opacity: 0; transform: translateY(16px); transition: opacity .45s, transform .45s;
    }
    .ai-card.visible { opacity: 1; transform: translateY(0); }
    .ai-card:hover { border-color: rgba(var(--pr),.3); background: rgba(255,255,255,.05); }
    .ai-icon {
        flex-shrink: 0; width: 42px; height: 42px; border-radius: 11px;
        display: flex; align-items: center; justify-content: center; font-size: .95rem;
    }
    .ai-card h4 { font-size: .97rem; font-weight: 700; margin-bottom: .35rem; }
    .ai-card p  { font-size: .82rem; color: var(--muted); line-height: 1.55; }
    .ai-tag { font-size: .68rem; color: var(--muted); margin-top: .4rem; font-style: italic; }

    /* ── HOW IT WORKS ──────────────────────────────────────────── */
    .steps { display: flex; flex-wrap: wrap; gap: 1.5rem; margin-top: 3rem; counter-reset: step; }
    .step {
        flex: 1; min-width: 200px;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: 1.8rem;
        counter-increment: step; position: relative;
        opacity: 0; transform: translateY(16px); transition: opacity .45s, transform .45s;
    }
    .step.visible { opacity: 1; transform: translateY(0); }
    .step::before {
        content: counter(step);
        position: absolute; top: -14px; left: 1.5rem;
        background: linear-gradient(135deg, var(--p), var(--s));
        color: #fff; width: 28px; height: 28px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: .78rem; font-weight: 800;
    }
    .step h4 { font-size: 1rem; font-weight: 700; margin-bottom: .5rem; }
    .step p  { font-size: .84rem; color: var(--muted); line-height: 1.6; }
    .step-icon { font-size: 1.6rem; margin-bottom: 1rem; }

    /* ── CTA BANNER ────────────────────────────────────────────── */
    .cta-banner {
        position: relative; overflow: hidden;
        background: linear-gradient(135deg, rgba(var(--pr),.15) 0%, rgba(var(--sr),.1) 100%);
        border-top: 1px solid rgba(var(--pr),.2); border-bottom: 1px solid rgba(var(--pr),.2);
        padding: 5rem 2rem; text-align: center;
    }
    .cta-banner h2 { font-size: clamp(1.6rem,4vw,2.4rem); font-weight: 800; margin-bottom: .8rem; }
    .cta-banner p  { color: var(--muted); font-size: 1.05rem; max-width: 520px; margin: 0 auto 2.2rem; line-height: 1.7; }

    /* ── FOOTER ────────────────────────────────────────────────── */
    .footer {
        border-top: 1px solid var(--border);
        padding: 2.5rem 2rem; display: flex; flex-wrap: wrap;
        align-items: center; justify-content: space-between; gap: 1rem;
        font-size: .82rem; color: var(--muted);
    }
    .footer-brand { display: flex; align-items: center; gap: .5rem; font-weight: 700; color: var(--text); }
    .footer-links { display: flex; gap: 1.4rem; flex-wrap: wrap; }
    .footer-links a { color: var(--muted); text-decoration: none; transition: color .2s; }
    .footer-links a:hover { color: var(--text); }
    @media (max-width: 500px) {
        .footer { flex-direction: column; text-align: center; }
        .footer-links { justify-content: center; }
    }

    /* ── PAGE LOADER ───────────────────────────────────────────── */
    #page-loader {
        position: fixed; inset: 0; z-index: 99999;
        background: var(--bg);
        display: flex; flex-direction: column;
        align-items: center; justify-content: center; gap: 1.25rem;
        transition: opacity 0.35s ease, visibility 0.35s ease;
    }
    #page-loader.loader-hidden { opacity: 0; visibility: hidden; }
    .page-loader-ring {
        width: 52px; height: 52px; border-radius: 50%;
        border: 3px solid rgba(255,255,255,0.08);
        border-top-color: var(--primary);
        animation: pl-spin 0.75s linear infinite;
    }
    @keyframes pl-spin { to { transform: rotate(360deg); } }
    .page-loader-label { font-size: 0.82rem; color: rgba(255,255,255,0.35); letter-spacing: 0.06em; }
    </style>
</head>
<body>

<div id="page-loader" aria-hidden="true">
    <div class="page-loader-ring"></div>
    <span class="page-loader-label">Loading&hellip;</span>
</div>
<script>
    window.addEventListener('load', function() {
        var loader = document.getElementById('page-loader');
        if (loader) loader.classList.add('loader-hidden');
    });
</script>

<!-- ── NAVIGATION ──────────────────────────────────────────────── -->
<nav class="nav">
    <a href="index.php" class="nav-brand">
        <?php if ($site_logo): ?>
            <img src="<?php echo $site_logo; ?>" alt="Logo">
        <?php else: ?>
            <div class="nav-brand-icon"><i class="fa-solid fa-brain"></i></div>
        <?php endif; ?>
        <?php echo $site_title; ?>
    </a>
    <button class="nav-hamburger" id="navHamburger" aria-label="Menu">
        <i class="fa-solid fa-bars"></i>
    </button>
    <div class="nav-links" id="navLinks">
        <a href="web/view_schedule.php">Schedules</a>
        <!-- <a href="web/student_view.php">Students</a> -->
        <a href="web/generate.php">Generate</a>
        <?php if ($is_logged_in): ?>
        <a href="web/dashboard.php" class="nav-cta">Dashboard &nbsp;<i class="fa-solid fa-arrow-right"></i></a>
        <a href="web/api/auth.php?logout=true">Logout</a>
        <?php else: ?>
        <a href="web/login.php" class="nav-cta">Sign In &nbsp;<i class="fa-solid fa-arrow-right"></i></a>
        <?php endif; ?>
    </div>
</nav>

<!-- ── HERO ────────────────────────────────────────────────────── -->
<header class="hero">
    <div class="orb hero-orb1"></div>
    <div class="orb hero-orb2"></div>
    <div class="orb hero-orb3"></div>

    <div class="hero-pill">
        <span class="dot"></span>
        AI-Powered Academic Timetabling
    </div>

    <h1>
        Smarter Schedules,<br>
        <span class="grad">Zero Conflicts.</span>
    </h1>

    <p class="hero-sub">
        <?php echo $site_title; ?> uses constraint solving, deep learning, and reinforcement learning 
        to generate optimized, conflict-free academic timetables in seconds.
    </p>

    <div class="hero-actions">
        <?php if ($is_logged_in): ?>
        <a href="web/dashboard.php" class="btn-glow">
            <i class="fa-solid fa-gauge-high"></i> Open Dashboard
        </a>
        <?php else: ?>
        <a href="web/login.php" class="btn-glow">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Get Started
        </a>
        <?php endif; ?>
        <a href="web/view_schedule.php" class="btn-ghost">
            <i class="fa-solid fa-calendar-days"></i> View Schedules
        </a>
    </div>

    <!-- Live Schedule Preview -->
    <div class="hero-visual">
        <div class="hero-card">
            <div class="hero-card-bar">
                <div class="hc-dot" style="background:#ef4444;"></div>
                <div class="hc-dot" style="background:#f59e0b;"></div>
                <div class="hc-dot" style="background:#22c55e;"></div>
                <span style="margin-left:.5rem;font-size:.75rem;color:var(--muted);">Live Schedule<?php if ($hero_meta): ?> — <?php echo htmlspecialchars($hero_meta); ?><?php endif; ?></span>
                <span style="margin-left:auto;font-size:.7rem;" class="td-pill pill-g">✓ 100% Accuracy</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="hero-table">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Day</th>
                            <th>Time</th>
                            <th>Room</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="heroTableBody">
                        <?php foreach ($initial_rows as $r): ?>
                        <tr>
                            <td><?php echo $r[0]; ?></td>
                            <td><?php echo $r[1]; ?></td>
                            <td><?php echo $r[2]; ?></td>
                            <td><?php echo $r[3]; ?></td>
                            <td><span class="td-pill <?php echo $r[4]; ?>"><?php echo $r[5]; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</header>

<!-- ── STATS BAR ────────────────────────────────────────────────── -->
<div class="stats-bar">
    <div class="stat-item">
        <div class="stat-num" data-target="99">0</div>
        <div class="stat-label">Accuracy Rate</div>
    </div>
    <div class="stat-item">
        <div class="stat-num" data-prefix="~" data-target="45" data-suffix="s">0</div>
        <div class="stat-label">Avg. Generation Time</div>
    </div>
    <div class="stat-item">
        <div class="stat-num" data-prefix="+" data-target="35" data-suffix="%">0</div>
        <div class="stat-label">Conflict Reduction</div>
    </div>
    <div class="stat-item">
        <div class="stat-num" data-prefix="+" data-target="28" data-suffix="%">0</div>
        <div class="stat-label">Room Utilization</div>
    </div>
</div>

<!-- ── QUICK ACCESS ─────────────────────────────────────────────── -->
<section class="section">
    <div class="section-inner">
        <div class="section-tag"><i class="fa-solid fa-bolt"></i> Quick Access</div>
        <h2 class="section-title">Everything you need,<br>in one place.</h2>
        <p class="section-sub">Navigate to any part of the system in a single click.</p>

        <div class="cards-grid">
            <a href="web/view_schedule.php" class="card" style="--delay:0s">
                <div class="card-icon" style="background:rgba(var(--pr),.12);color:var(--p);">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <span class="card-badge badge-open"><i class="fa-solid fa-globe"></i> Public</span>
                <h3>View Timetable</h3>
                <p>Browse the latest finalized course schedules for the current semester.</p>
                <div class="card-arrow">Open <i class="fa-solid fa-arrow-right"></i></div>
            </a>

            <a href="web/student_view.php" class="card" style="--delay:.06s">
                <div class="card-icon" style="background:rgba(16,185,129,.12);color:#10b981;">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <span class="card-badge badge-open"><i class="fa-solid fa-user"></i> Students</span>
                <h3>Student Portal</h3>
                <p>View your personalized timetable based on enrolled courses.</p>
                <div class="card-arrow">My Schedule <i class="fa-solid fa-arrow-right"></i></div>
            </a>

            <a href="web/generate.php" class="card" style="--delay:.12s">
                <div class="card-icon" style="background:rgba(167,139,250,.12);color:#a78bfa;">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                </div>
                <span class="card-badge badge-admin"><i class="fa-solid fa-lock"></i> Admin</span>
                <h3>AI Generation</h3>
                <p>Configure and run the AI engine to generate conflict-free schedules.</p>
                <div class="card-arrow">Generate <i class="fa-solid fa-arrow-right"></i></div>
            </a>

            <a href="web/lecturers.php" class="card" style="--delay:.18s">
                <div class="card-icon" style="background:rgba(245,158,11,.12);color:#f59e0b;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <span class="card-badge badge-open"><i class="fa-solid fa-chalkboard-teacher"></i> Lecturers</span>
                <h3>Availability</h3>
                <p>Set your weekly teaching preferences and available time windows.</p>
                <div class="card-arrow">Update <i class="fa-solid fa-arrow-right"></i></div>
            </a>

            <a href="web/import_data.php" class="card" style="--delay:.24s">
                <div class="card-icon" style="background:rgba(59,130,246,.12);color:#60a5fa;">
                    <i class="fa-solid fa-database"></i>
                </div>
                <span class="card-badge badge-admin"><i class="fa-solid fa-lock"></i> Admin</span>
                <h3>Data Management</h3>
                <p>Import CSV datasets for rooms, courses, and lecturers in bulk.</p>
                <div class="card-arrow">Import <i class="fa-solid fa-arrow-right"></i></div>
            </a>

            <a href="web/courses.php" class="card" style="--delay:.30s">
                <div class="card-icon" style="background:rgba(236,72,153,.12);color:#f472b6;">
                    <i class="fa-solid fa-book-open"></i>
                </div>
                <span class="card-badge badge-admin"><i class="fa-solid fa-lock"></i> Admin</span>
                <h3>Curriculum Control</h3>
                <p>Define courses, credit hours, levels, and department assignments.</p>
                <div class="card-arrow">Manage <i class="fa-solid fa-arrow-right"></i></div>
            </a>

            <a href="web/ai_training.php" class="card" style="--delay:.36s">
                <div class="card-icon" style="background:rgba(var(--pr),.12);color:var(--p);">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <span class="card-badge badge-admin"><i class="fa-solid fa-lock"></i> Admin</span>
                <h3>Model Retraining</h3>
                <p>Retrain Neural Networks and ML classifiers to adapt to new datasets.</p>
                <div class="card-arrow">Train <i class="fa-solid fa-arrow-right"></i></div>
            </a>
        </div>
    </div>
</section>

<!-- ── HOW IT WORKS ─────────────────────────────────────────────── -->
<section class="section" style="background:rgba(255,255,255,.015);border-top:1px solid var(--border);border-bottom:1px solid var(--border);">
    <div class="section-inner">
        <div class="section-tag"><i class="fa-solid fa-route"></i> Workflow</div>
        <h2 class="section-title">From data to timetable<br>in four steps.</h2>
        <p class="section-sub">A streamlined pipeline powered end-to-end by AI.</p>

        <div class="steps">
            <div class="step">
                <div class="step-icon">📂</div>
                <h4>Upload Data</h4>
                <p>Import your CSV files or manually input data for courses, lecturers, rooms, and semester parameters.</p>
            </div>
            <div class="step">
                <div class="step-icon">🧠</div>
                <h4>AI Solves Constraints</h4>
                <p>The CSP engine and neural networks co-optimize assignments across hundreds of variables simultaneously.</p>
            </div>
            <div class="step">
                <div class="step-icon">✅</div>
                <h4>Review & Refine</h4>
                <p>Inspect the generated schedule, resolve edge-case conflicts, and adjust via the visual editor.</p>
            </div>
            <div class="step">
                <div class="step-icon">📤</div>
                <h4>Publish</h4>
                <p>Export to PDF or CSV, save to cloud storage, and share with students and faculty instantly.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── AI FEATURES ──────────────────────────────────────────────── -->
<section class="section">
    <div class="section-inner">
        <div class="section-tag"><i class="fa-solid fa-microchip"></i> Under the Hood</div>
        <h2 class="section-title">Advanced AI that<br>actually works.</h2>
        <p class="section-sub">Six interlocking systems deliver precision scheduling at academic scale.</p>

        <div class="ai-grid">
            <div class="ai-card">
                <div class="ai-icon" style="background:rgba(var(--pr),.12);color:var(--p);">
                    <i class="fa-solid fa-cube"></i>
                </div>
                <div>
                    <h4>Constraint Satisfaction (CSP)</h4>
                    <p>Backtracking search with MRV heuristic and forward-checking eliminates conflicts before they occur.</p>
                    <div class="ai-tag">Core engine · Arc Consistency</div>
                </div>
            </div>
            <div class="ai-card">
                <div class="ai-icon" style="background:rgba(167,139,250,.12);color:#a78bfa;">
                    <i class="fa-solid fa-brain"></i>
                </div>
                <div>
                    <h4>Deep Learning Quality Scorer</h4>
                    <p>Neural classifier rates generated schedules 0–100 and flags sub-optimal assignments in real time.</p>
                    <div class="ai-tag">PyTorch · Feedforward Network</div>
                </div>
            </div>
            <div class="ai-card">
                <div class="ai-icon" style="background:rgba(16,185,129,.12);color:#10b981;">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div>
                    <h4>Q-Learning Optimizer</h4>
                    <p>Reinforcement learning agent adapts to feedback, continuously improving slot selection strategies.</p>
                    <div class="ai-tag">Bidirectional feedback loop</div>
                </div>
            </div>
            <div class="ai-card">
                <div class="ai-icon" style="background:rgba(245,158,11,.12);color:#f59e0b;">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <h4>Feasibility Predictor</h4>
                    <p>Random Forest classifier pre-validates assignments using historical patterns before committing.</p>
                    <div class="ai-tag">Random Forest · Pre-validation</div>
                </div>
            </div>
            <div class="ai-card">
                <div class="ai-icon" style="background:rgba(236,72,153,.12);color:#f472b6;">
                    <i class="fa-solid fa-magnifying-glass-chart"></i>
                </div>
                <div>
                    <h4>AI Explainability</h4>
                    <p>SHAP-based feature importance shows exactly why each course was assigned to a specific slot.</p>
                    <div class="ai-tag">SHAP · Transparent decisions</div>
                </div>
            </div>
            <div class="ai-card">
                <div class="ai-icon" style="background:rgba(34,197,94,.12);color:#22c55e;">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <div>
                    <h4>Performance Analytics</h4>
                    <p>Live dashboard tracks room utilization, lecturer satisfaction, and conflict resolution rates.</p>
                    <div class="ai-tag">Real-time metrics · History</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── CTA ──────────────────────────────────────────────────────── -->
<section class="cta-banner">
    <div class="orb" style="width:400px;height:400px;top:-150px;left:50%;transform:translateX(-50%);background:rgba(var(--pr),.12);filter:blur(100px);"></div>
    <h2>Ready to eliminate timetable chaos?</h2>
    <p>Join administrators and lecturers already using <?php echo $site_title; ?> to schedule smarter.</p>
    <?php if ($is_logged_in): ?>
    <a href="web/dashboard.php" class="btn-glow" style="font-size:1.05rem;padding:1rem 2.5rem;">
        <i class="fa-solid fa-gauge-high"></i> Go to Dashboard
    </a>
    <?php else: ?>
    <a href="web/login.php" class="btn-glow" style="font-size:1.05rem;padding:1rem 2.5rem;">
        <i class="fa-solid fa-bolt"></i> Start Scheduling Now
    </a>
    <?php endif; ?>
</section>

<!-- ── FOOTER ───────────────────────────────────────────────────── -->
<footer class="footer">
    <div class="footer-brand">
        <div class="nav-brand-icon" style="width:26px;height:26px;font-size:.8rem;">
            <i class="fa-solid fa-brain"></i>
        </div>
        <?php echo $site_title; ?>
    </div>
    <div class="footer-links">
        <a href="web/view_schedule.php">Schedules</a>
        <a href="web/student_view.php">Student Portal</a>
        <?php if (!$is_logged_in): ?>
        <a href="web/login.php">Sign In</a>
        <?php endif; ?>
        <a href="web/generate.php">Generate</a>
    </div>
    <span>&copy; <?php echo date('Y'); ?> Valley View University · AI Scheduling Team</span>
</footer>

<script>
// ── Hamburger ──
document.getElementById('navHamburger').addEventListener('click', () => {
    document.getElementById('navLinks').classList.toggle('open');
});

// ── Scroll-triggered reveal using IntersectionObserver ──
const revealEls = document.querySelectorAll('.card, .ai-card, .step, .stat-item');
const io = new IntersectionObserver((entries) => {
    entries.forEach((entry, i) => {
        if (entry.isIntersecting) {
            // stagger delay based on sibling index
            const siblings = [...entry.target.parentElement.children];
            const idx = siblings.indexOf(entry.target);
            entry.target.style.transitionDelay = (idx * 0.07) + 's';
            entry.target.classList.add('visible');
            io.unobserve(entry.target);
        }
    });
}, { threshold: 0.12 });
revealEls.forEach(el => io.observe(el));

// ── Animated counters ──
const countEls = document.querySelectorAll('.stat-num[data-target]');
const counterIO = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        const el = entry.target;
        const target = parseInt(el.dataset.target, 10);
        const prefix = el.dataset.prefix || '';
        const suffix = el.dataset.suffix || '%';
        let current = 0;
        const step = Math.ceil(target / 50);
        const timer = setInterval(() => {
            current = Math.min(current + step, target);
            el.textContent = prefix + current + suffix;
            if (current >= target) clearInterval(timer);
        }, 30);
        counterIO.unobserve(el);
    });
}, { threshold: 0.5 });
countEls.forEach(el => counterIO.observe(el));

// ── Typewriter row cycling on hero table ──
const rows = <?php echo json_encode($hero_rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const tbody = document.getElementById('heroTableBody');
let cycle = 0;
setInterval(() => {
    const r = rows[(cycle++) % rows.length];
    const newRow = document.createElement('tr');
    newRow.style.cssText = 'animation:fade-up .4s ease both;opacity:0;';
    newRow.innerHTML = `<td>${r[0]}</td><td>${r[1]}</td><td>${r[2]}</td><td>${r[3]}</td><td><span class="td-pill ${r[4]}">${r[5]}</span></td>`;
    tbody.insertBefore(newRow, tbody.firstChild);
    requestAnimationFrame(() => { newRow.style.opacity = '1'; });
    if (tbody.children.length > 5) tbody.removeChild(tbody.lastChild);
}, 2200);
</script>
</body>
</html>
