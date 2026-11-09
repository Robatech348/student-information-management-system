-- UniSphere database schema (Phase 1–2). MySQL 5.7+/MariaDB 10.3+, InnoDB, utf8mb4.
-- Later phases (registration, exams, finance, attendance…) add their own migration files.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------- Authentication & RBAC
CREATE TABLE roles (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(50)  NOT NULL UNIQUE,
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug   VARCHAR(80)  NOT NULL UNIQUE,
  name   VARCHAR(150) NOT NULL,
  module VARCHAR(50)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                 VARCHAR(150) NOT NULL,
  email                VARCHAR(190) NOT NULL UNIQUE,
  phone                VARCHAR(30)  NULL,
  password_hash        VARCHAR(255) NOT NULL,
  status               ENUM('active','suspended') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Security
CREATE TABLE login_history (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  email      VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45)  NOT NULL,
  user_agent VARCHAR(255) NULL,
  success    TINYINT(1)   NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lh_email (email, created_at),
  INDEX idx_lh_ip (ip_address, created_at),
  CONSTRAINT fk_lh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(50)  NOT NULL,
  entity_type VARCHAR(50)  NOT NULL,
  entity_id   VARCHAR(50)  NULL,
  description VARCHAR(255) NULL,
  metadata    TEXT         NULL,
  ip_address  VARCHAR(45)  NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_user (user_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  setting_key   VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Institution structure
CREATE TABLE campuses (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code     VARCHAR(20)  NOT NULL UNIQUE,
  name     VARCHAR(150) NOT NULL,
  location VARCHAR(150) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faculties (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20)  NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  dean VARCHAR(150) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  faculty_id INT UNSIGNED NOT NULL,
  code       VARCHAR(20)  NOT NULL UNIQUE,
  name       VARCHAR(150) NOT NULL,
  CONSTRAINT fk_dept_faculty FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE programs (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id  INT UNSIGNED NOT NULL,
  code           VARCHAR(20)  NOT NULL UNIQUE,
  name           VARCHAR(150) NOT NULL,
  level          ENUM('certificate','diploma','bachelor','master','doctorate') NOT NULL DEFAULT 'bachelor',
  duration_years TINYINT UNSIGNED NOT NULL DEFAULT 4,
  total_credits  SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  CONSTRAINT fk_prog_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE academic_terms (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  academic_year VARCHAR(20)  NOT NULL,
  start_date    DATE NOT NULL,
  end_date      DATE NOT NULL,
  is_current    TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calendar_events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(150) NOT NULL,
  event_date  DATE NOT NULL,
  event_type  ENUM('academic','exam','holiday','deadline','event') NOT NULL DEFAULT 'academic',
  description VARCHAR(255) NULL,
  INDEX idx_event_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Students
CREATE TABLE students (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_no          VARCHAR(30)  NOT NULL UNIQUE,
  user_id             INT UNSIGNED NULL UNIQUE,
  first_name          VARCHAR(80)  NOT NULL,
  middle_name         VARCHAR(80)  NULL,
  last_name           VARCHAR(80)  NOT NULL,
  gender              ENUM('male','female','other') NULL,
  date_of_birth       DATE NULL,
  national_id         VARCHAR(30)  NULL,
  nationality         VARCHAR(60)  NULL DEFAULT 'Kenyan',
  email               VARCHAR(190) NULL,
  phone               VARCHAR(30)  NULL,
  address             VARCHAR(255) NULL,
  photo_path          VARCHAR(255) NULL,
  program_id          INT UNSIGNED NOT NULL,
  campus_id           INT UNSIGNED NULL,
  year_of_study       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  enrollment_status   ENUM('active','graduated','suspended','deferred','withdrawn') NOT NULL DEFAULT 'active',
  academic_standing   ENUM('good','dean_list','warning','probation') NOT NULL DEFAULT 'good',
  admission_date      DATE NOT NULL,
  expected_graduation DATE NULL,
  current_gpa         DECIMAL(3,2) NULL,
  credits_completed   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME NULL,
  INDEX idx_students_name (last_name, first_name),
  INDEX idx_students_status (enrollment_status),
  INDEX idx_students_program (program_id),
  CONSTRAINT fk_students_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT,
  CONSTRAINT fk_students_campus  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  CONSTRAINT fk_students_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_students_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guardians (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id           INT UNSIGNED NOT NULL,
  full_name            VARCHAR(150) NOT NULL,
  relationship         VARCHAR(50)  NOT NULL,
  phone                VARCHAR(30)  NOT NULL,
  email                VARCHAR(190) NULL,
  is_emergency_contact TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_guardian_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_documents (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id    INT UNSIGNED NOT NULL,
  title         VARCHAR(150) NOT NULL,
  doc_type      ENUM('national_id','transcript','certificate','admission_letter','medical','other') NOT NULL DEFAULT 'other',
  file_path     VARCHAR(255) NOT NULL,
  original_name VARCHAR(200) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  file_size     INT UNSIGNED NOT NULL,
  uploaded_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Staff
CREATE TABLE staff (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_no      VARCHAR(30)  NOT NULL UNIQUE,
  user_id       INT UNSIGNED NULL UNIQUE,
  first_name    VARCHAR(80)  NOT NULL,
  last_name     VARCHAR(80)  NOT NULL,
  email         VARCHAR(190) NULL,
  phone         VARCHAR(30)  NULL,
  department_id INT UNSIGNED NULL,
  category      ENUM('lecturer','administrator','support') NOT NULL,
  designation   VARCHAR(100) NULL,
  status        ENUM('active','on_leave','exited') NOT NULL DEFAULT 'active',
  hire_date     DATE NULL,
  CONSTRAINT fk_staff_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Communication
CREATE TABLE notifications (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  title      VARCHAR(150) NOT NULL,
  body       VARCHAR(500) NULL,
  link       VARCHAR(255) NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_user (user_id, is_read),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
