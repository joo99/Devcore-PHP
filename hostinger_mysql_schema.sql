-- ============================================================
-- Clinic API — MySQL / MariaDB schema for Hostinger shared hosting
-- Import once via: hPanel -> Databases -> phpMyAdmin -> Import
-- (MySQL version of the original Postgres complete_supabase_schema.sql)
--
-- Notes:
--   * Numeric columns (BIGINT/INT) and booleans (TINYINT(1)) are returned as
--     numbers/booleans in PHP (with mysqlnd + real prepared statements), matching
--     the Postgres response shape.
--   * created_at/updated_at are DATETIME(6) to keep microsecond precision, and
--     the connection sets the time zone to +00:00 so values stay in UTC.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------- 1) departments ----------
CREATE TABLE IF NOT EXISTS departments (
  id          BIGINT       NOT NULL AUTO_INCREMENT,
  name        VARCHAR(255) NOT NULL,
  icon_url    TEXT         NULL,
  `order`     INT          NOT NULL DEFAULT 0,
  created_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 2) doctor_types ----------
CREATE TABLE IF NOT EXISTS doctor_types (
  id             BIGINT       NOT NULL AUTO_INCREMENT,
  department_id  BIGINT       NOT NULL,
  type           ENUM('male','female') NOT NULL,
  label          VARCHAR(255) NOT NULL DEFAULT 'دكتور',
  enabled        TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_doctor_types_dept_type (department_id, type),
  CONSTRAINT fk_doctor_types_department
    FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 3) custom_slots ----------
CREATE TABLE IF NOT EXISTS custom_slots (
  id              BIGINT      NOT NULL AUTO_INCREMENT,
  doctor_type_id  BIGINT      NOT NULL,
  date            DATE        NOT NULL,
  capacity        INT         NOT NULL DEFAULT 1,
  from_time       TIME        NOT NULL,
  to_time         TIME        NOT NULL,
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_custom_slots_doctor_type_date (doctor_type_id, date),
  CONSTRAINT fk_custom_slots_doctor_type
    FOREIGN KEY (doctor_type_id) REFERENCES doctor_types (id) ON DELETE CASCADE,
  CONSTRAINT chk_custom_slots_capacity CHECK (capacity > 0),
  CONSTRAINT chk_custom_slots_time     CHECK (from_time < to_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 4) admins ----------
CREATE TABLE IF NOT EXISTS admins (
  id          BIGINT       NOT NULL AUTO_INCREMENT,
  email       VARCHAR(255) NOT NULL,
  password    VARCHAR(255) NOT NULL,
  role        VARCHAR(50)  NOT NULL DEFAULT 'admin',
  created_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 5) bookings ----------
CREATE TABLE IF NOT EXISTS bookings (
  id              BIGINT       NOT NULL AUTO_INCREMENT,
  department_id   BIGINT       NOT NULL,
  doctor_type_id  BIGINT       NOT NULL,
  custom_slot_id  BIGINT       NOT NULL,
  booking_date    DATE         NOT NULL,
  booking_time    VARCHAR(255) NOT NULL,
  patient_name    VARCHAR(255) NOT NULL,
  patient_age     INT          NOT NULL,
  patient_phone   VARCHAR(50)  NOT NULL,
  patient_gender  ENUM('male','female') NOT NULL,
  status          ENUM('pending','attended','absent') NOT NULL DEFAULT 'pending',
  created_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_bookings_custom_slot (custom_slot_id),
  KEY idx_bookings_patient_phone (patient_phone),
  CONSTRAINT fk_bookings_department
    FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE,
  CONSTRAINT fk_bookings_doctor_type
    FOREIGN KEY (doctor_type_id) REFERENCES doctor_types (id) ON DELETE CASCADE,
  CONSTRAINT fk_bookings_custom_slot
    FOREIGN KEY (custom_slot_id) REFERENCES custom_slots (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed: default admin account (for logging in)
-- Same old login credentials. Change the password in the database later.
-- ============================================================
INSERT INTO admins (email, password, role)
VALUES ('admin@clinic.com', 'admin123', 'admin')
ON DUPLICATE KEY UPDATE email = email;

-- Super admin account (full access: manage admins/users + all admin features). Change the password later.
INSERT INTO admins (email, password, role)
VALUES ('superadmin@clinic.com', 'superadmin123', 'superadmin')
ON DUPLICATE KEY UPDATE email = email;

-- Lower-privilege user account (role = user). Change the password later.
INSERT INTO admins (email, password, role)
VALUES ('user@clinic.com', 'user123', 'user')
ON DUPLICATE KEY UPDATE email = email;

-- ============================================================
-- Optional seed: default departments + two doctor types each (editable/removable from the dashboard)
-- Delete this section if you will migrate your existing Supabase data instead of starting fresh.
-- ============================================================
INSERT INTO departments (name, icon_url, `order`) VALUES
  ('العلاج الطبيعي', 'https://example.com/icons/physio.svg', 1),
  ('الكشف الطبي', 'https://example.com/icons/general-checkup.svg', 2),
  ('جهاز الحث المغناطيسي TMS', 'https://example.com/icons/tms.svg', 3),
  ('رسم العصب', 'https://example.com/icons/nerve-conduction.svg', 4),
  ('رسم المخ الخارجي EEG', 'https://example.com/icons/eeg.svg', 5);

INSERT INTO doctor_types (department_id, type, label, enabled)
SELECT id, 'male', 'دكتور', 1 FROM departments;

INSERT INTO doctor_types (department_id, type, label, enabled)
SELECT id, 'female', 'دكتورة', 0 FROM departments;
