/**
 * Columna JSON `subtasks` en tareas de agenda.
 */

export async function migrateAgendaTasksV3(db){const key="agenda_tasks_v3",done=await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(key).first();if(done?.value==="done")return;const cols=(await db.prepare("PRAGMA table_info(agenda_tasks)").all()).results;if(!cols.some(c=>c.name==="subtasks"))await db.prepare("ALTER TABLE agenda_tasks ADD COLUMN subtasks TEXT NOT NULL DEFAULT '[]'").run();await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(key,"done").run()}
