<?php
$conn = new mysqli('localhost', 'root', '', 'vvu_scheduler', 3306, '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$res = $conn->query("SELECT DISTINCT department FROM lecturers WHERE department IS NOT NULL AND department != ''");
$depts = [];
while ($row = $res->fetch_assoc()) {
    $depts[] = $row['department'];
}
echo json_encode($depts);
?>