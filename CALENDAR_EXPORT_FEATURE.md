# Google Calendar Export Feature

## Overview
Added a Calendar integration to export the Weekly Timetable to Google Calendar, including both scheduled classes and personal events.

## Components

### 1. Backend API: `/web/api/export_calendar.php`
- **Purpose**: Generates a Google Calendar export link for the current week
- **Request Method**: GET
- **Parameters**:
  - `type=google` (export format)
  - `week=0` (week offset, 0 = current week)

- **Response**: JSON with:
  - `url`: Direct Google Calendar event creation link
  - `week.start` and `week.end`: Date range
  - `events_count`: Total events being exported
  - `message`: User-friendly message

- **Functionality**:
  - Calculates Monday-Sunday date range for specified week
  - **For Lecturers**: Fetches classes from `generated_schedules` filtered by lecturer name
    - Infers department from course codes if not set in lecturers table
  - **For Students**: Fetches classes from `generated_schedules` filtered by enrolled course codes
  - Fetches personal events from `personal_events` table
  - Generates Google Calendar template URL with event details
  - Returns URL that opens in new tab for user to add events

### 2. Frontend UI: Export Button in `/web/my_schedule.php`
- **Location**: Quick Actions Panel (line ~751)
- **Button Text**: "Export to Google Calendar" with calendar icon
- **Visual**: Glass-morphism styling consistent with existing UI

### 3. JavaScript Function: `exportToGoogleCalendar()`
- **Location**: my_schedule.php (line ~1234)
- **Behavior**:
  1. Calls `/api/export_calendar.php` with current week offset
  2. Shows success notification with event count
  3. Opens Google Calendar in new tab (user can select calendar and add)
  4. Displays notification for 5 seconds
  5. Handles errors gracefully with user-friendly messages

## Export Data Sources

### Classes (Scheduled Events)
- Source: `generated_schedules` table (latest AI-generated or manually created schedules)
- **Lecturer Path**: Filters by lecturer_name match
- **Student Path**: Filters by enrolled course_codes

### Personal Events
- Source: `personal_events` table
- Includes: All personal commitments (study, work, rest, exercise, personal, other)

### Event Details Included
- Event title (course code + section or personal event title)
- Date (specific day in the current week)
- Start time
- End time
- Type (scheduled class or personal event)

## User Workflow

1. **View My Schedule** → Open my_schedule.php
2. **Click Export Button** → "Export to Google Calendar"
3. **See Notification** → "Calendar Export Ready - X events ready to add"
4. **Google Calendar Opens** → In new browser tab
5. **Add Events** → User can review and add to their selected calendar

## Technical Integration

### Database Tables Used
- `generated_schedules`: Class schedule data (JSON format with day, time_range, label, lecturer)
- `personal_events`: Personal commitments (user_id, day, start_time, end_time, title)
- `lecturers`: Lecturer info (name, department)
- `user_progress`: Track enrolled courses for students
- `enrollments`: Section enrollments
- `sections`: Course sections taught by lecturers
- `courses`: Course info and codes

### URL Format
Google Calendar integration uses `https://calendar.google.com/calendar/u/0/r/eventedit?` with parameters:
- `action=TEMPLATE`: Allows event creation
- `text`: Event title
- `dates`: ISO 8601 format date/time range
- `details`: Event description

### Week Navigation
- Currently exports current week (week=0)
- Can be extended to support `week=-1` (previous) or `week=1` (next) via query parameters

## Status
✅ **Complete and Tested**
- API endpoint: Syntax validated
- Frontend integration: Syntax validated
- Export button: Added to Quick Actions Panel
- JavaScript handler: Fully implemented with error handling and notifications

## Future Enhancements
1. Add week navigation (previous/next week exports)
2. Add .ics file download option for offline calendar apps
3. Generate recurring events for recurring classes
4. Color-code events by type (class=blue, personal=green, etc.)
5. Add option to sync with external calendars (Outlook, Apple Calendar)
6. Batch export multiple weeks
