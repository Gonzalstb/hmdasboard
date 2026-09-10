/**
 * Acción `delete_agenda_subtask`.
 *
 * Borra una subtarea y sus comentarios.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const taskId=number(payload.taskId),subtaskId=number(payload.subtaskId),task=await db.prepare("SELECT subtasks FROM agenda_tasks WHERE id=? AND user_id=?").bind(taskId,uid).first();
    if(!task)throw new Error("La tarea de agenda ya no existe.");
    let items=[];try{items=JSON.parse(String(task.subtasks||"[]"))}catch{}if(!Array.isArray(items))items=[];const filtered=items.filter(x=>Number(x.id)!==subtaskId);if(filtered.length===items.length)throw new Error("La subtarea ya no existe.");
    await db.batch([db.prepare("DELETE FROM agenda_task_comments WHERE task_id=? AND subtask_id=?").bind(taskId,subtaskId),db.prepare("UPDATE agenda_tasks SET subtasks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(JSON.stringify(filtered),taskId)]);
  
}
