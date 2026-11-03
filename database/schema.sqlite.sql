-- SQLite schema, applied automatically when DB_DRIVER=sqlite and the file does not exist yet.
CREATE TABLE service_requests (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  service_type   TEXT NOT NULL CHECK (service_type IN ('baptism','wedding','funeral','blessing','first_communion','confirmation','mass_intention')),
  full_name      TEXT NOT NULL,
  email          TEXT NOT NULL,
  phone          TEXT,
  preferred_date TEXT,
  notes          TEXT,
  status         TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','completed','declined')),
  created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at     TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

INSERT INTO service_requests (service_type, full_name, email, phone, preferred_date, notes, status) VALUES
  ('baptism', 'Maria Santos', 'maria.santos@example.com', '+63 917 555 0143', '2026-11-08', 'First child, godparents confirmed.', 'pending'),
  ('wedding', 'James & Liza Cruz', 'james.cruz@example.com', '+63 918 555 0921', '2026-12-12', 'Both baptized Catholic; pre-cana seminar requested.', 'approved'),
  ('funeral', 'Family of Roberto Reyes', 'reyes.family@example.com', '+63 906 555 0774', '2026-10-26', 'Wake at the family home, interment at Holy Cross.', 'pending'),
  ('blessing', 'Ana Domingo', 'ana.domingo@example.com', '+63 915 555 0388', '2026-11-01', 'House blessing in San Roque village.', 'completed'),
  ('first_communion', 'Grace Lim', 'grace.lim@example.com', '+63 917 555 0655', '2026-11-15', 'Child is 9 years old, enrolled in catechism.', 'approved'),
  ('mass_intention', 'Pedro Bautista', 'pedro.bautista@example.com', NULL, '2026-10-25', 'Thanksgiving mass for a recovered family member.', 'declined');
