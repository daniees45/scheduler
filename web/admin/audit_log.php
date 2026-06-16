<?php
// audit_log.php — Admin audit log viewer
require_once __DIR__ . '/../includes/header.php';
if (!in_array($user_role, ['super_admin','faculty_admin'])) {
    echo '<div class="container" style="margin:2rem auto;max-width:600px;"><h2>Access Denied</h2><p>You do not have permission to view this page.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$res = $conn->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 200");
?>
<div class="container" style="margin:2.5rem auto;max-width:900px;">
    <h2>Audit Log</h2>
    <table class="feedback-admin-table" style="width:100%;border-collapse:collapse;margin-top:1.5rem;">
        <thead><tr><th>ID</th><th>User</th><th>Action</th><th>Details</th><th>Created</th></tr></thead>
        <tbody>
        <?php while ($row = $res->fetch_assoc()): ?>
            <tr>
                <td><?php echo $row['id']; ?></td>
                <td><?php echo $row['user_id']; ?></td>
                <td><?php echo htmlspecialchars($row['action']); ?></td>
                <td><?php echo htmlspecialchars($row['details']); ?></td>
                <td><?php echo $row['created_at']; ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<style>
.feedback-admin-table th, .feedback-admin-table td {
    border: 1px solid #e5e7eb;
    padding: 0.5rem 0.7rem;
    font-size: 0.97rem;
}
.feedback-admin-table th {
    background: #f1f5f9;
    font-weight: 700;
}
.feedback-admin-table tr:nth-child(even) {
    background: #f8fafc;
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
