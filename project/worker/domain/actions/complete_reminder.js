/**
 * Acción `complete_reminder`.
 *
 * Marca un recordatorio como hecho (solo si la fecha ya llegó).
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number, madridDateKey } from "../../lib/values.js";
import { EARLY_RETURN } from "./early-return.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),reminder=await db.prepare("SELECT due_date dueDate,is_done isDone FROM reminders WHERE id=? AND user_id=?").bind(id,uid).first();
    if(!reminder)throw new Error("El recordatorio ya no existe.");
    if(reminder.isDone)return EARLY_RETURN;
    if(String(reminder.dueDate)>madridDateKey())throw new Error("Este recordatorio todavía no puede marcarse como hecho.");
    await db.prepare("UPDATE reminders SET is_done=1,completed_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?").bind(id,uid).run();
  
}
