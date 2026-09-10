/**
 * Tabla e índice de adjuntos de notas permanentes.
 */

export async function migratePermanentNoteAttachments(db) {
  await db.prepare("CREATE TABLE IF NOT EXISTS permanent_note_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, note_id INTEGER NOT NULL, storage_key TEXT NOT NULL UNIQUE, file_name TEXT NOT NULL, content_type TEXT NOT NULL, size_bytes INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)").run();
  await db.prepare("CREATE INDEX IF NOT EXISTS permanent_note_attachments_note_idx ON permanent_note_attachments(note_id,created_at,id)").run();
  await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES('permanent_note_attachments_v1','done')").run();
}
