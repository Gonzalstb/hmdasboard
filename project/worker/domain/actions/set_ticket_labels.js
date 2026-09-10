/**
 * Acción `set_ticket_labels`.
 *
 * Sustituye las labels de un ticket.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number, idList } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const ticketId=number(payload.ticketId),ticket=await db.prepare("SELECT id FROM tickets WHERE id=? AND user_id=?").bind(ticketId,uid).first();
    if(!ticket)throw new Error("El ticket ya no existe.");
    await db.batch([db.prepare("DELETE FROM ticket_labels WHERE ticket_id=?").bind(ticketId),...idList(payload.labelIds).map(labelId=>db.prepare("INSERT OR IGNORE INTO ticket_labels(ticket_id,label_id) SELECT ?,id FROM labels WHERE id=? AND user_id=?").bind(ticketId,labelId,uid)),db.prepare("UPDATE tickets SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?").bind(ticketId,uid)]);
  
}
