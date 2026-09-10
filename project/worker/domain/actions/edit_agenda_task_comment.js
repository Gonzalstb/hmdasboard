/**
 * Acción `edit_agenda_task_comment`.
 *
 * Edita un comentario de agenda.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),content=text(payload.content).slice(0,1200);
    if(!content)throw new Error("El comentario no puede estar vacío.");
    if(!await db.prepare("SELECT c.id FROM agenda_task_comments c JOIN agenda_tasks a ON a.id=c.task_id WHERE c.id=? AND a.user_id=?").bind(id,uid).first())throw new Error("El comentario ya no existe.");
    await db.prepare("UPDATE agenda_task_comments SET content=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(content,id).run();
  
}
