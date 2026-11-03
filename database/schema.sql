-- MySQL / MariaDB schema. Import with phpMyAdmin or: mysql -u root < database/schema.sql
CREATE DATABASE IF NOT EXISTS parish_service_compass CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE parish_service_compass;

CREATE TABLE IF NOT EXISTS service_requests (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_type   VARCHAR(32)  NOT NULL,
  full_name      VARCHAR(120) NOT NULL,
  email          VARCHAR(255) NOT NULL,
  phone          VARCHAR(40)  NULL,
  preferred_date DATE         NULL,
  notes          TEXT         NULL,
  status         VARCHAR(16)  NOT NULL DEFAULT 'pending',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_service CHECK (service_type IN ('baptism','wedding','funeral','blessing','first_communion','confirmation','mass_intention')),
  CONSTRAINT chk_status  CHECK (status IN ('pending','approved','completed','declined')),
  INDEX idx_status (status),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO service_requests (service_type, full_name, email, phone, preferred_date, notes, status) VALUES
  ('baptism', 'Maria Santos', 'maria.santos@example.com', '+63 917 555 0143', '2026-11-08', 'First child, godparents confirmed.', 'pending'),
  ('wedding', 'James & Liza Cruz', 'james.cruz@example.com', '+63 918 555 0921', '2026-12-12', 'Both baptized Catholic; pre-cana seminar requested.', 'approved'),
  ('funeral', 'Family of Roberto Reyes', 'reyes.family@example.com', '+63 906 555 0774', '2026-10-26', 'Wake at the family home, interment at Holy Cross.', 'pending'),
  ('blessing', 'Ana Domingo', 'ana.domingo@example.com', '+63 915 555 0388', '2026-11-01', 'House blessing in San Roque village.', 'completed'),
  ('first_communion', 'Grace Lim', 'grace.lim@example.com', '+63 917 555 0655', '2026-11-15', 'Child is 9 years old, enrolled in catechism.', 'approved'),
  ('mass_intention', 'Pedro Bautista', 'pedro.bautista@example.com', NULL, '2026-10-25', 'Thanksgiving mass for a recovered family member.', 'declined');
