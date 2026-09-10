/**
 * Columna `copied_from_id` e índice único de copias entre días.
 */

export async function migrateAgendaTasksV2(db) {
  const migrationKey="agenda_tasks_v2",applied=await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(migrationKey).first();
  if(applied?.value==="done")return;
  const columns=(await db.prepare("PRAGMA table_info(agenda_tasks)").all()).results;
  if(!columns.some(column=>column.name==="copied_from_id"))await db.prepare("ALTER TABLE agenda_tasks ADD COLUMN copied_from_id INTEGER").run();
  await db.prepare("CREATE UNIQUE INDEX IF NOT EXISTS idx_agenda_tasks_copy_unique ON agenda_tasks(copied_from_id,task_date) WHERE copied_from_id IS NOT NULL").run();
  await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(migrationKey,"done").run();
}
