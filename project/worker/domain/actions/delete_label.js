/**
 * Acción `delete_label`.
 *
 * Borra una label y sus asignaciones.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id);
    if(!await db.prepare("SELECT id FROM labels WHERE id=? AND user_id=?").bind(id,uid).first())throw new Error("La label ya no existe.");
    await db.batch([db.prepare("DELETE FROM ticket_labels WHERE label_id=?").bind(id),db.prepare("DELETE FROM labels WHERE id=? AND user_id=?").bind(id,uid)]);
  
}
