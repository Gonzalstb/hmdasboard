/**
 * Acción `update_user`.
 *
 * El superadmin edita nombre, correo y rol de cualquier cuenta.
 */

import { updateManagedUser } from "../../auth/users.js";

export async function handle(db, payload, filesBucket, uid, result) {
    await updateManagedUser(db, uid, payload);
}
