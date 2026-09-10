/**
 * Columnas `ticket_key` y `ticket_title` en ítems de standup.
 */

export async function migrateStandupTicketDetails(db) {
  const columns = (await db.prepare("PRAGMA table_info(standup_items)").all()).results;
  if (!columns.some(column => column.name === "ticket_key")) {
    await db.prepare("ALTER TABLE standup_items ADD COLUMN ticket_key TEXT NOT NULL DEFAULT ''").run();
  }
  if (!columns.some(column => column.name === "ticket_title")) {
    await db.prepare("ALTER TABLE standup_items ADD COLUMN ticket_title TEXT NOT NULL DEFAULT ''").run();
  }
}
