/**
 * Acción `set_billing_invoiced`.
 *
 * Marca seguimientos como facturados o pendientes.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { idList } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const ids=idList(payload.ids).slice(0,200),invoiced=payload.invoiced!==false;
    if(!ids.length)throw new Error("Selecciona al menos un ticket.");
    const placeholders=ids.map(()=>"?").join(",");
    await db.prepare("UPDATE billing_carryovers SET status=?,invoiced_at="+(invoiced?"COALESCE(invoiced_at,CURRENT_TIMESTAMP)":"NULL")+",updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND id IN ("+placeholders+")").bind(invoiced?"invoiced":"pending",uid,...ids).run();
  
}
