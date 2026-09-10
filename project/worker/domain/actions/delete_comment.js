/**
 * Acción `delete_comment`.
 *
 * Borra un comentario.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),comment=await db.prepare("SELECT c.ticket_id ticketId FROM comments c JOIN tickets t ON t.id=c.ticket_id WHERE c.id=? AND t.user_id=?").bind(id,uid).first();
    if(!comment)throw new Error("El comentario ya no existe.");
    await db.batch([db.prepare("DELETE FROM comments WHERE id=?").bind(id),db.prepare("UPDATE tickets SET updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(number(comment.ticketId))]);
  
}
