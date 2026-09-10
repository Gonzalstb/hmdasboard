/**
 * Añade `tickets.cancelled_at` y lo rellena para los Cancelled.
 */

export async function migrateTicketCancellation(db) {
  const migrationKey = "ticket_cancelled_at_v1";
  const applied = await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(migrationKey).first();
  if (applied?.value === "done") return;
  const columns = (await db.prepare("PRAGMA table_info(tickets)").all()).results;
  if (!columns.some(column => column.name === "cancelled_at")) {
    await db.prepare("ALTER TABLE tickets ADD COLUMN cancelled_at TEXT").run();
  }
  await db.prepare("UPDATE tickets SET cancelled_at=updated_at WHERE cancelled_at IS NULL AND status_id IN (SELECT id FROM statuses WHERE lower(name)='cancelled')").run();
  await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(migrationKey, "done").run();
}
