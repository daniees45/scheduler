INSERT INTO audit_log (user_id, action, resource, resource_id, status, details, ip_address, user_agent) VALUES
(1, 'LOGIN', 'user', 1, 'success', '{"method": "password", "success": true}', '127.0.0.1', 'Mozilla/5.0'),
(1, 'GENERATE_SCHEDULE', 'schedule', 1, 'success', '{"courses": 25, "duration_seconds": 45, "conflicts": 2}', '127.0.0.1', 'Mozilla/5.0'),
(1, 'VIEW_ANALYTICS', 'analytics', NULL, 'success', '{"dashboard": "ai_analytics"}', '127.0.0.1', 'Mozilla/5.0'),
(1, 'EXPORT_DATA', 'export', NULL, 'success', '{"format": "csv", "rows": 150}', '127.0.0.1', 'Mozilla/5.0'),
(1, 'UPDATE_COURSE', 'course', 5, 'success', '{"field": "lecturer", "old_value": "Dr. Smith", "new_value": "Dr. Johnson"}', '127.0.0.1', 'Mozilla/5.0'),
(1, 'DELETE_SCHEDULE', 'schedule', 3, 'success', '{"reason": "outdated"}', '127.0.0.1', 'Mozilla/5.0'),
(1, 'CREATE_USER', 'user', 15, 'success', '{"username": "newuser", "role": "lecturer"}', '127.0.0.1', 'Mozilla/5.0'),
(1, 'CONFLICT_DETECTED', 'conflict', NULL, 'warning', '{"type": "room_double_booking", "severity": "high"}', '127.0.0.1', 'Mozilla/5.0'),
(1, 'RESOLVE_CONFLICT', 'conflict', 42, 'success', '{"action": "reassign_room", "status": "resolved"}', '127.0.0.1', 'Mozilla/5.0'),
(1, 'VIEW_AUDIT_TRAIL', 'audit', NULL, 'success', '{"filters": {"date_range": "7d"}}', '127.0.0.1', 'Mozilla/5.0');
