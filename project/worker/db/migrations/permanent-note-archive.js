/**
 * Columna `archived_at` en notas permanentes.
 */

export async function migratePermanentNoteArchive(db) {
  const columns = (await db.prepare("PRAGMA table_info(permanent_notes)").all()).results;
  if (!columns.some(column => column.name === "archived_at")) {
    await db.prepare("ALTER TABLE permanent_notes ADD COLUMN archived_at TEXT").run();
  }
}
