/**
 * Tabla de agenda diaria (`agenda_tasks_v1`).
 */

export async function migrateAgendaTasks(db) {
  const migrationKey="agenda_tasks_v1",applied=await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(migrationKey).first();
  if(applied?.value==="done")return;
  await db.prepare("CREATE TABLE IF NOT EXISTS agenda_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_date TEXT NOT NULL, content TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0, is_done INTEGER NOT NULL DEFAULT 0, link_type TEXT NOT NULL DEFAULT '', link_id INTEGER, copied_from_id INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT)").run();
  await db.prepare("CREATE INDEX IF NOT EXISTS idx_agenda_tasks_date_position ON agenda_tasks(task_date,position,id)").run();
  await db.prepare("PRAGMA optimize").run();
  await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(migrationKey,"done").run();
}
