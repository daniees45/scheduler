# 🎓 RBAC Implementation - Quick Start Guide

## What Was Done

Implemented a complete **Role-Based Access Control (RBAC)** system with:
- ✅ Separate dashboards for Students, Lecturers, and Admins
- ✅ Department-based user registration
- ✅ Course enrollment system for students
- ✅ Auto-filtered schedules based on user role
- ✅ Protected admin pages (students/lecturers can't access)

---

## 🚀 How to Test

### Step 1: Run Database Migration
```bash
mysql -uroot -p vvu_scheduler < web/db/setup_rbac_safe.sql
```

### Step 2: Test the Implementation
Open in browser: `http://localhost/vvu-scheduler/web/test_rbac.php`

This will show:
- ✅ Database schema status
- ✅ File existence checks
- ✅ User statistics
- ✅ Quick access to all features

### Step 3: Create Test Accounts

#### A) Register as Student
1. Go to: `http://localhost/vvu-scheduler/web/login.php`
2. Click "Create Account"
3. Select Role: **Student**
4. Fill in:
   - Full Name: "John Doe"
   - Department: **Computing Science**
   - Level: **200**
   - Username: "johndoe"
   - Password: "student123"
5. Click Register → Login

**What You'll See:**
- Redirected to **Student Dashboard** (not admin dashboard!)
- Can see "Enroll in Courses" and "My Schedule" links
- Cannot see "AI Generator" or "Manage Users"
- Dashboard shows today's classes and enrolled courses

#### B) Register as Lecturer
1. Go to login page → Create Account
2. Select Role: **Lecturer**
3. Fill in:
   - Link Lecturer Profile: Select existing lecturer
   - Department: **Computing Science**
   - Username: "prof_smith"
   - Password: "lecturer123"
4. Register → Login

**What You'll See:**
- Redirected to **Lecturer Dashboard**
- Can see courses taught and student counts
- Auto-filtered schedule (only your classes)
- Cannot access admin pages

#### C) Test Admin Access
1. Login with existing admin account
2. Try to access: `courses.php`, `generate.php`, `users.php`
3. All should work (no restrictions)

---

## 🎯 Key Features to Test

### For Students:
1. **Dashboard** → Should see today's classes and enrollment stats
2. **My Courses** → Browse available courses for your level
3. **Enroll** → Click "Enroll in Course" on any course
4. **My Schedule** → See personalized timetable with ONLY enrolled courses
5. **View Schedule** → Auto-filtered to show only enrolled courses
6. **Try Admin Page** → Go to `generate.php` → Should be blocked!

### For Lecturers:
1. **Dashboard** → See assigned courses and teaching schedule
2. **View Schedule** → Auto-filtered to show only classes you teach
3. **Student Counts** → See enrollment numbers per course
4. **Try Admin Page** → Go to `courses.php` → Should be blocked!

### For Admins:
1. **Full Access** → All pages accessible
2. **View Schedule** → No auto-filtering (see full schedule)
3. **User Management** → Can see departments and levels of users

---

## 📊 What Changed

### New Pages Created:
- `web/student_dashboard.php` - Student home page
- `web/my_courses.php` - Course enrollment page
- `web/api/enrollment.php` - Enrollment API
- `web/test_rbac.php` - Implementation test page

### Modified Pages:
- `web/dashboard.php` - Routes to role-specific dashboards
- `web/login.php` - Added department and level fields
- `web/view_schedule.php` - Auto-filters by role
- `web/courses.php` - Protected with access control
- `web/rooms.php` - Protected with access control
- `web/lecturers.php` - Protected with access control
- `web/special_rooms.php` - Protected with access control
- `web/import_data.php` - Protected with access control
- `web/api/auth.php` - Stores department/level in session
- `web/api/register_public.php` - Validates dept/level

### Database Changes:
**users table:**
- Added: `department` (VARCHAR 100)
- Added: `level` (INT) - For students (100, 200, 300, 400)
- Added: `lecturer_id` (INT) - Link to lecturers table

**lecturers table:**
- Added: `department` (VARCHAR 100)

**New Table: student_enrollments**
- `id`, `user_id`, `course_id`, `semester`, `academic_year`, `created_at`
- Tracks which courses students are enrolled in

---

## 🐛 Troubleshooting

### Issue: "Access Denied" for all users
**Fix:** Run the database migration script

### Issue: Students can still access admin pages
**Fix:** Check that `requireAdmin()` is called at the top of admin pages

### Issue: Auto-filtering not working
**Fix:** Ensure user has department/level set in their profile

### Issue: "Column not found" errors
**Fix:** Database migration didn't complete - run setup_rbac_safe.sql again

---

## 🔐 Security Features

1. **Access Control Functions** (in `includes/access_control.php`):
   - `requireRole(['student'])` - Only students
   - `requireRole(['lecturer'])` - Only lecturers
   - `requireAdmin()` - Admins only
   - Auto-redirect if unauthorized

2. **Auto-Filtering**:
   - Students: Only see enrolled courses
   - Lecturers: Only see assigned classes
   - No cross-user data access

3. **Protected Pages**:
   - Admin pages blocked for students/lecturers
   - Students can't see other students' enrollments
   - Lecturers can't modify courses they don't teach

---

## 📈 Expected Results After Testing

✅ **Students:**
- See personalized dashboard
- Can enroll/unenroll from courses
- Only see their schedule, not full schedule
- Cannot access admin features

✅ **Lecturers:**
- See teaching dashboard
- Only see classes they teach
- See student enrollment counts
- Cannot access admin features

✅ **Admins:**
- Full access maintained
- Can manage all data
- No restrictions

---

## 🎉 Success Indicators

After implementation, you should observe:
1. Students registering with department/level
2. Students enrolling in courses via "My Courses"
3. Personalized schedules showing only relevant data
4. Admin pages blocked for non-admin users
5. Role-appropriate navigation menus
6. No unauthorized access to restricted features

---

## 📞 Need Help?

Check these files:
- `RBAC_IMPLEMENTATION_COMPLETE.md` - Full documentation
- `web/test_rbac.php` - System tests
- `web/db/setup_rbac_safe.sql` - Database setup

---

**Implementation Date:** February 14, 2026  
**Status:** ✅ Complete and Ready  
**Test URL:** http://localhost/vvu-scheduler/web/test_rbac.php
