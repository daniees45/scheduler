# 🚀 Quick Start Guide - Personal Scheduler Enhanced Features

## Installation (2 minutes)

### Option 1: Automatic Installation (Recommended)
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/scheduler
chmod +x install_personal_features.sh
./install_personal_features.sh
```

### Option 2: Manual Installation
```bash
mysql -u root -p vvu_scheduler < web/db/personal_scheduler_schema.sql
```

---

## 🎯 Getting Started (5 minutes)

### Step 1: Set Your Priorities
1. Log in as a student
2. Go to **My Schedule**
3. Click **"Priorities & Goals"** button
4. Add your first priority:
   - Name: "Master Data Structures"
   - Level: High
   - Category: Study
   - Target: 10 hours/week

### Step 2: Set a Goal
1. In Priorities & Goals page
2. Click **"Add Goal"**
3. Create a goal:
   - Title: "Achieve 3.5 GPA this semester"
   - Category: Academic
   - Priority: High
   - Target Date: End of semester

### Step 3: Get AI Suggestions
1. Return to **My Schedule**
2. Click **"AI Suggestions"** button
3. Review recommended time slots
4. Click **"Accept"** on slots that work for you
5. Click **"Reject"** on others (improves learning)

### Step 4: Track Your Productivity
1. Click **"Analytics"** button
2. Log your first task manually via API:
```bash
curl -X POST http://localhost/scheduler/web/api/productivity_tracking.php \
  -H "Content-Type: application/json" \
  -d '{
    "action": "log_task",
    "task_name": "Data Structures Study",
    "task_category": "study",
    "day": "Monday",
    "start_time": "09:00",
    "end_time": "11:00",
    "quality_rating": 4,
    "completion_status": "completed"
  }'
```

### Step 5: Enable Reminders
1. Click **"Reminders"** button (or go to notifications page)
2. Configure reminder settings:
   - Course Reminder: Enabled, 30 min before
   - Exam Reminder: Enabled, 1 day before
   - Personal Event: Enabled, 15 min before
3. Click **"Generate Reminders"**

---

## 📊 Feature Overview

### 1. Smart Suggestions
**What it does:** AI analyzes your schedule and recommends optimal times for tasks

**How to use:**
- Click "AI Suggestions" on My Schedule
- Review top 15 recommended slots
- Accept/Reject to train the AI

**Behind the scenes:**
- Analyzes busy blocks from classes and personal events
- Uses your priorities to weight suggestions
- Learns from your accept/reject patterns
- Considers time of day preferences

### 2. Priorities & Goals
**What it does:** Define what matters most to optimize your schedule

**How to use:**
- Add priorities (e.g., "Study", "Health", "Work")
- Set priority levels (High/Medium/Low)
- Define target hours per week
- Create SMART goals with deadlines
- Track goal progress (0-100%)

**Impact:**
- AI prioritizes slots for high-priority categories
- Goal progress visible on dashboard
- Time allocation aligned with your values

### 3. Productivity Analytics
**What it does:** Visual insights into your productive patterns

**Metrics tracked:**
- Total productive hours
- Task completion rate
- Average quality rating
- Most productive day/hour
- Category breakdown

**How to use:**
- Log tasks after completion
- View heatmap to identify patterns
- Adjust schedule based on insights
- Switch between day/week/month views

### 4. Notifications & Reminders
**What it does:** Never miss a commitment

**Types:**
- Course reminders (before class starts)
- Exam reminders (days before)
- Personal event reminders
- Custom notifications

**How to use:**
- Configure reminder settings
- Set lead time per type
- View all notifications in one place
- Mark as read or dismiss

---

## 🔧 API Examples

### Add a Priority
```bash
curl -X POST http://localhost/scheduler/web/api/personal_priorities.php \
  -H "Content-Type: application/json" \
  -d '{
    "action": "add_priority",
    "priority_name": "Health & Fitness",
    "priority_level": "high",
    "category": "health",
    "target_hours_per_week": 5,
    "description": "Exercise and meal prep time"
  }'
```

### Get Smart Suggestions
```bash
curl http://localhost/scheduler/web/api/smart_suggestions.php?action=get_suggestions
```

### Log Productivity
```bash
curl -X POST http://localhost/scheduler/web/api/productivity_tracking.php \
  -H "Content-Type: application/json" \
  -d '{
    "action": "log_task",
    "task_name": "Algorithm Practice",
    "task_category": "study",
    "day": "Tuesday",
    "start_time": "14:00",
    "end_time": "16:00",
    "quality_rating": 5,
    "completion_status": "completed",
    "notes": "Solved 5 LeetCode problems"
  }'
