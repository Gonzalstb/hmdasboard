/**
 * Añade el rol de usuario, promociona al propietario actual a superadmin
 * e indexa `user_id` en las tablas de workspace.
 */

import { SUPERADMIN } from "../../auth/access.js";

export async function migrateUserRoles(db, seedUser) {
  const key = "users_roles_v1";
  const columns = (await db.prepare("PRAGMA table_info(users)").all()).results;
  if (!columns.some(column => column.name === "role")) {
    await db.prepare("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'").run();
  }
  for (const table of ["statuses", "labels", "attention_markers", "tickets", "reminders", "permanent_notes", "standup_guides", "agenda_tasks", "billing_carryovers"]) {
    await db.prepare("CREATE INDEX IF NOT EXISTS idx_" + table + "_user_id ON " + table + "(user_id)").run();
  }
  const applied = await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(key).first();
  const hasSuperadmin = await db.prepare("SELECT id FROM users WHERE role=? ORDER BY id LIMIT 1").bind(SUPERADMIN).first();
  if (!hasSuperadmin) {
    let owner = null;
    if (seedUser?.email) {
      owner = await db.prepare("SELECT id FROM users WHERE email=?").bind(seedUser.email).first();
    }
    if (!owner) owner = await db.prepare("SELECT id FROM users ORDER BY id LIMIT 1").first();
    if (owner) {
      await db.prepare("UPDATE users SET role=? WHERE id=?").bind(SUPERADMIN, Number(owner.id)).run();
    }
  }
  if (applied?.value !== "done") {
    await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(key, "done").run();
  }
}
