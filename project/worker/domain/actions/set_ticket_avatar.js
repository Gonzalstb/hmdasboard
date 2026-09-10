/**
 * Acción `set_ticket_avatar`.
 *
 * Asigna el avatar de un ticket.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const ticketId=number(payload.ticketId),avatarKey=text(payload.avatarKey),ticket=await db.prepare("SELECT id FROM tickets WHERE id=? AND user_id=?").bind(ticketId,uid).first();
    if(!ticket)throw new Error("El ticket ya no existe.");
    if(avatarKey&&!['yellow','bald_glasses','flower'].includes(avatarKey))throw new Error("El avatar seleccionado no es válido.");
    await db.prepare("UPDATE tickets SET avatar_key=? WHERE id=? AND user_id=?").bind(avatarKey||null,ticketId,uid).run();
  
}
