/**
 * Acción `delete_user`.
 *
 * El superadmin elimina un usuario y todo su workspace.
 */

import { deleteManagedUser } from "../../auth/users.js";

export async function handle(db, payload, filesBucket, uid, result) {
    await deleteManagedUser(db, filesBucket, uid, payload);
}
