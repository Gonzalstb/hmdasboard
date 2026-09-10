/**
 * Columnas `user_id` y semilla de estados para un workspace nuevo.
 */

import { defaultStatuses } from "../config/default-statuses.js";

export async function addUserIdColumn(db, table) {
  const columns = (await db.prepare("PRAGMA table_info(" + table + ")").all()).results;
  if (!columns.some(column => column.name === "user_id")) {
    await db.prepare("ALTER TABLE " + table + " ADD COLUMN user_id INTEGER").run();
  }
}

export async function seedWorkspace(db, userId) {
  const statusCount = await db.prepare("SELECT COUNT(*) total FROM statuses WHERE user_id=?").bind(userId).first();
  if (!Number(statusCount?.total)) {
    await db.batch(defaultStatuses.map(x => db.prepare("INSERT INTO statuses(name,color,is_done,position,user_id) VALUES(?,?,?,?,?)").bind(...x, userId)));
  }
}
