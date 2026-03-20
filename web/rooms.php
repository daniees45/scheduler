<?php
$page_title = 'Manage Rooms';
$page_css = 'assets/rooms.css';
include 'includes/header.php';
require_once 'api/db.php';
require_once __DIR__ . '/../lib/B2Storage.php';

requireAdmin();

$b2 = new B2Storage();

$room_sources = [
    ['key' => 'csv/general/rooms.csv', 'label' => 'General Rooms'],
    ['key' => 'csv/department/computing_science_rooms.csv', 'label' => 'Computing Science Rooms'],
    ['key' => 'csv/department/nursing_rooms.csv', 'label' => 'Nursing Rooms'],
    ['key' => 'csv/department/theology_rooms.csv', 'label' => 'Theology Rooms'],
    ['key' => 'csv/department/business_rooms.csv', 'label' => 'Business Rooms'],
    ['key' => 'csv/department/education_rooms.csv', 'label' => 'Education Rooms'],
    ['key' => 'csv/department/biomedical_engineering_rooms.csv', 'label' => 'Biomedical Engineering Rooms'],
    ['key' => 'csv/department/development_studies_rooms.csv', 'label' => 'Development Studies Rooms'],
];

function parse_rooms_csv($csv_content)
{
    $rows = [];
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv_content);
    rewind($handle);

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return [];
    }

    $header_map = array_flip(array_map('trim', array_map('strtolower', $headers)));
    $name_idx = $header_map['room_name'] ?? -1;
    $cap_idx = $header_map['capacity'] ?? -1;

    // Fallback if headers are not found or named differently
    if ($name_idx === -1) $name_idx = 0;
    if ($cap_idx === -1) $cap_idx = 1;

    while (($line = fgetcsv($handle, 1000, ',')) !== false) {
        $room_name = trim($line[$name_idx] ?? '');
        if ($room_name === '') {
            continue;
        }
        $capacity = (int) ($line[$cap_idx] ?? 50);
        if ($capacity <= 0) {
            $capacity = 50;
        }
        $rows[] = [
            'room_name' => $room_name,
            'capacity' => $capacity,
        ];
    }

    fclose($handle);
    return $rows;
}

$source_data = [];
foreach ($room_sources as $source) {
    $result = $b2->download($source['key']);
    if ($result['success']) {
        $source_data[] = [
            'key' => $source['key'],
            'label' => $source['label'],
            'rows' => parse_rooms_csv($result['content']),
            'load_error' => null,
        ];
    } else {
        $source_data[] = [
            'key' => $source['key'],
            'label' => $source['label'],
            'rows' => [],
            'load_error' => $result['error'] ?? 'Failed to load from B2',
        ];
    }
}
?>

<div class="glass-panel rooms-panel">
    <div class="rooms-header-row">
        <div>
            <h2 class="rooms-title"><i class="fa-solid fa-building"></i> Department Rooms Management</h2>
            <p class="rooms-subtitle">Manage room name and capacity per department file from B2.</p>
        </div>
        <button class="glass-btn" onclick="saveCurrentSource()"><i class="fa-solid fa-floppy-disk"></i> Save to B2 + DB</button>
    </div>

    <div class="rooms-toolbar">
        <select id="sourceSelect" class="glass-input" onchange="changeSource()"></select>
        <button class="glass-btn secondary" onclick="addRoomRow()"><i class="fa-solid fa-plus"></i> Add Room</button>
        <button class="glass-btn secondary" onclick="reloadSource()"><i class="fa-solid fa-rotate"></i> Reload</button>
    </div>

    <div id="sourceStatus" class="rooms-source-status"></div>

    <div class="rooms-table-wrap">
        <table class="rooms-table">
            <thead>
                <tr class="rooms-table-head-row">
                    <th class="rooms-head-cell">Room Name</th>
                    <th class="rooms-head-cell rooms-capacity-col">Capacity</th>
                    <th class="rooms-head-cell rooms-action-col">Action</th>
                </tr>
            </thead>
            <tbody id="roomsBody"></tbody>
        </table>
    </div>
</div>

<script>
const roomSources = <?php echo json_encode($source_data, JSON_UNESCAPED_UNICODE); ?>;
let selectedKey = roomSources.length ? roomSources[0].key : null;
let workingRows = [];

function sourceByKey(key) {
    return roomSources.find(source => source.key === key) || null;
}

