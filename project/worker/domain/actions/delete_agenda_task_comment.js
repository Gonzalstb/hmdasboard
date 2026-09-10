/**
 * Acción `delete_agenda_task_comment`.
 *
 * Borra un comentario de agenda.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id);
    if(!await db.prepare("SELECT c.id FROM agenda_task_comments c JOIN agenda_tasks a ON a.id=c.task_id WHERE c.id=? AND a.user_id=?").bind(id,uid).first())throw new Error("El comentario ya no existe.");
    await db.prepare("DELETE FROM agenda_task_comments WHERE id=?").bind(id).run();
  
}
