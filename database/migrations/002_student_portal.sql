-- 002: Student self-registration, separate portals, daily programme and announcements.

ALTER TABLE users MODIFY status ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'active';

ALTER TABLE students MODIFY enrollment_status ENUM('pending','active','graduated','suspended','deferred','withdrawn') NOT NULL DEFAULT 'active';

ALTER TABLE students
  ADD COLUMN registration_source ENUM('admin','self') NOT NULL DEFAULT 'admin' AFTER created_by,
  ADD COLUMN approved_by INT UNSIGNED NULL AFTER registration_source,
  ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
  ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER approved_at,
  ADD CONSTRAINT fk_students_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE schedule_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  series_id     VARCHAR(32)  NULL,
  title         VARCHAR(150) NOT NULL,
  description   VARCHAR(500) NULL,
  item_date     DATE NOT NULL,
  start_time    TIME NOT NULL,
  end_time      TIME NULL,
  venue         VARCHAR(120) NULL,
  category      ENUM('class','exam','assembly','sports','meeting','event','holiday','other') NOT NULL DEFAULT 'event',
  audience      ENUM('all','faculty','program','year') NOT NULL DEFAULT 'all',
  faculty_id    INT UNSIGNED NULL,
  program_id    INT UNSIGNED NULL,
  year_of_study TINYINT UNSIGNED NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sched_date (item_date, start_time),
  INDEX idx_sched_series (series_id),
  CONSTRAINT fk_sched_faculty FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcements (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(150) NOT NULL,
  body        TEXT NOT NULL,
  audience    ENUM('everyone','students','staff') NOT NULL DEFAULT 'everyone',
  is_pinned   TINYINT(1) NOT NULL DEFAULT 0,
  expires_at  DATE NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ann_created (created_at),
  CONSTRAINT fk_ann_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (slug, name, module) VALUES
  ('registrations.approve', 'Approve or reject student self-registrations', 'Students'),
  ('schedule.manage', 'Manage the daily school programme', 'Academics'),
  ('announcements.manage', 'Post and remove announcements', 'Communication');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
   WHERE r.slug = 'registrar' AND p.slug IN ('registrations.approve', 'schedule.manage', 'announcements.manage');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
   WHERE r.slug = 'super_admin' AND p.slug IN ('registrations.approve', 'schedule.manage', 'announcements.manage');

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('student_registration_mode', 'approval');
