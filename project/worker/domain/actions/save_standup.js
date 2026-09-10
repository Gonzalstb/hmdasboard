/**
 * Acción `save_standup`.
 *
 * Crea o actualiza una guía de standup y sus ítems.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number, madridDateKey } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),title=text(payload.title),standupDate=text(payload.standupDate),today=madridDateKey();
    const allowedSections=new Set(['points','highlights','deployed_bdonline','pending_bdonline']),items=Array.isArray(payload.items)?payload.items.map(item=>({section:allowedSections.has(item?.section)?item.section:'points',content:text(item?.content),ticketKey:text(item?.ticketKey).toUpperCase(),ticketTitle:text(item?.ticketTitle),isDone:item?.isDone?1:0})).filter(item=>item.content||(item.ticketKey&&item.ticketTitle)).slice(0,100):[];
    if(!title)throw new Error("Escribe un título para la guía.");
    if(!/^\d{4}-\d{2}-\d{2}$/.test(standupDate))throw new Error("Elige una fecha válida para el standup.");
    if(!items.length)throw new Error("Añade al menos un punto, destacado o ticket de despliegue.");
    if(id){
      const guide=await db.prepare("SELECT standup_date standupDate FROM standup_guides WHERE id=? AND user_id=?").bind(id,uid).first();
      if(!guide)throw new Error("La guía ya no existe.");
      if(String(guide.standupDate)<today)throw new Error("Las guías archivadas son de solo lectura. Puedes duplicarla.");
      if(standupDate<today)throw new Error("No puedes mover una guía a una fecha pasada.");
      await db.prepare("UPDATE standup_guides SET title=?,standup_date=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?").bind(title,standupDate,id,uid).run();
    }else{
      if(standupDate<today)throw new Error("No puedes crear una guía con una fecha pasada.");
      const row=await db.prepare("INSERT INTO standup_guides(title,standup_date,user_id) VALUES(?,?,?) RETURNING id").bind(title,standupDate,uid).first();
      payload.id=row.id;
    }
    const guideId=number(payload.id),positions={points:0,highlights:0,deployed_bdonline:0,pending_bdonline:0};
    await db.batch([db.prepare("DELETE FROM standup_items WHERE guide_id=?").bind(guideId),...items.map(item=>db.prepare("INSERT INTO standup_items(guide_id,section,position,content,ticket_key,ticket_title,is_done) VALUES(?,?,?,?,?,?,?)").bind(guideId,item.section,positions[item.section]++,item.content,item.ticketKey,item.ticketTitle,item.isDone))]);
  
}
