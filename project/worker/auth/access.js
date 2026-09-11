/**
 * Roles y autorización de gestión de usuarios.
 *
 * `superadmin` administra cuentas. `user` solo accede a su workspace.
 */

export const SUPERADMIN = "superadmin";
export const USER = "user";

export function normalizeRole(value) {
  return String(value || "") === SUPERADMIN ? SUPERADMIN : USER;
}

export function isSuperadmin(user) {
  return normalizeRole(user?.role) === SUPERADMIN;
}

export function publicRole(user) {
  return normalizeRole(user?.role);
}

export class ForbiddenError extends Error {
  constructor(message = "No tienes permiso para gestionar usuarios.") {
    super(message);
    this.name = "ForbiddenError";
    this.status = 403;
  }
}

export async function findUser(db, userId) {
  return db.prepare("SELECT id,email,name,role,created_at createdAt FROM users WHERE id=?").bind(userId).first();
}

export async function requireSuperadmin(db, userId) {
  const user = await findUser(db, userId);
  if (!user || !isSuperadmin(user)) throw new ForbiddenError();
  return user;
}

export async function superadminCount(db, exceptId) {
  const sql = "SELECT COUNT(*) total FROM users WHERE role=?";
  const row = exceptId
    ? await db.prepare(sql + " AND id<>?").bind(SUPERADMIN, exceptId).first()
    : await db.prepare(sql).bind(SUPERADMIN).first();
  return Number(row?.total || 0);
}

export function assertPassword(password) {
  const value = String(password || "");
  if (value.length < 6) throw new Error("La contraseña debe tener al menos 6 caracteres.");
  if (value.length > 200) throw new Error("La contraseña es demasiado larga.");
}
