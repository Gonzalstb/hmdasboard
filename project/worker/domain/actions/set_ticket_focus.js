/**
 * Acción `set_ticket_focus`.
 *
 * Marca o desmarca un ticket como foco.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const ticketId=number(payload.ticketId),ticket=await db.prepare("SELECT id FROM tickets WHERE id=? AND user_id=?").bind(ticketId,uid).first();
    if(!ticket)throw new Error("El ticket ya no existe.");
    await db.prepare("UPDATE tickets SET is_focus=? WHERE id=? AND user_id=?").bind(payload.focus===true?1:0,ticketId,uid).run();
  
}
