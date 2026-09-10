/**
 * Acción `delete_standup`.
 *
 * Borra una guía y sus ítems.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),guide=await db.prepare("SELECT id FROM standup_guides WHERE id=? AND user_id=?").bind(id,uid).first();
    if(!guide)throw new Error("La guía ya no existe.");
    await db.batch([db.prepare("DELETE FROM standup_items WHERE guide_id=?").bind(id),db.prepare("DELETE FROM standup_guides WHERE id=? AND user_id=?").bind(id,uid)]);
  
}
