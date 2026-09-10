/**
 * Acción `move_agenda_subtask`.
 *
 * Reordena una subtarea arriba o abajo.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const taskId=number(payload.taskId),subtaskId=number(payload.subtaskId),direction=text(payload.direction),task=await db.prepare("SELECT subtasks FROM agenda_tasks WHERE id=? AND user_id=?").bind(taskId,uid).first();
    if(!task)throw new Error("La tarea de agenda ya no existe.");
    let items=[];try{items=JSON.parse(String(task.subtasks||"[]"))}catch{}if(!Array.isArray(items))items=[];const index=items.findIndex(x=>Number(x.id)===subtaskId),target=index+(direction==="up"?-1:direction==="down"?1:0);if(index<0)throw new Error("La subtarea ya no existe.");if(target>=0&&target<items.length)[items[index],items[target]]=[items[target],items[index]];
    await db.prepare("UPDATE agenda_tasks SET subtasks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(JSON.stringify(items),taskId).run();
  
}
