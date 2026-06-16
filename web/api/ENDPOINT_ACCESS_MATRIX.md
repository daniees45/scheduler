# API Endpoint Access Matrix

This matrix was generated from current source patterns in web/api.

Policy levels:
- public: no user/session guard detected
- authenticated: requires logged-in user/session
- admin: requires super_admin or faculty_admin
- super_admin: requires super_admin

Method gate:
- explicit values come from require_http_methods(...)
- custom-check means endpoint checks REQUEST_METHOD manually
- ANY means no method guard detected in-file

| Endpoint | Method Gate | Access Level | Mutation Likely |
|---|---|---|---|
| ai_feedback.php | custom-check | authenticated | yes |
| ai_proxy.php | custom-check | authenticated | no |
| async_schedule.php | ANY | admin | yes |
| auth_recovery.php | custom-check | public | yes |
| auth.php | custom-check | authenticated | yes |
| automated_etl.php | 'POST' | admin | yes |
| bulk_export.php | custom-check | public | yes |
| check_conflicts.php | ANY | public | no |
| cleanup_data.php | 'POST' | admin | yes |
| clear_session.php | ANY | public | no |
| compare_versions.php | custom-check | public | no |
| course_list.php | ANY | public | yes |
| create_test_users.php | ANY | public | yes |
| csv_to_pdf_b2.php | ANY | authenticated | yes |
| db_query.php | ANY | authenticated | no |
| db.php | ANY | public | yes |
| delete_b2_file.php | 'POST' | super_admin | yes |
| delete_csv.php | 'POST' | authenticated | yes |
| diag.php | ANY | public | no |
| download_b2_file.php | 'GET' | authenticated | no |
| download_historical_archive.php | ANY | public | no |
| download_pdf.php | ANY | public | no |
| enrollment.php | custom-check | authenticated | yes |
| error_handler.php | custom-check | authenticated | yes |
| export_calendar.php | ANY | authenticated | no |
| export_db_to_csv.php | ANY | public | yes |
| export_feedback.php | ANY | public | yes |
| export_pdf.php | ANY | public | yes |
| export_to_ics.php | custom-check | public | no |
| extract_pdf.php | 'POST' | admin | yes |
| feedback_db.php | custom-check | admin | yes |
| files_to_db.php | ANY | public | yes |
| generated_schedules.php | ANY | public | no |
| get_ai_analytics.php | ANY | authenticated | no |
| get_audit_logs.php | ANY | authenticated | no |
| get_conflicts.php | ANY | authenticated | no |
| get_constraint_metrics.php | ANY | authenticated | yes |
| get_latest_b2_schedule.php | ANY | public | no |
| get_lecturer_load.php | custom-check | public | no |
| get_my_unified_schedule.php | ANY | authenticated | no |
| get_recent_schedules.php | ANY | authenticated | yes |
| get_resource_predictions.php | ANY | authenticated | no |
| get_room_source.php | ANY | authenticated | no |
| get_room_utilization.php | custom-check | public | no |
| get_schedule_history.php | ANY | public | no |
| get_schedule_metrics.php | custom-check | public | no |
| get_session_csv.php | ANY | authenticated | yes |
| get_time_distribution.php | custom-check | public | no |
| import_before_gen.php | ANY | public | yes |
| import_csv_to_db.php | 'POST' | admin | yes |
| init_manual_input.php | ['GET', 'POST'] | authenticated | no |
| lecturer_personal_data.php | ANY | authenticated | yes |
| list_b2_schedules.php | 'GET' | authenticated | no |
| log.php | ANY | public | yes |
| manage_templates.php | custom-check | authenticated | yes |
| migrate_csv_storage.php | ANY | public | yes |
| notifications.php | ANY | authenticated | yes |
| personal_priorities.php | ANY | authenticated | yes |
| pre_flight_check.php | custom-check | public | yes |
| productivity_tracking.php | ANY | authenticated | yes |
| py_diag.php | ANY | public | no |
| rate_limiter.php | custom-check | public | yes |
| recommendations.php | ANY | authenticated | yes |
| register_public.php | custom-check | public | yes |
| relax_conflict.php | ANY | public | no |
| resolve_conflict.php | ANY | authenticated | yes |
| rest.php | custom-check | public | yes |
| rollback_schedule.php | custom-check | admin | yes |
| run_analyzer.php | 'POST' | admin | no |
| save_csv.php | 'POST' | admin | yes |
| save_data_edits.php | 'POST' | admin | yes |
| save_generated_schedule.php | 'POST' | authenticated | yes |
| save_settings.php | ANY | admin | yes |
| save_to_session.php | 'POST' | authenticated | no |
| scan_conflicts.php | ANY | admin | yes |
| schedule_versions.php | ANY | authenticated | yes |
| schema_check.php | ANY | public | no |
| send_notification.php | ANY | public | yes |
| smart_suggestions.php | ANY | authenticated | yes |
| student_personal_data.php | ANY | authenticated | yes |
| sync.php | ANY | public | yes |
| update_db.php | ['GET', 'POST'] | admin | yes |
| update_department_courses.php | 'POST' | admin | yes |
| update_schedule_row.php | ANY | public | yes |
| upload_csv.php | 'POST' | authenticated | yes |
| upload_general_schedule.php | 'POST' | admin | yes |
| upload_generated_to_b2.php | ANY | public | yes |
| validate_csv.php | custom-check | public | yes |
| validate_data.php | ANY | public | no |
| webhook_dispatcher.php | custom-check | public | yes |
| websocket_progress.php | custom-check | authenticated | yes |

## Immediate Follow-up Candidates

These endpoints are marked as public plus mutation-likely and should be reviewed first for guard upgrades:
- auth_recovery.php
- bulk_export.php
- create_test_users.php
- export_db_to_csv.php
- export_feedback.php
- export_pdf.php
- files_to_db.php
- import_before_gen.php
- log.php
- migrate_csv_storage.php
- pre_flight_check.php
- rate_limiter.php
- register_public.php
- rest.php
- send_notification.php
- sync.php
- update_schedule_row.php
- upload_generated_to_b2.php
- validate_csv.php
- webhook_dispatcher.php
