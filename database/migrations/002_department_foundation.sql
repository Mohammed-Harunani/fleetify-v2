-- Fleetify 2.0 / Phase 24 / Module 1
-- Apply once to a backed-up fleetify_v2 database. Existing tables are preserved.
USE fleetify_v2;

CREATE TABLE IF NOT EXISTS company_departments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(50) NOT NULL,
 name VARCHAR(120) NOT NULL,
 is_enabled TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_dept_company_code (company_id,code),
 UNIQUE KEY uq_dept_company_id (company_id,id),
 CONSTRAINT fk_dept_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_departments (
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 department_id BIGINT UNSIGNED NOT NULL,
 is_primary TINYINT(1) NOT NULL DEFAULT 0,
 assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (company_id,user_id,department_id),
 KEY idx_staff_dept (company_id,department_id,user_id),
 CONSTRAINT fk_sd_user FOREIGN KEY (company_id,user_id) REFERENCES users(company_id,id),
 CONSTRAINT fk_sd_dept FOREIGN KEY (company_id,department_id) REFERENCES company_departments(company_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_branch_access (
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 office_id BIGINT UNSIGNED NOT NULL,
 assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (company_id,user_id,office_id),
 KEY idx_sba_office (company_id,office_id,user_id),
 CONSTRAINT fk_sba_user FOREIGN KEY (company_id,user_id) REFERENCES users(company_id,id),
 CONSTRAINT fk_sba_office FOREIGN KEY (company_id,office_id) REFERENCES offices(company_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(100) NOT NULL,
 description VARCHAR(255) NOT NULL,
 UNIQUE KEY uq_permission_slug (slug)
) ENGINE=InnoDB;

-- Roles remain tenant-specific; role codes must match users.role enum in Phase 23.
CREATE TABLE IF NOT EXISTS role_permissions (
 company_id BIGINT UNSIGNED NOT NULL,
 role_code VARCHAR(50) NOT NULL,
 permission_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (company_id,role_code,permission_id),
 KEY idx_rp_permission (permission_id),
 CONSTRAINT fk_rp_company FOREIGN KEY (company_id) REFERENCES companies(id),
 CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED DEFAULT NULL,
 office_id BIGINT UNSIGNED DEFAULT NULL,
 action VARCHAR(100) NOT NULL,
 entity_type VARCHAR(80) NOT NULL,
 entity_id BIGINT UNSIGNED DEFAULT NULL,
 old_values LONGTEXT DEFAULT NULL,
 new_values LONGTEXT DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_audit_company_time (company_id,created_at,id),
 KEY idx_audit_entity (company_id,entity_type,entity_id),
 CONSTRAINT fk_audit_company FOREIGN KEY (company_id) REFERENCES companies(id),
 CONSTRAINT fk_audit_actor FOREIGN KEY (company_id,actor_user_id) REFERENCES users(company_id,id),
 CONSTRAINT fk_audit_office FOREIGN KEY (company_id,office_id) REFERENCES offices(company_id,id),
 CONSTRAINT chk_audit_old_json CHECK (old_values IS NULL OR JSON_VALID(old_values)),
 CONSTRAINT chk_audit_new_json CHECK (new_values IS NULL OR JSON_VALID(new_values))
) ENGINE=InnoDB;

INSERT INTO permissions (slug,description) VALUES
 ('users.manage','Manage staff accounts'),
 ('offices.manage','Manage offices and depots'),
 ('vehicles.manage','Manage fleet vehicles'),
 ('routes.manage','Manage transport routes'),
 ('consignments.create','Create consignments'),
 ('consignments.view','View consignments'),
 ('consignments.update','Update consignments'),
 ('consignments.assign','Assign consignments'),
 ('reports.view','View reports'),
 ('departments.view','View departments'),
 ('departments.manage','Manage departments'),
 ('staff.assign','Assign staff to departments and branches'),
 ('branches.view','View assigned branches'),
 ('branches.global','View all branches in own company'),
 ('audit.view','View audit records'),
 ('fleet.view','View fleet records'),
 ('finance.view','View finance records'),
 ('warehouse.view','View warehouse records'),
 ('crm.view','View customer records'),
 ('hr.view','View HR records'),
 ('procurement.view','View procurement records'),
 ('support.view','View support records')
ON DUPLICATE KEY UPDATE description=VALUES(description);

INSERT INTO company_departments (company_id,code,name,is_enabled)
SELECT c.id,d.code,d.name,1 FROM companies c
CROSS JOIN (
 SELECT 'operations' code,'Operations & Dispatch' name UNION ALL
 SELECT 'fleet','Fleet & Workshop' UNION ALL
 SELECT 'warehouse','Warehouse & Hub' UNION ALL
 SELECT 'sales','Sales & CRM' UNION ALL
 SELECT 'finance','Finance & Accounts' UNION ALL
 SELECT 'support','Customer Service & Claims' UNION ALL
 SELECT 'hr','Human Resources' UNION ALL
 SELECT 'procurement','Procurement' UNION ALL
 SELECT 'management','Executive Management'
) d
WHERE 1=1
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Backfill existing Phase 23 permission behaviour, without granting new privileges to non-admin roles.
INSERT IGNORE INTO role_permissions (company_id,role_code,permission_id)
SELECT c.id, m.role_code, p.id FROM companies c
JOIN (
 SELECT 'admin' role_code,'users.manage' slug UNION ALL
 SELECT 'admin','offices.manage' UNION ALL
 SELECT 'admin','vehicles.manage' UNION ALL
 SELECT 'admin','routes.manage' UNION ALL
 SELECT 'admin','consignments.create' UNION ALL
 SELECT 'admin','consignments.view' UNION ALL
 SELECT 'admin','consignments.update' UNION ALL
 SELECT 'admin','consignments.assign' UNION ALL
 SELECT 'admin','reports.view' UNION ALL
 SELECT 'branch_manager','consignments.create' UNION ALL
 SELECT 'branch_manager','consignments.view' UNION ALL
 SELECT 'branch_manager','consignments.update' UNION ALL
 SELECT 'branch_manager','consignments.assign' UNION ALL
 SELECT 'branch_manager','reports.view' UNION ALL
 SELECT 'dispatcher','consignments.create' UNION ALL
 SELECT 'dispatcher','consignments.view' UNION ALL
 SELECT 'dispatcher','consignments.update' UNION ALL
 SELECT 'dispatcher','consignments.assign' UNION ALL
 SELECT 'driver','consignments.view' UNION ALL
 SELECT 'admin','departments.view' UNION ALL
 SELECT 'admin','departments.manage' UNION ALL
 SELECT 'admin','staff.assign' UNION ALL
 SELECT 'admin','branches.view' UNION ALL
 SELECT 'admin','branches.global' UNION ALL
 SELECT 'admin','audit.view' UNION ALL
 SELECT 'admin','fleet.view' UNION ALL
 SELECT 'admin','finance.view' UNION ALL
 SELECT 'admin','warehouse.view' UNION ALL
 SELECT 'admin','crm.view' UNION ALL
 SELECT 'admin','hr.view' UNION ALL
 SELECT 'admin','procurement.view' UNION ALL
 SELECT 'admin','support.view' UNION ALL
 SELECT 'branch_manager','departments.view' UNION ALL
 SELECT 'branch_manager','branches.view' UNION ALL
 SELECT 'dispatcher','branches.view' UNION ALL
 SELECT 'driver','branches.view'
) m ON 1=1
JOIN permissions p ON p.slug=m.slug;

-- Existing users retain access to their home office. No blanket access to other branches.
INSERT IGNORE INTO staff_branch_access (company_id,user_id,office_id)
SELECT company_id,id,office_id FROM users WHERE office_id IS NOT NULL;

-- Existing staff get an initial departmental assignment; it can be changed in Batch B.
INSERT IGNORE INTO staff_departments (company_id,user_id,department_id,is_primary)
SELECT u.company_id,u.id,d.id,1 FROM users u
JOIN company_departments d ON d.company_id=u.company_id
 AND d.code=CASE WHEN u.role='admin' THEN 'management' ELSE 'operations' END;
