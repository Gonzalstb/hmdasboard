/**
 * Acción `save_status`.
 *
 * Crea o actualiza un estado del workspace.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number, color } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    if (!text(payload.name)) throw new Error("Escribe un nombre.");
    if (number(payload.id)) await db.prepare("UPDATE statuses SET name=?,color=?,is_done=? WHERE id=? AND user_id=?").bind(text(payload.name),color(payload.color),payload.isDone?1:0,number(payload.id),uid).run();
    else { const max=await db.prepare("SELECT COALESCE(MAX(position),-1)+1 position FROM statuses WHERE user_id=?").bind(uid).first();await db.prepare("INSERT INTO statuses(name,color,is_done,position,user_id) VALUES(?,?,?,?,?)").bind(text(payload.name),color(payload.color),payload.isDone?1:0,Number(max.position),uid).run(); }
  
}
