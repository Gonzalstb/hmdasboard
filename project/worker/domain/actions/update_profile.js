/**
 * Acción `update_profile`.
 *
 * Actualiza email y nombre del usuario autenticado.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text } from "../../lib/values.js";
import { normalizeEmail, validEmail } from "../../auth/identity.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const email=normalizeEmail(payload.email),displayName=text(payload.name).slice(0,80);
    if(!displayName)throw new Error("Escribe un nombre.");
    if(!validEmail(email))throw new Error("Escribe un correo válido.");
    const taken=await db.prepare("SELECT id FROM users WHERE email=? AND id<>?").bind(email,uid).first();
    if(taken)throw new Error("Ese correo ya está registrado.");
    await db.prepare("UPDATE users SET email=?,name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(email,displayName,uid).run();
  
}
