ALTER TABLE standup_items ADD COLUMN section TEXT NOT NULL DEFAULT 'points';

CREATE INDEX IF NOT EXISTS standup_items_guide_section_position_idx
  ON standup_items(guide_id, section, position, id);
