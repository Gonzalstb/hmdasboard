/**
 * Crea tablas de usuarios/sesiones, añade `user_id` a las entidades
 * y asigna el histórico existente al usuario semilla.
 *
 * El semilla llega desde `.dev.vars` / secrets (`seedUserFromEnv`),
 * nunca hardcodeado en el repositorio.
 */

import { hashPassword } from "../../auth/password.js";
import { addUserIdColumn, seedWorkspace } from "../workspace.js";

export async function migrateUsers(db, seedUser) {
  const key = "users_v1";
  await db.batch([
    db.prepare("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT '', password_hash TEXT NOT NULL, password_salt TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'user', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE TABLE IF NOT EXISTS sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token)"),
    db.prepare("CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON sessions(expires_at)"),
  ]);
  for (const table of ["statuses", "labels", "attention_markers", "tickets", "reminders", "permanent_notes", "standup_guides", "agenda_tasks", "billing_carryovers"]) {
    await addUserIdColumn(db, table);
  }
  const applied = await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(key).first();
  let owner = null;
  if (seedUser?.email) {
    owner = await db.prepare("SELECT id FROM users WHERE email=?").bind(seedUser.email).first();
    if (!owner) {
      const hashed = await hashPassword(seedUser.password);
      owner = await db.prepare("INSERT INTO users(email,name,password_hash,password_salt) VALUES(?,?,?,?) RETURNING id")
        .bind(seedUser.email, seedUser.name, hashed.hash, hashed.salt).first();
    }
  } else {
    owner = await db.prepare("SELECT id FROM users ORDER BY id LIMIT 1").first();
  }
  if (!owner) {
    if (applied?.value === "done") return;
    throw new Error("Configura SEED_USER_EMAIL, SEED_USER_NAME y SEED_USER_PASSWORD en project/.dev.vars");
  }
  const userId = Number(owner.id);
  if (applied?.value !== "done") {
    for (const table of ["statuses", "labels", "attention_markers", "tickets", "reminders", "permanent_notes", "standup_guides", "agenda_tasks", "billing_carryovers"]) {
      await db.prepare("UPDATE " + table + " SET user_id=? WHERE user_id IS NULL").bind(userId).run();
    }
    await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(key, "done").run();
  }
  await seedWorkspace(db, userId);
}
