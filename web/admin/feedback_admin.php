<?php
// feedback_admin.php — Admin dashboard for student feedback
require_once __DIR__ . '/../includes/header.php';
if (!in_array($user_role, ['super_admin','faculty_admin'])) {
    echo '<div class="container" style="margin:2rem auto;max-width:600px;"><h2>Access Denied</h2><p>You do not have permission to view this page.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>
<div class="container" style="margin:2.2rem auto;max-width:1250px;">
    <div class="glass-panel" style="padding:1.1rem 1.2rem; margin-bottom:1rem; background: linear-gradient(135deg, rgba(79,70,229,0.12), rgba(236,72,153,0.08));">
    <div class="admin-head">
        <div>
            <h2 style="margin:0;">Student Clash Feedback</h2>
            <p class="muted">Track, filter, and resolve clash reports quickly.</p>
        </div>
        <div class="head-actions">
            <button class="btn secondary" id="refreshBtn" type="button">Refresh</button>
            <button class="btn" id="exportBtn" type="button">Export CSV</button>
        </div>
    </div>
    </div>

    <div id="feedbackStatus"></div>

    <div class="kpi-grid" id="kpiGrid">
        <div class="kpi-card"><span>Total</span><strong id="kpiTotal">0</strong></div>
        <div class="kpi-card"><span>Pending</span><strong id="kpiPending">0</strong></div>
        <div class="kpi-card"><span>Resolved</span><strong id="kpiResolved">0</strong></div>
        <div class="kpi-card"><span>Ignored</span><strong id="kpiIgnored">0</strong></div>
    </div>

    <div class="glass-panel" style="padding:0.95rem; margin-bottom:1rem;">
    <div class="toolbar">
        <input id="searchInput" class="toolbar-input" type="text" placeholder="Search by course, reason, timetable, or user ID...">
        <select id="statusFilter" class="toolbar-input">
            <option value="all">All statuses</option>
            <option value="pending">Pending</option>
            <option value="resolved">Resolved</option>
            <option value="ignored">Ignored</option>
        </select>
        <select id="semesterFilter" class="toolbar-input">
            <option value="all">All semesters</option>
        </select>
        <select id="pageSize" class="toolbar-input">
            <option value="10">10 / page</option>
            <option value="20" selected>20 / page</option>
            <option value="50">50 / page</option>
        </select>
    </div>
    </div>

    <div class="glass-panel" style="padding:0.95rem;">
        <div id="feedbackTable"></div>
    </div>

    <div class="pager" id="pager" style="display:none;">
        <button class="btn secondary" id="prevPage" type="button">Previous</button>
        <span id="pageLabel" class="muted"></span>
        <button class="btn secondary" id="nextPage" type="button">Next</button>
    </div>
</div>
<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let feedbackRows = [];
let filteredRows = [];
let currentPage = 1;

const escapeHtmlAdmin = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

function showStatus(message, type = 'info') {
    const node = document.getElementById('feedbackStatus');
    const styles = {
        success: 'background:#dcfce7;color:#166534;border-color:#86efac;',
        error: 'background:#fee2e2;color:#991b1b;border-color:#fecaca;',
        info: 'background:#e0f2fe;color:#0c4a6e;border-color:#bae6fd;'
    };
    node.innerHTML = `<div class="feedback-success" style="${styles[type] || styles.info}">${escapeHtmlAdmin(message)}</div>`;
}

function updateKpis(rows) {
    const total = rows.length;
    const pending = rows.filter(r => r.status === 'pending').length;
    const resolved = rows.filter(r => r.status === 'resolved').length;
    const ignored = rows.filter(r => r.status === 'ignored').length;
    document.getElementById('kpiTotal').textContent = total;
    document.getElementById('kpiPending').textContent = pending;
    document.getElementById('kpiResolved').textContent = resolved;
    document.getElementById('kpiIgnored').textContent = ignored;
}

function hydrateSemesterFilter(rows) {
    const sel = document.getElementById('semesterFilter');
    const current = sel.value;
    const semesters = Array.from(new Set(rows.map(r => String(r.semester || '').trim()).filter(Boolean))).sort();
    sel.innerHTML = '<option value="all">All semesters</option>' + semesters.map(s => `<option value="${escapeHtmlAdmin(s)}">${escapeHtmlAdmin(s)}</option>`).join('');
    if (current && Array.from(sel.options).some(o => o.value === current)) {
        sel.value = current;
    }
}

function applyFilters() {
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const semester = document.getElementById('semesterFilter').value;

    filteredRows = feedbackRows.filter((row) => {
        const hitStatus = status === 'all' || row.status === status;
        const hitSemester = semester === 'all' || String(row.semester || '') === semester;
        if (!hitStatus || !hitSemester) return false;

        if (!q) return true;
        const hay = [
            row.id,
            row.user_id,
            row.course1,
            row.course2,
            row.semester,
            row.reason,
            row.timetable_file,
            row.status,
            row.created_at,
        ].join(' ').toLowerCase();
        return hay.includes(q);
    });

    currentPage = 1;
    renderTable();
}

function getPagedRows() {
    const pageSize = Number(document.getElementById('pageSize').value || 20);
    const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
    if (currentPage > totalPages) currentPage = totalPages;
    const start = (currentPage - 1) * pageSize;
    const end = start + pageSize;
    return { pageRows: filteredRows.slice(start, end), totalPages };
}

function statusBadge(status) {
    const cls = status === 'resolved' ? 'ok' : status === 'ignored' ? 'warn' : 'pending';
    return `<span class="status-pill ${cls}">${escapeHtmlAdmin(status)}</span>`;
}

function renderTable() {
    const tableDiv = document.getElementById('feedbackTable');
    if (!filteredRows.length) {
        tableDiv.innerHTML = '<div class="feedback-success">No feedback matches your filters.</div>';
        document.getElementById('pager').style.display = 'none';
        return;
    }

    const { pageRows, totalPages } = getPagedRows();

    let html = '<div class="table-wrap"><table class="feedback-admin-table">';
    html += '<thead><tr><th>ID</th><th>User</th><th>Courses</th><th>Semester</th><th>Timetable</th><th>Reason</th><th>Status</th><th>Created</th><th>Action</th></tr></thead><tbody>';
    for (const row of pageRows) {
        html += `<tr>
            <td>${escapeHtmlAdmin(row.id)}</td>
            <td>${escapeHtmlAdmin(row.user_id)}</td>
            <td><strong>${escapeHtmlAdmin(row.course1)}</strong><br><span class="muted">${escapeHtmlAdmin(row.course2)}</span></td>
            <td>${escapeHtmlAdmin(row.semester)}</td>
            <td title="${escapeHtmlAdmin(row.timetable_file || '')}">${escapeHtmlAdmin(row.timetable_file || 'N/A')}</td>
            <td>${row.reason ? escapeHtmlAdmin(row.reason) : '<span class="muted">No reason supplied</span>'}</td>
            <td>${statusBadge(row.status)}</td>
            <td>${escapeHtmlAdmin(row.created_at)}</td>
            <td>
                <div class="action-row">
                    <select id="st_${row.id}" class="status-select">
                        <option value="pending" ${row.status === 'pending' ? 'selected' : ''}>Pending</option>
                        <option value="resolved" ${row.status === 'resolved' ? 'selected' : ''}>Resolved</option>
                        <option value="ignored" ${row.status === 'ignored' ? 'selected' : ''}>Ignored</option>
                    </select>
                    <button class="btn tiny" type="button" onclick="updateStatus(${Number(row.id)})">Save</button>
                </div>
            </td>
        </tr>`;
    }
    html += '</tbody></table></div>';
    tableDiv.innerHTML = html;

    document.getElementById('pager').style.display = 'flex';
    document.getElementById('pageLabel').textContent = `Page ${currentPage} of ${totalPages}`;
    document.getElementById('prevPage').disabled = currentPage <= 1;
    document.getElementById('nextPage').disabled = currentPage >= totalPages;
}

async function loadFeedback() {
    try {
        const res = await fetch('../api/feedback_db.php', { credentials: 'same-origin' });
        const data = await res.json();
        if (data.status !== 'success') {
            showStatus('Failed to load feedback: ' + (data.message || ''), 'error');
            document.getElementById('feedbackTable').innerHTML = '';
            return;
        }

        feedbackRows = Array.isArray(data.data) ? data.data : [];
        updateKpis(feedbackRows);
        hydrateSemesterFilter(feedbackRows);
        applyFilters();
    } catch (err) {
        showStatus('Failed to load feedback: ' + (err && err.message ? err.message : 'Unknown error'), 'error');
    }
}

async function updateStatus(id) {
    const sel = document.getElementById(`st_${id}`);
    if (!sel) return;

    const fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('feedback_id', String(id));
    fd.append('status', sel.value);
    fd.append('csrf_token', csrfToken);

    try {
        const res = await fetch('../api/feedback_db.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });
        const data = await res.json();
        if (data.status !== 'success') {
            showStatus(data.message || 'Failed to update status.', 'error');
            return;
        }

        const idx = feedbackRows.findIndex(r => Number(r.id) === Number(id));
        if (idx >= 0) feedbackRows[idx].status = sel.value;
        showStatus('Feedback status updated.', 'success');
        updateKpis(feedbackRows);
        applyFilters();
    } catch (err) {
        showStatus('Update failed: ' + (err && err.message ? err.message : 'Unknown error'), 'error');
    }
}

function exportCsv() {
    if (!filteredRows.length) {
        showStatus('No rows available to export.', 'info');
        return;
    }

    const header = ['id', 'user_id', 'course1', 'course2', 'semester', 'timetable_file', 'reason', 'status', 'created_at'];
    const lines = [header.join(',')];
    for (const row of filteredRows) {
        const vals = header.map((k) => {
            const v = String(row[k] ?? '').replace(/"/g, '""');
            return `"${v}"`;
        });
        lines.push(vals.join(','));
    }

    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'student_feedback_export.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

document.getElementById('searchInput').addEventListener('input', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);
document.getElementById('semesterFilter').addEventListener('change', applyFilters);
document.getElementById('pageSize').addEventListener('change', applyFilters);
document.getElementById('refreshBtn').addEventListener('click', loadFeedback);
document.getElementById('exportBtn').addEventListener('click', exportCsv);

document.getElementById('prevPage').addEventListener('click', () => {
    currentPage = Math.max(1, currentPage - 1);
    renderTable();
});

document.getElementById('nextPage').addEventListener('click', () => {
    const pageSize = Number(document.getElementById('pageSize').value || 20);
    const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
    currentPage = Math.min(totalPages, currentPage + 1);
    renderTable();
});

loadFeedback();
</script>
<style>
.admin-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}
.muted {
    color: var(--text-muted);
    font-size: 0.9rem;
}
.head-actions {
    display: flex;
    gap: 0.5rem;
}
.btn {
    border: 1px solid rgba(255,255,255,0.08);
    background: var(--primary-color);
    color: #fff;
    border-radius: 8px;
    padding: 0.55rem 0.9rem;
    cursor: pointer;
    font-weight: 600;
}
.btn.secondary {
    background: rgba(15,23,42,0.75);
}
.btn.tiny {
    padding: 0.35rem 0.55rem;
    font-size: 0.82rem;
}
.btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0.8rem;
    margin-bottom: 1rem;
}
.kpi-card {
    background: rgba(15,23,42,0.55);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 12px;
    padding: 0.8rem;
}
.kpi-card span {
    color: var(--text-muted);
    font-size: 0.85rem;
}
.kpi-card strong {
    display: block;
    font-size: 1.35rem;
    margin-top: 0.2rem;
    color: var(--primary-color);
}
.toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) 180px 180px 130px;
    gap: 0.6rem;
}
.toolbar-input {
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    padding: 0.55rem 0.65rem;
    background: rgba(15,23,42,0.45);
    color: var(--text-color);
}
.table-wrap {
    overflow-x: auto;
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px;
    background: rgba(2,6,23,0.35);
}
.feedback-admin-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1080px;
}
.feedback-admin-table th,
.feedback-admin-table td {
    border-bottom: 1px solid rgba(255,255,255,0.08);
    padding: 0.58rem 0.64rem;
    font-size: 0.93rem;
    vertical-align: top;
}
.feedback-admin-table th {
    background: rgba(148,163,184,0.12);
    font-weight: 700;
    color: var(--text-muted);
    text-align: left;
}
.feedback-admin-table tr:hover {
    background: rgba(255,255,255,0.04);
}
.status-pill {
    display: inline-block;
    padding: 0.2rem 0.45rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: capitalize;
}
.status-pill.pending {
    background: #fef3c7;
    color: #92400e;
}
.status-pill.ok {
    background: #dcfce7;
    color: #166534;
}
.status-pill.warn {
    background: #e2e8f0;
    color: #334155;
}
.action-row {
    display: flex;
    gap: 0.45rem;
    align-items: center;
}
.status-select {
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    padding: 0.35rem 0.4rem;
    min-width: 110px;
    font-size: 0.85rem;
    background: rgba(15,23,42,0.55);
    color: var(--text-color);
}
.pager {
    margin-top: 0.75rem;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.65rem;
}
.feedback-success {
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.08);
    padding: 0.75rem 0.9rem;
    margin-bottom: 0.9rem;
}
@media (max-width: 900px) {
    .toolbar {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
