/**
 * Acción `delete_ticket`.
 *
 * Borra un ticket y sus relaciones.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id);
    if(!await db.prepare("SELECT id FROM tickets WHERE id=? AND user_id=?").bind(id,uid).first())throw new Error("El ticket ya no existe.");
    await db.batch([db.prepare("DELETE FROM comments WHERE ticket_id=?").bind(id),db.prepare("DELETE FROM ticket_status_history WHERE ticket_id=?").bind(id),db.prepare("DELETE FROM ticket_labels WHERE ticket_id=?").bind(id),db.prepare("DELETE FROM tickets WHERE id=? AND user_id=?").bind(id,uid)]);
  
}
