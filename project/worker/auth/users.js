/**
 * Alta de usuarios y rehash de credenciales.
 *
 * Depende de crypto (hash) y del semillado de estados por workspace.
 */

import { text } from "../lib/text.js";
import { hashPassword } from "./password.js";
import { normalizeEmail, validEmail, publicUser } from "./identity.js";
import { seedWorkspace } from "../db/workspace.js";

export async function createUser(db, { email, name, password, seed = true }) {
  const normalized = normalizeEmail(email);
  if (!validEmail(normalized)) throw new Error("Escribe un correo válido.");
  if (!text(name)) throw new Error("Escribe un nombre.");
  if (String(password || "").length < 6) throw new Error("La contraseña debe tener al menos 6 caracteres.");
  const existing = await db.prepare("SELECT id FROM users WHERE email=?").bind(normalized).first();
  if (existing) throw new Error("Ese correo ya está registrado.");
  const hashed = await hashPassword(password);
  const row = await db.prepare("INSERT INTO users(email,name,password_hash,password_salt) VALUES(?,?,?,?) RETURNING id,email,name,created_at createdAt")
    .bind(normalized, text(name).slice(0, 80), hashed.hash, hashed.salt).first();
  if (seed) await seedWorkspace(db, Number(row.id));
  return publicUser(row);
}
