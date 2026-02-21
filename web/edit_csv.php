<?php
// web/edit_csv.php
$page_title = 'Edit CSV Data';
include 'includes/header.php';

// Check if data is from session (uploaded CSV)
if (isset($_GET['session']) && isset($_SESSION['uploaded_csv'])) {
    $csvData = $_SESSION['uploaded_csv']['data'];
    $filename = $_SESSION['uploaded_csv']['filename'];
    $upload_id = $_SESSION['uploaded_csv']['id'];
    
    $headers = array_shift($csvData);
    $rows = $csvData;
    $source = 'session';
    $is_file = false;
    
} elseif (isset($_GET['file'])) {
    $filename = $_GET['file'];
    // Security: only allow csv/ or root level csv files
    if (strpos($filename, '..') !== false) die("Access denied");
    
    // Use B2Storage to get file content
    require_once '../lib/B2Storage.php';
    $b2 = new B2Storage();
    
    // Key mapping logic (same as save_csv.php)
    $key = ltrim(str_replace('../', '', $filename), '/');
    if (strpos($key, 'csv/') !== 0) {
       $key = 'csv/general/' . $key;
    }
    
    $result = $b2->download($key);
    
    if (!$result['success']) {
        die("File not found in B2: " . htmlspecialchars($filename));
    }
    
    $csvData = [];
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $result['content']);
    rewind($handle);
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $csvData[] = $data;
    }
    fclose($handle);
    
    if (empty($csvData)) die("File is empty: " . htmlspecialchars($filename));
    
    $headers = array_shift($csvData);
    $rows = $csvData;
    $source = 'file';
    $is_file = true;

} else {
    echo '<div style="padding: 2rem; text-align: center;">';
    echo '<i class="fa-solid fa-exclamation-triangle" style="font-size: 3rem; color: #f59e0b;"></i>';
    echo '<h2>No Data to Edit</h2>';
    echo '<p>Please upload a CSV file first.</p>';
    echo '<a href="generate.php" class="glass-btn"><i class="fa-solid fa-arrow-left"></i> Back to Generator</a>';
    echo '</div>';
    include 'includes/footer.php';
    exit;
}
?>

<div class="glass-panel" style="padding: 2rem;">
    <div id="saveSyncBanner" style="display: none; margin-bottom: 1rem; padding: 0.9rem 1rem; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.45); background: rgba(16, 185, 129, 0.12); color: #10b981; font-weight: 600;"></div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h2><i class="fa-solid fa-edit"></i> Editing: <?php echo htmlspecialchars($filename); ?></h2>
        <div style="display: flex; gap: 10px;">
            <button onclick="addRow()" class="glass-btn secondary small"><i class="fa-solid fa-plus"></i> Add Row</button>
            <?php if ($is_file): ?>
                <button onclick="saveToFile()" class="glass-btn small" style="background: linear-gradient(135deg, #10b981, #34d399);">
                    <i class="fa-solid fa-save"></i> Save Changes
                </button>
            <?php endif; ?>
            <button onclick="useForScheduling()" class="glass-btn small" style="background: linear-gradient(135deg, #6366f1, #a855f7);">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Use for Scheduling
            </button>
        </div>
    </div>

    <div style="margin-bottom: 1rem; padding: 1rem; background: rgba(99, 102, 241, 0.1); border-radius: 8px;">
        <p style="margin: 0; display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-info-circle" style="color: #6366f1;"></i>
            <span style="color: var(--text-muted);">Edit cells directly in the table. Click <strong>Use for Scheduling</strong> when ready to generate the timetable.</span>
        </p>
    </div>

    <div id="paginationControls" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <input type="text" id="searchInput" class="glass-input" style="min-width: 220px;" placeholder="Search rows..." oninput="applyFilter()">
            <button class="glass-btn secondary small" type="button" onclick="clearFilter()">
                <i class="fa-solid fa-rotate"></i> Reset
            </button>
            <label style="color: var(--text-muted); font-size: 0.9rem;">Rows per page</label>
            <select id="rowsPerPage" class="glass-input" style="width: 110px;" onchange="setRowsPerPage()">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <button class="glass-btn secondary small" id="prevPageBtn" onclick="goToPage(currentPage - 1)">
                <i class="fa-solid fa-chevron-left"></i> Prev
            </button>
            <span id="pageInfo" style="color: var(--text-muted); font-size: 0.9rem;">Page 1 of 1</span>
            <button class="glass-btn secondary small" id="nextPageBtn" onclick="goToPage(currentPage + 1)">
                Next <i class="fa-solid fa-chevron-right"></i>
            </button>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table class="data-table" id="csvTable">
            <thead>
                <tr>
                    <?php foreach ($headers as $h): ?>
                        <th><?php echo htmlspecialchars($h); ?></th>
                    <?php endforeach; ?>
                    <th style="width: 50px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $rowIndex => $row): ?>
                    <tr>
                        <?php foreach ($row as $cellIndex => $cell): ?>
                            <td contenteditable="true"><?php echo htmlspecialchars($cell); ?></td>
                        <?php endforeach; ?>
                        <td>
                            <button class="text-btn danger" onclick="deleteRow(this)"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
