<?php
$page_title = 'PDF to CSV (ETL Wizard)';
include 'includes/header.php';
require_once 'api/db.php';

// Access Control - Admin Only
requireAdmin();
requireRole(['super_admin', 'faculty_admin']);
?>

<div class="glass-panel" style="padding: 2rem; max-width: 900px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h2><i class="fa-solid fa-wand-magic-sparkles"></i> Data Processing Wizard (ETL)</h2>
            <p style="color: var(--text-muted);">Extract PDF data and clean it before syncing to the database.</p>
        </div>
    </div>

    <div style="background: rgba(99, 102, 241, 0.1); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(99, 102, 241, 0.2);">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
            <!-- Automated ETL (Recommended) -->
             <div style="background: rgba(16, 185, 129, 0.1); padding: 1rem; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.2);">
                <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem; color: #10b981;"><i class="fa-solid fa-bolt"></i> One-Click ETL (Auto)</h4>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.8rem;">Runs extraction AND cleanup in one go. Highly recommended.</p>
                <form id="autoEtlForm" enctype="multipart/form-data">
                    <input type="file" name="pdf_file" class="glass-input" style="font-size: 0.8rem; margin-bottom: 0.5rem;" accept=".pdf" required>
                    <input type="text" name="filename" class="glass-input" placeholder="Final Name (e.g. departmental_courses.csv)" style="font-size: 0.8rem; margin-bottom: 0.5rem;" required>
                    <select name="output_folder" class="glass-input" style="font-size: 0.8rem; margin-bottom: 0.5rem;">
                        <option value="csv/department/">csv/department/</option>
                        <option value="csv/general/">csv/general/</option>
                        <option value="csv/final/">csv/final/</option>
                        <option value="">Root Folder (B2)</option>
                    </select>
                    <button type="submit" class="glass-btn primary small" style="width: 100%; border-color: #10b981; background: rgba(16, 185, 129, 0.1);"><i class="fa-solid fa-rocket"></i> Automated Extract & Clean</button>
                </form>
            </div>

            <!-- Extraction
            div style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px;">
                <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem;">Manual: 1. Extract PDF</h4>
                <form id="extractForm" enctype="multipart/form-data">
                    <input type="file" name="pdf_file" class="glass-input" style="font-size: 0.8rem; margin-bottom: 0.5rem;" accept=".pdf" required>
                    <input type="text" name="filename" class="glass-input" placeholder="Save as (e.g. vvu_raw.csv)" style="font-size: 0.8rem; margin-bottom: 0.5rem;" required>
                    <select name="output_folder" class="glass-input" style="font-size: 0.8rem; margin-bottom: 0.5rem;">
                        <option value="">Root Folder (B2)</option>
                        <option value="csv/general/">csv/general/</option>
                        <option value="csv/department/">csv/department/</option>
                        <option value="csv/final/">csv/final/</option>
                    </select>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                        <input type="checkbox" name="validate_structure" value="1" checked>
                        Validate Structure
                    </label>
                    <button type="submit" class="glass-btn secondary small" style="width: 100%"><i class="fa-solid fa-file-export"></i> Extract Only</button>
                </form>
            </div>
             
            <div style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px;">
                <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem;">Manual: 2. Clean Raw Data</h4>
                <div style="margin-bottom: 0.5rem;">
                    <input type="text" id="cleanInput" class="glass-input" placeholder="Input (e.g. vvu_raw.csv)" style="font-size: 0.8rem; margin-bottom: 0.5rem;" required>
                    <input type="text" id="cleanOutput" class="glass-input" placeholder="Output (e.g. clean_data.csv)" style="font-size: 0.8rem; margin-bottom: 0.5rem;" required>
                    <select id="cleanFolder" class="glass-input" style="font-size: 0.8rem; margin-bottom: 0.5rem;">
                        <option value="">Root Folder (B2)</option>
                        <option value="csv/general/">csv/general/</option>
                        <option value="csv/department/">csv/department/</option>
                        <option value="csv/final/">csv/final/</option>
                    </select>
                </div>
                <button onclick="runCleanup()" id="cleanupBtn" class="glass-btn secondary small" style="width: 100%"><i class="fa-solid fa-broom"></i> Clean & Save to DB</button>
            </div> -->
        </div>
        <div id="wizardLog" style="margin-top: 10px; font-family: monospace; font-size: 0.75rem; max-height: 180px; overflow-y: auto; background: rgba(0,0,0,0.3); padding: 8px; border-radius: 6px; display: none;"></div>
    </div>
