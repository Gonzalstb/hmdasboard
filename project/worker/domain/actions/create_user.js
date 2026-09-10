/**
 * Acción `create_user`.
 *
 * Alta de un usuario nuevo con workspace vacío (estados por defecto).
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { createUser } from "../../auth/users.js";

export async function handle(db, payload, filesBucket, uid, result) {
    await createUser(db,{email:payload.email,name:payload.name,password:payload.password,seed:true});
  
}
