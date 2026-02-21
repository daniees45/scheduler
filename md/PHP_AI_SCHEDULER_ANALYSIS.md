# PHP Files Analysis: AI Scheduler - What's Missing

**Date:** February 16, 2026  
**Analysis Target:** PHP layer integration with AI Scheduler  
**Status:** ⚠️ Mostly complete but with critical gaps

---

## Executive Summary

The PHP infrastructure for the AI Scheduler is **~85% complete**. The core pipeline (sync → Flask → update_db) is operational, but several supporting features and error-handling mechanisms are missing or incomplete.

### Health Status
- ✅ **Core Pipeline:** Working (sync.php → API → update_db.php)
- ✅ **Authentication:** RBAC implemented
- ⚠️ **Error Handling:** Minimal
- ⚠️ **Analytics Dashboard:** Missing
- ⚠️ **Conflict Detection:** Incomplete
- ⚠️ **Version Control:** Exists but not fully integrated
- ⚠️ **Data Validation:** Basic only

---

## Section 1: Existing PHP Files (✅ Present)

### Core Pipeline Files
```
web/api/
├── sync.php                  ✅ DB → CSV export
├── update_db.php             ✅ CSV → DB import
└── schedule_versions.php     ✅ Version management
```

### Authentication & Access Control
```
web/api/
├── auth.php                  ✅ Login/session management
├── register_public.php       ✅ User registration
└── includes/access_control.php ✅ RBAC enforcement
```

### Web Pages (Display)
```
web/
├── login.php                 ✅ Authentication interface
├── dashboard.php             ✅ Role-based dashboard (student/lecturer/admin)
├── student_dashboard.php     ✅ Student home
├── lecturer_dashboard.php    ✅ Lecturer home
├── generate.php              ✅ Schedule generation UI
├── view_schedule.php         ✅ View generated schedules
├── student_view.php          ✅ Student personalized view
├── courses.php               ✅ Course management
├── rooms.php                 ✅ Room management
├── lecturers.php             ✅ Lecturer management
└── special_rooms.php         ✅ Special room assignments
```

### Data Management
```
web/api/
├── import_csv_to_db.php      ✅ CSV import interface
├── upload_csv.php            ✅ File upload handler
├── save_csv.php              ✅ CSV persistence
└── import_before_gen.php     ✅ Pre-generation import
```

### Database Support
```
web/db/
├── init_db.sql               ✅ Database initialization
├── setup_rbac_safe.sql       ✅ RBAC schema
└── migrate_rbac.sql          ✅ RBAC migration
```

### Configuration & Headers
```
web/
├── includes/header.php       ✅ Navigation & UI template
├── includes/footer.php       ✅ Footer template
└── config.js                 ✅ Unified API configuration
```

---

## Section 2: Missing or Incomplete Features (❌ Gap Analysis)

### 2.1 Error Handling & Validation

**Status:** ⚠️ **INCOMPLETE**

#### Missing Files:
- **`web/api/validate_csv.php`** - Pre-validation before processing
- **`web/api/error_handler.php`** - Centralized error logging
- **`web/api/check_data_integrity.php`** - Data validation after import
- **`web/api/rollback_schedule.php`** - Rollback on error

#### What's Missing:
```php
// web/api/validate_csv.php (NEEDED)
<?php
/**
 * Pre-generation data validation
 * Checks:
 * - Column headers match expected format
 * - No null values in critical fields
 * - Room capacities match course enrollments
 * - Lecturer availability is valid
 * - Course codes are unique
 * - Time slot conflicts pre-exist
 */
function validate_csv_structure($file) {
    // Implementation missing
}

function validate_data_integrity($file) {
    // Implementation missing
}

function check_room_capacity($courses, $rooms) {
    // Implementation missing
}

function check_lecturer_conflicts($courses, $availability) {
    // Implementation missing
}
?>
```

**Impact:** 
- Invalid data can crash the AI engine
- No early warning of scheduling impossibilities
- Silent failures without clear error messages

---

### 2.2 Conflict Detection & Prevention

