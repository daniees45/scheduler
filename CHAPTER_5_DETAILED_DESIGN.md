# Chapter 5 - Detailed Design of the Proposed System

## 5.0 Functional Processes of the Proposed System

1. Authentication and role management
   - Users authenticate through the web UI.
   - Role (super_admin, faculty_admin, lecturer, student) drives access to features and data scopes.

2. Course and resource setup (admin)
   - Admins maintain courses, lecturers, rooms, and special room mappings.
   - Lecturer availability and constraints are recorded for scheduling.

3. Timetable generation (admin)
   - Admin triggers generation.
   - Python AI engine runs CSP-based scheduling with learned historical weights.
   - Result is persisted as JSON into `generated_schedules`.

4. Schedule consumption (students and lecturers)
   - Students view weekly schedule by enrolled courses.
   - Lecturers view weekly schedule by their assigned sections.

5. Enrollment and progress tracking (students)
   - Students enroll/unenroll in courses.
   - Completed courses are tracked to refine suggestions and progress views.

6. Personal event management
   - Users add personal events linked to a priority or goal.
   - Conflicts are checked against existing personal events.

7. AI smart suggestions
   - Busy blocks are derived from courses and personal events.
   - Free slots are scored by priority, productivity patterns, and learned preferences.
   - Suggestions are stored in `schedule_suggestions` and displayed for accept/reject feedback.

8. Notifications and reminders
   - Reminders are generated for courses, exams (placeholder), and personal events.
   - Notifications are stored and surfaced in the UI with unread status.

9. Calendar export
   - Scheduled classes and personal events are combined.
   - A Google Calendar event link is produced for the current week.

10. Analytics
   - Productivity logs are aggregated into metrics.
   - Heatmaps and statistics are shown on the analytics page.

---

## 5.1 Algorithm and Flowchart of the Processes

### 5.1.1 Core Timetable Generation (AI Scheduler)

Algorithm overview (CSP + learning):

1. Load courses, lecturers, rooms, availability, and constraints.
2. Build the constraint model (room, lecturer, level, special rooms).
3. Use MRV heuristic and backtracking to assign slots.
4. Rank candidate slots using historical scores and soft preferences.
5. If CSP times out or fails, invoke genetic algorithm fallback.
6. Compute schedule accuracy and persist results.

Pseudocode:

```
Input: courses, lecturers, rooms, constraints, historical_model
Output: feasible schedule

build_domains(courses, rooms, time_slots)
apply_hard_constraints(domains)
order_variables_by_MRV(courses)

function backtrack(assignments):
    if all courses assigned:
        return assignments
    course = select_unassigned_course()
    for slot in order_by_historical_score(course, domains[course]):
        if consistent(course, slot, assignments):
            assign(course, slot)
            if backtrack(assignments):
                return assignments
            unassign(course)
    return failure

schedule = backtrack({})
if failure:
    schedule = genetic_algorithm_fallback()
score = compute_accuracy(schedule)
persist(schedule, score)
return schedule
```

Flowchart:

```mermaid
flowchart TD
    A[Load input data] --> B[Build domains and constraints]
    B --> C[Select course by MRV]
    C --> D{Valid slot exists?}
    D -->|Yes| E[Assign slot and recurse]
    E --> F{All assigned?}
    F -->|Yes| G[Compute accuracy]
    G --> H[Persist schedule]
    F -->|No| C
    D -->|No| I[Backtrack]
    I --> J{Backtrack success?}
    J -->|Yes| C
    J -->|No| K[Run GA fallback]
    K --> G
```

### 5.1.2 Smart Suggestions (Personal Scheduler)

Algorithm overview:

1. Gather busy blocks from enrolled courses and personal events.
2. Compute free slots for each day.
3. Score slots using priorities, productivity history, and learned preferences.
4. Persist top-N suggestions.

Pseudocode:

```
Input: busy_blocks, priorities, productivity_patterns, task_preferences
Output: ranked suggestions

free_slots = find_free_slots(busy_blocks)
for slot in free_slots:
    slot.score = score(slot, priorities, productivity_patterns, task_preferences)
return top_n(sort_by_score(free_slots))
```

Flowchart:

```mermaid
flowchart TD
    A1[Load busy blocks] --> B1[Find free slots]
    B1 --> C1[Load priorities and patterns]
    C1 --> D1[Score each slot]
    D1 --> E1[Rank and store top 15]
    E1 --> F1[Return suggestions]
```

---

## 5.2 Data Flow Diagrams

### Level 0 (Context Diagram)

