/**
 * Acción `delete_permanent_note`.
 *
 * Borra una nota y sus documentos en R2.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),note=await db.prepare("SELECT id FROM permanent_notes WHERE id=? AND user_id=?").bind(id,uid).first();
    if(!note)throw new Error("La nota permanente ya no existe.");
    const attachments=(await db.prepare("SELECT storage_key storageKey FROM permanent_note_attachments WHERE note_id=?").bind(id).all()).results;
    if(attachments.length&&!filesBucket)throw new Error("El almacenamiento de documentos no está disponible.");
    if(attachments.length)await Promise.all(attachments.map(file=>filesBucket.delete(String(file.storageKey))));
    await db.batch([db.prepare("DELETE FROM permanent_note_attachments WHERE note_id=?").bind(id),db.prepare("DELETE FROM permanent_notes WHERE id=?").bind(id)]);
  
}
