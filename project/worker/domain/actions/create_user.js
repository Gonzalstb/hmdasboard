/**
 * Acción `create_user`.
 *
 * Alta de un usuario nuevo con workspace vacío (estados por defecto).
 * Solo el superadmin puede crear cuentas.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { createUser } from "../../auth/users.js";
import { requireSuperadmin } from "../../auth/access.js";

export async function handle(db, payload, filesBucket, uid, result) {
    await requireSuperadmin(db, uid);
    await createUser(db,{email:payload.email,name:payload.name,password:payload.password,role:payload.role,seed:true});
}
