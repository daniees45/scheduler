<?php
$page_title = 'Notifications & Reminders';
$page_css = 'assets/notifications.css';
include 'includes/header.php';
requireRole(['student', 'lecturer']);
$user_id = $_SESSION['user_id'];
?>

<div class="container notifications-container">
    <div class="notifications-header">
        <h1><i class="fas fa-bell"></i> Notifications & Reminders</h1>
        <div class="notifications-header-btns">
            <button class="glass-btn" onclick="markAllRead()">
                <i class="fas fa-check-double"></i> Mark All Read
            </button>
            <button class="glass-btn" onclick="window.location.href='reminder_settings.php'">
                <i class="fas fa-cog"></i> Settings
            </button>
        </div>
    </div>
    
    <div class="glass-panel notifications-panel">
        <div id="notificationsList">
            <div class="notifications-loading">
                Loading notifications...
            </div>
        </div>
    </div>
</div>

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
                        <div class="notification-body">
                            <div class="notification-content">
                                <div class="notification-title-row">
                                    <h3 class="notification-title">${n.title}</h3>
                                    ${isUnread ? '<span class="type-pill notification-pill--new">NEW</span>' : ''}
                                    ${isPriority ? '<span class="type-pill notification-pill--urgent"><i class="fas fa-exclamation"></i> URGENT</span>' : ''}
                                </div>
                                <p class="notification-message">
                                    ${n.message}
                                </p>
                                <div class="notification-time">
                                    <i class="fas fa-clock"></i> ${time}
                                </div>
                            </div>
                            <div class="notification-item-actions">
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
                <div class="notifications-empty">
                    <i class="fas fa-bell-slash notifications-empty-icon"></i>
                    <p>No notifications yet</p>
                    <button class="glass-btn" onclick="generateReminders()">Generate Reminders</button>
                </div>
            `;
        }
    } catch (error) {
        document.getElementById('notificationsList').innerHTML = 
            '<div class="notifications-error">Failed to load notifications</div>';
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
        await showAlert('Failed to mark as read', 'Error');
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
            await showAlert(`Marked ${data.count} notifications as read`, 'Success');
        }
    } catch (error) {
        await showAlert('Failed to mark all as read', 'Error');
    }
}

async function deleteNotification(id) {
    if (!await showConfirm('Delete this notification?', 'Delete Notification')) return;
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
        await showAlert('Failed to delete notification', 'Error');
    }
}

async function generateReminders() {
    try {
        const response = await fetch('api/notifications.php?action=generate_reminders');
        const data = await response.json();
        if (data.success) {
            await showAlert(`Generated ${data.count} reminders`, 'Success');
            loadNotifications();
        }
    } catch (error) {
        await showAlert('Failed to generate reminders', 'Error');
    }
}

document.addEventListener('DOMContentLoaded', loadNotifications);
</script>

<?php include 'includes/footer.php'; ?>
