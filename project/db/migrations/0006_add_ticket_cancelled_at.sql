ALTER TABLE tickets ADD COLUMN cancelled_at TEXT;

UPDATE tickets
SET cancelled_at = updated_at
WHERE cancelled_at IS NULL
  AND status_id IN (SELECT id FROM statuses WHERE lower(name) = 'cancelled');
