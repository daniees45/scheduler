# ✅ Personal Scheduler Features - Testing Checklist

## Pre-Installation

- [ ] MySQL server is running
- [ ] Database `vvu_scheduler` exists
- [ ] User has access credentials
- [ ] Web server (Apache/XAMPP) is running

---

## Installation

### Database Schema
- [ ] Run: `mysql -u root -p vvu_scheduler < web/db/personal_scheduler_schema.sql`
- [ ] Verify 8 new tables created:
  - [ ] `user_priorities`
  - [ ] `user_goals`
  - [ ] `productivity_log`
  - [ ] `task_preferences`
  - [ ] `notifications`
  - [ ] `reminder_settings`
  - [ ] `schedule_suggestions`
  - [ ] `productivity_metrics`

### File Verification
- [ ] API files exist in `web/api/`:
  - [ ] `personal_priorities.php`
  - [ ] `smart_suggestions.php`
  - [ ] `productivity_tracking.php`
  - [ ] `notifications.php`
- [ ] UI files exist in `web/`:
  - [ ] `priorities_goals.php`
  - [ ] `notifications.php`
  - [ ] `productivity_analytics.php`
  - [ ] `reminder_settings.php`
- [ ] `my_schedule.php` has been enhanced

---

## Feature Testing

### 1. Priorities & Goals Management

#### Add Priority
- [ ] Navigate to `web/priorities_goals.php`
- [ ] Click "Add Priority"
- [ ] Fill form:
  - [ ] Priority Name: "Master Data Structures"
  - [ ] Level: High
  - [ ] Category: Study
  - [ ] Target Hours: 10
- [ ] Submit form
- [ ] Verify priority appears in list
- [ ] Verify high priority has red border

#### Edit Priority
- [ ] Click edit button on a priority
- [ ] Modify fields
- [ ] Save changes
- [ ] Verify changes reflected

#### Delete Priority
- [ ] Click delete button
- [ ] Confirm deletion
- [ ] Verify priority removed from list

#### Add Goal
- [ ] Click "Add Goal"
- [ ] Fill form:
  - [ ] Title: "Achieve 3.5 GPA"
  - [ ] Category: Academic
  - [ ] Priority: High
  - [ ] Target Date: [future date]
- [ ] Submit form
- [ ] Verify goal appears in list

#### Update Goal Progress
- [ ] Click progress button on goal
- [ ] Enter new percentage (e.g., 50)
- [ ] Verify progress bar updates
- [ ] Verify completed goals marked as such at 100%

---

### 2. Smart Suggestions

#### Generate Suggestions
- [ ] Go to `web/my_schedule.php`
- [ ] Click "AI Suggestions" button
- [ ] Suggestions panel appears
- [ ] If no suggestions, click "Generate Suggestions"
- [ ] Verify 0-15 suggestions appear
- [ ] Each suggestion shows:
  - [ ] Day and time range
  - [ ] Duration
  - [ ] Score
  - [ ] Reason
  - [ ] Accept/Reject buttons

#### Accept Suggestion
- [ ] Click "Accept" on a suggestion
- [ ] Verify success message
- [ ] Verify suggestion removed from list
- [ ] Check `task_preferences` table updated

#### Reject Suggestion
- [ ] Click "Reject" on a suggestion
- [ ] Verify suggestion removed
- [ ] Check negative score in `task_preferences`

#### API Testing
```bash
# Test get suggestions
curl http://localhost/scheduler/web/api/smart_suggestions.php?action=get_suggestions

# Test generate suggestions
curl http://localhost/scheduler/web/api/smart_suggestions.php?action=generate_suggestions

# Test optimal times
curl http://localhost/scheduler/web/api/smart_suggestions.php?action=get_optimal_times&category=study
```
- [ ] All endpoints return valid JSON
- [ ] No 500 errors
- [ ] Data structure is correct

---

### 3. Productivity Tracking & Analytics

#### Log Task (API)
```bash
curl -X POST http://localhost/scheduler/web/api/productivity_tracking.php \
  -H "Content-Type: application/json" \
  -d '{
    "action": "log_task",
    "task_name": "Algorithm Study",
    "task_category": "study",
    "day": "Monday",
    "start_time": "09:00",
    "end_time": "11:00",
    "quality_rating": 4,
    "completion_status": "completed"
  }'
```
- [ ] Task logged successfully
- [ ] Returns success JSON
- [ ] Task appears in database

#### View Analytics Dashboard
- [ ] Navigate to `web/productivity_analytics.php`
- [ ] Verify stats cards show:
  - [ ] Total Tasks
  - [ ] Productive Hours
  - [ ] Completion Rate (with progress bar)
  - [ ] Average Quality
