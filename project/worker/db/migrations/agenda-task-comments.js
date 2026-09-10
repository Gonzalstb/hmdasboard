/**
 * Comentarios de tareas/subtareas de agenda.
 */

export async function migrateAgendaTaskComments(db){const key="agenda_task_comments_v1",done=await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(key).first();if(done?.value==="done")return;await db.prepare("CREATE TABLE IF NOT EXISTS agenda_task_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, subtask_id INTEGER NOT NULL DEFAULT 0, content TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)").run();await db.prepare("CREATE INDEX IF NOT EXISTS idx_agenda_task_comments_target ON agenda_task_comments(task_id,subtask_id,created_at,id)").run();await db.prepare("PRAGMA optimize").run();await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(key,"done").run()}
