-- Fleetify V2 / Module 2
-- Fleet & Workshop Management Foundation
--
-- Requires:
--   Existing Fleetify V2 schema
--   Module 1 department and permission migrations
--
-- Safety:
--   Apply only after database backup.
--   Preserve existing vehicles, users and consignments.
--   Enforce company-scoped foreign keys.
--   Do not execute until the full migration is reviewed.

USE fleetify_v2;

-- 1. VEHICLE DRIVER ASSIGNMENTS

CREATE TABLE IF NOT EXISTS vehicle_driver_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    driver_user_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_vda_company_id (company_id, id),

    KEY idx_vda_vehicle (
        company_id, vehicle_id, ended_at
    ),

    KEY idx_vda_driver (
        company_id, driver_user_id, ended_at
    ),

    CONSTRAINT fk_vda_vehicle
        FOREIGN KEY (company_id, vehicle_id)
        REFERENCES vehicles (company_id, id),

    CONSTRAINT fk_vda_driver
        FOREIGN KEY (company_id, driver_user_id)
        REFERENCES users (company_id, id),

    CONSTRAINT fk_vda_actor
        FOREIGN KEY (company_id, assigned_by)
        REFERENCES users (company_id, id),

    CONSTRAINT chk_vda_dates
        CHECK (
            ended_at IS NULL
            OR ended_at >= assigned_at
        )
) ENGINE=InnoDB;


-- 2. VEHICLE ODOMETER LOGS

CREATE TABLE IF NOT EXISTS vehicle_odometer_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    odometer_km DECIMAL(12,2) NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(500) DEFAULT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_vol_company_id (company_id, id),

    KEY idx_vol_vehicle_time (
        company_id, vehicle_id, recorded_at
    ),

    CONSTRAINT fk_vol_vehicle
        FOREIGN KEY (company_id, vehicle_id)
        REFERENCES vehicles (company_id, id),

    CONSTRAINT fk_vol_actor
        FOREIGN KEY (company_id, recorded_by)
        REFERENCES users (company_id, id),

    CONSTRAINT chk_vol_nonnegative
        CHECK (odometer_km >= 0)
) ENGINE=InnoDB;

-- 3. VEHICLE MAINTENANCE SCHEDULES

CREATE TABLE IF NOT EXISTS vehicle_maintenance_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    service_name VARCHAR(150) NOT NULL,
    interval_km DECIMAL(12,2) DEFAULT NULL,
    interval_days INT UNSIGNED DEFAULT NULL,
    next_due_km DECIMAL(12,2) DEFAULT NULL,
    next_due_date DATE DEFAULT NULL,
    status ENUM('active','paused','cancelled')
        NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_vms_company_id (company_id, id),
    UNIQUE KEY uq_vms_vehicle_schedule (company_id, vehicle_id, id),

    KEY idx_vms_vehicle (
        company_id, vehicle_id, status
    ),

    KEY idx_vms_due_date (
        company_id, next_due_date
    ),

    CONSTRAINT fk_vms_vehicle
        FOREIGN KEY (company_id, vehicle_id)
        REFERENCES vehicles (company_id, id),

    CONSTRAINT fk_vms_creator
        FOREIGN KEY (company_id, created_by)
        REFERENCES users (company_id, id),

    CONSTRAINT chk_vms_interval
        CHECK (
            (interval_km IS NOT NULL AND interval_km > 0)
            OR
            (interval_days IS NOT NULL AND interval_days > 0)
        ),

    CONSTRAINT chk_vms_due_km
        CHECK (
            next_due_km IS NULL
            OR next_due_km >= 0
        )
) ENGINE=InnoDB;


-- 4. VEHICLE MAINTENANCE JOBS

CREATE TABLE IF NOT EXISTS vehicle_maintenance_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    schedule_id BIGINT UNSIGNED DEFAULT NULL,
    job_type ENUM('service','inspection','repair')
        NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open','in_progress','completed','cancelled')
        NOT NULL DEFAULT 'open',
    priority ENUM('low','normal','high','critical')
        NOT NULL DEFAULT 'normal',
    opened_by BIGINT UNSIGNED NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    completion_notes TEXT DEFAULT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_vmj_company_id (company_id, id),

    KEY idx_vmj_vehicle_status (
        company_id, vehicle_id, status
    ),

    KEY idx_vmj_schedule (
        company_id, schedule_id
    ),

    CONSTRAINT fk_vmj_vehicle
        FOREIGN KEY (company_id, vehicle_id)
        REFERENCES vehicles (company_id, id),

    CONSTRAINT fk_vmj_schedule
        FOREIGN KEY (company_id, vehicle_id, schedule_id) REFERENCES vehicle_maintenance_schedules (company_id, vehicle_id, id),

    CONSTRAINT fk_vmj_opener
        FOREIGN KEY (company_id, opened_by)
        REFERENCES users (company_id, id),

    CONSTRAINT chk_vmj_completion
        CHECK (
            (status = 'completed' AND completed_at IS NOT NULL)
            OR
            (status <> 'completed' AND completed_at IS NULL)
        )
) ENGINE=InnoDB;

-- 5. VEHICLE DEFECTS

CREATE TABLE IF NOT EXISTS vehicle_defects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    reported_by BIGINT UNSIGNED NOT NULL,
    defect_title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    severity ENUM('minor','major','critical')
        NOT NULL DEFAULT 'minor',
    status ENUM('open','under_repair','resolved')
        NOT NULL DEFAULT 'open',
    reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_by BIGINT UNSIGNED DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    resolution_notes TEXT DEFAULT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_vdef_company_id (company_id, id),
    UNIQUE KEY uq_vdef_vehicle_defect (company_id, vehicle_id, id),

    KEY idx_vdef_vehicle_status (
        company_id, vehicle_id, status, severity
    ),

    CONSTRAINT fk_vdef_vehicle
        FOREIGN KEY (company_id, vehicle_id)
        REFERENCES vehicles (company_id, id),

    CONSTRAINT fk_vdef_reporter
        FOREIGN KEY (company_id, reported_by)
        REFERENCES users (company_id, id),

    CONSTRAINT fk_vdef_resolver
        FOREIGN KEY (company_id, resolved_by)
        REFERENCES users (company_id, id),

    CONSTRAINT chk_vdef_resolution
        CHECK (
            (
                status = 'resolved'
                AND resolved_by IS NOT NULL
                AND resolved_at IS NOT NULL
            )
            OR
            (
                status <> 'resolved'
                AND resolved_at IS NULL
                AND resolved_by IS NULL
            )
        )
) ENGINE=InnoDB;


-- 6. VEHICLE GROUNDING EVENTS

CREATE TABLE IF NOT EXISTS vehicle_grounding_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    defect_id BIGINT UNSIGNED DEFAULT NULL,
    action ENUM('grounded','released') NOT NULL,
    reason TEXT NOT NULL,
    performed_by BIGINT UNSIGNED NOT NULL,
    performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_vge_company_id (company_id, id),

    KEY idx_vge_vehicle_time (
        company_id, vehicle_id, performed_at, id
    ),

    KEY idx_vge_defect (
        company_id, defect_id
    ),

    CONSTRAINT fk_vge_vehicle
        FOREIGN KEY (company_id, vehicle_id)
        REFERENCES vehicles (company_id, id),

    CONSTRAINT fk_vge_defect
        FOREIGN KEY (company_id, vehicle_id, defect_id) REFERENCES vehicle_defects (company_id, vehicle_id, id),

    CONSTRAINT fk_vge_actor
        FOREIGN KEY (company_id, performed_by)
        REFERENCES users (company_id, id)
) ENGINE=InnoDB;
