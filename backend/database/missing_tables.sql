-- ============================================================
-- MISSING TABLES — Generated from 3NF Normalization Analysis
-- Tables that already exist are SKIPPED (not created here).
-- ============================================================
-- Existing tables already present in the project:
--   users, departments, visitors, visitor_categories,
--   visit_requests, locations, qr_tokens, visitor_badges,
--   blacklist, audit_logs, notification_templates,
--   badge_templates, system_notifications, feedback,
--   incidents, company_settings, check_in_out_logs,
--   visit_documents, password_reset_tokens, sessions,
--   cache, cache_locks, jobs, job_batches, failed_jobs,
--   working_hours, watchlist, system_integrations,
--   visitor_properties, visit_group_members,
--   reschedule_requests
-- ============================================================

-- ------------------------------------------------------------
-- 1. ROLES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. PERMISSIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
    permission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    permission_name VARCHAR(150) NOT NULL,
    module_name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. ROLE_PERMISSIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS role_permissions (
    role_permission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    granted_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. USER_ROLES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_roles (
    user_role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. VISIT_PURPOSES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visit_purposes (
    purpose_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    purpose_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. MEETING_ROOMS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meeting_rooms (
    room_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(150) NOT NULL,
    room_number VARCHAR(50) NOT NULL,
    building VARCHAR(150) NULL,
    floor VARCHAR(50) NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 0,
    room_status VARCHAR(20) NOT NULL DEFAULT 'available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. ADDRESSES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS addresses (
    address_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    address_line VARCHAR(255) NOT NULL,
    city VARCHAR(100) NULL,
    state_region VARCHAR(100) NULL,
    country VARCHAR(100) NULL,
    postal_code VARCHAR(20) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. HOSTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hosts (
    host_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    office_number VARCHAR(50) NULL,
    extension_number VARCHAR(20) NULL,
    designation VARCHAR(150) NULL,
    availability_status VARCHAR(20) NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. GATES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gates (
    gate_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    gate_name VARCHAR(150) NOT NULL,
    gate_code VARCHAR(50) NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    gate_type VARCHAR(50) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. VEHICLES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vehicles (
    vehicle_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visitor_id BIGINT UNSIGNED NOT NULL,
    plate_number VARCHAR(30) NOT NULL,
    vehicle_type VARCHAR(50) NULL,
    vehicle_model VARCHAR(100) NULL,
    vehicle_color VARCHAR(50) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. VISITOR_FILES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visitor_files (
    file_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visitor_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) NULL,
    file_path VARCHAR(500) NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. VISITOR_CONTACTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visitor_contacts (
    contact_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visitor_id BIGINT UNSIGNED NOT NULL,
    contact_name VARCHAR(200) NOT NULL,
    relationship VARCHAR(100) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13. VISITOR_ADDRESSES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visitor_addresses (
    address_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visitor_id BIGINT UNSIGNED NOT NULL,
    country VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    sub_city VARCHAR(100) NULL,
    street_address VARCHAR(255) NULL,
    postal_code VARCHAR(20) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14. COMPANIES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS companies (
    company_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(200) NOT NULL,
    industry VARCHAR(150) NULL,
    address_id BIGINT UNSIGNED NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    website VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 15. VISITOR_VEHICLES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visitor_vehicles (
    visitor_vehicle_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visitor_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    registered_date TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 16. COMPANY_REPRESENTATIVES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS company_representatives (
    representative_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    visitor_id BIGINT UNSIGNED NOT NULL,
    position VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 17. VISITOR_COMPANY_HISTORY
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visitor_company_history (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visitor_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    visit_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_visit_date TIMESTAMP NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 18. VISITOR_PHOTOS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visitor_photos (
    photo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visitor_id BIGINT UNSIGNED NOT NULL,
    photo_path VARCHAR(500) NOT NULL,
    captured_by BIGINT UNSIGNED NOT NULL,
    capture_date TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 19. GATE_DEVICES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gate_devices (
    device_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    gate_id BIGINT UNSIGNED NOT NULL,
    device_name VARCHAR(150) NOT NULL,
    device_type VARCHAR(100) NULL,
    serial_number VARCHAR(100) NULL,
    installed_date DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 20. PARKING_AREAS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS parking_areas (
    parking_area_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    area_name VARCHAR(150) NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 0,
    available_spaces INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 21. HOST_AVAILABILITY
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS host_availability (
    availability_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    host_id BIGINT UNSIGNED NOT NULL,
    available_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 22. APPOINTMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appointments (
    appointment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    host_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NULL,
    appointment_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 23. APPOINTMENT_VISITORS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appointment_visitors (
    appointment_visitor_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    visitor_id BIGINT UNSIGNED NOT NULL,
    attendance_status VARCHAR(30) NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 24. APPROVAL_HISTORY
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS approval_history (
    approval_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    approval_level VARCHAR(50) NULL,
    decision VARCHAR(30) NOT NULL,
    remarks TEXT NULL,
    approved_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 25. VISIT_STATUS_HISTORY
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visit_status_history (
    status_history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    previous_status VARCHAR(30) NULL,
    current_status VARCHAR(30) NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    changed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 26. ROOM_BOOKINGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS room_bookings (
    booking_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    booked_by BIGINT UNSIGNED NOT NULL,
    booking_start DATETIME NOT NULL,
    booking_end DATETIME NOT NULL,
    booking_status VARCHAR(20) NOT NULL DEFAULT 'confirmed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 27. SECURITY_CHECKS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS security_checks (
    security_check_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    check_in_id BIGINT UNSIGNED NOT NULL,
    security_officer_id BIGINT UNSIGNED NOT NULL,
    scan_result VARCHAR(50) NULL,
    remarks TEXT NULL,
    checked_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 28. BLACKLIST_HISTORY
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blacklist_history (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blacklist_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    action_by BIGINT UNSIGNED NOT NULL,
    action_date TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    remarks TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 29. EMAIL_LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_logs (
    email_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT UNSIGNED NOT NULL,
    recipient_email VARCHAR(150) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    sent_by BIGINT UNSIGNED NOT NULL,
    sent_time TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    delivery_status VARCHAR(30) NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 30. SMS_LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sms_logs (
    sms_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT UNSIGNED NOT NULL,
    phone_number VARCHAR(30) NOT NULL,
    message TEXT NOT NULL,
    sent_by BIGINT UNSIGNED NOT NULL,
    sent_time TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    delivery_status VARCHAR(30) NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 31. ACCESS_LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS access_logs (
    access_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    login_time TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    logout_time TIMESTAMP NULL,
    ip_address VARCHAR(45) NULL,
    device VARCHAR(255) NULL,
    browser VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 32. DEVICE_LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS device_logs (
    device_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    event_time TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 33. PARKING_RESERVATIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS parking_reservations (
    reservation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    parking_area_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    reserved_by BIGINT UNSIGNED NOT NULL,
    reservation_time TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 34. REPORTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reports (
    report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    report_name VARCHAR(200) NOT NULL,
    report_type VARCHAR(100) NOT NULL,
    generated_by BIGINT UNSIGNED NOT NULL,
    generated_date TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    file_path VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'generated'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 35. DASHBOARD_STATISTICS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dashboard_statistics (
    statistic_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dashboard_name VARCHAR(150) NOT NULL,
    metric_name VARCHAR(150) NOT NULL,
    metric_value DECIMAL(15,2) NULL,
    recorded_date TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 36. BACKUP_HISTORY
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS backup_history (
    backup_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    backup_type VARCHAR(50) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    backup_size BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    backup_date TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'completed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 37. SYSTEM_HEALTH
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_health (
    health_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    server_name VARCHAR(150) NOT NULL,
    cpu_usage DECIMAL(5,2) NULL,
    memory_usage DECIMAL(5,2) NULL,
    disk_usage DECIMAL(5,2) NULL,
    checked_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'healthy'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 38. API_KEYS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    api_key_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(150) NOT NULL,
    api_key VARCHAR(500) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_date TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    expiry_date TIMESTAMP NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 39. INTEGRATION_LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS integration_logs (
    integration_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    api_key_id BIGINT UNSIGNED NOT NULL,
    service_name VARCHAR(150) NOT NULL,
    request_type VARCHAR(50) NULL,
    response_code INT NULL,
    executed_by BIGINT UNSIGNED NOT NULL,
    executed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'success'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
