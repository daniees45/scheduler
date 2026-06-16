<?php
require_once 'web/api/db.php';
session_start();
$user_id = $_SESSION['user_id'] ?? 'NONE';
echo "Session User ID: $user_id\n";

$res = $conn->query("SELECT * FROM personal_events WHERE user_id = " . (int)$user_id);
echo "Personal Events Count: " . $res->num_rows . "\n";
while($row = $res->fetch_assoc()) {
    print_r($row);
}

$res2 = $conn->query("SELECT * FROM personal_schedule WHERE user_id = " . (int)$user_id);
echo "Personal Schedule Count: " . $res2->num_rows . "\n";
while($row = $res2->fetch_assoc()) {
    print_r($row);
}
?>