- [ ] Verify productivity heatmap displays
- [ ] Verify category breakdown shows
- [ ] Verify peak performance time displays

#### Period Selection
- [ ] Click "Today" button
- [ ] Verify stats update
- [ ] Click "This Week" button
- [ ] Verify stats update
- [ ] Click "This Month" button
- [ ] Verify stats update

#### Heatmap
- [ ] Verify 7 days × 24 hours grid
- [ ] Verify color coding (0-5 levels)
- [ ] Hover over cells shows tooltip
- [ ] Empty cells are gray

#### API Testing
```bash
# Get statistics
curl http://localhost/scheduler/web/api/productivity_tracking.php?action=get_statistics&period=week

# Get heatmap
curl http://localhost/scheduler/web/api/productivity_tracking.php?action=get_heatmap

# Get patterns
curl http://localhost/scheduler/web/api/productivity_tracking.php?action=get_patterns
```
- [ ] All endpoints return valid data
- [ ] Calculations are correct

---

### 4. Notifications & Reminders

#### Configure Reminder Settings
- [ ] Go to `web/reminder_settings.php`
- [ ] Verify 4 reminder types listed:
  - [ ] Class Reminders
  - [ ] Exam Reminders
  - [ ] Personal Event Reminders
  - [ ] Study Session Reminders
- [ ] Toggle each on/off
- [ ] Change minutes_before for each
- [ ] Click "Save Settings"
- [ ] Verify success message

#### Generate Reminders
- [ ] Click "Generate Reminders Now"
- [ ] Verify count of reminders generated
- [ ] Go to `web/notifications.php`
- [ ] Verify reminders appear in list

#### View Notifications
- [ ] Navigate to `web/notifications.php`
- [ ] Verify notifications listed
- [ ] Unread notifications have blue border
- [ ] New badge appears on unread items

#### Mark as Read
- [ ] Click checkmark on notification
- [ ] Verify notification marked as read
- [ ] Verify badge removed

#### Mark All Read
- [ ] Click "Mark All Read" button
- [ ] Verify all notifications marked as read
- [ ] Verify count updates

#### Delete Notification
- [ ] Click trash icon
- [ ] Confirm deletion
- [ ] Verify notification removed

#### Notification Badge (My Schedule)
- [ ] Go to `web/my_schedule.php`
- [ ] If unread notifications exist, verify badge shows count
- [ ] Badge is red with white text
- [ ] Badge updates automatically every 60s

#### API Testing
```bash
# List notifications
curl http://localhost/scheduler/web/api/notifications.php?action=list

# Get reminder settings
curl http://localhost/scheduler/web/api/notifications.php?action=get_reminder_settings

# Generate reminders
curl http://localhost/scheduler/web/api/notifications.php?action=generate_reminders
```
- [ ] All endpoints work
- [ ] Data format is correct

---

### 5. My Schedule Integration

#### Quick Actions Panel
- [ ] Navigate to `web/my_schedule.php`
- [ ] Verify 5 buttons appear:
  - [ ] Analytics (links to analytics page)
  - [ ] AI Suggestions (opens panel)
  - [ ] Priorities & Goals (links to page)
  - [ ] Notifications (links to page, shows badge)
  - [ ] Reminders (links to settings)

#### Smart Suggestions Panel
- [ ] Click "AI Suggestions"
- [ ] Panel expands below
- [ ] Shows suggestions or empty state
- [ ] X button closes panel
- [ ] Accept/Reject buttons work

#### Notification Count
- [ ] Create unread notification
- [ ] Refresh `my_schedule.php`
- [ ] Verify badge shows count
- [ ] Mark notification as read
- [ ] Wait 60s or refresh
- [ ] Verify badge updates or disappears

---

## Integration Testing

### End-to-End Workflow
- [ ] **Step 1:** Add priority "Study" - High
- [ ] **Step 2:** Add goal "Master Algorithms"
- [ ] **Step 3:** Get AI suggestions
- [ ] **Step 4:** Accept a study slot suggestion
- [ ] **Step 5:** Log completed study task
- [ ] **Step 6:** View analytics showing the task
- [ ] **Step 7:** Enable course reminders
- [ ] **Step 8:** Generate reminders
- [ ] **Step 9:** View notification for upcoming class

### Priority Impact on Suggestions
- [ ] Add high priority "Health"
- [ ] Generate suggestions
- [ ] Verify health-related slots get higher scores
- [ ] Add low priority "Entertainment"
- [ ] Generate suggestions
- [ ] Verify entertainment slots get lower scores

