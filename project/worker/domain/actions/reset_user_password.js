/**
 * Acción `reset_user_password`.
 *
 * El superadmin asigna una nueva contraseña y cierra las sesiones del usuario.
 */

import { resetManagedPassword } from "../../auth/users.js";

export async function handle(db, payload, filesBucket, uid, result) {
    await resetManagedPassword(db, uid, payload);
}
