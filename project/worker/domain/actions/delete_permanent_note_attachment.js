/**
 * Acción `delete_permanent_note_attachment`.
 *
 * Borra un documento concreto de una nota.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),attachment=await db.prepare("SELECT a.storage_key storageKey FROM permanent_note_attachments a JOIN permanent_notes n ON n.id=a.note_id WHERE a.id=? AND n.user_id=?").bind(id,uid).first();
    if(!attachment)throw new Error("El documento ya no existe.");
    if(!filesBucket)throw new Error("El almacenamiento de documentos no está disponible.");
    await filesBucket.delete(String(attachment.storageKey));
    await db.prepare("DELETE FROM permanent_note_attachments WHERE id=?").bind(id).run();
  
}