### Productivity Pattern Learning
- [ ] Log 3+ tasks at same day/hour
- [ ] All with high quality ratings
- [ ] Generate suggestions
- [ ] Verify that day/hour gets bonus score in reason

### Q-Learning Preferences
- [ ] Accept suggestions for "Monday 9:00"
- [ ] Reject suggestions for "Friday 16:00"
- [ ] Generate new suggestions
- [ ] Verify Monday morning slots ranked higher
- [ ] Verify Friday afternoon slots ranked lower

---

## Performance Testing

### Page Load Times
- [ ] `my_schedule.php` loads < 2 seconds
- [ ] `priorities_goals.php` loads < 2 seconds
- [ ] `productivity_analytics.php` loads < 3 seconds
- [ ] `notifications.php` loads < 2 seconds

### API Response Times
- [ ] All API endpoints respond < 500ms
- [ ] Suggestion generation < 2 seconds
- [ ] Analytics computation < 1 second

### Database Queries
- [ ] No N+1 query problems
- [ ] All queries use indexes
- [ ] No slow query warnings

---

## Security Testing

### Authentication
- [ ] Logged out users redirected to login
- [ ] Cannot access APIs without session
- [ ] User can only see own data

### Input Validation
- [ ] SQL injection attempts fail:
```bash
curl -X POST http://localhost/scheduler/web/api/personal_priorities.php \
  -H "Content-Type: application/json" \
  -d '{"action":"add_priority","priority_name":"Test\"; DROP TABLE users; --"}'
```
- [ ] XSS attempts are escaped
- [ ] Invalid data types rejected

### Authorization
- [ ] User A cannot access User B's priorities
- [ ] User A cannot modify User B's goals
- [ ] User A cannot see User B's notifications

---

## Browser Compatibility

- [ ] Chrome/Edge (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Mobile browsers (responsive)

---

## Error Handling

### Database Errors
- [ ] Stop MySQL → Verify error messages
- [ ] Wrong credentials → Show error
- [ ] Missing tables → Show error

### API Errors
- [ ] Invalid action → 400 error
- [ ] Missing parameters → Error message
- [ ] Database failure → 500 error

### User Errors
- [ ] Empty form submission → Validation message
- [ ] Invalid dates → Validation message
- [ ] Duplicate entries → Warning message

---

## Documentation Verification

- [ ] `PERSONAL_SCHEDULER_COMPLETE.md` accurate
- [ ] `QUICK_START_PERSONAL.md` accurate
- [ ] `IMPLEMENTATION_SUMMARY.md` accurate
- [ ] API endpoints documented
- [ ] Database schema documented
- [ ] Installation script works

---

## Final Checks

### Code Quality
- [ ] No PHP errors in logs
- [ ] No JavaScript console errors
- [ ] No MySQL warnings
- [ ] Code follows project conventions

### Data Integrity
- [ ] Foreign keys working
- [ ] Cascading deletes working
- [ ] No orphaned records
- [ ] Data types correct

### User Experience
- [ ] All buttons work
- [ ] All links work
- [ ] Forms validate properly
- [ ] Error messages are clear
- [ ] Success messages appear
- [ ] Loading states show

---

## Sign-Off

- [ ] All features tested and working
- [ ] All bugs fixed
- [ ] Documentation complete
- [ ] Ready for production

**Tested by:** ___________________  
**Date:** ___________________  
**Status:** ☐ Pass ☐ Fail  
**Notes:** ___________________  

---

## Quick Test Commands

```bash
# 1. Verify database
mysql -u root -p -e "USE vvu_scheduler; SELECT COUNT(*) FROM user_priorities; SELECT COUNT(*) FROM user_goals;"

# 2. Test APIs
curl http://localhost/scheduler/web/api/personal_priorities.php?action=list_priorities
curl http://localhost/scheduler/web/api/smart_suggestions.php?action=get_suggestions
curl http://localhost/scheduler/web/api/productivity_tracking.php?action=get_statistics&period=week
curl http://localhost/scheduler/web/api/notifications.php?action=list

# 3. Check page access
open http://localhost/scheduler/web/my_schedule.php
open http://localhost/scheduler/web/priorities_goals.php
open http://localhost/scheduler/web/productivity_analytics.php
open http://localhost/scheduler/web/notifications.php
```

---

**TESTING STATUS:** ☐ Not Started ☐ In Progress ☐ Complete

**PRODUCTION READY:** ☐ Yes ☐ No
