CREATE TABLE IF NOT EXISTS crm_employees (
  id CHAR(36) NOT NULL,
  full_name VARCHAR(200) NOT NULL,
  role_name VARCHAR(120) NOT NULL DEFAULT '',
  department VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(60) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  attendance_mode ENUM('hours','days') NOT NULL DEFAULT 'hours',
  pay_rate_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  advance_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_employees_active (active, full_name),
  KEY idx_crm_employees_department (department, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(96) NOT NULL,
  display_name VARCHAR(180) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','admin','finance','manager','production','procurement','foreman','employee','viewer') NOT NULL DEFAULT 'employee',
  employee_id CHAR(36) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_users_username (username),
  UNIQUE KEY uq_crm_users_employee (employee_id),
  CONSTRAINT fk_crm_users_employee FOREIGN KEY (employee_id) REFERENCES crm_employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_crews (
  id CHAR(36) NOT NULL,
  name VARCHAR(200) NOT NULL,
  specialty VARCHAR(160) NOT NULL DEFAULT '',
  foreman_id CHAR(36) NULL,
  phone VARCHAR(60) NOT NULL DEFAULT '',
  notes TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_crews_active (active, name),
  CONSTRAINT fk_crm_crews_foreman FOREIGN KEY (foreman_id) REFERENCES crm_employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_crew_members (
  crew_id CHAR(36) NOT NULL,
  employee_id CHAR(36) NOT NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (crew_id, employee_id),
  CONSTRAINT fk_crm_crew_members_crew FOREIGN KEY (crew_id) REFERENCES crm_crews(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_crew_members_employee FOREIGN KEY (employee_id) REFERENCES crm_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_clients (
  id CHAR(36) NOT NULL,
  display_name VARCHAR(200) NOT NULL,
  phone VARCHAR(60) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  source VARCHAR(120) NOT NULL DEFAULT '',
  notes TEXT NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_clients_name (display_name),
  KEY idx_crm_clients_phone (phone),
  CONSTRAINT fk_crm_clients_created_by FOREIGN KEY (created_by) REFERENCES crm_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_sites (
  id CHAR(36) NOT NULL,
  client_id CHAR(36) NOT NULL,
  name VARCHAR(220) NOT NULL,
  address VARCHAR(500) NOT NULL DEFAULT '',
  calculator_project_id CHAR(36) NULL,
  calculator_project_number VARCHAR(32) NOT NULL DEFAULT '',
  calculator_revision INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_sites_client (client_id, updated_at),
  UNIQUE KEY uq_crm_sites_calculator_project (calculator_project_id),
  CONSTRAINT fk_crm_sites_client FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_leads (
  id CHAR(36) NOT NULL,
  site_id CHAR(36) NOT NULL,
  public_number VARCHAR(32) NOT NULL,
  status ENUM('new','in_progress','calculation','offer','approval','contract','won','lost') NOT NULL DEFAULT 'new',
  owner_id BIGINT UNSIGNED NULL,
  next_action VARCHAR(250) NOT NULL DEFAULT '',
  next_action_at DATETIME NULL,
  source_inquiry_id CHAR(36) NULL,
  source_inquiry_number VARCHAR(32) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_leads_number (public_number),
  UNIQUE KEY uq_crm_leads_source_inquiry (source_inquiry_id),
  KEY idx_crm_leads_owner_status (owner_id, status, next_action_at),
  CONSTRAINT fk_crm_leads_site FOREIGN KEY (site_id) REFERENCES crm_sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_leads_owner FOREIGN KEY (owner_id) REFERENCES crm_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_tasks (
  id CHAR(36) NOT NULL,
  title VARCHAR(250) NOT NULL,
  description TEXT NOT NULL,
  site_id CHAR(36) NULL,
  assignee_id CHAR(36) NULL,
  crew_id CHAR(36) NULL,
  status ENUM('planned','doing','review','blocked','done') NOT NULL DEFAULT 'planned',
  priority ENUM('normal','high') NOT NULL DEFAULT 'normal',
  due_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_tasks_assignee (assignee_id, status, due_at),
  KEY idx_crm_tasks_crew (crew_id, status, due_at),
  CONSTRAINT fk_crm_tasks_site FOREIGN KEY (site_id) REFERENCES crm_sites(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_tasks_assignee FOREIGN KEY (assignee_id) REFERENCES crm_employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_tasks_crew FOREIGN KEY (crew_id) REFERENCES crm_crews(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_tasks_created_by FOREIGN KEY (created_by) REFERENCES crm_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_attendance (
  id CHAR(36) NOT NULL,
  employee_id CHAR(36) NOT NULL,
  work_date DATE NOT NULL,
  kind ENUM('work','off','leave','sick') NOT NULL,
  hours DECIMAL(5,2) NOT NULL DEFAULT 0,
  note VARCHAR(1000) NOT NULL DEFAULT '',
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_attendance_employee_day (employee_id, work_date),
  KEY idx_crm_attendance_day (work_date, employee_id),
  CONSTRAINT fk_crm_attendance_employee FOREIGN KEY (employee_id) REFERENCES crm_employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_attendance_updated_by FOREIGN KEY (updated_by) REFERENCES crm_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_communications (
  id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  channel ENUM('note','call','email','telegram','whatsapp','max','system') NOT NULL,
  direction ENUM('internal','incoming','outgoing') NOT NULL DEFAULT 'internal',
  external_key VARCHAR(255) NOT NULL DEFAULT '',
  subject VARCHAR(500) NOT NULL DEFAULT '',
  body MEDIUMTEXT NOT NULL,
  occurred_at DATETIME NOT NULL,
  author_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_communications_external (channel, external_key),
  KEY idx_crm_communications_site (site_id, occurred_at),
  CONSTRAINT fk_crm_communications_site FOREIGN KEY (site_id) REFERENCES crm_sites(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_communications_author FOREIGN KEY (author_id) REFERENCES crm_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_integration_accounts (
  id CHAR(36) NOT NULL,
  provider ENUM('vk_workspace_mail','vk_workspace_disk','telegram','whatsapp','max','eft_calculator') NOT NULL,
  display_name VARCHAR(180) NOT NULL,
  status ENUM('disabled','pending','active','error') NOT NULL DEFAULT 'disabled',
  settings_json JSON NULL,
  last_sync_at DATETIME NULL,
  last_error VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_integration_provider_name (provider, display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  action_name VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id VARCHAR(80) NOT NULL DEFAULT '',
  details_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_audit_created (created_at),
  KEY idx_crm_audit_entity (entity_type, entity_id, created_at),
  CONSTRAINT fk_crm_audit_user FOREIGN KEY (user_id) REFERENCES crm_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_hash CHAR(64) NOT NULL,
  username VARCHAR(96) NOT NULL,
  succeeded TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_login_limit (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