**Status:** ⚠️ **PARTIALLY IMPLEMENTED**

#### Existing but Incomplete:
- `web/api/check_conflicts.php` - Exists but only checks POST-generation conflicts

#### Missing:
- **`web/api/pre_flight_check.php`** - PRE-generation conflict detection
- **`web/api/lecturer_overlap_detector.php`** - Detect lecturer time conflicts
- **`web/api/room_conflict_detector.php`** - Detect room double-bookings
- **`web/api/level_clash_detector.php`** - Detect level-based course clashes
- **`web/api/get_conflict_report.php`** - Generate detailed conflict report

#### What's Missing:
```php
// web/api/pre_flight_check.php (NEEDED)
<?php
/**
 * Pre-generation diagnostic check
 * Should be called BEFORE sending to Flask API
 * Returns:
 * - Feasibility score (0-100%)
 * - List of potential conflicts
 * - Warning messages
 * - Recommendations for resolution
 */
function run_pre_flight_checks($input_file) {
    $issues = [];
    
    // Check 1: Data integrity
    if (!validate_csv_structure($input_file)) {
        $issues[] = "CSV structure invalid";
    }
    
    // Check 2: Lecturer availability
    // Load lecturer_availability.csv
    // Check if any lecturer has 0 availability slots
    // Load courses matching each lecturer
    // Check if total slots ≥ course count
    
    // Check 3: Room capacity
    // Load rooms.csv with capacities
    // Load courses with enrollment
    // Check if sum(room capacities) ≥ max enrollment
    
    // Check 4: Level clashes
    // Load level files
    // Check if same-level courses have overlaps
    
    // Check 5: Special rooms
    // If course requires special room, verify room exists
    
    // Return feasibility score
    $feasibility = calculate_feasibility_score($issues);
    return [
        "feasible" => $feasibility > 60,
        "score" => $feasibility,
        "issues" => $issues
    ];
}
?>
```

**Impact:**
- Users don't know if generation will fail BEFORE spending 2+ minutes waiting
- No early warning about impossible constraints
- Leads to frustration and wasted computational time

---

### 2.3 Analytics & Performance Dashboard

**Status:** ❌ **MISSING**

#### Missing Files:
- **`web/analytics_dashboard.php`** - Main analytics UI
- **`web/api/get_schedule_metrics.php`** - Compute schedule quality metrics
- **`web/api/get_room_utilization.php`** - Room usage statistics
- **`web/api/get_lecturer_load.php`** - Lecturer workload analysis
- **`web/api/get_time_distribution.php`** - Time slot occupancy
- **`web/api/export_analytics.php`** - Export analytics to PDF/CSV

#### What's Missing:
```php
// web/api/get_schedule_metrics.php (NEEDED)
<?php
/**
 * Calculate comprehensive schedule quality metrics
 * Returns JSON with:
 * - Scheduling Success Rate (%)
 * - Average Room Utilization (%)
 * - Lecturer Load Balance (std dev)
 * - Time Slot Efficiency (%)
 * - Course Distribution by Day
 * - Peak Load Hours
 * - Conflict Count (if any)
 */
function get_schedule_metrics($schedule_csv) {
    $metrics = [];
    
    // 1. Success Rate
    $total_courses = count($schedule_csv);
    $scheduled = count(array_filter($schedule_csv, fn($c) => $c['room'] && $c['time']));
    $metrics['success_rate'] = ($scheduled / $total_courses) * 100;
    
    // 2. Room Utilization
    // Group by room, count bookings per room
    // Calculate: (booked_slots / total_slots) × 100
    
    // 3. Lecturer Load
    // Calculate: average courses per lecturer
    // Std dev from average (for balance metric)
    
    // 4. Time Efficiency
    // How many empty slots exist?
    // (Used_slots / Total_slots) × 100
    
    return $metrics;
}
?>
```

**Expected Dashboard UI:**
- Chart showing room utilization by department
- Heatmap of time slots usage (Mon-Fri, 8am-5pm)
- Lecturer workload distribution
- Conflict summary (if any)
- Export buttons (PDF, PNG, CSV)

