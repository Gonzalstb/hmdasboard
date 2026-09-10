/**
 * Acción `postpone_billing_ticket`.
 *
 * Aplaza el mes de invoice de un seguimiento.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),invoiceMonth=text(payload.invoiceMonth),monthPattern=/^\d{4}-(0[1-9]|1[0-2])$/,item=await db.prepare("SELECT invoice_month invoiceMonth,status FROM billing_carryovers WHERE id=? AND user_id=?").bind(id,uid).first();
    if(!item)throw new Error("El seguimiento de facturación ya no existe.");
    if(item.status==="invoiced")throw new Error("Este ticket ya está incluido en el invoice.");
    if(!monthPattern.test(invoiceMonth)||invoiceMonth<=String(item.invoiceMonth))throw new Error("El nuevo mes debe ser posterior al mes de facturación actual.");
    await db.prepare("UPDATE billing_carryovers SET invoice_month=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?").bind(invoiceMonth,id,uid).run();
  
}
