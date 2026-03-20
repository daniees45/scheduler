<?php
$conn = new mysqli('localhost', 'root', '', 'vvu_scheduler', 3306, '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$res = $conn->query("SELECT COUNT(*) as cnt FROM generated_schedules");
$row = $res->fetch_assoc();
echo "Total Rows: " . $row['cnt'] . "\n";

$res = $conn->query("DESCRIBE generated_schedules");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}
?>