/**
 * Acción `save_ticket`.
 *
 * Crea o actualiza un ticket, labels e historial de estado.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number, idList } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id = number(payload.id), key = text(payload.ticketKey).toUpperCase(), title = text(payload.title), statusId = number(payload.statusId),attentionMarkerId=number(payload.attentionMarkerId);
    if (!key || !title || !statusId) throw new Error("La clave, el título y el estado son obligatorios.");
    const duplicateTicket=await db.prepare("SELECT id FROM tickets WHERE user_id=? AND upper(trim(ticket_key))=? AND id<>? LIMIT 1").bind(uid,key,id||0).first();
    if(duplicateTicket)throw new Error("El código "+key+" ya pertenece a otro ticket. Cada ticket debe tener un código único y no se ha guardado.");
    const targetStatus=await db.prepare("SELECT id,name,color,CASE WHEN lower(name)='done' THEN 1 ELSE 0 END isDone,CASE WHEN lower(name)='cancelled' THEN 1 ELSE 0 END isCancelled FROM statuses WHERE id=? AND user_id=?").bind(statusId,uid).first();
    if(!targetStatus)throw new Error("El estado seleccionado ya no existe.");
    if(attentionMarkerId&&!await db.prepare("SELECT id FROM attention_markers WHERE id=? AND user_id=?").bind(attentionMarkerId,uid).first())throw new Error("El llamador seleccionado ya no existe.");
    if (id) {
      const currentStatus=await db.prepare("SELECT s.id statusId,s.name statusName,s.color statusColor FROM tickets t JOIN statuses s ON s.id=t.status_id WHERE t.id=? AND t.user_id=?").bind(id,uid).first();
      if(!currentStatus)throw new Error("El ticket ya no existe.");
      const updates=[db.prepare("UPDATE tickets SET ticket_key=?,title=?,summary=?,status_id=?,priority=?,next_action=?,attention_marker_id=?,due_date=?,jira_url=?,completed_at=CASE WHEN ?=1 THEN CASE WHEN status_id=? AND completed_at IS NOT NULL THEN completed_at ELSE CURRENT_TIMESTAMP END ELSE NULL END,cancelled_at=CASE WHEN ?=1 THEN CASE WHEN status_id=? AND cancelled_at IS NOT NULL THEN cancelled_at ELSE CURRENT_TIMESTAMP END ELSE NULL END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?")
        .bind(key,title,text(payload.summary),statusId,text(payload.priority)||"medium",text(payload.nextAction),attentionMarkerId||null,text(payload.dueDate)||null,text(payload.jiraUrl),Number(targetStatus.isDone),statusId,Number(targetStatus.isCancelled),statusId,id,uid)];
      if(Number(currentStatus.statusId)!==statusId)updates.push(db.prepare("INSERT INTO ticket_status_history(ticket_id,from_status_id,from_status_name,from_status_color,to_status_id,to_status_name,to_status_color,event_type) VALUES(?,?,?,?,?,?,?,'change')").bind(id,Number(currentStatus.statusId),String(currentStatus.statusName),String(currentStatus.statusColor),statusId,String(targetStatus.name),String(targetStatus.color)));
      await db.batch(updates);
    } else {
      const row = await db.prepare("INSERT INTO tickets(ticket_key,title,summary,status_id,priority,next_action,attention_marker_id,due_date,jira_url,user_id) SELECT ?,?,?,?,?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM tickets WHERE user_id=? AND upper(trim(ticket_key))=?) RETURNING id")
        .bind(key,title,text(payload.summary),statusId,text(payload.priority)||"medium",text(payload.nextAction),attentionMarkerId||null,text(payload.dueDate)||null,text(payload.jiraUrl),uid,uid,key).first();
      if(!row)throw new Error("El código "+key+" ya pertenece a otro ticket. Cada ticket debe tener un código único y no se ha creado.");
      payload.id = row.id;
      await db.batch([db.prepare("UPDATE tickets SET completed_at=CASE WHEN ?=1 THEN CURRENT_TIMESTAMP ELSE NULL END,cancelled_at=CASE WHEN ?=1 THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=? AND user_id=?").bind(Number(targetStatus.isDone),Number(targetStatus.isCancelled),number(row.id),uid),db.prepare("INSERT INTO ticket_status_history(ticket_id,to_status_id,to_status_name,to_status_color,event_type) VALUES(?,?,?,?,'created')").bind(number(row.id),statusId,String(targetStatus.name),String(targetStatus.color))]);
    }
    const ticketId = number(payload.id);
    await db.batch([db.prepare("DELETE FROM ticket_labels WHERE ticket_id=?").bind(ticketId),...idList(payload.labelIds).map(labelId=>db.prepare("INSERT OR IGNORE INTO ticket_labels(ticket_id,label_id) SELECT ?,id FROM labels WHERE id=? AND user_id=?").bind(ticketId,labelId,uid))]);
  
}