</div>

<script>
// Wizard Actions
const autoEtlForm = document.getElementById('autoEtlForm');
if (autoEtlForm) {
    autoEtlForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const log = document.getElementById('wizardLog');
        log.style.display = 'block';
        log.innerHTML = '<span style="color: #10b981;">[AUTO]</span> Initializing One-Click ETL...<br>';
        
        const btn = e.target.querySelector('button');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

        const formData = new FormData(e.target);
        try {
            log.innerHTML += '<span style="color: #10b981;">[AUTO]</span> Uploading PDF and starting extraction...<br>';
            const res = await fetch('api/automated_etl.php', { method: 'POST', body: formData });
            const data = await res.json();
            
            if (data.status === 'success') {
                log.innerHTML += '<span style="color: #10b981;">[AUTO]</span> Extraction Output: ' + (data.extract_log || 'OK').replace(/\n/g, '<br>') + '<br>';
                log.innerHTML += '<span style="color: #10b981;">[AUTO]</span> Cleanup Output: ' + (data.cleanup_log || 'OK').replace(/\n/g, '<br>') + '<br>';
                log.innerHTML += '<span style="color: #10b981;">[AUTO]</span> <b>SUCCESS:</b> ' + data.message + '<br>';
                showNotice('success', data.message);
            } else {
                log.innerHTML += '<span style="color: #ef4444;">[ERROR]</span> ' + (data.message || 'ETL Failed') + '<br>';
                showNotice('error', data.message || 'Automated ETL failed');
            }
        } catch (err) {
            log.innerHTML += '<span style="color: #ef4444;">[ERROR]</span> ' + err.message + '<br>';
            showNotice('error', err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    });
}

const extractForm = document.getElementById('extractForm');
if (extractForm) {
    extractForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const log = document.getElementById('wizardLog');
        log.style.display = 'block';
        log.innerHTML = 'Starting extraction...\n';

        const formData = new FormData(e.target);
        try {
            const res = await fetch('api/extract_pdf.php', { method: 'POST', body: formData });
            const data = await res.json();
            log.innerHTML += (data.output || data.message) + '\n';
            if (data.status === 'success') {
                log.innerHTML += 'Success: ' + data.message;
                showNotice('success', data.message);
            } else if (data.status === 'warning') {
                const warn = (data.validation && data.validation.warnings) ? data.validation.warnings.join(' | ') : data.message;
                log.innerHTML += 'Warning: ' + warn;
                showNotice('warning', warn);
            } else {
                showNotice('error', data.message || 'Extraction failed');
            }
        } catch (err) {
            log.innerHTML += 'Error: ' + err.message;
            showNotice('error', err.message);
        }
    });
}

async function runCleanup() {
    const log = document.getElementById('wizardLog');
    const inputName = document.getElementById('cleanInput').value.trim();
    const outputName = document.getElementById('cleanOutput').value.trim();
    const outputFolder = document.getElementById('cleanFolder').value;

    log.style.display = 'block';
    log.innerHTML = 'Running cleanup...\n';

    if (!inputName || !outputName) {
        log.innerHTML += 'Error: Please provide both input and output filenames.\n';
        return;
    }

    const formData = new FormData();
    formData.append('input_filename', inputName);
    formData.append('output_filename', outputName);
    formData.append('output_folder', outputFolder);

    try {
        const res = await fetch('api/cleanup_data.php', { method: 'POST', body: formData });
        const data = await res.json();
        log.innerHTML += (data.output || data.message) + '\n';
        if (data.status === 'success') {
            log.innerHTML += 'Success: ' + data.message;
            showNotice('success', data.message);
        } else {
            showNotice('error', data.message || 'Cleanup failed');
        }
    } catch (err) {
        log.innerHTML += 'Error: ' + err.message;
        showNotice('error', err.message);
    }
}

function showNotice(type, message) {
    if (typeof customAlert === 'function') {
        const title = type === 'success' ? 'Success' : type === 'warning' ? 'Warning' : 'Error';
        customAlert(title, message, type === 'warning' ? 'warning' : type);
        return;
    }
    const log = document.getElementById('wizardLog');
    if (log) {
        log.style.display = 'block';
        log.innerHTML += `[${type.toUpperCase()}] ${message}\n`;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
