/**
 * Acción `comment`.
 *
 * Añade un comentario a un ticket (con anti-duplicado de 12s).
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const ticketId=number(payload.ticketId),content=text(payload.content),ticket=await db.prepare("SELECT id FROM tickets WHERE id=? AND user_id=?").bind(ticketId,uid).first();
    if(!content)throw new Error("Escribe un comentario.");
    if(!ticket)throw new Error("El ticket ya no existe.");
    const inserted=await db.prepare("INSERT INTO comments(ticket_id,content) SELECT ?,? WHERE NOT EXISTS (SELECT 1 FROM comments WHERE ticket_id=? AND content=? AND created_at>=datetime('now','-12 seconds'))").bind(ticketId,content,ticketId,content).run();
    if(Number(inserted.meta?.changes))await db.prepare("UPDATE tickets SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?").bind(ticketId,uid).run();
  
}
