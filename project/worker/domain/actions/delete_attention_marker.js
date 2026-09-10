/**
 * Acción `delete_attention_marker`.
 *
 * Borra un llamador y lo quita de los tickets.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id);
    if(!await db.prepare("SELECT id FROM attention_markers WHERE id=? AND user_id=?").bind(id,uid).first())throw new Error("El llamador ya no existe.");
    await db.batch([db.prepare("UPDATE tickets SET attention_marker_id=NULL WHERE attention_marker_id=? AND user_id=?").bind(id,uid),db.prepare("DELETE FROM attention_markers WHERE id=? AND user_id=?").bind(id,uid)]);
  
}
