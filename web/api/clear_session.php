<?php
// web/api/clear_session.php
// Clear uploaded/ready CSV data from session
session_start();

header('Content-Type: application/json');

unset($_SESSION['uploaded_csv']);
unset($_SESSION['ready_for_scheduling']);

echo json_encode(['status' => 'success', 'message' => 'Session cleared']);
?>
