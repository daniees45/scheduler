<?php
require_once 'web/api/db.php';
$res = $conn->query("SELECT DISTINCT department FROM generated_schedules");
$depts = [];
while ($row = $res->fetch_assoc()) {
    $depts[] = $row['department'];
}
echo json_encode($depts);
?>