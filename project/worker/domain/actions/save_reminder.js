/**
 * Acción `save_reminder`.
 *
 * Crea un recordatorio con fecha.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, madridDateKey } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const content=text(payload.content),dueDate=text(payload.dueDate),today=madridDateKey();
    if(!content)throw new Error("Escribe qué quieres recordar.");
    if(!/^\d{4}-\d{2}-\d{2}$/.test(dueDate))throw new Error("Elige una fecha válida.");
    if(dueDate<today)throw new Error("No puedes crear un recordatorio en una fecha pasada.");
    await db.prepare("INSERT INTO reminders(content,due_date,user_id) VALUES(?,?,?)").bind(content,dueDate,uid).run();
  
}