```mermaid
flowchart LR
    Student[Student] -->|Login, Enroll, Events, Feedback| System[VVU Scheduler]
    Lecturer[Lecturer] -->|Login, View schedule| System
    Admin[Admin] -->|Manage data, Generate schedule| System
    System -->|Weekly schedule, Suggestions, Notifications| Student
    System -->|Weekly schedule| Lecturer
    System -->|Reports, Logs| Admin
    AI[AI Engine] <--> |Schedule generation| System
    Calendar[Google Calendar] <..>|Export link| System
```

### Level 1 (Decomposition)

```mermaid
flowchart LR
    Student --> P1[Auth and Profile]
    Lecturer --> P1
    Admin --> P1

    P2[Course and Resource Mgmt] --> D1[(Courses)]
    P2 --> D2[(Lecturers)]
    P2 --> D3[(Rooms)]
    P2 --> D4[(Special Rooms)]

    P3[Schedule Generation] --> D5[(Generated Schedules)]
    P3 <--> AI[AI Engine]

    P4[Enrollment and Progress] --> D6[(Student Enrollments)]
    P4 --> D7[(Completed Courses)]

    P5[Personal Events] --> D8[(Personal Events)]
    P5 --> D9[(Priorities/Goals)]

    P6[Smart Suggestions] --> D10[(Schedule Suggestions)]
    P6 --> D11[(Task Preferences)]
    P6 --> D12[(Productivity Log/Metrics)]

    P7[Notifications] --> D13[(Notifications)]
    P7 --> D14[(Reminder Settings)]

    P8[Calendar Export] --> Calendar[Google Calendar]
```

---

## 5.3 Data Dictionary

### Database Schema Summary

Core academic scheduling tables:
- users, lecturers, courses, rooms, sections, schedules, generated_schedules, student_enrollments, special_rooms

Personal scheduler tables:
- personal_events, user_priorities, user_goals, schedule_suggestions, task_preferences, productivity_log, productivity_metrics, notifications, reminder_settings

Operational/support tables (created on demand):
- webhooks, webhook_logs, error_log, audit_log, api_rate_limit, rate_limit_violations, schedule_backup, csv_storage

### Tables (Key Fields and Descriptions)

#### users
- id (PK, INT)
- username (VARCHAR)
- password_hash (VARCHAR)
- role (ENUM: super_admin, faculty_admin, lecturer, student)
- full_name, email (VARCHAR)
- department (VARCHAR, nullable)
- level (INT, nullable)
- lecturer_id (FK -> lecturers.id, nullable)
- created_at (TIMESTAMP)

#### lecturers
- id (PK, INT)
- name (VARCHAR)
- email (VARCHAR)
- availability_json (JSON)
- department (VARCHAR, nullable)
- created_at (TIMESTAMP)

#### courses
- id (PK, INT)
- course_code (VARCHAR, unique)
- course_title (VARCHAR)
- credit_hours (INT)
- level (INT)
- semester (ENUM: 1,2)
- type (ENUM: Departmental, General)
- program (VARCHAR)
- lecturer_id (FK -> lecturers.id, nullable)
- created_at (TIMESTAMP)

#### rooms
- id (PK, INT)
- room_name (VARCHAR, unique)
- capacity (INT)
- type (VARCHAR)
- is_lab (BOOLEAN)
- created_at (TIMESTAMP)

#### sections
- id (PK, INT)
- course_id (FK -> courses.id)
- lecturer_id (FK -> lecturers.id, nullable)
- room_id (FK -> rooms.id, nullable)
- assigned_day (VARCHAR)
- assigned_time (VARCHAR)

#### schedules
- id (PK, INT)
- generated_by (FK -> users.id)
- status (ENUM: pending, completed, failed)
- log_file, output_file (VARCHAR)
- created_at (TIMESTAMP)

#### generated_schedules
- id (PK, INT)
- schedule_name (VARCHAR)
- semester (VARCHAR)
- department (VARCHAR)
- accuracy (VARCHAR)
- schedule_data (LONGTEXT JSON)
- generated_by (FK -> users.id)
- created_at (TIMESTAMP)

#### student_enrollments
- id (PK, INT)
- user_id (FK -> users.id)
- course_id (FK -> courses.id)
- semester (ENUM: 1,2)
- academic_year (VARCHAR)
- section (VARCHAR, optional)
- created_at (TIMESTAMP)

#### special_rooms
- id (PK, INT)
- course_code (VARCHAR, unique)
- room_name (VARCHAR)
- fixed_day (VARCHAR, nullable)
- fixed_time (VARCHAR, nullable)
- created_at, updated_at (TIMESTAMP)

#### personal_events
- id (PK, INT)
- user_id (FK -> users.id)
- title (VARCHAR)
- description (TEXT)
- day (VARCHAR)
- start_time, end_time (TIME)
- event_type (VARCHAR)
- color (VARCHAR)
- priority_id (FK -> user_priorities.id, nullable)
- goal_id (FK -> user_goals.id, nullable)
- created_at, updated_at (TIMESTAMP)

