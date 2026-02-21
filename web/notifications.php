<?php
$page_title = 'Notifications & Reminders';
include 'includes/header.php';
requireRole(['student', 'lecturer']);
$user_id = $_SESSION['user_id'];
?>

<div class="container" style="max-width: 900px; margin: 0 auto; padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h1><i class="fas fa-bell"></i> Notifications & Reminders</h1>
        <div style="display: flex; gap: 10px;">
            <button class="glass-btn" onclick="markAllRead()">
                <i class="fas fa-check-double"></i> Mark All Read
            </button>
            <button class="glass-btn" onclick="window.location.href='reminder_settings.php'">
                <i class="fas fa-cog"></i> Settings
            </button>
        </div>
    </div>
    
    <div class="glass-panel" style="padding: 1.5rem;">
        <div id="notificationsList">
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                Loading notifications...
            </div>
        </div>
    </div>
</div>

<style>
.notification-item {
    padding: 1rem;
    background: rgba(15, 23, 42, 0.55);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 0.75rem;
    margin-bottom: 0.8rem;
    transition: all 0.2s;
}

.notification-item:hover {
    background: rgba(15, 23, 42, 0.75);
}

.notification-unread {
    border-left: 4px solid #6366f1;
    background: rgba(99, 102, 241, 0.1);
}

.notification-high {
    border-left: 4px solid #ef4444;
}
</style>

<script>
async function loadNotifications() {
    try {
        const response = await fetch('api/notifications.php?action=list');
        const data = await response.json();
        
        if (data.success && data.notifications.length > 0) {
            let html = '';
            data.notifications.forEach(n => {
                const isUnread = !n.is_read;
                const isPriority = n.priority === 'high';
                const classes = ['notification-item'];
                if (isUnread) classes.push('notification-unread');
                if (isPriority) classes.push('notification-high');
                
                const time = new Date(n.scheduled_time).toLocaleString();
                
                html += `
                    <div class="${classes.join(' ')}">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 0.5rem;">
                                    <h3 style="margin: 0;">${n.title}</h3>
                                    ${isUnread ? '<span class="type-pill" style="background: rgba(99,102,241,0.3);">NEW</span>' : ''}
                                    ${isPriority ? '<span class="type-pill" style="background: rgba(239,68,68,0.3);"><i class="fas fa-exclamation"></i> URGENT</span>' : ''}
                                </div>
                                <p style="margin: 0.5rem 0; font-size: 14px; color: var(--text-muted);">
                                    ${n.message}
                                </p>
                                <div style="font-size: 12px; color: var(--text-muted);">
                                    <i class="fas fa-clock"></i> ${time}
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                ${isUnread ? `
                                    <button class="glass-btn small" onclick="markRead(${n.id})" title="Mark as read">
                                        <i class="fas fa-check"></i>
                                    </button>
                                ` : ''}
                                <button class="glass-btn small" onclick="deleteNotification(${n.id})" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            document.getElementById('notificationsList').innerHTML = html;
        } else {
            document.getElementById('notificationsList').innerHTML = `
                <div style="text-align: center; padding: 60px; color: var(--text-muted);">
                    <i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 20px; opacity: 0.5;"></i>
                    <p>No notifications yet</p>
                    <button class="glass-btn" onclick="generateReminders()">Generate Reminders</button>
                </div>
            `;
        }
    } catch (error) {
        document.getElementById('notificationsList').innerHTML = 
            '<div style="color: #ef4444; padding: 20px;">Failed to load notifications</div>';
    }
}

async function markRead(id) {
    try {
        const response = await fetch('api/notifications.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=mark_read&notification_id=${id}`
        });
        const data = await response.json();
        if (data.success) {
            loadNotifications();
        }
    } catch (error) {
        alert('Failed to mark as read');
    }
}

async function markAllRead() {
    try {
        const response = await fetch('api/notifications.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=mark_all_read'
        });
        const data = await response.json();
        if (data.success) {
            loadNotifications();
            alert(`Marked ${data.count} notifications as read`);
        }
    } catch (error) {
        alert('Failed to mark all as read');
    }
}

async function deleteNotification(id) {
    if (!confirm('Delete this notification?')) return;
    try {
        const response = await fetch('api/notifications.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=delete&notification_id=${id}`
        });
        const data = await response.json();
        if (data.success) {
            loadNotifications();
        }
    } catch (error) {
        alert('Failed to delete notification');
    }
}

async function generateReminders() {
    try {
        const response = await fetch('api/notifications.php?action=generate_reminders');
        const data = await response.json();
        if (data.success) {
            alert(`Generated ${data.count} reminders`);
            loadNotifications();
        }
    } catch (error) {
        alert('Failed to generate reminders');
    }
}

document.addEventListener('DOMContentLoaded', loadNotifications);
</script>

<?php include 'includes/footer.php'; ?>
