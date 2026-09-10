/**
 * Añade `tickets.completed_at` y lo rellena para los que están en Done.
 */

export async function migrateTicketCompletion(db) {
  const migrationKey = "ticket_completed_at_v1";
  const applied = await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(migrationKey).first();
  if (applied?.value === "done") return;
  const columns = (await db.prepare("PRAGMA table_info(tickets)").all()).results;
  if (!columns.some(column => column.name === "completed_at")) {
    await db.prepare("ALTER TABLE tickets ADD COLUMN completed_at TEXT").run();
  }
  await db.prepare("UPDATE tickets SET completed_at=updated_at WHERE completed_at IS NULL AND status_id IN (SELECT id FROM statuses WHERE lower(name)='done')").run();
  await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(migrationKey, "done").run();
}
