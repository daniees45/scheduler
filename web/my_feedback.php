<?php
// my_feedback.php — Student feedback status dashboard
require_once __DIR__ . '/includes/header.php';
if ($user_role !== 'student') {
    echo '<div class="container" style="margin:2rem auto;max-width:600px;"><h2>Access Denied</h2><p>Only students can view their feedback status.</p></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
?>
<div class="container" style="margin:2.5rem auto;max-width:900px;">
    <h2>My Clash Feedback</h2>
    <div id="myFeedbackTable"></div>
</div>
<script>
async function loadMyFeedback() {
    const tableDiv = document.getElementById('myFeedbackTable');
    try {
        const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
        const endpoint = window.location.origin + basePath + 'api/feedback_db.php?mine=1';
        const res = await fetch(endpoint, { credentials: 'same-origin' });

        if (!res.ok) {
            throw new Error('HTTP ' + res.status);
        }

        const data = await res.json();
        if (data.status !== 'success') {
            tableDiv.innerHTML = '<div class="feedback-success" style="background:#fee2e2;color:#991b1b;border-color:#fecaca;">Failed to load feedback: ' + (data.message || '') + '</div>';
            return;
        }

        const rows = data.data || [];
        if (!rows.length) {
            tableDiv.innerHTML = '<div class="feedback-success">You have not submitted any clash feedback yet.</div>';
            return;
        }

        let html = '<table class="feedback-admin-table" style="width:100%;border-collapse:collapse;margin-top:1.5rem;">';
        html += '<thead><tr><th>Course 1</th><th>Course 2</th><th>Semester</th><th>Reason</th><th>Status</th><th>Created</th></tr></thead><tbody>';
        for (const row of rows) {
            html += `<tr>
                <td>${row.course1}</td>
                <td>${row.course2}</td>
                <td>${row.semester}</td>
                <td>${row.reason ? row.reason.replace(/</g, '&lt;').replace(/>/g, '&gt;') : ''}</td>
                <td>${row.status}</td>
                <td>${row.created_at}</td>
            </tr>`;
        }
        html += '</tbody></table>';
        tableDiv.innerHTML = html;
    } catch (err) {
        tableDiv.innerHTML = '<div class="feedback-success" style="background:#fee2e2;color:#991b1b;border-color:#fecaca;">Failed to load feedback: ' + (err && err.message ? err.message : 'Unexpected error') + '</div>';
        console.error('loadMyFeedback error:', err);
    }
}
loadMyFeedback();
</script>
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
<?php require_once __DIR__ . '/includes/footer.php'; ?>
