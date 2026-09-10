/**
 * Acción `toggle_standup_item`.
 *
 * Marca o desmarca un punto de standup.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number, madridDateKey } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),item=await db.prepare("SELECT si.id,sg.standup_date standupDate FROM standup_items si JOIN standup_guides sg ON sg.id=si.guide_id WHERE si.id=? AND sg.user_id=?").bind(id,uid).first();
    if(!item)throw new Error("Este punto ya no existe.");
    if(String(item.standupDate)<madridDateKey())throw new Error("Las guías archivadas son de solo lectura.");
    await db.prepare("UPDATE standup_items SET is_done=CASE WHEN is_done=1 THEN 0 ELSE 1 END WHERE id=?").bind(id).run();
  
}
