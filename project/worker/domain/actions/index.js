/**
 * Despacha `payload.action` al handler de su dominio.
 *
 * Abierto a extensión (OCP): añadir un archivo handler y registrarlo
 * en `handlers` no requiere tocar el resto de acciones.
 */

import { ensureSetup } from "../../db/setup.js";
import { text } from "../../lib/text.js";
import { EARLY_RETURN } from "./early-return.js";
import { handle as update_profile } from "./update_profile.js";
import { handle as change_password } from "./change_password.js";
import { handle as create_user } from "./create_user.js";
import { handle as save_ticket } from "./save_ticket.js";
import { handle as set_ticket_labels } from "./set_ticket_labels.js";
import { handle as set_ticket_attention } from "./set_ticket_attention.js";
import { handle as set_ticket_focus } from "./set_ticket_focus.js";
import { handle as set_ticket_avatar } from "./set_ticket_avatar.js";
import { handle as status } from "./status.js";
import { handle as comment } from "./comment.js";
import { handle as edit_comment } from "./edit_comment.js";
import { handle as delete_comment } from "./delete_comment.js";
import { handle as delete_ticket } from "./delete_ticket.js";
import { handle as save_agenda_task } from "./save_agenda_task.js";
import { handle as toggle_agenda_task } from "./toggle_agenda_task.js";
import { handle as update_agenda_subtasks } from "./update_agenda_subtasks.js";
import { handle as save_agenda_subtask } from "./save_agenda_subtask.js";
import { handle as toggle_agenda_subtask } from "./toggle_agenda_subtask.js";
import { handle as move_agenda_subtask } from "./move_agenda_subtask.js";
import { handle as delete_agenda_subtask } from "./delete_agenda_subtask.js";
import { handle as save_agenda_task_comment } from "./save_agenda_task_comment.js";
import { handle as edit_agenda_task_comment } from "./edit_agenda_task_comment.js";
import { handle as delete_agenda_task_comment } from "./delete_agenda_task_comment.js";
import { handle as delete_agenda_task } from "./delete_agenda_task.js";
import { handle as reorder_agenda_tasks } from "./reorder_agenda_tasks.js";
import { handle as copy_agenda_tasks } from "./copy_agenda_tasks.js";
import { handle as save_reminder } from "./save_reminder.js";
import { handle as complete_reminder } from "./complete_reminder.js";
import { handle as save_billing_ticket } from "./save_billing_ticket.js";
import { handle as postpone_billing_ticket } from "./postpone_billing_ticket.js";
import { handle as set_billing_invoiced } from "./set_billing_invoiced.js";
import { handle as save_permanent_note } from "./save_permanent_note.js";
import { handle as delete_permanent_note } from "./delete_permanent_note.js";
import { handle as delete_permanent_note_attachment } from "./delete_permanent_note_attachment.js";
import { handle as archive_permanent_note } from "./archive_permanent_note.js";
import { handle as save_standup } from "./save_standup.js";
import { handle as toggle_standup_item } from "./toggle_standup_item.js";
import { handle as delete_standup } from "./delete_standup.js";
import { handle as save_status } from "./save_status.js";
import { handle as delete_status } from "./delete_status.js";
import { handle as save_label } from "./save_label.js";
import { handle as delete_label } from "./delete_label.js";
import { handle as save_attention_marker } from "./save_attention_marker.js";
import { handle as delete_attention_marker } from "./delete_attention_marker.js";

const handlers = {
  update_profile,
  change_password,
  create_user,
  save_ticket,
  set_ticket_labels,
  set_ticket_attention,
  set_ticket_focus,
  set_ticket_avatar,
  status,
  comment,
  edit_comment,
  delete_comment,
  delete_ticket,
  save_agenda_task,
  toggle_agenda_task,
  update_agenda_subtasks,
  save_agenda_subtask,
  toggle_agenda_subtask,
  move_agenda_subtask,
  delete_agenda_subtask,
  save_agenda_task_comment,
  edit_agenda_task_comment,
  delete_agenda_task_comment,
  delete_agenda_task,
  reorder_agenda_tasks,
  copy_agenda_tasks,
  save_reminder,
  complete_reminder,
  save_billing_ticket,
  postpone_billing_ticket,
  set_billing_invoiced,
  save_permanent_note,
  delete_permanent_note,
  delete_permanent_note_attachment,
  archive_permanent_note,
  save_standup,
  toggle_standup_item,
  delete_standup,
  save_status,
  delete_status,
  save_label,
  delete_label,
  save_attention_marker,
  delete_attention_marker,
};

export async function action(db, payload, filesBucket, userId) {
  await ensureSetup(db);
  const name = text(payload.action);
  const result = {};
  const uid = Number(userId);
  const handler = handlers[name];
  if (!handler) throw new Error("Acción no reconocida.");
  const early = await handler(db, payload, filesBucket, uid, result);
  if (early === EARLY_RETURN) return;
  return result;
}
