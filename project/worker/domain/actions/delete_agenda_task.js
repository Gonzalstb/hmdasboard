/**
 * Acción `delete_agenda_task`.
 *
 * Borra una tarea de agenda y sus comentarios.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),task=await db.prepare("SELECT id FROM agenda_tasks WHERE id=? AND user_id=?").bind(id,uid).first();
    if(!task)throw new Error("La tarea de agenda ya no existe.");
    await db.batch([db.prepare("DELETE FROM agenda_task_comments WHERE task_id=?").bind(id),db.prepare("DELETE FROM agenda_tasks WHERE id=? AND user_id=?").bind(id,uid)]);
  
}
