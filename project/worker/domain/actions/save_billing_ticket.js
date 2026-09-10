/**
 * Acción `save_billing_ticket`.
 *
 * Crea o actualiza un seguimiento de facturación.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),ticketId=number(payload.ticketId),workedMonth=text(payload.workedMonth),invoiceMonth=text(payload.invoiceMonth),minutes=Math.floor(number(payload.minutes)),projectValue=text(payload.project).trim().toUpperCase(),project=projectValue==="BD"||projectValue==="HM"?projectValue:"",notes=text(payload.notes).slice(0,3000),monthPattern=/^\d{4}-(0[1-9]|1[0-2])$/;
    if(!ticketId)throw new Error("Selecciona un ticket.");
    if(!monthPattern.test(workedMonth)||!monthPattern.test(invoiceMonth))throw new Error("Selecciona meses válidos.");
    if(invoiceMonth<=workedMonth)throw new Error("El mes del invoice debe ser posterior al mes en el que se trabajó el ticket.");
    if(minutes<1||minutes>600000)throw new Error("Indica un tiempo trabajado válido.");
    if(projectValue&&projectValue!=="BD"&&projectValue!=="HM")throw new Error("Selecciona BD o HM como proyecto.");
    const ticket=await db.prepare("SELECT id,ticket_key ticketKey,title,jira_url jiraUrl FROM tickets WHERE id=? AND user_id=?").bind(ticketId,uid).first();
    if(!ticket)throw new Error("El ticket seleccionado ya no existe.");
    const duplicate=await db.prepare("SELECT id FROM billing_carryovers WHERE user_id=? AND ticket_id=? AND worked_month=? AND id<>? LIMIT 1").bind(uid,ticketId,workedMonth,id||0).first();
    if(duplicate)throw new Error("Este ticket ya tiene un seguimiento para el mes trabajado seleccionado.");
    if(id){
      if(!await db.prepare("SELECT id FROM billing_carryovers WHERE id=? AND user_id=?").bind(id,uid).first())throw new Error("El seguimiento de facturación ya no existe.");
      await db.prepare("UPDATE billing_carryovers SET ticket_id=?,ticket_key=?,ticket_title=?,jira_url=?,worked_month=?,invoice_month=?,minutes=?,project=?,notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?").bind(ticketId,String(ticket.ticketKey),String(ticket.title),String(ticket.jiraUrl||''),workedMonth,invoiceMonth,minutes,project,notes,id,uid).run();
    }else{
      await db.prepare("INSERT INTO billing_carryovers(ticket_id,ticket_key,ticket_title,jira_url,worked_month,invoice_month,minutes,project,notes,user_id) VALUES(?,?,?,?,?,?,?,?,?,?)").bind(ticketId,String(ticket.ticketKey),String(ticket.title),String(ticket.jiraUrl||''),workedMonth,invoiceMonth,minutes,project,notes,uid).run();
    }
  
}
