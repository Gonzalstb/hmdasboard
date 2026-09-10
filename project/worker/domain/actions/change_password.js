/**
 * Acción `change_password`.
 *
 * Cambia la contraseña, invalida sesiones y emite una nueva cookie.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { hashPassword, verifyPassword } from "../../auth/crypto.js";
import { createSession } from "../../auth/session.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const currentPassword=String(payload.currentPassword||""),newPassword=String(payload.newPassword||"");
    if(newPassword.length<6)throw new Error("La nueva contraseña debe tener al menos 6 caracteres.");
    const user=await db.prepare("SELECT password_hash passwordHash,password_salt passwordSalt FROM users WHERE id=?").bind(uid).first();
    if(!user||!(await verifyPassword(currentPassword,user.passwordHash,user.passwordSalt)))throw new Error("La contraseña actual no es correcta.");
    const hashed=await hashPassword(newPassword);
    await db.prepare("UPDATE users SET password_hash=?,password_salt=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(hashed.hash,hashed.salt,uid).run();
    await db.prepare("DELETE FROM sessions WHERE user_id=?").bind(uid).run();
    result.newSession=await createSession(db,uid);
  
}
