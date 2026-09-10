/**
 * Acción `update_agenda_subtasks`.
 *
 * Sustituye el JSON de subtareas.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),items=JSON.stringify(Array.isArray(payload.subtasks)?payload.subtasks.map((x,i)=>({id:Number(x.id)||i+1,content:text(x.content).slice(0,500),isDone:Boolean(x.isDone)})).filter(x=>x.content).slice(0,50):[]);
    if(!await db.prepare("SELECT id FROM agenda_tasks WHERE id=? AND user_id=?").bind(id,uid).first())throw new Error("La tarea de agenda ya no existe.");
    await db.prepare("UPDATE agenda_tasks SET subtasks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(items,id).run();
  
}
