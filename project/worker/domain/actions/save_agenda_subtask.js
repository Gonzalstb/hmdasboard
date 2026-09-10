/**
 * Acción `save_agenda_subtask`.
 *
 * Crea o edita una subtarea.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const taskId=number(payload.taskId),subtaskId=number(payload.subtaskId),content=text(payload.content).slice(0,500),task=await db.prepare("SELECT subtasks FROM agenda_tasks WHERE id=? AND user_id=?").bind(taskId,uid).first();
    if(!task)throw new Error("La tarea de agenda ya no existe.");
    if(!content)throw new Error("Escribe el contenido de la subtarea.");
    let items=[];try{items=JSON.parse(String(task.subtasks||"[]"))}catch{}if(!Array.isArray(items))items=[];
    if(subtaskId){const item=items.find(x=>Number(x.id)===subtaskId);if(!item)throw new Error("La subtarea ya no existe.");item.content=content}else{if(items.length>=50)throw new Error("Una tarea no puede tener más de 50 subtareas.");items.push({id:items.reduce((max,x)=>Math.max(max,Number(x.id)||0),0)+1,content,isDone:false})}
    await db.prepare("UPDATE agenda_tasks SET subtasks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(JSON.stringify(items),taskId).run();
  
}
