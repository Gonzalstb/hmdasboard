/**
 * Añade `tickets.is_focus` (migración `ticket_focus_v1`).
 */

export async function migrateTicketFocus(db) {
  const migrationKey = "ticket_focus_v1";
  const applied = await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(migrationKey).first();
  if (applied?.value === "done") return;
  const columns = (await db.prepare("PRAGMA table_info(tickets)").all()).results;
  if (!columns.some(column => column.name === "is_focus")) {
    await db.prepare("ALTER TABLE tickets ADD COLUMN is_focus INTEGER NOT NULL DEFAULT 0").run();
  }
  await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(migrationKey,"done").run();
}
