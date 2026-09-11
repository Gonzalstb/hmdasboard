/**
 * Alta, edición, contraseña y baja de usuarios (solo superadmin).
 */

import { text, number } from "../lib/values.js";
import { hashPassword } from "./password.js";
import { normalizeEmail, validEmail, publicUser } from "./identity.js";
import { seedWorkspace } from "../db/workspace.js";
import { USER, normalizeRole, requireSuperadmin, findUser, isSuperadmin, superadminCount, assertPassword } from "./access.js";

export async function createUser(db, { email, name, password, role = USER, seed = true }) {
  const normalized = normalizeEmail(email);
  if (!validEmail(normalized)) throw new Error("Escribe un correo válido.");
  if (!text(name)) throw new Error("Escribe un nombre.");
  assertPassword(password);
  const existing = await db.prepare("SELECT id FROM users WHERE email=?").bind(normalized).first();
  if (existing) throw new Error("Ese correo ya está registrado.");
  const hashed = await hashPassword(password);
  const row = await db.prepare("INSERT INTO users(email,name,password_hash,password_salt,role) VALUES(?,?,?,?,?) RETURNING id,email,name,role,created_at createdAt")
    .bind(normalized, text(name).slice(0, 80), hashed.hash, hashed.salt, normalizeRole(role)).first();
  if (seed) await seedWorkspace(db, Number(row.id));
  return publicUser(row);
}

export async function updateManagedUser(db, actorId, payload) {
  await requireSuperadmin(db, actorId);
  const id = number(payload.id);
  const target = await findUser(db, id);
  if (!target) throw new Error("El usuario ya no existe.");
  const email = normalizeEmail(payload.email);
  const name = text(payload.name).slice(0, 80);
  const role = normalizeRole(payload.role ?? target.role);
  if (!name) throw new Error("Escribe un nombre.");
  if (!validEmail(email)) throw new Error("Escribe un correo válido.");
  const taken = await db.prepare("SELECT id FROM users WHERE email=? AND id<>?").bind(email, id).first();
  if (taken) throw new Error("Ese correo ya está registrado.");
  if (isSuperadmin(target) && role !== "superadmin" && await superadminCount(db, id) === 0) {
    throw new Error("Debe quedar al menos un superusuario.");
  }
  await db.prepare("UPDATE users SET email=?,name=?,role=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(email, name, role, id).run();
}

export async function resetManagedPassword(db, actorId, payload) {
  await requireSuperadmin(db, actorId);
  const id = number(payload.id);
  if (!await findUser(db, id)) throw new Error("El usuario ya no existe.");
  assertPassword(payload.password);
  const hashed = await hashPassword(payload.password);
  await db.prepare("UPDATE users SET password_hash=?,password_salt=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(hashed.hash, hashed.salt, id).run();
  await db.prepare("DELETE FROM sessions WHERE user_id=?").bind(id).run();
}

export async function deleteManagedUser(db, filesBucket, actorId, payload) {
  await requireSuperadmin(db, actorId);
  const id = number(payload.id);
  const target = await findUser(db, id);
  if (!target) throw new Error("El usuario ya no existe.");
  if (id === Number(actorId)) throw new Error("No puedes eliminar tu propio usuario.");
  if (isSuperadmin(target) && await superadminCount(db, id) === 0) {
    throw new Error("Debe quedar al menos un superusuario.");
  }
  const attachments = (await db.prepare("SELECT a.storage_key storageKey FROM permanent_note_attachments a JOIN permanent_notes n ON n.id=a.note_id WHERE n.user_id=?").bind(id).all()).results;
  if (attachments.length && filesBucket) {
    await Promise.all(attachments.map(file => filesBucket.delete(String(file.storageKey))));
  }
  await db.batch([
    db.prepare("DELETE FROM ticket_labels WHERE ticket_id IN (SELECT id FROM tickets WHERE user_id=?)").bind(id),
    db.prepare("DELETE FROM comments WHERE ticket_id IN (SELECT id FROM tickets WHERE user_id=?)").bind(id),
    db.prepare("DELETE FROM ticket_status_history WHERE ticket_id IN (SELECT id FROM tickets WHERE user_id=?)").bind(id),
    db.prepare("DELETE FROM agenda_task_comments WHERE task_id IN (SELECT id FROM agenda_tasks WHERE user_id=?)").bind(id),
    db.prepare("DELETE FROM standup_items WHERE guide_id IN (SELECT id FROM standup_guides WHERE user_id=?)").bind(id),
    db.prepare("DELETE FROM permanent_note_attachments WHERE note_id IN (SELECT id FROM permanent_notes WHERE user_id=?)").bind(id),
    db.prepare("DELETE FROM tickets WHERE user_id=?").bind(id),
    db.prepare("DELETE FROM reminders WHERE user_id=?").bind(id),
    db.prepare("DELETE FROM permanent_notes WHERE user_id=?").bind(id),
    db.prepare("DELETE FROM standup_guides WHERE user_id=?").bind(id),
    db.prepare("DELETE FROM agenda_tasks WHERE user_id=?").bind(id),
    db.prepare("DELETE FROM billing_carryovers WHERE user_id=?").bind(id),
    db.prepare("DELETE FROM statuses WHERE user_id=?").bind(id),
    db.prepare("DELETE FROM labels WHERE user_id=?").bind(id),
    db.prepare("DELETE FROM attention_markers WHERE user_id=?").bind(id),
    db.prepare("DELETE FROM sessions WHERE user_id=?").bind(id),
    db.prepare("DELETE FROM users WHERE id=?").bind(id),
  ]);
}