function renderSourceSelect() {
    const select = document.getElementById('sourceSelect');
    select.innerHTML = '';

    roomSources.forEach(source => {
        const opt = document.createElement('option');
        opt.value = source.key;
        opt.textContent = `${source.label} (${source.rows.length})`;
        if (source.key === selectedKey) {
            opt.selected = true;
        }
        select.appendChild(opt);
    });
}

function renderRows() {
    const tbody = document.getElementById('roomsBody');
    tbody.innerHTML = '';

    workingRows.forEach((row, index) => {
        const tr = document.createElement('tr');
        tr.className = 'rooms-table-row';
        tr.innerHTML = `
            <td class="rooms-body-cell">
                <input class="glass-input rooms-input" value="${escapeHtml(row.room_name)}" onchange="updateRoomName(${index}, this.value)">
            </td>
            <td class="rooms-body-cell">
                <input type="number" min="1" class="glass-input rooms-input" value="${parseInt(row.capacity || 50, 10)}" onchange="updateCapacity(${index}, this.value)">
            </td>
            <td class="rooms-body-cell rooms-action-cell">
                <button class="glass-btn secondary small" onclick="removeRow(${index})"><i class="fa-solid fa-trash"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function renderStatus() {
    const source = sourceByKey(selectedKey);
    const status = document.getElementById('sourceStatus');
    if (!source) {
        status.textContent = 'No room source available.';
        return;
    }

    if (source.load_error) {
        status.innerHTML = `<span class="rooms-status-warning">Loaded with warning: ${escapeHtml(source.load_error)}</span>`;
        return;
    }

    status.textContent = `${source.label} · ${workingRows.length} room(s)`;
}

async function fetchSourceFromServer(key) {
    try {
        const res = await fetch(`api/get_room_source.php?source_key=${encodeURIComponent(key)}`);
        const payload = await res.json();
        if (payload && payload.status === 'success' && Array.isArray(payload.rooms)) {
            const source = sourceByKey(key);
            if (source) {
                source.rows = payload.rooms.map(r => ({
                    room_name: String(r.room_name || '').trim(),
                    capacity: parseInt(r.capacity, 10) || 50
                }));
                source.load_error = null;
            }
            return true;
        }
    } catch (error) {
        console.error('Failed to fetch source from server:', error);
    }
    return false;
}

function changeSource() {
    selectedKey = document.getElementById('sourceSelect').value;
    const source = sourceByKey(selectedKey);
    workingRows = source ? source.rows.map(room => ({ ...room })) : [];
    renderRows();
    renderStatus();
}

function addRoomRow() {
    workingRows.push({ room_name: '', capacity: 50 });
    renderRows();
    renderStatus();
}

function removeRow(index) {
    workingRows.splice(index, 1);
    renderRows();
    renderStatus();
}

function updateRoomName(index, value) {
    workingRows[index].room_name = value;
}

function updateCapacity(index, value) {
    const parsed = parseInt(value, 10);
    workingRows[index].capacity = Number.isFinite(parsed) && parsed > 0 ? parsed : 50;
}

async function reloadSource() {
    if (!selectedKey) return;
    await fetchSourceFromServer(selectedKey);
    changeSource();
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function saveCurrentSource() {
    if (!selectedKey) {
        await showAlert('No room source selected.', 'Save Error');
        return;
    }

    const cleanRows = workingRows
        .map(row => ({
            room_name: String(row.room_name || '').trim(),
            capacity: parseInt(row.capacity, 10) || 50
        }))
        .filter(row => row.room_name.length > 0)
        .map(row => [row.room_name, row.capacity]);

    if (cleanRows.length === 0) {
        await showAlert('Add at least one room before saving.', 'Validation');
        return;
    }

    const payload = {
        file: selectedKey,
        data: [['room_name', 'capacity'], ...cleanRows]
    };

    try {
        const res = await fetch('api/save_csv.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.status !== 'success') {
            await showAlert(data.message || 'Failed to save room file.', 'Save Error');
            return;
        }

        const source = sourceByKey(selectedKey);
        if (source) {
            source.rows = cleanRows.map(row => ({ room_name: row[0], capacity: row[1] }));
            source.load_error = null;
        }

        await fetchSourceFromServer(selectedKey);

        workingRows = cleanRows.map(row => ({ room_name: row[0], capacity: row[1] }));
        renderSourceSelect();
        renderRows();
        renderStatus();
        await showAlert('Saved to B2 and synced to database successfully.', 'Success');
    } catch (error) {
        await showAlert('Save failed: ' + error.message, 'Save Error');
    }
}

renderSourceSelect();
changeSource();
</script>

<?php include 'includes/footer.php'; ?>