/**
 * Acción `save_agenda_task`.
 *
 * Crea o actualiza una tarea de la agenda diaria.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";
import { validateAgendaLink } from "../links.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),taskDate=text(payload.taskDate),content=text(payload.content).slice(0,1200),linkType=text(payload.linkType),linkId=number(payload.linkId),datePattern=/^\d{4}-\d{2}-\d{2}$/;
    if(!content)throw new Error("Escribe la tarea que quieres añadir.");
    if(!datePattern.test(taskDate))throw new Error("Elige un día válido para la tarea.");
    const validLinkId=await validateAgendaLink(db,linkType,linkId,uid);
    if(id){
      const current=await db.prepare("SELECT task_date taskDate FROM agenda_tasks WHERE id=? AND user_id=?").bind(id,uid).first();
      if(!current)throw new Error("La tarea de agenda ya no existe.");
      let position;
      if(String(current.taskDate)!==taskDate){const max=await db.prepare("SELECT COALESCE(MAX(position),-1)+1 position FROM agenda_tasks WHERE task_date=? AND user_id=?").bind(taskDate,uid).first();position=Number(max?.position)||0}
      await db.prepare("UPDATE agenda_tasks SET task_date=?,content=?,position=COALESCE(?,position),link_type=?,link_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?").bind(taskDate,content,position??null,linkType,validLinkId,id,uid).run();
    }else{
      const max=await db.prepare("SELECT COALESCE(MAX(position),-1)+1 position FROM agenda_tasks WHERE task_date=? AND user_id=?").bind(taskDate,uid).first();
      await db.prepare("INSERT INTO agenda_tasks(task_date,content,position,link_type,link_id,subtasks,user_id) VALUES(?,?,?,?,?,'[]',?)").bind(taskDate,content,Number(max?.position)||0,linkType,validLinkId,uid).run();
    }
  
}