```

### Get Analytics
```bash
curl http://localhost/scheduler/web/api/productivity_tracking.php?action=get_statistics&period=week
```

---

## 🎨 UI Navigation

### Main Dashboard (my_schedule.php)
```
[My Schedule]
  ├─ Quick Actions Panel
  │   ├─ Analytics
  │   ├─ AI Suggestions
  │   ├─ Priorities & Goals
  │   ├─ Notifications (with badge)
  │   └─ Reminders
  ├─ Smart Suggestions Panel (collapsible)
  ├─ Add Personal Event Form
  └─ Weekly Schedule Table
```

### Analytics Dashboard (productivity_analytics.php)
```
[Productivity Analytics]
  ├─ Period Selector (Day/Week/Month)
  ├─ Stats Cards
  │   ├─ Total Tasks
  │   ├─ Productive Hours
  │   ├─ Completion Rate
  │   └─ Average Quality
  ├─ Productivity Heatmap (7×24 grid)
  ├─ Category Breakdown
  └─ Peak Performance Time
```

### Priorities & Goals (priorities_goals.php)
```
[Priorities & Goals]
  ├─ My Priorities
  │   ├─ Add Priority Button
  │   └─ Priority Cards (with delete)
  └─ My Goals
      ├─ Add Goal Button
      └─ Goal Cards (with progress slider)
```

### Notifications (notifications.php)
```
[Notifications & Reminders]
  ├─ Mark All Read Button
  ├─ Settings Button
  └─ Notification List
      └─ Each with: Title, Message, Time, Actions
```

---

## 💡 Tips & Best Practices

### For Better AI Suggestions
1. **Accept/Reject consistently** - The AI learns from your choices
2. **Set realistic priorities** - Don't mark everything as high priority
3. **Log tasks regularly** - More data = better suggestions
4. **Update goals** - Keep progress current

### For Accurate Analytics
1. **Log tasks immediately** after completion
2. **Be honest** with quality ratings (1-5 scale)
3. **Use consistent categories** (study, work, personal, health, etc.)
4. **Track duration accurately**

### For Effective Reminders
1. **Set appropriate lead times**
   - Classes: 15-30 minutes
   - Exams: 24 hours
   - Personal events: 15 minutes
2. **Generate reminders weekly** to stay updated
3. **Mark read** after acting on notifications

---

## 🐛 Troubleshooting

### Suggestions not appearing?
- Click "Generate Suggestions" button
- Ensure you have enrolled courses or personal events
- Check that priorities are set

### Analytics showing no data?
- Log some tasks first
- Wait 24 hours for metrics to compute
- Check that tasks are marked as "completed"

### Reminders not generating?
- Verify reminder settings are enabled
- Ensure you have upcoming classes/events
- Click "Generate Reminders" manually

### Database errors?
- Run the installation script again
- Check MySQL connection settings
- Verify all 8 tables exist

---

## 📚 Additional Resources

- **Full Documentation:** `PERSONAL_SCHEDULER_COMPLETE.md`
- **Database Schema:** `web/db/personal_scheduler_schema.sql`
- **API Documentation:** Each API file has inline comments

---

## ✅ Quick Test

Run this test to verify everything works:

```bash
# 1. Check database
mysql -u root -p -e "USE vvu_scheduler; SHOW TABLES LIKE '%priority%' OR LIKE '%productivity%' OR LIKE '%notification%';"

# 2. Test API endpoints
curl http://localhost/scheduler/web/api/personal_priorities.php?action=list_priorities
curl http://localhost/scheduler/web/api/smart_suggestions.php?action=get_suggestions
curl http://localhost/scheduler/web/api/notifications.php?action=list

# 3. Open in browser
open http://localhost/scheduler/web/my_schedule.php
```

Expected Results:
- Database shows 8 new tables
- APIs return JSON responses
- My Schedule page loads with new buttons

---

## 🎉 You're Ready!

Start using your enhanced personal scheduler:
1. Set priorities and goals
2. Get AI recommendations
3. Track your productivity
4. Never miss a commitment

**Enjoy your optimized schedule!** 🚀
