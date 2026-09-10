/**
 * Acción `save_label`.
 *
 * Crea o actualiza una label.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number, color } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    if (!text(payload.name)) throw new Error("Escribe un nombre.");
    if (number(payload.id)) await db.prepare("UPDATE labels SET name=?,color=? WHERE id=? AND user_id=?").bind(text(payload.name),color(payload.color),number(payload.id),uid).run();
    else await db.prepare("INSERT INTO labels(name,color,user_id) VALUES(?,?,?)").bind(text(payload.name),color(payload.color),uid).run();
  
}
