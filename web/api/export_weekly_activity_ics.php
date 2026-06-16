<?php
require_once 'db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$daysMap = ['Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3, 'Friday' => 4, 'Saturday' => 5, 'Sunday' => 6];
$weekStart = new DateTime('monday this week');
$weekEnd = new DateTime('sunday this week');

$stmt = $conn->prepare("
    SELECT task_name, task_category, day, start_time, end_time, duration_minutes, notes, logged_at
    FROM productivity_log
    WHERE user_id = ? AND logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY logged_at DESC, day ASC, start_time ASC
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();

$events = [];
while ($row = $result->fetch_assoc()) {
    if (!isset($daysMap[$row['day']])) {
        continue;
    }

    $eventDate = clone $weekStart;
    $eventDate->modify('+' . $daysMap[$row['day']] . ' days');

    $startTime = date('H:i', strtotime($row['start_time']));
    $endTime = date('H:i', strtotime($row['end_time']));

    $events[] = [
        'summary' => $row['task_name'],
        'description' => trim(($row['task_category'] ?: 'Activity') . ' | ' . ($row['notes'] ?: 'Logged in Scheduler')),
        'date' => $eventDate->format('Y-m-d'),
        'start_time' => $startTime,
        'end_time' => $endTime,
    ];
}

if (empty($events)) {
    http_response_code(404);
    echo 'No weekly activities found to export.';
    exit;
}

$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//VVU Scheduler//Weekly Activity Export//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";

foreach ($events as $event) {
    $start = str_replace(['-', ':'], '', $event['date'] . 'T' . $event['start_time']) . '00';
    $end = str_replace(['-', ':'], '', $event['date'] . 'T' . $event['end_time']) . '00';
    $uid = sha1($event['summary'] . '|' . $start . '|' . $user_id) . '@vvuscheduler.local';
    $description = str_replace(["\r", "\n"], [' ', ' '], $event['description']);
    $summary = str_replace(["\r", "\n"], [' ', ' '], $event['summary']);

    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= "UID:{$uid}\r\n";
    $ics .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    $ics .= "DTSTART:{$start}\r\n";
    $ics .= "DTEND:{$end}\r\n";
    $ics .= "SUMMARY:" . $summary . "\r\n";
    $ics .= "DESCRIPTION:" . $description . "\r\n";
    $ics .= "END:VEVENT\r\n";
}

$ics .= "END:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="weekly_activity.ics"');
echo $ics;
