/**
 * Acción `reorder_agenda_tasks`.
 *
 * Guarda el orden de las tareas de un día.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, idList } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const taskDate=text(payload.taskDate),ids=idList(payload.ids).slice(0,300);
    if(!/^\d{4}-\d{2}-\d{2}$/.test(taskDate)||!ids.length)throw new Error("No se pudo guardar el nuevo orden.");
    const placeholders=ids.map(()=>"?").join(","),found=await db.prepare("SELECT COUNT(*) total FROM agenda_tasks WHERE user_id=? AND task_date=? AND id IN ("+placeholders+")").bind(uid,taskDate,...ids).first();
    if(Number(found?.total)!==ids.length)throw new Error("Alguna tarea ya no pertenece a este día.");
    await db.batch(ids.map((id,position)=>db.prepare("UPDATE agenda_tasks SET position=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND task_date=? AND user_id=?").bind(position,id,taskDate,uid)));
  
}
