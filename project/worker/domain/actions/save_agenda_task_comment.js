/**
 * Acción `save_agenda_task_comment`.
 *
 * Comenta una tarea o subtarea de agenda.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const taskId=number(payload.taskId),subtaskId=number(payload.subtaskId),content=text(payload.content).slice(0,1200),task=await db.prepare("SELECT subtasks FROM agenda_tasks WHERE id=? AND user_id=?").bind(taskId,uid).first();
    if(!task)throw new Error("La tarea de agenda ya no existe.");
    if(!content)throw new Error("Escribe un comentario.");
    if(subtaskId){let items=[];try{items=JSON.parse(String(task.subtasks||"[]"))}catch{}if(!Array.isArray(items)||!items.some(x=>Number(x.id)===subtaskId))throw new Error("La subtarea ya no existe.")}
    await db.prepare("INSERT INTO agenda_task_comments(task_id,subtask_id,content) SELECT ?,?,? WHERE NOT EXISTS (SELECT 1 FROM agenda_task_comments WHERE task_id=? AND subtask_id=? AND content=? AND created_at>=datetime('now','-12 seconds'))").bind(taskId,subtaskId,content,taskId,subtaskId,content).run();
  
}
