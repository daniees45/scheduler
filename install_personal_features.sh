#!/bin/bash

# Personal Scheduler Enhanced Features - Installation Script
# Run this script to set up all new features

echo "=========================================="
echo "Personal Scheduler Enhanced Features"
echo "Installation Script"
echo "=========================================="
echo ""

# Check if MySQL is running
if ! command -v mysql &> /dev/null; then
    echo "❌ MySQL is not installed or not in PATH"
    exit 1
fi

echo "✅ MySQL found"

# Database configuration
read -p "Enter MySQL username [root]: " DB_USER
DB_USER=${DB_USER:-root}

read -sp "Enter MySQL password: " DB_PASS
echo ""

read -p "Enter database name [vvu_scheduler]: " DB_NAME
DB_NAME=${DB_NAME:-vvu_scheduler}

echo ""
echo "Installing database schema..."

# Execute the SQL schema file
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "web/db/personal_scheduler_schema.sql"

if [ $? -eq 0 ]; then
    echo "✅ Database schema installed successfully"
else
    echo "❌ Failed to install database schema"
    exit 1
fi

echo ""
echo "Verifying tables..."

# Count new tables
TABLE_COUNT=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -se "
    SELECT COUNT(*) FROM information_schema.tables 
    WHERE table_schema = '$DB_NAME' 
    AND table_name IN (
        'user_priorities', 
        'user_goals', 
        'productivity_log', 
        'task_preferences', 
        'notifications', 
        'reminder_settings', 
        'schedule_suggestions', 
        'productivity_metrics'
    )
")

if [ "$TABLE_COUNT" -eq 8 ]; then
    echo "✅ All 8 tables created successfully"
else
    echo "⚠️  Expected 8 tables, found $TABLE_COUNT"
fi

echo ""
echo "=========================================="
echo "Installation Complete!"
echo "=========================================="
echo ""
echo "New Features Available:"
echo "  • Priorities & Goals Management"
echo "  • Smart Scheduling Suggestions"
echo "  • Productivity Tracking & Analytics"
echo "  • Notifications & Reminders"
echo ""
echo "New Pages:"
echo "  • web/priorities_goals.php"
echo "  • web/notifications.php"
echo "  • web/productivity_analytics.php"
echo ""
echo "New APIs:"
echo "  • web/api/personal_priorities.php"
echo "  • web/api/smart_suggestions.php"
echo "  • web/api/productivity_tracking.php"
echo "  • web/api/notifications.php"
echo ""
echo "Enhanced:"
echo "  • web/my_schedule.php (integrated all features)"
echo ""
echo "Documentation:"
echo "  • PERSONAL_SCHEDULER_COMPLETE.md"
echo ""
echo "Next Steps:"
echo "1. Visit web/my_schedule.php"
echo "2. Click 'Priorities & Goals' to set up"
echo "3. Click 'AI Suggestions' to get recommendations"
echo "4. View 'Analytics' to track productivity"
echo ""
echo "🎉 Happy Scheduling!"
