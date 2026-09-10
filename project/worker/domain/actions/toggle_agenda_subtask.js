/**
 * Acción `toggle_agenda_subtask`.
 *
 * Marca o desmarca una subtarea.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const taskId=number(payload.taskId),subtaskId=number(payload.subtaskId),task=await db.prepare("SELECT subtasks FROM agenda_tasks WHERE id=? AND user_id=?").bind(taskId,uid).first();
    if(!task)throw new Error("La tarea de agenda ya no existe.");
    let items=[];try{items=JSON.parse(String(task.subtasks||"[]"))}catch{}if(!Array.isArray(items))items=[];const item=items.find(x=>Number(x.id)===subtaskId);if(!item)throw new Error("La subtarea ya no existe.");item.isDone=!Boolean(item.isDone);
    await db.prepare("UPDATE agenda_tasks SET subtasks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(JSON.stringify(items),taskId).run();
  
}