#### user_priorities
- id (PK, INT)
- user_id (FK -> users.id)
- priority_name (VARCHAR)
- priority_level (ENUM: high, medium, low)
- category (VARCHAR)
- target_hours_per_week (DECIMAL)
- is_active (BOOLEAN)
- created_at, updated_at (TIMESTAMP)

#### user_goals
- id (PK, INT)
- user_id (FK -> users.id)
- goal_title (VARCHAR)
- category (VARCHAR)
- target_completion_date (DATE)
- status (ENUM: active, completed, paused, cancelled)
- priority_level (ENUM: high, medium, low)
- progress_percentage (INT)
- created_at, updated_at (TIMESTAMP)

#### productivity_log
- id (PK, INT)
- user_id (FK -> users.id)
- task_name (VARCHAR)
- task_category (VARCHAR)
- day (VARCHAR)
- start_time, end_time (TIME)
- duration_minutes (INT)
- quality_rating (INT)
- completion_status (ENUM: completed, partial, skipped)
- productivity_score (DECIMAL)
- notes (TEXT)
- logged_at (TIMESTAMP)

#### productivity_metrics
- id (PK, INT)
- user_id (FK -> users.id)
- metric_date (DATE)
- total_productive_hours (DECIMAL)
- task_completion_rate (DECIMAL)
- average_quality_rating (DECIMAL)
- most_productive_day (VARCHAR)
- most_productive_hour (INT)
- category_breakdown (JSON)
- computed_at (TIMESTAMP)

#### task_preferences
- id (PK, INT)
- user_id (FK -> users.id)
- task_category (VARCHAR)
- preferred_day (VARCHAR)
- preferred_time_start, preferred_time_end (TIME)
- preference_score (DECIMAL)
- times_accepted, times_rejected (INT)
- last_updated (TIMESTAMP)

#### schedule_suggestions
- id (PK, INT)
- user_id (FK -> users.id)
- suggestion_type (VARCHAR)
- day (VARCHAR)
- start_time, end_time (TIME)
- duration_minutes (INT)
- priority_score (DECIMAL)
- productivity_score (DECIMAL)
- reason (TEXT)
- status (ENUM: pending, accepted, rejected, expired)
- suggested_at, responded_at (TIMESTAMP)

#### notifications
- id (PK, INT)
- user_id (FK -> users.id)
- notification_type (VARCHAR)
- title (VARCHAR)
- message (TEXT)
- related_event_id (INT, nullable)
- related_course_id (INT, nullable)
- scheduled_time (DATETIME)
- is_sent, is_read (BOOLEAN)
- sent_at, read_at (DATETIME)
- priority (ENUM: high, medium, low)
- delivery_method (VARCHAR)
- created_at (TIMESTAMP)

#### reminder_settings
- id (PK, INT)
- user_id (FK -> users.id)
- reminder_type (VARCHAR)
- enabled (BOOLEAN)
- minutes_before (INT)
- delivery_method (VARCHAR)
- created_at, updated_at (TIMESTAMP)

---

## Table Relationship Diagram

```mermaid
flowchart LR
    users -->|1..n| student_enrollments
    courses -->|1..n| student_enrollments
    lecturers -->|1..n| courses
    courses -->|1..n| sections
    lecturers -->|1..n| sections
    rooms -->|1..n| sections
    users -->|1..n| generated_schedules
    users -->|1..n| personal_events
    users -->|1..n| user_priorities
    users -->|1..n| user_goals
    users -->|1..n| productivity_log
    users -->|1..n| task_preferences
    users -->|1..n| schedule_suggestions
    users -->|1..n| notifications
    users -->|1..n| reminder_settings
    user_priorities -->|0..n| personal_events
    user_goals -->|0..n| personal_events
```

---

## Entity Relationship Diagram (ERD)

