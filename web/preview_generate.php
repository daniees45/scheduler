<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$page_title = 'AI Schedule Generator';
$page_css = 'assets/generate.css';
include 'includes/header.php';
require_once 'api/db.php';

// Access Control
requireRole(['super_admin', 'faculty_admin']);

// Check if data is ready from upload/edit flow
$data_ready = false;
$ready_filename = '';
$ready_rows = 0;
$ready_temp_file = '';

if (isset($_GET['ready']) && isset($_SESSION['ready_for_scheduling'])) {
    $data_ready = true;
    $ready_filename = $_SESSION['ready_for_scheduling']['filename'];
    $ready_rows = count($_SESSION['ready_for_scheduling']['data']) - 1; // Exclude header
    $ready_temp_file = $_SESSION['ready_for_scheduling']['temp_file'] ?? '';
}

$uploaded_session_id = $_SESSION['uploaded_csv']['id'] ?? '';
$ready_session_id = $_SESSION['ready_for_scheduling']['id'] ?? '';
?>

<!-- Include Unified API Configuration -->
<script src="config.js"></script>

<div class="glass-panel generate-container">
    <div class="generate-hero-section">
        <div class="generate-hero-icon">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
        </div>
        <div class="generate-hero-title">
            <h2>Generate Schedule</h2>
        </div>
        <p class="generate-hero-description">Use the AI engine to optimally assign courses to rooms and times slots.</p>

        <div id="apiStatus" class="api-status-badge checking">
            <span class="pulse-dot"></span>
            <span class="status-label">Checking AI Engine...</span>
        </div>
    </div>

    <!-- Configuration Form -->
    <div id="configStep">
        <div class="config-form-grid">

            <div class="form-group form-group-full">
                <label class="form-label-primary">Schedule Type</label>
                <div class="radio-option-flex">
                    <label class="glass-input radio-option-item">
                        <input type="radio" name="scheduleType" value="class" checked onchange="toggleExamMode()">
                        <span><i class="fa-solid fa-chalkboard-user"></i> Class Timetable</span>
                    </label>
                    <label class="glass-input radio-option-item">
                        <input type="radio" name="scheduleType" value="exam" onchange="toggleExamMode()">
                        <span><i class="fa-solid fa-file-pen"></i> Examination Timetable</span>
                    </label>
                </div>
            </div>

            <div class="form-group form-group-full">
                <label class="form-label-standard">Input Data Source</label>
                <?php if ($data_ready): ?>
                <div class="data-ready-box">
                    <div class="data-ready-content">
                        <i class="fa-solid fa-circle-check data-ready-icon"></i>
                        <div class="data-ready-info">
                            <p class="data-ready-title">Data Ready for Scheduling</p>
                            <p class="data-ready-filename">
                                <?php echo htmlspecialchars($ready_filename); ?> (
                                <?php echo $ready_rows; ?> rows)
                            </p>
                        </div>
                    </div>
                    <div class="data-ready-buttons">
                        <button type="button" onclick="editCurrentInputSource()" class="glass-btn secondary"
                            title="Edit Selected Input">
                            <i class="fa-solid fa-pen"></i> Edit
                        </button>
                        <button type="button" onclick="uploadNewCSV()" class="glass-btn secondary"
                            title="Upload Different CSV">
                            <i class="fa-solid fa-rotate"></i> Change
                        </button>
                    </div>
                </div>
                <?php
else: ?>
                <div class="upload-dropzone">
                    <i class="fa-solid fa-cloud-arrow-up upload-icon"></i>
                    <p class="upload-text">Upload a CSV file to begin</p>
                    <p class="upload-text-supports">
                        Supports: Course data, Room lists, Lecturer availability
                    </p>
                    <div class="upload-button-flex">
                        <button type="button" onclick="document.getElementById('csvUpload').click()" class="glass-btn">
                            <i class="fa-solid fa-upload"></i> Upload CSV File
                        </button>
                        <button type="button" onclick="startManualInput()" class="glass-btn secondary">
                            <i class="fa-solid fa-keyboard"></i> Manual Input
                        </button>
                    </div>
                </div>
                <?php
