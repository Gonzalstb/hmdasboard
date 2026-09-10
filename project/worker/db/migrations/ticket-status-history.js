/**
 * Crea el historial inicial (`baseline`) de estados por ticket.
 */

export async function migrateTicketStatusHistory(db) {
  const migrationKey = "ticket_status_history_v1";
  const applied = await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(migrationKey).first();
  if (applied?.value === "done") return;
  await db.prepare("INSERT INTO ticket_status_history(ticket_id,to_status_id,to_status_name,to_status_color,event_type) SELECT t.id,s.id,s.name,s.color,'baseline' FROM tickets t JOIN statuses s ON s.id=t.status_id WHERE NOT EXISTS (SELECT 1 FROM ticket_status_history h WHERE h.ticket_id=t.id)").run();
  await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(migrationKey,"done").run();
}
