CREATE TABLE IF NOT EXISTS crm_employees (
  id CHAR(36) NOT NULL,
  full_name VARCHAR(200) NOT NULL,
  role_name VARCHAR(120) NOT NULL DEFAULT '',
  department VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(60) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  avatar_key VARCHAR(40) NOT NULL DEFAULT 'employee-01.jpg',
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
  construction_status ENUM('planning','active','paused','complete') NOT NULL DEFAULT 'planning',
  manager_employee_id CHAR(36) NULL,
  contract_number VARCHAR(120) NOT NULL DEFAULT '',
  planned_start DATE NULL,
  planned_finish DATE NULL,
  actual_start DATE NULL,
  actual_finish DATE NULL,
  construction_notes TEXT NULL,
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

CREATE TABLE IF NOT EXISTS crm_construction_stages (
  id CHAR(36) NOT NULL,
  site_id CHAR(36) NOT NULL,
  title VARCHAR(250) NOT NULL,
  stage_group VARCHAR(120) NOT NULL DEFAULT '',
  stage_status ENUM('planned','ready','doing','blocked','review','done') NOT NULL DEFAULT 'planned',
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  planned_start DATE NULL,
  planned_finish DATE NULL,
  actual_start DATE NULL,
  actual_finish DATE NULL,
  assignee_id CHAR(36) NULL,
  crew_id CHAR(36) NULL,
  construction_stage_id CHAR(36) NULL,
  dependency_ids_json JSON NULL,
  notes TEXT NULL,
  block_reason TEXT NULL,
  comments_json JSON NULL,
  attachments_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_construction_site (site_id, planned_start),
  KEY idx_crm_construction_status (stage_status, planned_finish),
  CONSTRAINT fk_crm_construction_site FOREIGN KEY (site_id) REFERENCES crm_sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_leads (
  id CHAR(36) NOT NULL,
  site_id CHAR(36) NOT NULL,
  public_number VARCHAR(32) NOT NULL,
  status ENUM('new','in_progress','calculation','offer','approval','contract','won','lost') NOT NULL DEFAULT 'new',
  owner_id BIGINT UNSIGNED NULL,
  owner_employee_id CHAR(36) NULL,
  next_action VARCHAR(250) NOT NULL DEFAULT '',
  next_action_at DATETIME NULL,
  source VARCHAR(120) NOT NULL DEFAULT '',
  notes TEXT NOT NULL,
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

CREATE TABLE IF NOT EXISTS crm_orders (
  id CHAR(36) NOT NULL,
  public_number VARCHAR(32) NOT NULL,
  site_id CHAR(36) NOT NULL,
  lead_id CHAR(36) NULL,
  scope TEXT NOT NULL,
  reference_text VARCHAR(1000) NOT NULL DEFAULT '',
  approved_by_employee_id CHAR(36) NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_orders_number (public_number),
  KEY idx_crm_orders_site (site_id, created_at),
  CONSTRAINT fk_crm_orders_site FOREIGN KEY (site_id) REFERENCES crm_sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_orders_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_orders_approved_by FOREIGN KEY (approved_by_employee_id) REFERENCES crm_employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_tasks (
  id CHAR(36) NOT NULL,
  order_id CHAR(36) NULL,
  title VARCHAR(250) NOT NULL,
  description TEXT NOT NULL,
  site_id CHAR(36) NULL,
  assignee_id CHAR(36) NULL,
  crew_id CHAR(36) NULL,
  status ENUM('planned','doing','review','blocked','done') NOT NULL DEFAULT 'planned',
  priority ENUM('normal','high') NOT NULL DEFAULT 'normal',
  due_at DATETIME NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
  completed_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit_name VARCHAR(80) NOT NULL DEFAULT 'задача',
  block_reason TEXT NOT NULL,
  checklist_json JSON NULL,
  original_due_at DATETIME NULL,
  reschedule_history_json JSON NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_tasks_assignee (assignee_id, status, due_at),
  KEY idx_crm_tasks_crew (crew_id, status, due_at),
  KEY idx_crm_tasks_stage (construction_stage_id, status, due_at),
  CONSTRAINT fk_crm_tasks_site FOREIGN KEY (site_id) REFERENCES crm_sites(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_tasks_assignee FOREIGN KEY (assignee_id) REFERENCES crm_employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_tasks_crew FOREIGN KEY (crew_id) REFERENCES crm_crews(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_tasks_stage FOREIGN KEY (construction_stage_id) REFERENCES crm_construction_stages(id) ON DELETE SET NULL,
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

CREATE TABLE IF NOT EXISTS crm_materials (
  id VARCHAR(120) NOT NULL,
  name VARCHAR(300) NOT NULL,
  category VARCHAR(160) NOT NULL,
  unit_name VARCHAR(50) NOT NULL,
  price_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tracked TINYINT(1) NOT NULL DEFAULT 0,
  min_stock DECIMAL(14,3) NOT NULL DEFAULT 0,
  source_name VARCHAR(255) NOT NULL DEFAULT '',
  price_note VARCHAR(500) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_materials_category (category, name),
  KEY idx_crm_materials_tracked (tracked, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_suppliers (
  id CHAR(36) NOT NULL,
  name VARCHAR(240) NOT NULL,
  contact_text VARCHAR(500) NOT NULL DEFAULT '',
  notes TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_supply_needs (
  id CHAR(36) NOT NULL,
  name VARCHAR(300) NOT NULL,
  need_kind ENUM('tool','consumable','equipment','other') NOT NULL,
  destination ENUM('production','tp') NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  unit_name VARCHAR(50) NOT NULL,
  priority ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
  need_status ENUM('requested','approved','ordered','received','rejected','cancelled') NOT NULL DEFAULT 'requested',
  needed_by DATE NULL,
  requested_by VARCHAR(200) NOT NULL DEFAULT '',
  links_json JSON NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_supply_needs_status (need_status, priority, needed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_purchases (
  id CHAR(36) NOT NULL,
  public_number VARCHAR(40) NOT NULL,
  purchase_date DATE NOT NULL,
  due_date DATE NULL,
  supplier_id CHAR(36) NOT NULL,
  lines_json JSON NOT NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_purchases_number (public_number),
  KEY idx_crm_purchases_supplier (supplier_id, purchase_date),
  CONSTRAINT fk_crm_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES crm_suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_stock_documents (
  id CHAR(36) NOT NULL,
  public_number VARCHAR(40) NOT NULL,
  document_date DATE NOT NULL,
  document_kind ENUM('receipt','issue','return','writeoff','direct') NOT NULL,
  target_name VARCHAR(500) NOT NULL,
  supplier_id CHAR(36) NULL,
  purchase_id CHAR(36) NULL,
  order_id CHAR(36) NULL,
  reference_text VARCHAR(500) NOT NULL DEFAULT '',
  note TEXT NOT NULL,
  lines_json JSON NOT NULL,
  total_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_stock_documents_number (public_number),
  KEY idx_crm_stock_documents_date (document_date, document_kind),
  CONSTRAINT fk_crm_stock_documents_supplier FOREIGN KEY (supplier_id) REFERENCES crm_suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_stock_documents_purchase FOREIGN KEY (purchase_id) REFERENCES crm_purchases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_tools (
  id CHAR(36) NOT NULL,
  name VARCHAR(300) NOT NULL,
  inventory_number VARCHAR(120) NOT NULL,
  home_kind ENUM('production','field') NOT NULL DEFAULT 'production',
  price_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  note TEXT NOT NULL,
  holder_type ENUM('','employee','crew') NOT NULL DEFAULT '',
  holder_id CHAR(36) NULL,
  due_date DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_tools_inventory_number (inventory_number),
  KEY idx_crm_tools_holder (holder_type, holder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_tool_events (
  id CHAR(36) NOT NULL,
  tool_id CHAR(36) NOT NULL,
  tool_name VARCHAR(300) NOT NULL,
  inventory_number VARCHAR(120) NOT NULL,
  event_kind ENUM('issue','return') NOT NULL,
  holder_name VARCHAR(240) NOT NULL,
  event_date DATE NOT NULL,
  due_date DATE NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_tool_events_tool (tool_id, event_date),
  CONSTRAINT fk_crm_tool_events_tool FOREIGN KEY (tool_id) REFERENCES crm_tools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_communications (
  id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  task_id CHAR(36) NULL,
  activity_type VARCHAR(32) NOT NULL DEFAULT 'note',
  channel ENUM('note','call','email','telegram','whatsapp','max','system') NOT NULL,
  direction ENUM('internal','incoming','outgoing') NOT NULL DEFAULT 'internal',
  external_key VARCHAR(255) NOT NULL DEFAULT '',
  subject VARCHAR(500) NOT NULL DEFAULT '',
  body MEDIUMTEXT NOT NULL,
  occurred_at DATETIME NOT NULL,
  author_id BIGINT UNSIGNED NULL,
  author_employee_id CHAR(36) NULL,
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

CREATE TABLE IF NOT EXISTS crm_settings (
  setting_key VARCHAR(120) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
