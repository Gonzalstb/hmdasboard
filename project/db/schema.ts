export const ticketsSchema = `
CREATE TABLE IF NOT EXISTS tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_key TEXT NOT NULL,
  title TEXT NOT NULL,
  summary TEXT NOT NULL DEFAULT '',
  status_id INTEGER NOT NULL,
  priority TEXT NOT NULL DEFAULT 'medium',
  next_action TEXT NOT NULL DEFAULT '',
  attention_marker_id INTEGER,
  due_date TEXT,
  jira_url TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TEXT
)
`;

export const attentionMarkersSchema = `
CREATE TABLE IF NOT EXISTS attention_markers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  color TEXT NOT NULL,
  position INTEGER NOT NULL DEFAULT 0
)
`;

export const standupGuidesSchema = `
CREATE TABLE IF NOT EXISTS standup_guides (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL DEFAULT '',
  standup_date TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
`;

export const permanentNotesSchema = `
CREATE TABLE IF NOT EXISTS permanent_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL DEFAULT '',
  content TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
`;

export const permanentNoteAttachmentsSchema = `
CREATE TABLE IF NOT EXISTS permanent_note_attachments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  note_id INTEGER NOT NULL,
  storage_key TEXT NOT NULL UNIQUE,
  file_name TEXT NOT NULL,
  content_type TEXT NOT NULL,
  size_bytes INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS permanent_note_attachments_note_idx
  ON permanent_note_attachments(note_id, created_at, id)
`;

export const standupItemsSchema = `
CREATE TABLE IF NOT EXISTS standup_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  guide_id INTEGER NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  content TEXT NOT NULL,
  ticket_key TEXT NOT NULL DEFAULT '',
  ticket_title TEXT NOT NULL DEFAULT '',
  is_done INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
`;

export const agendaTaskCommentsSchema = `
CREATE TABLE IF NOT EXISTS agenda_task_comments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER NOT NULL,
  subtask_id INTEGER NOT NULL DEFAULT 0,
  content TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_agenda_task_comments_target
  ON agenda_task_comments(task_id, subtask_id, created_at, id)
`;