**Impact:**
- Admins can't assess schedule quality at a glance
- No data-driven insights for optimization
- Cannot identify bottlenecks (e.g., overbooked lecturers)
- Missing compliance reporting

---

### 2.4 Version Control & History

**Status:** ⚠️ **PARTIALLY WORKING**

#### Existing:
- `web/api/schedule_versions.php` - Saves versions to database
- `web/api/generated_schedules.php` - Lists versions

#### Missing:
- **`web/api/compare_versions.php`** - Compare two schedules side-by-side
- **`web/api/restore_version.php`** - Rollback to previous version
- **`web/api/export_version_history.php`** - Export all versions as timeline
- **`web/version_history.php`** - UI to browse versions with diff view

#### What's Missing:
```php
// web/api/compare_versions.php (NEEDED)
<?php
/**
 * Compare two schedule versions
 * Returns:
 * - List of courses moved (course_code, old_time, new_time, old_room, new_room)
 * - Metrics improvement (old_accuracy, new_accuracy)
 * - Changes summary
 */
function compare_versions($version_id_1, $version_id_2) {
    // Fetch both versions from database
    // Identify differences
    // Calculate delta metrics
    // Return diff report
}

function get_version_timeline() {
    // Return all versions with timestamps
    // Include accuracy scores, department, semester
    // Sort by most recent first
}
?>
```

**Impact:**
- Cannot audit which schedules were generated when
- Cannot revert to previous known-good schedules
- No historical trending (is accuracy improving over time?)

---

### 2.5 Data Management & Exports

**Status:** ⚠️ **PARTIAL**

#### Existing:
- `web/api/export_pdf.php` - PDF export
- `web/api/download_pdf.php` - PDF download handler

#### Missing:
- **`web/api/export_to_ics.php`** - Calendar file export
- **`web/api/export_to_json.php`** - JSON export for integration
- **`web/api/export_to_xlsx.php`** - Excel export (better formatting)
- **`web/api/bulk_export_all_departments.php`** - Export all depts at once

#### What's Missing:
```php
// web/api/export_to_ics.php (NEEDED)
<?php
/**
 * Export schedule to iCalendar format
 * Allows students/lecturers to import into Outlook, Google Calendar, etc.
 * One ICS file per role:
 * - student_schedule.ics (personalized)
 * - lecturer_schedule.ics (personalized)
 * - all_schedule.ics (full schedule)
 */
function export_to_ics($csv_file, $export_type = 'all') {
    // Parse CSV
    // Create VCALENDAR structure
    // Add VEVENT entries for each class
    // Set reminders (2 hours before)
    // Return ICS formatted string
}
?>
```

**Impact:**
- Students/lecturers can't add schedule to personal calendars
- No integration with external calendar systems
- Reduced adoption and usage of schedule system

---

### 2.6 Logging & Auditing

**Status:** ❌ **MISSING**

#### Missing Files:
- **`web/api/log_operation.php`** - Centralized operation logging
- **`web/api/get_audit_log.php`** - Retrieve audit trail
- **`web/api/export_audit_log.php`** - Export audit for compliance
- **`web/audit_log_viewer.php`** - UI to view logs

#### What Should Be Logged:
```php
// web/api/log_operation.php (NEEDED)
<?php
/**
 * Log all operations for auditing
 * What to log:
 * - User login/logout
 * - Schedule generation (input, params, result)
 * - Data imports/exports
 * - Database modifications
 * - Permission denials
 * - Errors and exceptions
 * 
 * Fields: timestamp, user_id, action, resource, status, details, ip_address
 */
function log_operation($action, $resource, $status, $details = '') {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $_SESSION['user_id'] ?? null,
        'action' => $action,
        'resource' => $resource,
        'status' => $status,
        'details' => $details,
        'ip_address' => $_SERVER['REMOTE_ADDR']
    ];
    
    // Insert into audit_log table
    // $conn->query("INSERT INTO audit_log VALUES (...)")
}

function get_audit_trail($filters = []) {
    // Query audit_log with filters (user, action, date range, etc.)
    // Return chronological list
    // Include pagination
}
?>
```

