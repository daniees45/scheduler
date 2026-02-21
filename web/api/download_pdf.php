<?php
// as/api/download_pdf.php
require_once 'db.php';

$id = $_GET['id'] ?? 0;

if (!$id) {
    die("Invalid ID");
}

$stmt = $conn->prepare("SELECT pdf_content, version_name FROM schedule_versions WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    if (!$row['pdf_content']) {
        die("PDF not available for this version.");
    }

    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $row['version_name']) . '.pdf';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo $row['pdf_content'];
} else {
    die("Version not found.");
}
?>
