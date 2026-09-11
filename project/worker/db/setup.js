/**
 * Bootstrap de esquema D1.
 *
 * Crea tablas si no existen, aplica migraciones en el mismo orden que
 * el monolito y memoíza el resultado por instancia de base (`WeakMap`)
 * para no repetir trabajo en el mismo isolate del Worker.
 */

import { defaultStatuses } from "../config/default-statuses.js";
import { migrateWorkflowStatuses } from "./migrations/workflow-statuses.js";
import { migrateTicketCompletion } from "./migrations/ticket-completion.js";
import { migrateTicketCancellation } from "./migrations/ticket-cancellation.js";
import { migrateTicketAvatars } from "./migrations/ticket-avatars.js";
import { migrateTicketFocus } from "./migrations/ticket-focus.js";
import { migrateStandupTicketDetails } from "./migrations/standup-ticket-details.js";
import { migrateStandupItemSections } from "./migrations/standup-item-sections.js";
import { migrateAttentionMarkers } from "./migrations/attention-markers.js";
import { migrateTicketStatusHistory } from "./migrations/ticket-status-history.js";
import { migratePermanentNoteArchive } from "./migrations/permanent-note-archive.js";
import { migratePermanentNoteAttachments } from "./migrations/permanent-note-attachments.js";
import { migrateBillingCarryovers } from "./migrations/billing-carryovers.js";
import { migrateAgendaTasks } from "./migrations/agenda-tasks.js";
import { migrateAgendaTasksV2 } from "./migrations/agenda-tasks-v2.js";
import { migrateAgendaTasksV3 } from "./migrations/agenda-tasks-v3.js";
import { migrateAgendaTaskComments } from "./migrations/agenda-task-comments.js";
import { migrateUsers } from "./migrations/users.js";
import { migrateUserRoles } from "./migrations/users-roles.js";
import { seedUserFromEnv } from "../config/seed-user.js";

