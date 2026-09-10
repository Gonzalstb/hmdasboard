ALTER TABLE tickets ADD COLUMN completed_at TEXT;

UPDATE tickets
SET completed_at = updated_at
WHERE completed_at IS NULL
  AND status_id IN (
    SELECT id
    FROM statuses
    WHERE lower(name) = 'done'
  );