**Impact:**
- No compliance trail for auditors
- Cannot trace who made what changes when
- Security liability for academic institution
- No forensic capability for investigations

---

### 2.7 API Rate Limiting & Protection

**Status:** ❌ **MISSING**

#### Missing Files:
- **`web/api/rate_limiter.php`** - Implement rate limiting
- **`web/api/csrf_protection.php`** - CSRF token verification
- **`web/api/input_sanitizer.php`** - Centralized input validation

#### What's Missing:
```php
// web/api/rate_limiter.php (NEEDED)
<?php
/**
 * Prevent abuse by limiting requests
 * Rules:
 * - Max 10 schedule generations per hour per user
 * - Max 100 API calls per minute per IP
 * - Max 1000 API calls per hour per user
 */
function check_rate_limit($user_id, $endpoint) {
    // Check Redis/database for recent requests
    // Return true if within limits, false if exceeded
}

function increment_rate_counter($user_id, $endpoint) {
    // Increment counter
    // Set TTL to 1 hour
}
?>
```

**Impact:**
- API can be flooded with requests (DoS)
- Malicious users can mine/scrape data
- Computational resources wasted on invalid requests
- No protection for legitimate users

---

### 2.8 Real-Time Progress Tracking

**Status:** ⚠️ **PARTIAL**

#### Existing:
- `app.py` saves `ai_progress.json` for polling
- `web/api/` has progress endpoints

#### Missing:
- **`web/api/websocket_progress.php`** - WebSocket for real-time updates (instead of polling)
- **`web/progress_monitor.php`** - Enhanced UI with detailed breakdown
- **`web/api/cancel_job.php`** - Allow user to cancel long-running job

#### What's Missing:
```php
// web/api/websocket_progress.php (NEEDED)
<?php
/**
 * WebSocket connection for real-time progress
 * Instead of polling every 500ms, server pushes updates
 * Benefits:
 * - Lower latency (immediate feedback)
 * - Reduced server load (fewer requests)
 * - Better UX (smooth progress bar)
 * - Can include intermediate messages
 */
// Requires: php-websockets library
?>
```

**Impact:**
- Progress polling creates server load
- Stale progress information
- Poor user experience (jerky updates)
- Users don't know if system is working during long waits

---

### 2.9 Email Notifications

**Status:** ❌ **MISSING**

#### Missing Files:
- **`web/api/send_notification.php`** - Email notification handler
- **`web/api/notify_schedule_ready.php`** - Notify when schedule generated
- **`web/api/notify_conflicts_detected.php`** - Alert on conflicts
- **`web/settings/email_preferences.php`** - User email preferences

#### What's Missing:
```php
// web/api/send_notification.php (NEEDED)
<?php
/**
 * Send email notifications to stakeholders
 * Events to notify:
 * - Schedule generation complete (accuracy score)
 * - Conflicts detected in schedule
 * - Your courses have been assigned times
 * - Department schedule published
 */
function notify_schedule_ready($schedule_id, $recipients) {
    // Fetch schedule metadata
    // Generate email body with summary
    // List all courses assigned
    // Include download link
    // Send via SMTP
}

function send_mass_notification($department_id, $title, $body, $type = 'email') {
    // Get all users in department
    // Send personalized emails
    // Track delivery/opens
}
?>
```

**Impact:**
- Admins must manually notify users
- Students/lecturers don't know schedule is ready
- Delays in schedule adoption
- Missed communication opportunities

---

### 2.10 Integration & Webhooks

**Status:** ❌ **MISSING**

#### Missing Files:
- **`web/api/webhook_dispatcher.php`** - Send webhooks on schedule changes
- **`web/api/external_system_sync.php`** - Sync with external systems
- **`web/settings/integrations.php`** - Manage external integrations