let currentPage = 1;
let rowsPerPage = 25;
let currentSearch = '';

function getFilteredRows() {
    const table = document.getElementById('csvTable');
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    const query = currentSearch.trim().toLowerCase();
    if (!query) return rows;

    return rows.filter(row => {
        const cells = Array.from(row.querySelectorAll('td'));
        return cells.some(cell => cell.innerText.toLowerCase().includes(query));
    });
}

function applyFilter() {
    const input = document.getElementById('searchInput');
    currentSearch = input.value || '';
    currentPage = 1;
    updatePaginationState();
}

function clearFilter() {
    const input = document.getElementById('searchInput');
    input.value = '';
    currentSearch = '';
    currentPage = 1;
    updatePaginationState();
}

function updatePaginationState() {
    const table = document.getElementById('csvTable');
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    const filteredRows = getFilteredRows();
    const totalRows = filteredRows.length;
    const totalPages = Math.max(1, Math.ceil(totalRows / rowsPerPage));
    if (currentPage > totalPages) currentPage = totalPages;

    rows.forEach(row => {
        row.style.display = 'none';
    });

    filteredRows.forEach((row, index) => {
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        row.style.display = (index >= start && index < end) ? '' : 'none';
    });

    document.getElementById('pageInfo').innerText = `Page ${currentPage} of ${totalPages}`;
    document.getElementById('prevPageBtn').disabled = currentPage <= 1;
    document.getElementById('nextPageBtn').disabled = currentPage >= totalPages;
}

function goToPage(page) {
    if (page < 1) page = 1;
    currentPage = page;
    updatePaginationState();
}

function setRowsPerPage() {
    const select = document.getElementById('rowsPerPage');
    rowsPerPage = parseInt(select.value, 10) || 25;
    currentPage = 1;
    updatePaginationState();
}

function addRow() {
    const table = document.getElementById('csvTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
    const colCount = <?php echo count($headers); ?>;
    
    for (let i = 0; i < colCount; i++) {
        const cell = newRow.insertCell(i);
        cell.contentEditable = "true";
        cell.innerText = "";
    }
    
    const actionCell = newRow.insertCell(colCount);
    actionCell.innerHTML = '<button class="text-btn danger" onclick="deleteRow(this)"><i class="fa-solid fa-trash"></i></button>';

    updatePaginationState();
}

async function deleteRow(btn) {
    if (await customConfirm('Delete Row', 'Are you sure you want to delete this row?')) {
        const row = btn.parentNode.parentNode;
        row.parentNode.removeChild(row);
        updatePaginationState();
    }
}

async function saveToFile() {
    const data = getTableData();
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

    try {
        const response = await fetch('api/save_csv.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                data: data,
                file: '<?php echo $filename; ?>'
            })
        });
        
        const result = await response.json();
        if (result.status === 'success') {
            const syncedTable = result.synced_table ? ` → DB synced: ${result.synced_table}` : '';
            showSaveSyncBanner(`Saved ${result.synced_file || '<?php echo basename($filename); ?>'}${syncedTable}`);
            await customAlert('Success', result.message, 'success');
        } else {
            showSaveSyncBanner(result.message || 'Save failed', true);
            await customAlert('Error', result.message, 'error');
        }
    } catch (error) {
        showSaveSyncBanner('Save failed: ' + error.message, true);
        await customAlert('System Error', 'Failed to save: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

function showSaveSyncBanner(message, isError = false) {
    const banner = document.getElementById('saveSyncBanner');
    if (!banner) return;
    banner.style.display = 'block';
    banner.textContent = message;

    if (isError) {
        banner.style.background = 'rgba(239, 68, 68, 0.12)';
        banner.style.border = '1px solid rgba(239, 68, 68, 0.45)';
        banner.style.color = '#ef4444';
    } else {
        banner.style.background = 'rgba(16, 185, 129, 0.12)';
        banner.style.border = '1px solid rgba(16, 185, 129, 0.45)';
        banner.style.color = '#10b981';
    }
}

function getTableData() {
    const table = document.getElementById('csvTable');
    const rows = table.querySelectorAll('tbody tr');
    const headers = <?php echo json_encode($headers); ?>;
    const data = [headers];
    
    rows.forEach(tr => {
        const rowData = [];
        const cells = tr.querySelectorAll('td');
        for (let i = 0; i < headers.length; i++) {
            rowData.push(cells[i].innerText.trim());
        }
        data.push(rowData);
    });
    return data;
}

async function useForScheduling() {
    const data = getTableData();
    
    try {
        // Save edited data to session
        const response = await fetch('api/save_to_session.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                data: data,
                filename: '<?php echo $filename; ?>'
            })
        });
        
        const result = await response.json();
        if (result.status === 'success') {
            // Redirect to generate page with ready data
            window.location.href = 'generate.php?ready=1';
        } else {
            await customAlert('Error', result.message, 'error');
        }
    } catch (error) {
        await customAlert('System Error', 'Failed to save: ' + error.message, 'error');
    }
}

window.addEventListener('load', () => {
    const select = document.getElementById('rowsPerPage');
    rowsPerPage = parseInt(select.value, 10) || 25;
    updatePaginationState();
});
</script>



<?php include 'includes/footer.php'; ?>
