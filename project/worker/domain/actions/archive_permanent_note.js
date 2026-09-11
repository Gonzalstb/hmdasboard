/**
 * Acción `archive_permanent_note`.
 *
 * Archiva o restaura una nota permanente.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),note=await db.prepare("SELECT id FROM permanent_notes WHERE id=? AND user_id=?").bind(id,uid).first();
    if(!note)throw new Error("La nota permanente ya no existe.");
    await db.prepare("UPDATE permanent_notes SET archived_at=CASE WHEN ?=1 THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=? AND user_id=?").bind(payload.archived?1:0,id,uid).run();
  
}