#### What's Missing:
```php
// web/api/webhook_dispatcher.php (NEEDED)
<?php
/**
 * Send webhooks to external systems
 * Triggers:
 * - Schedule generated (POST to registered URLs)
 * - Conflicts detected
 * - Version updated
 * 
 * Allows:
 * - LMS systems to fetch updated schedule
 * - Mobile apps to receive push notifications
 * - Analytics systems to process data
 * - Third-party integrations
 */
function register_webhook($url, $events, $api_key) {
    // Store webhook URL + events in database
    // Verify endpoint is reachable
}

function dispatch_webhook($event_type, $payload) {
    // Get all registered webhooks for event
    // Send POST request to each
    // Retry if failed
    // Log dispatch results
}
?>
```

**Impact:**
- Cannot integrate with institutional systems (LMS, SIS)
- No event-driven architecture
- Third-party tools cannot react to schedule changes
- Reduced system value/adoption

---

## Section 3: Critical Issues to Address

### Priority 1 (High - Should Fix ASAP)

| Issue | File Needed | Impact | Effort |
|-------|------------|--------|--------|
| No pre-flight validation | `web/api/validate_csv.php` | Crashes on invalid data | Low |
| No conflict detection frontend | `web/api/pre_flight_check.php` | Silent failures | Medium |
| No error handling centralization | `web/api/error_handler.php` | Messy error messages | Low |
| No data rollback on failure | `web/api/rollback_schedule.php` | Bad schedule stuck in DB | Medium |
| Missing analytics dashboard | `web/analytics_dashboard.php` + APIs | Cannot assess quality | High (UI) |

### Priority 2 (Medium - Should Have)

| Issue | File Needed | Impact | Effort |
|-------|------------|--------|--------|
| No version comparison | `web/api/compare_versions.php` | Cannot audit changes | Medium |
| No audit logging | `web/api/log_operation.php` | Compliance gap | Medium |
| No calendar export | `web/api/export_to_ics.php` | Low adoption | Low |
| No rate limiting | `web/api/rate_limiter.php` | Security risk | Medium |
| No WebSocket progress | `web/api/websocket_progress.php` | Server load, UX issue | High |

### Priority 3 (Low - Nice to Have)

| Issue | File Needed | Impact | Effort |
|-------|------------|--------|--------|
| No email notifications | `web/api/send_notification.php` | Manual communication | Medium |
| No webhooks | `web/api/webhook_dispatcher.php` | Limited integration | High |
| No bulk export | `web/api/bulk_export_all_departments.php` | Convenience feature | Low |
| No audit log UI | `web/audit_log_viewer.php` | UI convenience | Medium |

---

## Section 4: Database Schema Gaps

### Missing Tables for Complete System

```sql
-- Missing: audit_log table
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    timestamp DATETIME,
    user_id INT,
    action VARCHAR(100),
    resource VARCHAR(255),
    status VARCHAR(50),
    details TEXT,
    ip_address VARCHAR(45),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Missing: api_rate_limit table
CREATE TABLE api_rate_limit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    endpoint VARCHAR(100),
    request_count INT,
    window_reset DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Missing: webhooks table
CREATE TABLE webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    url VARCHAR(500) NOT NULL,
    events JSON,
    api_key VARCHAR(255),
    active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Missing: email_preferences table
CREATE TABLE email_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    notify_schedule_ready BOOLEAN DEFAULT 1,
    notify_conflicts BOOLEAN DEFAULT 1,
    notify_assignments BOOLEAN DEFAULT 1,
    notification_email VARCHAR(255),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

---

## Section 5: Implementation Roadmap

### Phase 1: Core Stability (1-2 weeks)
- [ ] Add `validate_csv.php` - prevent invalid data
- [ ] Add `error_handler.php` - unified error logging
- [ ] Add `rollback_schedule.php` - recovery mechanism
- [ ] Add pre-flight checks to `generate.php` UI

### Phase 2: Analytics (2-3 weeks)
- [ ] Create `analytics_dashboard.php`
- [ ] Create metrics computation APIs
- [ ] Add Chart.js for visualizations
- [ ] Add export functionality

### Phase 3: Enterprise Features (3-4 weeks)
- [ ] Add audit logging system
- [ ] Add rate limiting
- [ ] Add version comparison
- [ ] Create version history UI

### Phase 4: Integration & Notifications (2-3 weeks)
- [ ] Add email notifications
- [ ] Add webhook system
- [ ] Add calendar export (ICS)
- [ ] Add external system sync

---

## Section 6: Security Concerns

### Current Gaps:
1. **SQL Injection Risk** - Most SQL is parameterized, but some still concatenate
2. **Path Traversal** - File uploads need stricter validation
3. **CSRF** - No CSRF token validation on POST endpoints
4. **Rate Limiting** - Any user can make unlimited API calls
5. **Audit Trail** - No logging of who did what when
6. **Data Validation** - Minimal input validation before processing

### Recommended Fixes:
```php
// Add to ALL POST endpoints
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    die(json_encode(['error' => 'CSRF validation failed']));
}