```mermaid
erDiagram
    USERS ||--o{ STUDENT_ENROLLMENTS : enrolls
    COURSES ||--o{ STUDENT_ENROLLMENTS : contains
    LECTURERS ||--o{ COURSES : teaches
    COURSES ||--o{ SECTIONS : has
    LECTURERS ||--o{ SECTIONS : assigned
    ROOMS ||--o{ SECTIONS : hosts
    USERS ||--o{ GENERATED_SCHEDULES : creates
    USERS ||--o{ PERSONAL_EVENTS : owns
    USERS ||--o{ USER_PRIORITIES : defines
    USERS ||--o{ USER_GOALS : sets
    USERS ||--o{ PRODUCTIVITY_LOG : logs
    USERS ||--o{ TASK_PREFERENCES : learns
    USERS ||--o{ SCHEDULE_SUGGESTIONS : receives
    USERS ||--o{ NOTIFICATIONS : receives
    USERS ||--o{ REMINDER_SETTINGS : configures
    USER_PRIORITIES ||--o{ PERSONAL_EVENTS : tags
    USER_GOALS ||--o{ PERSONAL_EVENTS : aligns

    USERS {
      int id PK
      string username
      string password_hash
      string role
    }
    COURSES {
      int id PK
      string course_code
      string course_title
      int level
      string semester
    }
    LECTURERS {
      int id PK
      string name
      json availability_json
    }
    ROOMS {
      int id PK
      string room_name
      int capacity
    }
    SECTIONS {
      int id PK
      int course_id FK
      int lecturer_id FK
      int room_id FK
      string assigned_day
      string assigned_time
    }
    STUDENT_ENROLLMENTS {
      int id PK
      int user_id FK
      int course_id FK
      string semester
    }
    GENERATED_SCHEDULES {
      int id PK
      string schedule_name
      longtext schedule_data
      int generated_by FK
    }
    PERSONAL_EVENTS {
      int id PK
      int user_id FK
      string title
      string day
      time start_time
      time end_time
    }
    USER_PRIORITIES {
      int id PK
      int user_id FK
      string priority_name
      string priority_level
    }
    USER_GOALS {
      int id PK
      int user_id FK
      string goal_title
      string status
    }
    SCHEDULE_SUGGESTIONS {
      int id PK
      int user_id FK
      string suggestion_type
      string status
    }
    NOTIFICATIONS {
      int id PK
      int user_id FK
      string notification_type
      string priority
    }
    REMINDER_SETTINGS {
      int id PK
      int user_id FK
      string reminder_type
      bool enabled
    }
```

---

## Use Cases or User Scenarios

1. Admin generates a departmental timetable
   - Admin uploads input data and triggers schedule generation.
   - AI engine builds a conflict-free timetable and stores it.

2. Lecturer views weekly teaching schedule
   - Lecturer logs in and sees classes filtered by lecturer name.

3. Student enrolls in courses
   - Student selects courses and the system checks for conflicts.

4. Student adds a personal event
   - Student adds a study session linked to a priority or goal.
   - System blocks overlapping personal events.

5. Student receives AI suggestions
   - System analyzes busy blocks and proposes optimal study slots.
   - Student accepts or rejects to improve recommendations.

6. User receives reminders
   - Notifications are generated for classes and personal events.
   - User marks reminders as read or dismisses them.

7. User exports schedule to Google Calendar
   - System combines timetable and personal events.
   - Google Calendar link is generated for the week.

---

## UML Class Diagram

```mermaid
classDiagram
    class User {
        +int id
        +string username
        +string password_hash
        +string role
        +string department
        +int level
    }

    class Lecturer {
        +int id
        +string name
        +string email
        +json availability_json
        +string department
    }

    class Course {
        +int id
        +string course_code
        +string course_title
        +int level
        +string semester
        +string type
    }

    class Room {
        +int id
        +string room_name
        +int capacity
        +string type
        +bool is_lab
    }

    class Section {
        +int id
        +string assigned_day
        +string assigned_time
    }

    class GeneratedSchedule {
        +int id
        +string schedule_name
        +string semester
        +string department
        +string accuracy
        +string schedule_data
    }

    class PersonalEvent {
        +int id
        +string title
        +string day
        +time start_time
        +time end_time
        +string event_type
    }

    class UserPriority {
        +int id
        +string priority_name
        +string priority_level
        +string category
    }

    class UserGoal {
        +int id
        +string goal_title
        +string status
        +int progress_percentage
    }

    class ScheduleSuggestion {
        +int id
        +string suggestion_type
        +string status
        +float priority_score
        +float productivity_score
    }

    class Notification {
        +int id
        +string notification_type
        +string priority
        +bool is_read
    }

    class ReminderSetting {
        +int id
        +string reminder_type
        +int minutes_before
        +bool enabled
    }

    User "1" --> "0..*" GeneratedSchedule : creates
    User "1" --> "0..*" PersonalEvent : owns
    User "1" --> "0..*" UserPriority : defines
    User "1" --> "0..*" UserGoal : sets
    User "1" --> "0..*" ScheduleSuggestion : receives
    User "1" --> "0..*" Notification : receives
    User "1" --> "0..*" ReminderSetting : configures

    Lecturer "1" --> "0..*" Course : teaches
    Course "1" --> "0..*" Section : has
    Room "1" --> "0..*" Section : hosts
    Lecturer "1" --> "0..*" Section : assigned
```
