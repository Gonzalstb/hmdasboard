/**
 * Acción `set_ticket_attention`.
 *
 * Asigna o quita el llamador visual de un ticket.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const ticketId=number(payload.ticketId),attentionMarkerId=number(payload.attentionMarkerId),ticket=await db.prepare("SELECT id FROM tickets WHERE id=? AND user_id=?").bind(ticketId,uid).first();
    if(!ticket)throw new Error("El ticket ya no existe.");
    if(attentionMarkerId&&!await db.prepare("SELECT id FROM attention_markers WHERE id=? AND user_id=?").bind(attentionMarkerId,uid).first())throw new Error("El llamador seleccionado ya no existe.");
    await db.prepare("UPDATE tickets SET attention_marker_id=? WHERE id=? AND user_id=?").bind(attentionMarkerId||null,ticketId,uid).run();
  
}