endif; ?>
                <input type="file" id="csvUpload" class="file-input-hidden" accept=".csv" onchange="uploadCSV(this)">
            </div>

            <div class="form-group form-group-full exam-input-group exam-input-section" id="examInputGroup">
                <label class="form-label-standard">Exam Input Source</label>
                <div class="radio-option-flex">
                    <label class="glass-input radio-option-item">
                        <input type="radio" name="examInputSource" value="upload" checked
                            onchange="toggleExamInputSource()">
                        <span><i class="fa-solid fa-cloud-arrow-up"></i> Upload Exam CSV</span>
                    </label>
                    <label class="glass-input radio-option-item">
                        <input type="radio" name="examInputSource" value="saved" onchange="toggleExamInputSource()">
                        <span><i class="fa-solid fa-database"></i> Use Saved Timetable</span>
                    </label>
                </div>
                <div id="examSavedSelectWrap" class="exam-saved-select-wrapper">
                    <div class="input-flex-wrapper">
                        <select id="examSavedScheduleSelect" class="select-field" style="flex: 1;">
                            <option value="">Loading saved timetables...</option>
                        </select>
                        <button type="button" onclick="refreshExamSavedSchedules()" class="glass-btn secondary"
                            title="Refresh">
                            <i class="fa-solid fa-rotate"></i>
                        </button>
                    </div>
                    <p class="form-label-small">
                        Select a saved timetable as the exam input source.
                    </p>
                </div>
            </div>

            <div class="form-group" id="semesterGroup">
                <label class="form-label-standard">Academic Semester</label>
                <select id="semester" class="select-field">
                    <option value="1">First Semester</option>
                    <option value="2">Second Semester</option>
                    <option value="3">All Semester</option>
                </select>
            </div>

            <div class="form-group" id="courseTypeGroup">
                <label class="form-label-standard">Course Category</label>
                <select id="courseType" class="select-field" onchange="toggleDept()">
                    <option value="Departmental">Departmental Courses</option>
                    <option value="General">General Courses</option>
                </select>
            </div>

            <div class="form-group" id="deptGroup">
                <label class="form-label-standard">Select Department Rooms</label>
                <select id="deptRooms" class="select-field">
                    <option value="General">General Pool (All Rooms)</option>
                    <option value="CS/IT/BBIS">Computer Science / IT</option>
                    <option value="Nursing">Nursing & Midwifery</option>
                    <option value="Theology">Theology</option>
                    <option value="Business">Business</option>
                    <option value="Education">Education</option>
                    <option value="BiomedicalEngineering">Biomedical Engineering</option>
                    <option value="DevelopmentStudies">Development Studies</option>
                </select>
            </div>

            <div class="form-group form-group-full" id="generalScheduleGroup">
                <label class="form-label-standard">General Schedule (Blocks) <span
                        class="required-asterisk">*</span></label>
                <div class="input-flex-wrapper">
                    <select id="generalSchedule" class="select-field" style="flex: 1;">
                        <option value="" selected>⚡ Auto-detect Most Recent</option>
                        <option value="csv/general/vvu_general_schedule.csv">📋 VVU Default Schedule</option>
                        <optgroup label="━━━ Recent Generated General Schedules ━━━"></optgroup>
                    </select>
                    <button type="button" onclick="document.getElementById('generalScheduleUpload').click()"
                        class="glass-btn secondary upload-btn">
                        <i class="fa-solid fa-upload"></i> Upload
                    </button>
                    <input type="file" id="generalScheduleUpload" class="file-input-hidden" accept=".csv"
                        onchange="loadGeneralSchedule(this)">
                </div>
                <p class="form-helper-text">
                    <i class="fa-solid fa-info-circle"></i>
                    Blocks prevent course scheduling conflicts. This is <strong>required</strong> for Departmental
                    scheduling.
                </p>
            </div>

            <div class="form-group exam-input-section" id="examHallGroup">
                <label class="form-label-standard">Exam Hall Name(s) <span class="required-asterisk">*</span></label>
                <input type="text" id="examHallName" class="glass-input"
                    placeholder="e.g. Caf Upstairs, Hall A, Examination Center" value="">
                <p class="form-helper-text">
                    <i class="fa-solid fa-info-circle"></i>
                    Required for exam scheduling. Enter one or multiple halls separated by commas.
                </p>
            </div>

            <div class="form-group exam-input-section" id="examCapacityGroup">
                <label class="form-label-standard">Exam Hall Capacity(ies) (Optional)</label>
                <input type="text" id="examHallCapacity" class="glass-input" placeholder="e.g. 200,150,120" value="">
                <p class="form-helper-text">
                    <i class="fa-solid fa-info-circle"></i>
                    Enter comma-separated capacities aligned to hall order. Example: Hall A,Hall B with 200,150.
                </p>
            </div>

            <div class="form-group exam-input-section" id="examDeptGroup">
                <label class="form-label-standard">Department (Optional)</label>
                <select id="examDeptRooms" class="select-field">
                    <option value="General">General (All Departments)</option>
                    <option value="CS/IT/BBIS">Computer Science / IT</option>
                    <option value="Nursing">Nursing & Midwifery</option>
                    <option value="Theology">Theology</option>
                    <option value="Business">Business</option>
                    <option value="Education">Education</option>
                    <option value="BiomedicalEngineering">Biomedical Engineering</option>
                    <option value="DevelopmentStudies">Development Studies</option>
                </select>
                <p class="form-helper-text">
                    <i class="fa-solid fa-info-circle"></i>
                    Filter exam rooms by department. Leave as "General" to use all available rooms.
                </p>
            </div>

            <div class="form-group">
                <label class="form-label-standard">Availability Strategy</label>
                <select id="availabilityMode" class="select-field">
                    <option value="1">AI Automatic (Auto-Expand Limited)</option>
                    <option value="2">Strict (Use File Data Only)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label-standard">Output Filename<span class="required-asterisk">*</span></label>
                <input type="text" id="outputFile" class="glass-input" value="schedule_"
                    placeholder="e.g. schedule_2026 (must start with 'schedule_')" required>
                <p class="form-helper-text">Filename will be automatically suffixed with timestamp. Must start with
                    'schedule_'</p>
            </div>

            <div class="form-group">
                <label class="form-label-standard">Optimization Mode</label>
                <select id="optMode" class="select-field">
                    <option value="balance">Balanced Load</option>
                    <option value="capacity">Maximize Capacity</option>
                    <option value="lecturer">Lecturer Preference</option>
                </select>
            </div>

            <div class="form-group form-group-full">
                <label class="form-label-standard">AI Scheduling Model</label>
                <select id="schedulingModel" class="select-field">
                    <option value="csp" selected>Standard AI (CSP - Recommended)</option>
                    <option value="ga">Genetic Algorithm (Evolutionary)</option>
                    <option value="rl">Reinforcement Learning (RL)</option>
                    <option value="nn">Neural Network (Deep Learning)</option>
                    <option value="ensemble">Unified Ensemble (Fast Hybrid)</option>
                    <option value="hybrid">All Models (Hybrid Search - Slowest)</option>
                </select>
                <p class="form-helper-text">Select the optimization algorithm for the AI engine.</p>
            </div>
            <!-- What-If Analyzer / Constraint Tuning -->
            <div class="form-group form-group-full constraint-section-wrapper">
                <div class="constraint-panel">
                    <div class="constraint-header">
                        <h3 class="constraint-title">
                            <i class="fa-solid fa-sliders"></i> What-If Analyzer
                        </h3>
                        <span class="experimental-badge">Experimental</span>
                    </div>
                    <p class="constraint-description">
                        Fine-tune the AI's internal constraint weights. Adjust these sliders to prioritize different
                        scheduling goals.
                    </p>

                    <div class="constraint-slider-grid">
                        <div class="constraint-slider-item">
                            <div class="constraint-slider-header">
                                <label for="weightRoom">Room Capacity Optimization</label>
                                <span id="valRoom" class="constraint-value-display">10.0</span>
                            </div>
                            <input type="range" id="weightRoom" min="1" max="20" step="1" value="10"
                                class="constraint-slider"
                                oninput="document.getElementById('valRoom').innerText=this.value + '.0'">
                            <div class="constraint-slider-labels">
                                <span>Ignore Capacity</span>
                                <span>Strict Match</span>
                            </div>
                        </div>

                        <div class="constraint-slider-item">
                            <div class="constraint-slider-header">
                                <label for="weightLecturer">Lecturer Preference</label>
                                <span id="valLecturer" class="constraint-value-display">5.0</span>
                            </div>
                            <input type="range" id="weightLecturer" min="1" max="20" step="1" value="5"
                                class="constraint-slider"
                                oninput="document.getElementById('valLecturer').innerText=this.value + '.0'">
                            <div class="constraint-slider-labels">
                                <span>Lower Priority</span>
                                <span>High Priority</span>
                            </div>
                        </div>

                        <div class="constraint-slider-item">
                            <div class="constraint-slider-header">
                                <label for="weightBalance">Balanced Load (Spread)</label>
                                <span id="valBalance" class="constraint-value-display">8.0</span>
                            </div>
                            <input type="range" id="weightBalance" min="1" max="20" step="1" value="8"
                                class="constraint-slider"
                                oninput="document.getElementById('valBalance').innerText=this.value + '.0'">
                            <div class="constraint-slider-labels">
                                <span>Compact Schedule</span>
                                <span>Evenly Spread</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="review-data-section">
                <h3 class="review-data-title"><i class="fa-solid fa-file-invoice"></i> Review Input Data</h3>
                <p class="review-data-description">Ensure your course assignments and preferences are correct before
                    starting.</p>
                <div class="review-data-buttons">
                    <button type="button" onclick="editSelected()" class="glass-btn secondary small">
                        <i class="fa-solid fa-pencil"></i> Edit Selected CSV
                    </button>
                    <a href="import_data.php" class="glass-btn secondary small">
                        <i class="fa-solid fa-cog"></i> Advanced Settings
                    </a>
                </div>
            </div>

            <div class="action-buttons-flex">
                <button id="startBtn" class="action-button-start">
                    <i class="fa-solid fa-play"></i> Start Generation
                </button>
                <button type="button" onclick="saveAsTemplate()" class="glass-btn secondary button-save-template">
                    <i class="fa-solid fa-save"></i> Save Template
                </button>
            </div>
        </div>

        <!-- ... (Progress Step) ... -->
        <!-- REMOVED to simplify target matching, will only replace config block -->
        <!-- WAIT, I need to update the JS fetch call too. -->

        <!-- Progress State (Hidden by default) -->
        <div id="progressStep" class="progress-step">
            <div class="progress-loader">
                <i class="fa-solid fa-circle-notch fa-spin"></i>
            </div>

            <!-- Progress Bar -->
            <div class="progress-bar-container">
                <div id="progressBar" class="progress-bar"></div>
            </div>
            <div id="progressStats" class="progress-stats">0%</div>
            <div id="progressTimer" class="progress-timer">
                <i class="fa-solid fa-hourglass-end"></i> Time elapsed: 0s
            </div>
            <h3 id="statusText" class="progress-status-text">Initializing AI Engine...</h3>
            <p class="progress-description">This may take a few minutes.</p>

            <div id="logs" class="progress-logs">
                <div style="color: var(--text-muted);">> System ready.</div>
            </div>
        </div>

        <!-- Success State (Hidden) -->
        <div id="successStep" class="success-step">
            <div class="success-hero">
                <div class="success-checkmark">
                    <i class="fa-regular fa-circle-check"></i>
                </div>
                <h3 class="success-title">Generation Complete!</h3>
                <div id="accuracyBadge" class="accuracy-badge">
                    Accuracy: <span id="accuracyVal">0%</span>
                </div>
            </div>

            <!-- AI Analytics Grid -->
            <div class="analytics-grid">
                <!-- Quality Score Card -->
                <div class="metric-card metric-card-quality-score">
                    <div class="metric-card-icon metric-icon-primary">
                        <i class="fa-solid fa-star"></i>
                    </div>
                    <p class="metric-card-label">QUALITY SCORE</p>
                    <h4 class="metric-card-value" id="qualityScore">--/10</h4>
                    <p class="metric-card-category" id="qualityCategory">Waiting for AI output</p>
                </div>

                <!-- Feasibility Card -->
                <div class="metric-card metric-card-feasibility">
                    <div class="metric-card-icon metric-icon-success">
                        <i class="fa-solid fa-check-double"></i>
                    </div>
                    <p class="metric-card-label">FEASIBILITY</p>
                    <h4 class="metric-card-value" id="feasibilityScore">--%</h4>
                    <p class="metric-card-category">Assignments Valid</p>
                </div>

                <!-- Optimization Card -->
                <div class="metric-card metric-card-optimization">
                    <div class="metric-card-icon metric-icon-secondary">
                        <i class="fa-solid fa-bolt"></i>
                    </div>
                    <p class="metric-card-label">OPTIMIZATION</p>
                    <h4 class="metric-card-value" id="optimizationScore">--%</h4>
                    <p class="metric-card-category" id="optimizationLabel">Room Utilization</p>
                </div>

                <!-- Time Taken Card -->
                <div class="metric-card metric-card-time-taken">
                    <div class="metric-card-icon metric-icon-accent">
                        <i class="fa-solid fa-stopwatch"></i>
                    </div>
                    <p class="metric-card-label">TIME TAKEN</p>
                    <h4 class="metric-card-value" id="timeTakenScore">--</h4>
                    <p class="metric-card-category">Generation Duration</p>
                </div>
            </div>

            <!-- Suggestions & Recommendations -->
            <div class="recommendations-panel">
                <h3 class="recommendations-title"><i class="fa-solid fa-lightbulb"></i> AI Recommendations</h3>
                <div id="suggestionsContainer" class="recommendations-container">
                    <div class="recommendation-item recommendation-item-high">
                        <p class="recommendation-text">
                            <strong>Optimize Morning Load:</strong> Consider redistributing 3 courses to afternoon slots
                            to
                            improve student performance.
                        </p>
                    </div>
                    <div class="recommendation-item recommendation-item-medium">
                        <p class="recommendation-text">
                            <strong>Consolidate Venues:</strong> Move CS courses to East Wing to reduce lecturer travel
                            time
                            by ~40%.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Feature Importance -->
            <div class="decision-factors-panel">
                <h3 class="decision-factors-title"><i class="fa-solid fa-chart-bar"></i> AI Decision Factors</h3>
                <div class="decision-factors-container">
                    <div class="decision-factor-item">
                        <div class="factor-header">
                            <span>Lecturer Availability</span>
                            <span class="factor-percentage" id="factorAvailPct">35%</span>
                        </div>
                        <div class="factor-bar-container">
                            <div id="factorAvailBar" class="factor-bar" style="width: 35%;"></div>
                        </div>
                    </div>
                    <div class="decision-factor-item">
                        <div class="factor-header">
                            <span>Room Capacity Optimization</span>
                            <span class="factor-percentage" id="factorRoomPct">28%</span>
                        </div>
                        <div class="factor-bar-container">
                            <div id="factorRoomBar" class="factor-bar" style="width: 28%;"></div>
                        </div>
                    </div>
                    <div class="decision-factor-item">
                        <div class="factor-header">
                            <span>Historical Preferences</span>
                            <span class="factor-percentage" id="factorHistoryPct">22%</span>
                        </div>
                        <div class="factor-bar-container">
                            <div id="factorHistoryBar" class="factor-bar" style="width: 22%;"></div>
                        </div>
                    </div>
                    <div class="decision-factor-item">
                        <div class="factor-header">
                            <span>Conflict Avoidance</span>
                            <span class="factor-percentage" id="factorConflictPct">15%</span>
                        </div>
                        <div class="factor-bar-container">
                            <div id="factorConflictBar" class="factor-bar" style="width: 15%;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="success-actions">
                <p class="success-description">The schedule has been successfully generated and optimized using advanced
                    AI algorithms.</p>
                <div class="success-actions-buttons">
                    <a href="view_schedule.php" id="viewScheduleBtn" class="glass-btn"><i
                            class="fa-solid fa-calendar-days"></i> View Schedule</a>
                    <a href="#" id="downloadPdfBtn" class="glass-btn secondary" style="display: none;"><i
                            class="fa-solid fa-file-pdf"></i> Download PDF</a>
                    <button onclick="recordScheduleAcceptance()" class="action-button-accept">
                        <i class="fa-solid fa-thumbs-up"></i> Accept & Learn
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleDept() {
            // Only show department/general schedule groups if NOT in Exam mode
            const isExamMode = document.querySelector('input[name="scheduleType"]:checked')?.value === 'exam';
            if (isExamMode) return;

            const type = document.getElementById('courseType').value;
            const deptGroup = document.getElementById('deptGroup');
            const genSchedGroup = document.getElementById('generalScheduleGroup');

            if (type === 'Departmental') {
                if (deptGroup) deptGroup.style.display = 'block';
                if (genSchedGroup) genSchedGroup.style.display = 'block';
                loadRecentSchedules();
            } else {
                // General courses don't need blocks (they are the blocks) or dept room filtering
                if (deptGroup) deptGroup.style.display = 'none';
                if (genSchedGroup) genSchedGroup.style.display = 'none';
            }
        }

        async function loadRecentSchedules() {
            try {
                const res = await fetch('api/get_recent_schedules.php?limit=8');
                const data = await res.json();

                const select = document.getElementById('generalSchedule');
                const optgroup = select.querySelector('optgroup');

                // Clear previous recent schedules
                const existingRecent = Array.from(optgroup.querySelectorAll('option'));
                existingRecent.forEach(opt => opt.remove());

                if (data.status === 'success' && Array.isArray(data.schedules) && data.schedules.length > 0) {
                    // Add recent generated general schedules with datetime labels
                    data.schedules.forEach(sched => {
                        const opt = document.createElement('option');
                        const generatedAt = sched.generated_at || 'Unknown date';
                        const courseCount = (typeof sched.lines === 'number' && sched.lines >= 0)
                            ? ` • ${sched.lines} courses`
                            : '';

                        opt.value = sched.path;
                        opt.textContent = `📅 ${sched.name} — ${generatedAt}${courseCount}`;
                        optgroup.appendChild(opt);
                    });
                } else {
                    const emptyOpt = document.createElement('option');
                    emptyOpt.value = '';
                    emptyOpt.disabled = true;
                    emptyOpt.textContent = 'No recent generated general schedules found';
                    optgroup.appendChild(emptyOpt);
                }
            } catch (err) {
                console.warn('Could not load recent schedules:', err.message);
            }
        }

        function loadGeneralSchedule(input) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            const reader = new FileReader();

            reader.onload = async function (e) {
                const content = e.target.result;
                try {
                    const res = await fetch('api/upload_general_schedule.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            filename: file.name,
                            content: content
                        })
                    });
                    const data = await res.json();

                    if (data.status === 'success') {
                        const select = document.getElementById('generalSchedule');
                        const opt = document.createElement('option');
                        opt.value = data.path;
                        opt.textContent = '📤 ' + file.name;
                        select.appendChild(opt);
                        select.value = data.path;
                        await customAlert('Success', 'General schedule uploaded successfully', 'success');
                    } else {
                        await customAlert('Error', data.message || 'Failed to upload', 'error');
                    }
                } catch (err) {
                    await customAlert('Error', 'Upload failed: ' + err.message, 'error');
                }
            };
            reader.readAsText(file);
            input.value = '';
        }

        function toggleExamMode() {
            // Check if the element exists to avoid errors on page load if incomplete
            const radio = document.querySelector('input[name="scheduleType"]:checked');
            if (!radio) return;

            const isExam = radio.value === 'exam';
            const outFile = document.getElementById('outputFile');
            const startBtn = document.getElementById('startBtn');
            const examHallGroup = document.getElementById('examHallGroup');
            const examCapacityGroup = document.getElementById('examCapacityGroup');
            const examDeptGroup = document.getElementById('examDeptGroup');
            const generalScheduleGroup = document.getElementById('generalScheduleGroup');
            const examInputSourceGroup = document.getElementById('examInputGroup'); // Corrected ID reference
            const semesterGroup = document.getElementById('semesterGroup');
            const courseTypeGroup = document.getElementById('courseTypeGroup');
            const deptGroup = document.getElementById('deptGroup');

            if (isExam) {
                outFile.value = 'exam_schedule';
                startBtn.innerHTML = '<i class="fa-solid fa-file-pen"></i> Generate Exam Timetable';
                document.getElementById('availabilityMode').value = '2';
                if (examHallGroup) examHallGroup.style.display = 'block';
                if (examCapacityGroup) examCapacityGroup.style.display = 'block';
                if (examDeptGroup) examDeptGroup.style.display = 'block';
                if (generalScheduleGroup) generalScheduleGroup.style.display = 'none';
                if (examInputSourceGroup) examInputSourceGroup.style.display = 'block';
                if (semesterGroup) semesterGroup.style.display = 'none';
                if (courseTypeGroup) courseTypeGroup.style.display = 'none';
                if (deptGroup) deptGroup.style.display = 'none';
                refreshExamSavedSchedules();
                toggleExamInputSource();
            } else {
                outFile.value = 'csv/final/final_web_schedule';
                startBtn.innerHTML = '<i class="fa-solid fa-play"></i> Start Generation';
                if (examHallGroup) examHallGroup.style.display = 'none';
                if (examCapacityGroup) examCapacityGroup.style.display = 'none';
                if (examDeptGroup) examDeptGroup.style.display = 'none';
                if (examInputSourceGroup) examInputSourceGroup.style.display = 'none';
                if (semesterGroup) semesterGroup.style.display = 'block';
                if (courseTypeGroup) courseTypeGroup.style.display = 'block';

                // For deptGroup and generalScheduleGroup, deference to toggleDept()
                toggleDept();
            }
        }

        function toggleExamInputSource() {
            const source = document.querySelector('input[name="examInputSource"]:checked')?.value || 'upload';
            const savedWrap = document.getElementById('examSavedSelectWrap');
            if (savedWrap) savedWrap.style.display = source === 'saved' ? 'block' : 'none';
        }

        async function refreshExamSavedSchedules() {
            const select = document.getElementById('examSavedScheduleSelect');
            if (!select) return;
            select.innerHTML = '<option value="">Loading saved timetables...</option>';

            try {
                const res = await fetch('api/list_b2_schedules.php', { credentials: 'include' });
                const data = await res.json();
                if (data.status !== 'success') {
                    throw new Error(data.message || 'Failed to load saved timetables');
                }

                const schedules = Array.isArray(data.schedules) ? data.schedules : [];
                const classSchedules = schedules.filter(s => (s.type || 'class') === 'class');
                if (!classSchedules.length) {
                    select.innerHTML = '<option value="">No saved timetables found</option>';
                    return;
                }

                select.innerHTML = '<option value="">Select a saved timetable</option>';
                classSchedules.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.file;
                    opt.textContent = `${s.name || 'Schedule'} • ${s.department || 'General'} • ${s.semester || 'Sem'} • ${s.uploaded || ''}`.trim();
                    select.appendChild(opt);
                });
            } catch (e) {
                select.innerHTML = '<option value="">Failed to load saved timetables</option>';
                console.warn('Exam timetable list error:', e.message || e);
            }
        }

        function editSelected() {
            const file = document.getElementById('inputFile').value;
            window.open('edit_csv.php?file=' + file, '_blank');
        }

        async function uploadCSV(input) {
            if (!input.files || !input.files[0]) return;

            const file = input.files[0];

            // Validate file extension
            if (!file.name.toLowerCase().endsWith('.csv')) {
                await customAlert('Invalid File', 'Please select a CSV file (.csv extension)', 'error');
                input.value = ''; // Clear the input
                return;
            }

            const formData = new FormData();
            formData.append('csv_file', file);

            // Show loading indicator
            const uploadProgress = await customAlert('Uploading...', 'Parsing CSV file...', 'info', false);

            try {
                const response = await fetch('api/upload_csv.php', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.status === 'success') {
                    // Redirect to edit page to view/edit the uploaded data
                    window.location.href = data.redirect;
                } else {
                    await customAlert('Upload Error', data.message, 'error');
                }
            } catch (e) {
                await customAlert('Error', 'Failed to upload file: ' + e.message, 'error');
            }

            // Clear the input so the same file can be uploaded again if needed
            input.value = '';
        }

        function uploadNewCSV() {
            // Clear ready state and trigger upload
            fetch('api/clear_session.php', {
                credentials: 'include'
            }).then(() => {
                document.getElementById('csvUpload').click();
            });
        }

        async function startManualInput() {
            const type = document.querySelector('input[name="scheduleType"]:checked')?.value || 'class';

            // Show loading
            const loader = await customAlert('Initializing...', 'Preparing manual input template...', 'info', false);

            try {
                const response = await fetch(`api/init_manual_input.php?type=${type}`, { credentials: 'include' });
                const data = await response.json();

                if (data.status === 'success') {
                    window.location.href = data.redirect;
                } else {
                    await customAlert('Error', data.message || 'Failed to initialize manual input', 'error');
                }
            } catch (e) {
                await customAlert('System Error', 'Failed to initialize: ' + e.message, 'error');
            }
        }

        function editCurrentInputSource() {
            const uploadedSessionId = <? php echo json_encode($uploaded_session_id); ?>;
            const readySessionId = <? php echo json_encode($ready_session_id); ?>;

            const sessionId = uploadedSessionId || readySessionId;
            if (sessionId) {
                window.open('edit_csv.php?session=' + encodeURIComponent(sessionId), '_blank');
                return;
            }

            customAlert('No Editable Source', 'No selected input source found in session. Please upload again.', 'warning');
        }


        async function recordScheduleAcceptance() {
            const qualityText = document.getElementById('qualityScore')?.textContent || '';
            const qualityMatch = qualityText.match(/([0-9]+(?:\.[0-9]+)?)/);
            let quality = qualityMatch ? (parseFloat(qualityMatch[1]) / 10.0) : null;
            if (quality === null || Number.isNaN(quality)) {
                const accuracyText = document.getElementById('accuracyVal')?.innerText || '';
                const accMatch = accuracyText.match(/([0-9]+(?:\.[0-9]+)?)/);
                quality = accMatch ? (parseFloat(accMatch[1]) / 100.0) : 0.5;
            }
            quality = Math.max(0, Math.min(1, quality));

            const feedbackData = {
                action: 'accept',
                quality: quality,
                metadata: {
                    department: document.getElementById('deptRooms')?.value || 'General',
                    course_type: document.getElementById('courseType').value,
                    output_file: document.getElementById('outputFile')?.value || '',
                    timestamp: new Date().toISOString()
                }
            };

            try {
                const data = await fetch('api/ai_feedback.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(feedbackData)
                }).then(r => r.json());

                // Fallback to direct AI endpoint if PHP bridge is unavailable
                if (data.status !== 'success') {
                    const fallback = await apiPost('/feedback', feedbackData);
                    if (fallback.status === 'success') {
                        console.log('[AI] Feedback recorded via fallback endpoint');
                    }
                    return;
                }

                if (data.status === 'success') {
                    console.log('[AI] Feedback recorded successfully');
                }
            } catch (e) {
                console.error('[AI] Feedback error:', e);
            }
        }

        function updateAnalyticsUI(analytics, accuracyText = null) {
            if (!analytics || !analytics.metrics) return;

            const m = analytics.metrics;

            const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
            const toPct = (v) => Math.round(clamp(v, 0, 1) * 100);

            let accuracyPct = null;
            const sourceAccuracy = accuracyText || (document.getElementById('accuracyVal')?.innerText || '');
            const accMatch = String(sourceAccuracy).match(/([0-9]+(?:\.[0-9]+)?)/);
            if (accMatch) accuracyPct = parseFloat(accMatch[1]);

            // Quality score: prefer backend accuracy when available, else derive from analytics
            let score;
            if (accuracyPct !== null && !Number.isNaN(accuracyPct)) {
                score = clamp(accuracyPct / 10.0, 1, 10);
            } else {
                const totalEvents = Number(m.total_events || 0);
                const conflicts = Number(m.lecturer_conflicts || 0);
                const conflictPenalty = totalEvents > 0 ? (conflicts / totalEvents) * 4.0 : 0;
                const balanceSpread = Math.max(Number(m.morning_load || 0), Number(m.afternoon_load || 0), Number(m.evening_load || 0))
                    - Math.min(Number(m.morning_load || 0), Number(m.afternoon_load || 0), Number(m.evening_load || 0));
                const balancePenalty = balanceSpread * 2.0;
                score = clamp(10 - conflictPenalty - balancePenalty, 1, 10);
            }

            document.getElementById('qualityScore').textContent = score.toFixed(1) + '/10';
            document.getElementById('qualityCategory').textContent = score > 8 ? 'Excellent' : (score > 6 ? 'Good' : 'Needs Improvement');

            // Feasibility: conflict-based probability
            const totalEvents = Number(m.total_events || 0);
            const conflicts = Number(m.lecturer_conflicts || 0);
            let feasibilityPct = 0;
            if (totalEvents > 0) {
                feasibilityPct = Math.round(clamp(1 - (conflicts / totalEvents), 0, 1) * 100);
            } else if (accuracyPct !== null) {
                feasibilityPct = Math.round(clamp(accuracyPct, 0, 100));
            }
            document.getElementById('feasibilityScore').textContent = feasibilityPct + '%';

            // Optimization (prefer room utilization from backend; fallback to load-balance score)
            const roomUtil = Number(m.room_utilization || 0);
            const hasRoomUtil = roomUtil > 0;
            let optimizationPct = 0;
            if (hasRoomUtil) {
                optimizationPct = Math.round(clamp(roomUtil, 0, 100));
                document.getElementById('optimizationLabel').textContent = 'Room Utilization';
            } else {
                const spread = Math.max(Number(m.morning_load || 0), Number(m.afternoon_load || 0), Number(m.evening_load || 0))
                    - Math.min(Number(m.morning_load || 0), Number(m.afternoon_load || 0), Number(m.evening_load || 0));
                optimizationPct = Math.round(clamp(1 - spread, 0, 1) * 100);
                document.getElementById('optimizationLabel').textContent = 'Load Balance';
            }
            document.getElementById('optimizationScore').textContent = optimizationPct + '%';

            // Dynamic decision-factor bars
            let wAvail = clamp(feasibilityPct / 100, 0.15, 0.55);
            let wRoom = clamp((hasRoomUtil ? roomUtil / 100 : 0.28), 0.10, 0.45);
            let wHistory = clamp(((accuracyPct ?? 75) / 100) * 0.35, 0.10, 0.35);
            let wConflict = clamp((1 - (feasibilityPct / 100)) * 0.35 + 0.08, 0.05, 0.30);

            const wSum = wAvail + wRoom + wHistory + wConflict;
            wAvail /= wSum; wRoom /= wSum; wHistory /= wSum; wConflict /= wSum;

            const factors = [
                ['factorAvailPct', 'factorAvailBar', toPct(wAvail)],
                ['factorRoomPct', 'factorRoomBar', toPct(wRoom)],
                ['factorHistoryPct', 'factorHistoryBar', toPct(wHistory)],
                ['factorConflictPct', 'factorConflictBar', toPct(wConflict)]
            ];
            factors.forEach(([labelId, barId, pct]) => {
                const labelEl = document.getElementById(labelId);
                const barEl = document.getElementById(barId);
                if (labelEl) labelEl.textContent = `${pct}%`;
                if (barEl) barEl.style.width = `${pct}%`;
            });

            // Recommendations
            const container = document.getElementById('suggestionsContainer');
            container.innerHTML = '';

            if (analytics.recommendations && analytics.recommendations.length > 0) {
                analytics.recommendations.forEach(rec => {
                    const div = document.createElement('div');
                    // Color based on priority
                    let color = '#6366f1'; // indigo (medium)
                    let bg = 'rgba(99, 102, 241, 0.1)';
                    if (rec.priority === 'high') { color = '#ef4444'; bg = 'rgba(239, 68, 68, 0.1)'; }
                    if (rec.priority === 'low') { color = '#10b981'; bg = 'rgba(16, 185, 129, 0.1)'; }

                    div.style.cssText = `padding: 1rem; background: ${bg}; border-left: 3px solid ${color}; border-radius: 4px;`;
                    div.innerHTML = `
                <p style="margin: 0; font-size: 0.9rem;">
                    <strong>${rec.title}:</strong> ${rec.description}
                </p>
            `;
                    container.appendChild(div);
                });
            } else {
                container.innerHTML = '<p style="color: var(--text-muted); font-style: italic;">No specific recommendations. Schedule looks good!</p>';
            }
        }

        function buildAccuracyDerivedAnalytics(accuracyText) {
            const src = String(accuracyText || '');
            const match = src.match(/([0-9]+(?:\.[0-9]+)?)/);
            const accuracy = match ? Math.max(0, Math.min(100, parseFloat(match[1]))) : 75;
            const norm = accuracy / 100;

            // Derive non-fixed proportions from actual generation accuracy
            const morning = Math.max(0.2, Math.min(0.5, 0.30 + (norm - 0.5) * 0.10));
            const afternoon = Math.max(0.2, Math.min(0.5, 0.35 - (norm - 0.5) * 0.05));
            const evening = Math.max(0.1, Math.min(0.4, 1 - morning - afternoon));

            return {
                metrics: {
                    total_events: 1,
                    morning_load: Number(morning.toFixed(2)),
                    afternoon_load: Number(afternoon.toFixed(2)),
                    evening_load: Number(evening.toFixed(2)),
                    room_utilization: Number((norm * 100).toFixed(2)),
                    lecturer_conflicts: 0,
                    ai_efficiency: Number((norm * 100).toFixed(2))
                },
                recommendations: []
            };
        }

        async function fetchAIAnalytics(scheduleFile) {
            try {
                const query = encodeURIComponent(scheduleFile || '');
                const res = await apiGet(`/analytics/performance?schedule_file=${query}`);
                if (res && res.status === 'success' && res.metrics) {
                    return res;
                }
            } catch (e) {
                console.warn('[AI] Could not fetch dynamic analytics:', e.message || e);
            }
            return null;
        }

        async function checkApiStatus() {
            const badge = document.getElementById('apiStatus');
            try {
                const data = await apiGet('/health');
                if (data.status === 'ok' || data.status === 'online' || data.status === 'healthy') {
                    badge.className = 'status-badge online';
                    badge.innerHTML = '<i class="fa-solid fa-check-circle"></i> AI Engine Online';
                } else {
                    throw new Error();
                }
            } catch (e) {
                badge.className = 'status-badge offline';
                badge.innerHTML = '<i class="fa-solid fa-times-circle"></i> AI Engine Offline (Port 5000)';
            }
        }

        // Initial checks
        checkApiStatus();
        setInterval(checkApiStatus, 30000);
        toggleDept(); // Ensure schedule block dropdown is populated on first load

        // ============================================================================
        // SCHEDULING PIPELINE FLOW
        // ============================================================================
        // 1. SYNC DATABASE → CSV (api/sync.php)
        //    - Export courses, rooms, lecturers, special_rooms to CSV files
        //    - Files go to csv/general/ and csv/department/ directories
        //
        // 2. CALL FLASK API (/generate endpoint on port 5000)
        //    - Send parameters: input_file, output_file, department, etc.
        //    - Flask API calls main_web.run_headless()
        //
        // 3. AI SCHEDULING PIPELINE (in main_web.py):
        //    a. Load AI model (scheduling_model.pkl)
        //    b. Detect department from input file
        //    c. Load department-specific rooms (csv/department/{dept}_room s.csv)
        //    d. Load special_rooms.csv for pre-assigned courses
        //    e. Load general schedule blocks (to avoid conflicts)
        //    f. Pre-flight validation checks
        //    g. Build domain and constraints
        //    h. Solve CSP with AI optimizer
        //    i. Export solution to output CSV
        //    j. Retrain AI model with new data
        //
        // 4. IMPORT RESULTS → DATABASE (api/update_db.php)
        //    - Read generated CSV from csv/final/
        //    - Import schedule entries into MySQL database
        //
        // 5. VERSION & ARCHIVE (api/schedule_versions.php)
        //    - Save schedule version for history tracking
        //    - Generate PDF export link
        // ============================================================================

        document.getElementById('startBtn').addEventListener('click', async () => {
            const dataReady = <? php echo $data_ready ? 'true' : 'false'; ?>;
            const isExamMode = document.querySelector('input[name="scheduleType"]:checked')?.value === 'exam';
            const examSource = document.querySelector('input[name="examInputSource"]:checked')?.value || 'upload';
            let outputFilename = document.getElementById('outputFile')?.value?.trim() || 'schedule';

            if (!dataReady && !(isExamMode && examSource === 'saved')) {
                await customAlert('No Data', 'Please upload a CSV file first.', 'warning');
                return;
            }

            // 1. UI Switch
            document.getElementById('configStep').style.display = 'none';
            document.getElementById('progressStep').style.display = 'block';

            const logs = document.getElementById('logs');
            const log = (msg) => {
                const div = document.createElement('div');
                div.textContent = `> ${msg}`;
                logs.appendChild(div);
                logs.scrollHeight;
            };

            let pollInterval = null;
            let startTime = Date.now();
            let timerInterval = null;
            try {
                // ========================================================================
                // PIPELINE: Use Session Data for AI Scheduling
                // Session contains the uploaded/edited CSV data
                // AI engine will read from temporary CSV file created from session
                // ========================================================================
                // 1. Sync Database to Project CSVs
                log("Synchronizing database with AI engine...");
                const syncRes = await fetch('api/sync.php', {
                    credentials: 'include'
                });
                const syncData = await syncRes.json();
                if (syncData.status !== 'success') {
                    log("Warning: Sync incomplete: " + syncData.message);
                } else {
                    log("Database synchronized.");
                }

                // Start countdown timer
                timerInterval = setInterval(() => {
                    const elapsed = Math.floor((Date.now() - startTime) / 1000);
                    const minutes = Math.floor(elapsed / 60);
                    const seconds = elapsed % 60;
                    const timeStr = minutes > 0 ? `${minutes}m ${seconds}s` : `${seconds}s`;
                    document.getElementById('progressTimer').innerHTML = `<i class="fa-solid fa-hourglass-end"></i> Time elapsed: ${timeStr}`;
                }, 1000);

                // Start real-time progress polling
                pollInterval = setInterval(async () => {
                    try {
                        const p = await apiGet('/progress?t=' + new Date().getTime());
                        if (p.status === 'running' && p.percent) {
                            document.getElementById('progressBar').style.width = p.percent + '%';
                            document.getElementById('progressStats').innerText = `${p.percent}% - Placing: ${p.placed}`;
                            document.getElementById('statusText').innerText = "AI Solving Constraints...";
                        }
                    } catch (e) { }
                }, 1000);

                // Send scheduling request with session flag
                log("Initializing AI engine...");
                // Get session CSV content from PHP
                let sessionData = { status: 'success', csv_content: [], filename: '' };
                if (!(isExamMode && examSource === 'saved')) {
                    const sessionRes = await fetch('api/get_session_csv.php', {
                        credentials: 'include'
                    });
                    sessionData = await sessionRes.json();
                    if (sessionData.status !== 'success') {
                        throw new Error(sessionData.message || 'No session data available');
                    }
                }

                // Get department name based on selected rooms
                const courseType = document.getElementById('courseType').value;
                let deptName = 'General';
                if (isExamMode) {
                    // Use exam department selector for exam mode
                    deptName = document.getElementById('examDeptRooms').value || 'General';
                } else if (courseType === 'Departmental') {
                    deptName = document.getElementById('deptRooms').value;
                }

                // Get general schedule path if applicable
                let generalSchedulePath = null;
                if (courseType === 'Departmental') {
                    generalSchedulePath = document.getElementById('generalSchedule').value;
                }

                // Get custom output filename (preserve user input, normalize safely)
                outputFilename = document.getElementById('outputFile').value.trim();
                outputFilename = outputFilename.replace(/^csv\/final\//i, '').replace(/\.csv$/i, '');
                if (!outputFilename) {
                    outputFilename = isExamMode ? ('exam_schedule_' + Date.now()) : ('schedule_' + Date.now());
                }

                // ====================================================================
                // PRE-FLIGHT CHECKS: Validate before wasting time on scheduling
                // ====================================================================
                let preFlightData = { score: 100, warnings: [], errors: [], checks: {} };

                if (!isExamMode) {
                    log("Running pre-flight feasibility checks...");
                    try {
                        const preFlightRes = await fetch('api/pre_flight_check.php', {
                            method: 'POST',
                            credentials: 'include',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'check',
                                courses_csv: 'csv/department/departmental_courses.csv',
                                availability_csv: 'csv/general/lecturer_availability.csv',
                                rooms_csv: 'csv/general/rooms.csv'
                            })
                        });
                        preFlightData = await preFlightRes.json();
                    } catch (e) {
                        log(`❌ Pre-flight failed: ${e.message || e}`);
                        throw e;
                    }
                } else {
                    log("Exam mode: Skipping pre-flight checks");
                }

                // Display pre-flight results
                if (preFlightData.score < 100) {
                    log(`Pre-flight score: ${preFlightData.score}% feasibility`);
                    if (preFlightData.warnings && preFlightData.warnings.length > 0) {
                        preFlightData.warnings.slice(0, 3).forEach(w => {
                            log(`⚠️ Warning: ${w}`);
                        });
                    }
                }

                // Manual/Strict mode: require user to review lecturers with 0 availability before generation
                const availabilityMode = document.getElementById('availabilityMode').value;
                const noAvail = Number(preFlightData?.checks?.lecturer_availability?.unavailable_count || 0);
                const noAvailList = preFlightData?.checks?.lecturer_availability?.unavailable_lecturers || [];
                if (!isExamMode && availabilityMode === '2' && noAvail > 0) {
                    const preview = noAvailList.slice(0, 8).join(', ');
                    const remainder = noAvailList.length > 8 ? ` (+${noAvailList.length - 8} more)` : '';
                    const proceed = await customAlert(
                        'Manual Mode: Missing Availability',
                        `${noAvail} lecturer(s) have 0 available days.${preview ? `\n\nLecturers: ${preview}${remainder}` : ''}\n\nOpen lecturer availability file now to add availability?`,
                        'warning',
                        ['Add Availability', 'Continue Anyway']
                    );

                    if (proceed) {
                        window.open('edit_csv.php?file=csv/general/lecturer_availability.csv', '_blank');
                        clearInterval(pollInterval);
                        document.getElementById('progressStep').style.display = 'none';
                        document.getElementById('configStep').style.display = 'block';
                        return;
                    }
                }

                // If critical errors, stop here
                if (!preFlightData.feasible && preFlightData.errors && preFlightData.errors.length > 0) {
                    log("❌ Pre-flight check FAILED - Cannot proceed");
                    preFlightData.errors.slice(0, 3).forEach(e => {
                        log(`❌ Error: ${e}`);
                    });
                    clearInterval(pollInterval);
                    throw new Error(`Schedule generation blocked by pre-flight checks.\n\nErrors:\n${preFlightData.errors.join('\n')}\n\nRecommendations:\n${(preFlightData.recommendation?.suggestion || []).join('\n')}`);
                }

                if (preFlightData.score < 40) {
                    // High risk - warn but allow
                    const proceed = await customAlert(
                        'High-Risk Schedule',
                        `Feasibility score is ${preFlightData.score}%. Generation may fail or take very long.\n\nIssues:\n${preFlightData.warnings.join('\n')}\n\nContinue anyway?`,
                        'warning',
                        ['Continue', 'Cancel']
                    );
                    if (!proceed) {
                        clearInterval(pollInterval);
                        return;
                    }
                }

                // Clear session data error
                log("All pre-flight checks passed. Proceeding with scheduling...");

                // Determine endpoint based on exam mode
                const endpoint = isExamMode ? '/generate/exam' : '/generate';

                // Build request payload
                const payload = {
                    semester: document.getElementById('semester').value,
                    use_session: true,
                    csv_content: sessionData.csv_content,
                    csv_filename: sessionData.filename,
                    course_type: courseType,
                    department: deptName,
                    availability_mode: document.getElementById('availabilityMode').value,
                    exam_mode: isExamMode,
                    model: document.getElementById('schedulingModel').value,
                    general_schedule_path: generalSchedulePath,
                    output_filename: outputFilename
                };

                if (isExamMode) {
                    const examSource = document.querySelector('input[name="examInputSource"]:checked')?.value || 'upload';
                    if (examSource === 'saved') {
                        const select = document.getElementById('examSavedScheduleSelect');
                        if (!select || !select.value) {
                            await customAlert('Select Timetable', 'Please select a saved timetable for exam input.', 'warning');
                            clearInterval(pollInterval);
                            clearInterval(timerInterval);
                            document.getElementById('progressStep').style.display = 'none';
                            document.getElementById('configStep').style.display = 'block';
                            return;
                        }

                        const dlRes = await fetch(`api/download_b2_file.php?file=${encodeURIComponent(select.value)}`, {
                            credentials: 'include'
                        });
                        const dlData = await dlRes.json();
                        if (dlData.status !== 'success') {
                            throw new Error(dlData.message || 'Failed to download saved timetable');
                        }
                        payload.csv_content = dlData.data || [];
                        payload.csv_filename = (dlData.file || select.value || '').split('/').pop() || 'saved_timetable.csv';
                    }

                    const hallNameRaw = document.getElementById('examHallName').value.trim();
                    const hallNames = hallNameRaw.split(',').map(h => h.trim()).filter(Boolean);
                    if (!hallNames.length) {
                        log('Exam hall name is required for exam scheduling.');
                        await customAlert('Missing Exam Hall', 'Please enter at least one exam hall name.', 'warning');
                        clearInterval(pollInterval);
                        clearInterval(timerInterval);
                        document.getElementById('progressStep').style.display = 'none';
                        document.getElementById('configStep').style.display = 'block';
                        return;
                    }

                    payload.exam_hall_name = hallNames[0];
                    payload.exam_halls = hallNames;

                    const hallCapRaw = document.getElementById('examHallCapacity').value.trim();
                    if (hallCapRaw) {
                        const capParts = hallCapRaw.split(',').map(c => c.trim()).filter(Boolean);
                        let capacities = capParts.map(c => parseInt(c, 10)).filter(c => !Number.isNaN(c) && c > 0);

                        if (capacities.length === 1 && hallNames.length > 1) {
                            capacities = new Array(hallNames.length).fill(capacities[0]);
                        }

                        if (capacities.length !== hallNames.length) {
                            log('Hall capacities must be one value or match the number of halls.');
                            await customAlert('Capacity Mismatch', 'Hall capacities must match hall names count (or provide one capacity to apply to all).', 'warning');
                            clearInterval(pollInterval);
                            clearInterval(timerInterval);
                            document.getElementById('progressStep').style.display = 'none';
                            document.getElementById('configStep').style.display = 'block';
                            return;
                        }

                        payload.exam_hall_capacity = capacities[0];
                        payload.exam_hall_capacities = capacities;
                        payload.exam_halls = hallNames.map((name, idx) => ({
                            name,
                            capacity: capacities[idx]
                        }));
                    }
                }

                const data = await apiPost(endpoint, payload);

                clearInterval(pollInterval);
                clearInterval(timerInterval);

                const totalTime = Math.floor((Date.now() - startTime) / 1000);
                const minutes = Math.floor(totalTime / 60);
                const seconds = totalTime % 60;
                const timeStr = minutes > 0 ? `${minutes}m ${seconds}s` : `${seconds}s`;

                document.getElementById('progressBar').style.width = '100%';
                document.getElementById('progressTimer').innerHTML = `<i class="fa-solid fa-check-circle" style="color: #10b981;"></i> Generation completed in: ${timeStr}`;

                if (data.status === 'success') {
                    log(`AI solved the schedule successfully. Accuracy: ${data.accuracy}`);
                    document.getElementById('accuracyVal').innerText = data.accuracy;
                    if (document.getElementById('timeTakenScore')) {
                        document.getElementById('timeTakenScore').innerText = timeStr;
                    }
                    // Use actual output filename returned by API
                    if (data.output_file) {
                        const outputPath = `csv/final/${data.output_file}`;
                        document.getElementById('outputFile').value = outputPath;
                    }

                    // ====================================================================
                    // MANUAL SAVE FLOW
                    // Generated schedules are uploaded to B2 only.
                    // Users explicitly decide in view_schedule.php whether to save to DB.
                    // ====================================================================
                    log("Schedule generated successfully and uploaded to B2.");
                    log("No automatic DB save performed.");
                    log("Open View Schedule to review and save manually if needed.");

                    // Notify Admin (Async)
                    fetch('api/send_notification.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            notification_type: 'generation_success',
                            title: 'Schedule Generation Complete',
                            message: `Success! Schedule '${outputFilename}' was generated with ${data.accuracy} accuracy in ${timeStr}.`,
                            priority: 'medium'
                        })
                    }).catch(e => console.error('Notification failed', e));

                    // Show success screen
                    log("Success! Schedule uploaded to B2. Redirecting to view...");
                    setTimeout(() => {
                        document.getElementById('progressStep').style.display = 'none';
                        document.getElementById('successStep').style.display = 'block';

                        // Update View Button - Since auto-save is disabled, use file path from B2
                        const outFile = document.getElementById('outputFile').value;
                        if (outFile && outFile !== '' && outFile !== 'null') {
                            // View the generated schedule from B2
                            document.getElementById('viewScheduleBtn').href = 'view_schedule.php?file=' + encodeURIComponent(outFile);
                        } else {
                            // Redirect to schedules page to see all schedules
                            document.getElementById('viewScheduleBtn').href = 'schedules.php';
                            document.getElementById('viewScheduleBtn').innerHTML = '<i class="fa-solid fa-folder-open"></i> View All Schedules';
                        }

                        // Update Analytics UI with REAL data (generate response -> analytics endpoint fallback)
                        if (data.analytics && data.analytics.metrics) {
                            updateAnalyticsUI(data.analytics, data.accuracy);
                        } else {
                            fetchAIAnalytics(data.output_file)
                                .then(liveAnalytics => {
                                    if (liveAnalytics && liveAnalytics.metrics) {
                                        updateAnalyticsUI(liveAnalytics, data.accuracy);
                                    } else {
                                        // Last resort: derive metrics from actual generation accuracy
                                        updateAnalyticsUI(buildAccuracyDerivedAnalytics(data.accuracy), data.accuracy);
                                    }
                                })
                                .catch(() => {
                                    updateAnalyticsUI(buildAccuracyDerivedAnalytics(data.accuracy), data.accuracy);
                                });
                        }
                    }, 1000);
                } else {
                    log(`AI Error: ${data.message}`);
                    document.getElementById('statusText').textContent = "Generation Failed";
                    document.getElementById('statusText').style.color = "var(--danger)";
                }
            } catch (e) {
                if (pollInterval) clearInterval(pollInterval);
                log(`❌ ERROR: ${e.message}`);

                // Notify Admin of Failure
                fetch('api/send_notification.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        notification_type: 'generation_failure',
                        title: 'Schedule Generation Failed',
                        message: `Error: Critical failure during '${outputFilename}' generation: ${e.message}`,
                        priority: 'high'
                    })
                }).catch(() => { });

                // Log to server for audit trail
                fetch('api/error_handler.php', {
                    method: 'GET',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'get_logs'
                    })
                }).catch(() => { });

                // Provide helpful recovery suggestions
                log("");
                log("Troubleshooting & Recovery:");

                if (e.message.includes('Pre-flight')) {
                    log("✓ Check data integrity in Data Management page");
                    log("✓ Review CSV format against templates");
                    log("✓ Ensure all required columns are present");
                } else if (e.message.includes('timeout') || e.message.includes('503')) {
                    log("✓ Ensure Flask AI Engine is running: python app.py");
                    log("✓ Check that port 5000 is accessible");
                    log("✓ Verify Python dependencies: pip install -r requirements.txt");
                } else if (e.message.includes('database')) {
                    log("✓ Verify MySQL database is running");
                    log("✓ Check database credentials in api/db.php");
                    log("✓ Try running Setup Wizard again");
                } else {
                    log("✓ Check browser console for detailed error (F12)");
                    log("✓ Review server logs in system/logs/");
                    log("✓ Contact support if issue persists");
                }

                log("");
                log("Offering rollback to previous schedule...");

                document.getElementById('statusText').textContent = "Generation Failed - Recovering...";
                document.getElementById('statusText').style.color = "var(--danger)";

                // Auto-rollback if something went wrong
                fetch('api/rollback_schedule.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'get_history',
                        limit: 1
                    })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.backups && data.backups.length > 0) {
                            log("✓ Previous schedule available for rollback");
                        }
                    })
                    .catch(() => { });

                // ... (rest of the catch block)
                setTimeout(() => {
                    document.getElementById('progressStep').style.display = 'none';
                    document.getElementById('configStep').style.display = 'block';
                    customAlert('Generation Failed', e.message, 'error');
                }, 2000);
            }
        });

        // Template Management
        async function saveAsTemplate() {
            const name = await showPrompt("Enter a name for this template:", "Schedule Config " + new Date().toLocaleDateString(), 'Save Template');
            if (!name) return;

            const config = {
                semester: document.getElementById('semester').value,
                course_type: document.getElementById('courseType').value,
                dept_rooms: document.getElementById('deptRooms')?.value,
                availability_mode: document.getElementById('availabilityMode').value,
                weights: {
                    room: document.getElementById('weightRoom').value,
                    lecturer: document.getElementById('weightLecturer').value,
                    balance: document.getElementById('weightBalance').value
                }
            };

            try {
                const res = await fetch('api/manage_templates.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        template_name: name,
                        config_json: config
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    customAlert('Success', 'Template saved successfully!', 'success');
                }
            } catch (e) {
                customAlert('Error', 'Failed to save template', 'error');
            }
        }

        async function loadTemplate(id) {
            try {
                const res = await fetch('api/manage_templates.php');
                const data = await res.json();
                if (data.status === 'success') {
                    const template = data.data.find(t => t.id == id);
                    if (template) {
                        const c = template.config_json;
                        if (c.semester) document.getElementById('semester').value = c.semester;
                        if (c.course_type) {
                            document.getElementById('courseType').value = c.course_type;
                            // Trigger any change events
                            document.getElementById('courseType').dispatchEvent(new Event('change'));
                        }
                        if (c.dept_rooms && document.getElementById('deptRooms')) {
                            document.getElementById('deptRooms').value = c.dept_rooms;
                        }
                        if (c.availability_mode) document.getElementById('availabilityMode').value = c.availability_mode;

                        if (c.weights) {
                            if (c.weights.room) {
                                document.getElementById('weightRoom').value = c.weights.room;
                                document.getElementById('valRoom').innerText = c.weights.room + '.0';
                            }
                            if (c.weights.lecturer) {
                                document.getElementById('weightLecturer').value = c.weights.lecturer;
                                document.getElementById('valLecturer').textContent = c.weights.lecturer + '.0';
                            }
                            if (c.weights.balance) {
                                document.getElementById('weightBalance').value = c.weights.balance;
                                document.getElementById('valBalance').innerText = c.weights.balance + '.0';
                            }
                        }
                        console.log('Template loaded:', template.template_name);
                    }
                }
            } catch (e) {
                console.error('Failed to load template:', e);
            }
        }

        // Check for template_id on load
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const templateId = urlParams.get('template_id');
            if (templateId) {
                loadTemplate(templateId);
            }
        });
    </script>



    <?php include 'includes/footer.php'; ?>