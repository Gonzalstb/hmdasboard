/**
 * Acción `toggle_agenda_task`.
 *
 * Marca o desmarca una tarea de agenda como hecha.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),task=await db.prepare("SELECT id,is_done isDone FROM agenda_tasks WHERE id=? AND user_id=?").bind(id,uid).first();
    if(!task)throw new Error("La tarea de agenda ya no existe.");
    await db.prepare("UPDATE agenda_tasks SET is_done=?,completed_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(task.isDone?0:1,task.isDone?null:new Date().toISOString(),id).run();
  
}
