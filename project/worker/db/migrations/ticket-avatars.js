/**
 * Añade `tickets.avatar_key` si la columna no existe.
 */

export async function migrateTicketAvatars(db) {
  const columns = (await db.prepare("PRAGMA table_info(tickets)").all()).results;
  if (!columns.some(column => column.name === "avatar_key")) {
    await db.prepare("ALTER TABLE tickets ADD COLUMN avatar_key TEXT").run();
  }
}
