/**
 * Acción `copy_agenda_tasks`.
 *
 * Copia tareas pendientes de un día a otro.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, idList } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const fromDate=text(payload.fromDate),toDate=text(payload.toDate),ids=idList(payload.ids).slice(0,300);
    if(!/^\d{4}-\d{2}-\d{2}$/.test(fromDate)||!/^\d{4}-\d{2}-\d{2}$/.test(toDate)||!ids.length||fromDate===toDate)throw new Error("No se pudieron copiar las tareas pendientes.");
    const placeholders=ids.map(()=>"?").join(","),sourceRows=(await db.prepare("SELECT id,content,link_type linkType,link_id linkId,subtasks FROM agenda_tasks WHERE user_id=? AND task_date=? AND is_done=0 AND id IN ("+placeholders+") ORDER BY position,id").bind(uid,fromDate,...ids).all()).results;
    if(sourceRows.length!==ids.length)throw new Error("Alguna tarea pendiente ya no pertenece al día anterior.");
    const max=await db.prepare("SELECT COALESCE(MAX(position),-1)+1 position FROM agenda_tasks WHERE task_date=? AND user_id=?").bind(toDate,uid).first();
    let position=Number(max?.position)||0;
    const statements=[];
    for(const row of sourceRows){
      let copiedSubtasks=[];try{copiedSubtasks=JSON.parse(String(row.subtasks||"[]"))}catch{}if(!Array.isArray(copiedSubtasks))copiedSubtasks=[];copiedSubtasks=copiedSubtasks.map(x=>({id:Number(x.id),content:text(x.content).slice(0,500),isDone:false})).filter(x=>x.content);
      statements.push(db.prepare("INSERT OR IGNORE INTO agenda_tasks(task_date,content,position,is_done,link_type,link_id,copied_from_id,subtasks,user_id) VALUES(?,?,?,0,?,?,?,?,?)").bind(toDate,String(row.content),position,String(row.linkType||''),row.linkId||null,Number(row.id),JSON.stringify(copiedSubtasks),uid));
      position++;
    }
    await db.batch(statements);
  
}
