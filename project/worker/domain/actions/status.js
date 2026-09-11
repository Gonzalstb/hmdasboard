/**
 * Acción `status`.
 *
 * Cambia el estado de un ticket y registra el historial.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";
import { EARLY_RETURN } from "./early-return.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),statusId=number(payload.statusId),targetStatus=await db.prepare("SELECT id,name,color,CASE WHEN lower(name)='done' THEN 1 ELSE 0 END isDone,CASE WHEN lower(name)='cancelled' THEN 1 ELSE 0 END isCancelled FROM statuses WHERE id=? AND user_id=?").bind(statusId,uid).first();
    if(!targetStatus)throw new Error("El estado seleccionado ya no existe.");
    const currentStatus=await db.prepare("SELECT s.id statusId,s.name statusName,s.color statusColor FROM tickets t JOIN statuses s ON s.id=t.status_id WHERE t.id=? AND t.user_id=?").bind(id,uid).first();
    if(!currentStatus)throw new Error("El ticket ya no existe.");
    if(Number(currentStatus.statusId)===statusId)return EARLY_RETURN;
    await db.batch([db.prepare("UPDATE tickets SET status_id=?,completed_at=CASE WHEN ?=1 THEN CASE WHEN status_id=? AND completed_at IS NOT NULL THEN completed_at ELSE CURRENT_TIMESTAMP END ELSE NULL END,cancelled_at=CASE WHEN ?=1 THEN CASE WHEN status_id=? AND cancelled_at IS NOT NULL THEN cancelled_at ELSE CURRENT_TIMESTAMP END ELSE NULL END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?").bind(statusId,Number(targetStatus.isDone),statusId,Number(targetStatus.isCancelled),statusId,id,uid),db.prepare("INSERT INTO ticket_status_history(ticket_id,from_status_id,from_status_name,from_status_color,to_status_id,to_status_name,to_status_color,event_type) VALUES(?,?,?,?,?,?,?,'change')").bind(id,Number(currentStatus.statusId),String(currentStatus.statusName),String(currentStatus.statusColor),statusId,String(targetStatus.name),String(targetStatus.color))]);
  
}
