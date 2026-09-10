/**
 * Migración `workflow_statuses_v2`.
 * Unifica nombres duplicados y alinea colores/posiciones por defecto.
 */

import { defaultStatuses } from "../../config/default-statuses.js";
import { statusKey } from "../status-key.js";

export async function migrateWorkflowStatuses(db) {
  const migrationKey = "workflow_statuses_v2";
  const applied = await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(migrationKey).first();
  if (applied?.value === "done") return;

  await db.prepare("UPDATE statuses SET name='Schedule' WHERE lower(name)='scheduled' AND NOT EXISTS (SELECT 1 FROM statuses WHERE lower(name)='schedule')").run();
  for (const [name, statusColor, isDone, position] of defaultStatuses) {
    await db.prepare("INSERT INTO statuses(name,color,is_done,position) SELECT ?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM statuses WHERE lower(name)=lower(?))")
      .bind(name, statusColor, isDone, position, name).run();
    await db.prepare("UPDATE statuses SET name=?,color=?,is_done=?,position=? WHERE lower(name)=lower(?)")
      .bind(name, statusColor, isDone, position, name).run();
  }

  const rows = (await db.prepare("SELECT id,name FROM statuses ORDER BY id").all()).results;
  const canonicalIds = new Map();
  for (const row of rows) {
    const key = statusKey(row.name);
    if (defaultStatuses.some(([name]) => statusKey(name) === key) && !canonicalIds.has(key)) canonicalIds.set(key, Number(row.id));
  }
  const openId = canonicalIds.get(statusKey("Open"));
  const cleanup = [];
  for (const row of rows) {
    const key = statusKey(row.name), canonicalId = canonicalIds.get(key);
    if (canonicalId === Number(row.id)) continue;
    cleanup.push(db.prepare("UPDATE tickets SET status_id=?,updated_at=CURRENT_TIMESTAMP WHERE status_id=?").bind(canonicalId || openId, Number(row.id)));
    cleanup.push(db.prepare("DELETE FROM statuses WHERE id=?").bind(Number(row.id)));
  }
  cleanup.push(db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(migrationKey, "done"));
  await db.batch(cleanup);
}
