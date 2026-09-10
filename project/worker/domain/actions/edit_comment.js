/**
 * Acción `edit_comment`.
 *
 * Edita el texto de un comentario.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),content=text(payload.content),comment=await db.prepare("SELECT c.ticket_id ticketId FROM comments c JOIN tickets t ON t.id=c.ticket_id WHERE c.id=? AND t.user_id=?").bind(id,uid).first();
    if(!content)throw new Error("El comentario no puede estar vacío.");
    if(!comment)throw new Error("El comentario ya no existe.");
    await db.batch([db.prepare("UPDATE comments SET content=? WHERE id=?").bind(content,id),db.prepare("UPDATE tickets SET updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(number(comment.ticketId))]);
  
}
