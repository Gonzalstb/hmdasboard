/**
 * Acción `delete_status`.
 *
 * Borra un estado si ningún ticket lo usa.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const used=await db.prepare("SELECT COUNT(*) total FROM tickets WHERE status_id=? AND user_id=?").bind(number(payload.id),uid).first();if(Number(used.total))throw new Error("Este estado está siendo utilizado.");await db.prepare("DELETE FROM statuses WHERE id=? AND user_id=?").bind(number(payload.id),uid).run();
  
}