export async function setup(db, env) {
  await db.batch([
    db.prepare("CREATE TABLE IF NOT EXISTS statuses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL, is_done INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0)"),
    db.prepare("CREATE TABLE IF NOT EXISTS labels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL)"),
    db.prepare("CREATE TABLE IF NOT EXISTS attention_markers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0)"),
    db.prepare("CREATE TABLE IF NOT EXISTS tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_key TEXT NOT NULL, title TEXT NOT NULL, summary TEXT NOT NULL DEFAULT '', status_id INTEGER NOT NULL, priority TEXT NOT NULL DEFAULT 'medium', next_action TEXT NOT NULL DEFAULT '', attention_marker_id INTEGER, avatar_key TEXT, is_focus INTEGER NOT NULL DEFAULT 0, due_date TEXT, jira_url TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT, cancelled_at TEXT)"),
    db.prepare("CREATE TABLE IF NOT EXISTS ticket_labels (ticket_id INTEGER NOT NULL, label_id INTEGER NOT NULL, PRIMARY KEY(ticket_id,label_id))"),
    db.prepare("CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, content TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE TABLE IF NOT EXISTS ticket_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, from_status_id INTEGER, from_status_name TEXT, from_status_color TEXT, to_status_id INTEGER NOT NULL, to_status_name TEXT NOT NULL, to_status_color TEXT NOT NULL, event_type TEXT NOT NULL DEFAULT 'change', changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE INDEX IF NOT EXISTS ticket_status_history_ticket_changed_idx ON ticket_status_history(ticket_id,changed_at DESC,id DESC)"),
    db.prepare("CREATE TABLE IF NOT EXISTS reminders (id INTEGER PRIMARY KEY AUTOINCREMENT, content TEXT NOT NULL, due_date TEXT NOT NULL, is_done INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT)"),
    db.prepare("CREATE TABLE IF NOT EXISTS permanent_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', content TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, archived_at TEXT)"),
    db.prepare("CREATE TABLE IF NOT EXISTS permanent_note_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, note_id INTEGER NOT NULL, storage_key TEXT NOT NULL UNIQUE, file_name TEXT NOT NULL, content_type TEXT NOT NULL, size_bytes INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE INDEX IF NOT EXISTS permanent_note_attachments_note_idx ON permanent_note_attachments(note_id,created_at,id)"),
    db.prepare("CREATE TABLE IF NOT EXISTS billing_carryovers (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, ticket_key TEXT NOT NULL DEFAULT '', ticket_title TEXT NOT NULL DEFAULT '', jira_url TEXT NOT NULL DEFAULT '', worked_month TEXT NOT NULL, invoice_month TEXT NOT NULL, minutes INTEGER NOT NULL DEFAULT 0, client TEXT NOT NULL DEFAULT '', project TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', defer_reason TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'pending', invoiced_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE UNIQUE INDEX IF NOT EXISTS idx_billing_carryovers_ticket_worked_unique ON billing_carryovers(ticket_id,worked_month)"),
    db.prepare("CREATE INDEX IF NOT EXISTS idx_billing_carryovers_status_invoice ON billing_carryovers(status,invoice_month)"),
    db.prepare("CREATE TABLE IF NOT EXISTS agenda_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_date TEXT NOT NULL, content TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0, is_done INTEGER NOT NULL DEFAULT 0, link_type TEXT NOT NULL DEFAULT '', link_id INTEGER, copied_from_id INTEGER, subtasks TEXT NOT NULL DEFAULT '[]', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT)"),
    db.prepare("CREATE INDEX IF NOT EXISTS idx_agenda_tasks_date_position ON agenda_tasks(task_date,position,id)"),
    db.prepare("CREATE TABLE IF NOT EXISTS agenda_task_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, subtask_id INTEGER NOT NULL DEFAULT 0, content TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE INDEX IF NOT EXISTS idx_agenda_task_comments_target ON agenda_task_comments(task_id,subtask_id,created_at,id)"),
    db.prepare("CREATE TABLE IF NOT EXISTS standup_guides (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', standup_date TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE TABLE IF NOT EXISTS standup_items (id INTEGER PRIMARY KEY AUTOINCREMENT, guide_id INTEGER NOT NULL, section TEXT NOT NULL DEFAULT 'points', position INTEGER NOT NULL DEFAULT 0, content TEXT NOT NULL, ticket_key TEXT NOT NULL DEFAULT '', ticket_title TEXT NOT NULL DEFAULT '', is_done INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE INDEX IF NOT EXISTS standup_items_guide_position_idx ON standup_items(guide_id,position,id)"),
    db.prepare("CREATE TABLE IF NOT EXISTS app_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)"),
  ]);
  const count = await db.prepare("SELECT COUNT(*) total FROM statuses").first();
  if (!Number(count?.total)) {
    await db.batch(defaultStatuses.map(x => db.prepare("INSERT INTO statuses(name,color,is_done,position) VALUES(?,?,?,?)").bind(...x)));
  }
  await migrateWorkflowStatuses(db);
  await migrateTicketCompletion(db);
  await migrateTicketCancellation(db);
  await migrateTicketAvatars(db);
  await migrateTicketFocus(db);
  await migrateStandupTicketDetails(db);
  await migrateStandupItemSections(db);
  await migrateAttentionMarkers(db);
  await migrateTicketStatusHistory(db);
  await migratePermanentNoteArchive(db);
  await migratePermanentNoteAttachments(db);
  await migrateBillingCarryovers(db);
  await migrateAgendaTasks(db);
  await migrateAgendaTasksV2(db);await migrateAgendaTasksV3(db);await migrateAgendaTaskComments(db);
  await migrateUsers(db, seedUserFromEnv(env));
  await migrateUserRoles(db, seedUserFromEnv(env));
}

export const setupPromises = new WeakMap();

export async function ensureSetup(db, env) {
  let promise = setupPromises.get(db);
  if (!promise) {
    promise = (async()=>{try{const ready=await db.prepare("SELECT COUNT(*) total FROM app_meta WHERE value='done' AND key IN ('permanent_note_attachments_v1','billing_carryovers_v1','agenda_tasks_v1','agenda_tasks_v2','agenda_tasks_v3','agenda_task_comments_v1','users_v1','users_roles_v1')").first();if(Number(ready?.total)===8)return}catch(error){}await setup(db, env)})().catch(error => {
      setupPromises.delete(db);
      throw error;
    });
    setupPromises.set(db, promise);
  }
  return promise;
}