// Add to file upload handlers
if (!is_valid_file_upload($_FILES['file'])) {
    die(json_encode(['error' => 'Invalid file']));
}

// Add rate checking before expensive operations
if (exceeded_rate_limit($_SESSION['user_id'], 'schedule_generation')) {
    die(json_encode(['error' => 'Too many requests. Please wait.']));
}
```

---

## Section 7: Conclusion

### What's Working Well:
✅ Core scheduling pipeline (DB → CSV → API → DB)  
✅ Authentication with RBAC  
✅ Basic CRUD operations for courses/rooms/lecturers  
✅ Version storage (database level)  

### What Needs Work:
⚠️ Error handling (too permissive)  
⚠️ Pre-generation validation (missing)  
⚠️ Analytics & reporting (missing)  
⚠️ Audit trail (missing)  
⚠️ Real-time progress (polling, not WebSocket)  

### Major Gaps:
❌ No analytics dashboard  
❌ No conflict detection UI  
❌ No email notifications  
❌ No audit logging  
❌ No rate limiting  
❌ No webhook/integration system  

### Recommendation:
**Start with Priority 1 items** (validation, error handling, analytics) to establish a solid foundation. These affect 95% of daily use.

---

## File Structure Reference

```
web/
├── generate.php                          ✅ Generation UI
├── analytics_dashboard.php               ❌ MISSING
├── version_history.php                   ❌ MISSING
├── audit_log_viewer.php                  ❌ MISSING
├── settings/
│   ├── email_preferences.php             ❌ MISSING
│   └── integrations.php                  ❌ MISSING
├── api/
│   ├── sync.php                          ✅ DB export
│   ├── update_db.php                     ✅ DB import
│   ├── validate_csv.php                  ❌ MISSING
│   ├── error_handler.php                 ❌ MISSING
│   ├── pre_flight_check.php              ❌ MISSING
│   ├── check_conflicts.php               ⚠️ Incomplete
│   ├── compare_versions.php              ❌ MISSING
│   ├── rate_limiter.php                  ❌ MISSING
│   ├── log_operation.php                 ❌ MISSING
│   ├── get_audit_log.php                 ❌ MISSING
│   ├── send_notification.php             ❌ MISSING
│   ├── webhook_dispatcher.php            ❌ MISSING
│   ├── export_to_ics.php                 ❌ MISSING
│   ├── export_to_json.php                ❌ MISSING
│   ├── get_schedule_metrics.php          ❌ MISSING
│   ├── get_room_utilization.php          ❌ MISSING
│   ├── get_lecturer_load.php             ❌ MISSING
│   └── ... (other existing files)        ✅
└── db/
    ├── init_db.sql                       ✅ Tables exist
    ├── audit_log.sql                     ❌ MISSING (add audit_log table)
    ├── webhooks.sql                      ❌ MISSING (add webhooks table)
    ├── email_preferences.sql             ❌ MISSING (add email prefs table)
    └── rate_limit.sql                    ❌ MISSING (add rate limit table)
```

---

**For immediate impact, focus on:**
1. `validate_csv.php` - Prevent bad data
2. `pre_flight_check.php` - Warn users before long wait
3. `get_schedule_metrics.php` - Show quality metrics
4. Enhanced error messages in all endpoints

