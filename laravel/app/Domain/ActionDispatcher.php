<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\FileStore;
use App\Support\PasswordHasher;
use App\Support\SqliteStore;
use App\Support\Values;

final class EarlyReturn
{
}

final class ActionDispatcher
{
    public static function run(SqliteStore $db, object $payload, FileStore $files, int $uid): array
    {
        SchemaInstaller::ensure($db);
        $result = [];
        $name = Values::text($payload->action ?? null);
        $handler = match ($name) {
            'update_profile' => 'updateProfile',
            'change_password' => 'changePassword',
            'create_user' => 'createUser',
            'save_ticket' => 'saveTicket',
            'set_ticket_labels' => 'setTicketLabels',
            'set_ticket_attention' => 'setTicketAttention',
            'set_ticket_focus' => 'setTicketFocus',
            'set_ticket_avatar' => 'setTicketAvatar',
            'status' => 'status',
            'comment' => 'comment',
            'edit_comment' => 'editComment',
            'delete_comment' => 'deleteComment',
            'delete_ticket' => 'deleteTicket',
            'save_agenda_task' => 'saveAgendaTask',
            'toggle_agenda_task' => 'toggleAgendaTask',
            'update_agenda_subtasks' => 'updateAgendaSubtasks',
            'save_agenda_subtask' => 'saveAgendaSubtask',
            'toggle_agenda_subtask' => 'toggleAgendaSubtask',
            'move_agenda_subtask' => 'moveAgendaSubtask',
            'delete_agenda_subtask' => 'deleteAgendaSubtask',
            'save_agenda_task_comment' => 'saveAgendaTaskComment',
            'edit_agenda_task_comment' => 'editAgendaTaskComment',
            'delete_agenda_task_comment' => 'deleteAgendaTaskComment',
            'delete_agenda_task' => 'deleteAgendaTask',
            'reorder_agenda_tasks' => 'reorderAgendaTasks',
            'copy_agenda_tasks' => 'copyAgendaTasks',
            'save_reminder' => 'saveReminder',
            'complete_reminder' => 'completeReminder',
            'save_billing_ticket' => 'saveBillingTicket',
            'postpone_billing_ticket' => 'postponeBillingTicket',
            'set_billing_invoiced' => 'setBillingInvoiced',
            'save_permanent_note' => 'savePermanentNote',
            'delete_permanent_note' => 'deletePermanentNote',
            'delete_permanent_note_attachment' => 'deletePermanentNoteAttachment',
            'archive_permanent_note' => 'archivePermanentNote',
            'save_standup' => 'saveStandup',
            'toggle_standup_item' => 'toggleStandupItem',
            'delete_standup' => 'deleteStandup',
            'save_status' => 'saveStatus',
            'delete_status' => 'deleteStatus',
            'save_label' => 'saveLabel',
            'delete_label' => 'deleteLabel',
            'save_attention_marker' => 'saveAttentionMarker',
            'delete_attention_marker' => 'deleteAttentionMarker',
            default => null,
        };
        if ($handler === null) {
            throw new \RuntimeException('Acción no reconocida.');
        }
        $early = self::$handler($db, $payload, $files, $uid, $result);
        if ($early instanceof EarlyReturn) {
            return [];
        }

        return $result;
    }

    private static function updateProfile(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $email = Values::normalizeEmail($payload->email ?? '');
        $displayName = substr(Values::text($payload->name ?? null), 0, 80);
        if ($displayName === '') {
            throw new \RuntimeException('Escribe un nombre.');
        }
        if (! Values::validEmail($email)) {
            throw new \RuntimeException('Escribe un correo válido.');
        }
        $taken = $db->prepare('SELECT id FROM users WHERE email=? AND id<>?')->bind($email, $uid)->first();
        if ($taken) {
            throw new \RuntimeException('Ese correo ya está registrado.');
        }
        $db->prepare('UPDATE users SET email=?,name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->bind($email, $displayName, $uid)->run();
    }

    private static function changePassword(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $currentPassword = (string) ($payload->currentPassword ?? '');
        $newPassword = (string) ($payload->newPassword ?? '');
        if (strlen($newPassword) < 6) {
            throw new \RuntimeException('La nueva contraseña debe tener al menos 6 caracteres.');
        }
        $user = $db->prepare('SELECT password_hash passwordHash,password_salt passwordSalt FROM users WHERE id=?')->bind($uid)->first();
        if (! $user || ! PasswordHasher::verify($currentPassword, $user->passwordHash, $user->passwordSalt)) {
            throw new \RuntimeException('La contraseña actual no es correcta.');
        }
        $hashed = PasswordHasher::hash($newPassword);
        $db->prepare('UPDATE users SET password_hash=?,password_salt=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->bind($hashed['hash'], $hashed['salt'], $uid)->run();
        $db->prepare('DELETE FROM sessions WHERE user_id=?')->bind($uid)->run();
        $result['newSession'] = AuthSessions::create($db, $uid);
    }

    private static function createUser(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        SchemaInstaller::createUser($db, $payload, true);
    }

    private static function saveTicket(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $key = strtoupper(Values::text($payload->ticketKey ?? null));
        $title = Values::text($payload->title ?? null);
        $statusId = (int) Values::number($payload->statusId ?? 0);
        $attentionMarkerId = (int) Values::number($payload->attentionMarkerId ?? 0);
        if ($key === '' || $title === '' || ! $statusId) {
            throw new \RuntimeException('La clave, el título y el estado son obligatorios.');
        }
        $duplicateTicket = $db->prepare('SELECT id FROM tickets WHERE user_id=? AND upper(trim(ticket_key))=? AND id<>? LIMIT 1')->bind($uid, $key, $id ?: 0)->first();
        if ($duplicateTicket) {
            throw new \RuntimeException('El código '.$key.' ya pertenece a otro ticket. Cada ticket debe tener un código único y no se ha guardado.');
        }
        $targetStatus = $db->prepare("SELECT id,name,color,CASE WHEN lower(name)='done' THEN 1 ELSE 0 END isDone,CASE WHEN lower(name)='cancelled' THEN 1 ELSE 0 END isCancelled FROM statuses WHERE id=? AND user_id=?")->bind($statusId, $uid)->first();
        if (! $targetStatus) {
            throw new \RuntimeException('El estado seleccionado ya no existe.');
        }
        if ($attentionMarkerId && ! $db->prepare('SELECT id FROM attention_markers WHERE id=? AND user_id=?')->bind($attentionMarkerId, $uid)->first()) {
            throw new \RuntimeException('El llamador seleccionado ya no existe.');
        }
        if ($id) {
            $currentStatus = $db->prepare('SELECT s.id statusId,s.name statusName,s.color statusColor FROM tickets t JOIN statuses s ON s.id=t.status_id WHERE t.id=? AND t.user_id=?')->bind($id, $uid)->first();
            if (! $currentStatus) {
                throw new \RuntimeException('El ticket ya no existe.');
            }
            $updates = [$db->prepare('UPDATE tickets SET ticket_key=?,title=?,summary=?,status_id=?,priority=?,next_action=?,attention_marker_id=?,due_date=?,jira_url=?,completed_at=CASE WHEN ?=1 THEN CASE WHEN status_id=? AND completed_at IS NOT NULL THEN completed_at ELSE CURRENT_TIMESTAMP END ELSE NULL END,cancelled_at=CASE WHEN ?=1 THEN CASE WHEN status_id=? AND cancelled_at IS NOT NULL THEN cancelled_at ELSE CURRENT_TIMESTAMP END ELSE NULL END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')
                ->bind($key, $title, Values::text($payload->summary ?? null), $statusId, Values::text($payload->priority ?? null) ?: 'medium', Values::text($payload->nextAction ?? null), $attentionMarkerId ?: null, Values::text($payload->dueDate ?? null) ?: null, Values::text($payload->jiraUrl ?? null), (int) $targetStatus->isDone, $statusId, (int) $targetStatus->isCancelled, $statusId, $id, $uid)];
            if ((int) $currentStatus->statusId !== $statusId) {
                $updates[] = $db->prepare("INSERT INTO ticket_status_history(ticket_id,from_status_id,from_status_name,from_status_color,to_status_id,to_status_name,to_status_color,event_type) VALUES(?,?,?,?,?,?,?,'change')")
                    ->bind($id, (int) $currentStatus->statusId, (string) $currentStatus->statusName, (string) $currentStatus->statusColor, $statusId, (string) $targetStatus->name, (string) $targetStatus->color);
            }
            $db->batch($updates);
        } else {
            $row = $db->prepare('INSERT INTO tickets(ticket_key,title,summary,status_id,priority,next_action,attention_marker_id,due_date,jira_url,user_id) SELECT ?,?,?,?,?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM tickets WHERE user_id=? AND upper(trim(ticket_key))=?) RETURNING id')
                ->bind($key, $title, Values::text($payload->summary ?? null), $statusId, Values::text($payload->priority ?? null) ?: 'medium', Values::text($payload->nextAction ?? null), $attentionMarkerId ?: null, Values::text($payload->dueDate ?? null) ?: null, Values::text($payload->jiraUrl ?? null), $uid, $uid, $key)->first();
            if (! $row) {
                throw new \RuntimeException('El código '.$key.' ya pertenece a otro ticket. Cada ticket debe tener un código único y no se ha creado.');
            }
            $payload->id = $row->id;
            $db->batch([
                $db->prepare('UPDATE tickets SET completed_at=CASE WHEN ?=1 THEN CURRENT_TIMESTAMP ELSE NULL END,cancelled_at=CASE WHEN ?=1 THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=? AND user_id=?')->bind((int) $targetStatus->isDone, (int) $targetStatus->isCancelled, (int) $row->id, $uid),
                $db->prepare("INSERT INTO ticket_status_history(ticket_id,to_status_id,to_status_name,to_status_color,event_type) VALUES(?,?,?,?,'created')")->bind((int) $row->id, $statusId, (string) $targetStatus->name, (string) $targetStatus->color),
            ]);
        }
        $ticketId = (int) Values::number($payload->id ?? 0);
        $db->batch([
            $db->prepare('DELETE FROM ticket_labels WHERE ticket_id=?')->bind($ticketId),
            ...array_map(
                fn (int $labelId) => $db->prepare('INSERT OR IGNORE INTO ticket_labels(ticket_id,label_id) SELECT ?,id FROM labels WHERE id=? AND user_id=?')->bind($ticketId, $labelId, $uid),
                Values::idList($payload->labelIds ?? null),
            ),
        ]);
    }

    private static function setTicketLabels(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $ticketId = (int) Values::number($payload->ticketId ?? 0);
        $ticket = $db->prepare('SELECT id FROM tickets WHERE id=? AND user_id=?')->bind($ticketId, $uid)->first();
        if (! $ticket) {
            throw new \RuntimeException('El ticket ya no existe.');
        }
        $db->batch([
            $db->prepare('DELETE FROM ticket_labels WHERE ticket_id=?')->bind($ticketId),
            ...array_map(
                fn (int $labelId) => $db->prepare('INSERT OR IGNORE INTO ticket_labels(ticket_id,label_id) SELECT ?,id FROM labels WHERE id=? AND user_id=?')->bind($ticketId, $labelId, $uid),
                Values::idList($payload->labelIds ?? null),
            ),
            $db->prepare('UPDATE tickets SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->bind($ticketId, $uid),
        ]);
    }

    private static function setTicketAttention(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $ticketId = (int) Values::number($payload->ticketId ?? 0);
        $attentionMarkerId = (int) Values::number($payload->attentionMarkerId ?? 0);
        $ticket = $db->prepare('SELECT id FROM tickets WHERE id=?')->bind($ticketId)->first();
        if (! $ticket) {
            throw new \RuntimeException('El ticket ya no existe.');
        }
        if ($attentionMarkerId && ! $db->prepare('SELECT id FROM attention_markers WHERE id=? AND user_id=?')->bind($attentionMarkerId, $uid)->first()) {
            throw new \RuntimeException('El llamador seleccionado ya no existe.');
        }
        $db->prepare('UPDATE tickets SET attention_marker_id=? WHERE id=? AND user_id=?')->bind($attentionMarkerId ?: null, $ticketId, $uid)->run();
    }

    private static function setTicketFocus(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $ticketId = (int) Values::number($payload->ticketId ?? 0);
        $ticket = $db->prepare('SELECT id FROM tickets WHERE id=? AND user_id=?')->bind($ticketId, $uid)->first();
        if (! $ticket) {
            throw new \RuntimeException('El ticket ya no existe.');
        }
        $db->prepare('UPDATE tickets SET is_focus=? WHERE id=? AND user_id=?')->bind(($payload->focus ?? null) === true ? 1 : 0, $ticketId, $uid)->run();
    }

    private static function setTicketAvatar(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $ticketId = (int) Values::number($payload->ticketId ?? 0);
        $avatarKey = Values::text($payload->avatarKey ?? null);
        $ticket = $db->prepare('SELECT id FROM tickets WHERE id=? AND user_id=?')->bind($ticketId, $uid)->first();
        if (! $ticket) {
            throw new \RuntimeException('El ticket ya no existe.');
        }
        if ($avatarKey !== '' && ! in_array($avatarKey, ['yellow', 'bald_glasses', 'flower'], true)) {
            throw new \RuntimeException('El avatar seleccionado no es válido.');
        }
        $db->prepare('UPDATE tickets SET avatar_key=? WHERE id=? AND user_id=?')->bind($avatarKey !== '' ? $avatarKey : null, $ticketId, $uid)->run();
    }

    private static function status(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): EarlyReturn|null
    {
        $id = (int) Values::number($payload->id ?? 0);
        $statusId = (int) Values::number($payload->statusId ?? 0);
        $targetStatus = $db->prepare("SELECT id,name,color,CASE WHEN lower(name)='done' THEN 1 ELSE 0 END isDone,CASE WHEN lower(name)='cancelled' THEN 1 ELSE 0 END isCancelled FROM statuses WHERE id=?")->bind($statusId)->first();
        if (! $targetStatus) {
            throw new \RuntimeException('El estado seleccionado ya no existe.');
        }
        $currentStatus = $db->prepare('SELECT s.id statusId,s.name statusName,s.color statusColor FROM tickets t JOIN statuses s ON s.id=t.status_id WHERE t.id=? AND t.user_id=?')->bind($id, $uid)->first();
        if (! $currentStatus) {
            throw new \RuntimeException('El ticket ya no existe.');
        }
        if ((int) $currentStatus->statusId === $statusId) {
            return new EarlyReturn;
        }
        $db->batch([
            $db->prepare('UPDATE tickets SET status_id=?,completed_at=CASE WHEN ?=1 THEN CASE WHEN status_id=? AND completed_at IS NOT NULL THEN completed_at ELSE CURRENT_TIMESTAMP END ELSE NULL END,cancelled_at=CASE WHEN ?=1 THEN CASE WHEN status_id=? AND cancelled_at IS NOT NULL THEN cancelled_at ELSE CURRENT_TIMESTAMP END ELSE NULL END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')
                ->bind($statusId, (int) $targetStatus->isDone, $statusId, (int) $targetStatus->isCancelled, $statusId, $id, $uid),
            $db->prepare("INSERT INTO ticket_status_history(ticket_id,from_status_id,from_status_name,from_status_color,to_status_id,to_status_name,to_status_color,event_type) VALUES(?,?,?,?,?,?,?,'change')")
                ->bind($id, (int) $currentStatus->statusId, (string) $currentStatus->statusName, (string) $currentStatus->statusColor, $statusId, (string) $targetStatus->name, (string) $targetStatus->color),
        ]);

        return null;
    }

    private static function comment(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $ticketId = (int) Values::number($payload->ticketId ?? 0);
        $content = Values::text($payload->content ?? null);
        $ticket = $db->prepare('SELECT id FROM tickets WHERE id=? AND user_id=?')->bind($ticketId, $uid)->first();
        if ($content === '') {
            throw new \RuntimeException('Escribe un comentario.');
        }
        if (! $ticket) {
            throw new \RuntimeException('El ticket ya no existe.');
        }
        $inserted = $db->prepare("INSERT INTO comments(ticket_id,content) SELECT ?,? WHERE NOT EXISTS (SELECT 1 FROM comments WHERE ticket_id=? AND content=? AND created_at>=datetime('now','-12 seconds'))")->bind($ticketId, $content, $ticketId, $content)->run();
        if ((int) ($inserted->meta->changes ?? 0)) {
            $db->prepare('UPDATE tickets SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->bind($ticketId, $uid)->run();
        }
    }

    private static function editComment(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $content = Values::text($payload->content ?? null);
        $comment = $db->prepare('SELECT c.ticket_id ticketId FROM comments c JOIN tickets t ON t.id=c.ticket_id WHERE c.id=? AND t.user_id=?')->bind($id, $uid)->first();
        if ($content === '') {
            throw new \RuntimeException('El comentario no puede estar vacío.');
        }
        if (! $comment) {
            throw new \RuntimeException('El comentario ya no existe.');
        }
        $db->batch([
            $db->prepare('UPDATE comments SET content=? WHERE id=?')->bind($content, $id),
            $db->prepare('UPDATE tickets SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->bind((int) $comment->ticketId),
        ]);
    }

    private static function deleteComment(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $comment = $db->prepare('SELECT c.ticket_id ticketId FROM comments c JOIN tickets t ON t.id=c.ticket_id WHERE c.id=? AND t.user_id=?')->bind($id, $uid)->first();
        if (! $comment) {
            throw new \RuntimeException('El comentario ya no existe.');
        }
        $db->batch([
            $db->prepare('DELETE FROM comments WHERE id=?')->bind($id),
            $db->prepare('UPDATE tickets SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->bind((int) $comment->ticketId),
        ]);
    }

    private static function deleteTicket(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        if (! $db->prepare('SELECT id FROM tickets WHERE id=? AND user_id=?')->bind($id, $uid)->first()) {
            throw new \RuntimeException('El ticket ya no existe.');
        }
        $db->batch([
            $db->prepare('DELETE FROM comments WHERE ticket_id=?')->bind($id),
            $db->prepare('DELETE FROM ticket_status_history WHERE ticket_id=?')->bind($id),
            $db->prepare('DELETE FROM ticket_labels WHERE ticket_id=?')->bind($id),
            $db->prepare('DELETE FROM tickets WHERE id=? AND user_id=?')->bind($id, $uid),
        ]);
    }

    private static function validateAgendaLink(SqliteStore $db, string $linkType, int $linkId, int $userId): ?int
    {
        if ($linkType === '') {
            return null;
        }
        $tables = ['ticket' => 'tickets', 'reminder' => 'reminders', 'note' => 'permanent_notes', 'standup' => 'standup_guides'];
        $table = $tables[$linkType] ?? null;
        if (! $table || ! $linkId) {
            throw new \RuntimeException('Selecciona un elemento válido para enlazar.');
        }
        if (! $db->prepare('SELECT id FROM '.$table.' WHERE id=? AND user_id=?')->bind($linkId, $userId)->first()) {
            throw new \RuntimeException('El elemento enlazado ya no existe.');
        }

        return $linkId;
    }

    private static function saveAgendaTask(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $taskDate = Values::text($payload->taskDate ?? null);
        $content = substr(Values::text($payload->content ?? null), 0, 1200);
        $linkType = Values::text($payload->linkType ?? null);
        $linkId = (int) Values::number($payload->linkId ?? 0);
        if ($content === '') {
            throw new \RuntimeException('Escribe la tarea que quieres añadir.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $taskDate)) {
            throw new \RuntimeException('Elige un día válido para la tarea.');
        }
        $validLinkId = self::validateAgendaLink($db, $linkType, $linkId, $uid);
        if ($id) {
            $current = $db->prepare('SELECT task_date taskDate FROM agenda_tasks WHERE id=? AND user_id=?')->bind($id, $uid)->first();
            if (! $current) {
                throw new \RuntimeException('La tarea de agenda ya no existe.');
            }
            $position = null;
            if ((string) $current->taskDate !== $taskDate) {
                $max = $db->prepare('SELECT COALESCE(MAX(position),-1)+1 position FROM agenda_tasks WHERE task_date=? AND user_id=?')->bind($taskDate, $uid)->first();
                $position = (int) ($max->position ?? 0);
            }
            $db->prepare('UPDATE agenda_tasks SET task_date=?,content=?,position=COALESCE(?,position),link_type=?,link_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')
                ->bind($taskDate, $content, $position, $linkType, $validLinkId, $id, $uid)->run();
        } else {
            $max = $db->prepare('SELECT COALESCE(MAX(position),-1)+1 position FROM agenda_tasks WHERE task_date=? AND user_id=?')->bind($taskDate, $uid)->first();
            $db->prepare("INSERT INTO agenda_tasks(task_date,content,position,link_type,link_id,subtasks,user_id) VALUES(?,?,?,?,?,'[]',?)")
                ->bind($taskDate, $content, (int) ($max->position ?? 0), $linkType, $validLinkId, $uid)->run();
        }
    }

    private static function toggleAgendaTask(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $task = $db->prepare('SELECT id,is_done isDone FROM agenda_tasks WHERE id=? AND user_id=?')->bind($id, $uid)->first();
        if (! $task) {
            throw new \RuntimeException('La tarea de agenda ya no existe.');
        }
        $db->prepare('UPDATE agenda_tasks SET is_done=?,completed_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->bind($task->isDone ? 0 : 1, $task->isDone ? null : (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.v\Z'), $id)->run();
    }

    private static function updateAgendaSubtasks(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $raw = is_array($payload->subtasks ?? null) ? $payload->subtasks : [];
        $items = [];
        foreach ($raw as $i => $x) {
            $item = is_object($x) ? $x : (object) $x;
            $content = substr(Values::text($item->content ?? null), 0, 500);
            if ($content === '') {
                continue;
            }
            $items[] = ['id' => (int) ($item->id ?? $i + 1), 'content' => $content, 'isDone' => (bool) ($item->isDone ?? false)];
            if (count($items) >= 50) {
                break;
            }
        }
        if (! $db->prepare('SELECT id FROM agenda_tasks WHERE id=? AND user_id=?')->bind($id, $uid)->first()) {
            throw new \RuntimeException('La tarea de agenda ya no existe.');
        }
        $db->prepare('UPDATE agenda_tasks SET subtasks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->bind(json_encode($items, JSON_UNESCAPED_UNICODE), $id)->run();
    }

    /** @return list<object> */
    private static function parseSubtasks(mixed $raw): array
    {
        try {
            $items = json_decode((string) ($raw ?: '[]'));
        } catch (\Throwable) {
            $items = [];
        }
        if (! is_array($items)) {
            return [];
        }

        return $items;
    }

    private static function saveAgendaSubtask(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $taskId = (int) Values::number($payload->taskId ?? 0);
        $subtaskId = (int) Values::number($payload->subtaskId ?? 0);
        $content = substr(Values::text($payload->content ?? null), 0, 500);
        $task = $db->prepare('SELECT subtasks FROM agenda_tasks WHERE id=? AND user_id=?')->bind($taskId, $uid)->first();
        if (! $task) {
            throw new \RuntimeException('La tarea de agenda ya no existe.');
        }
        if ($content === '') {
            throw new \RuntimeException('Escribe el contenido de la subtarea.');
        }
        $items = self::parseSubtasks($task->subtasks ?? '[]');
        if ($subtaskId) {
            $found = false;
            foreach ($items as $item) {
                if ((int) ($item->id ?? 0) === $subtaskId) {
                    $item->content = $content;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                throw new \RuntimeException('La subtarea ya no existe.');
            }
        } else {
            if (count($items) >= 50) {
                throw new \RuntimeException('Una tarea no puede tener más de 50 subtareas.');
            }
            $max = 0;
            foreach ($items as $item) {
                $max = max($max, (int) ($item->id ?? 0));
            }
            $items[] = (object) ['id' => $max + 1, 'content' => $content, 'isDone' => false];
        }
        $db->prepare('UPDATE agenda_tasks SET subtasks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->bind(json_encode($items, JSON_UNESCAPED_UNICODE), $taskId)->run();
    }

    private static function toggleAgendaSubtask(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $taskId = (int) Values::number($payload->taskId ?? 0);
        $subtaskId = (int) Values::number($payload->subtaskId ?? 0);
        $task = $db->prepare('SELECT subtasks FROM agenda_tasks WHERE id=? AND user_id=?')->bind($taskId, $uid)->first();
        if (! $task) {
            throw new \RuntimeException('La tarea de agenda ya no existe.');
        }
        $items = self::parseSubtasks($task->subtasks ?? '[]');
        $item = null;
        foreach ($items as $candidate) {
            if ((int) ($candidate->id ?? 0) === $subtaskId) {
                $item = $candidate;
                break;
            }
        }
        if (! $item) {
            throw new \RuntimeException('La subtarea ya no existe.');
        }
        $item->isDone = ! (bool) ($item->isDone ?? false);
        $db->prepare('UPDATE agenda_tasks SET subtasks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->bind(json_encode($items, JSON_UNESCAPED_UNICODE), $taskId)->run();
    }

    private static function moveAgendaSubtask(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $taskId = (int) Values::number($payload->taskId ?? 0);
        $subtaskId = (int) Values::number($payload->subtaskId ?? 0);
        $direction = Values::text($payload->direction ?? null);
        $task = $db->prepare('SELECT subtasks FROM agenda_tasks WHERE id=? AND user_id=?')->bind($taskId, $uid)->first();
        if (! $task) {
            throw new \RuntimeException('La tarea de agenda ya no existe.');
        }
        $items = self::parseSubtasks($task->subtasks ?? '[]');
        $index = -1;
        foreach ($items as $i => $item) {
            if ((int) ($item->id ?? 0) === $subtaskId) {
                $index = $i;
                break;
            }
        }
        $target = $index + ($direction === 'up' ? -1 : ($direction === 'down' ? 1 : 0));
        if ($index < 0) {
            throw new \RuntimeException('La subtarea ya no existe.');
        }
        if ($target >= 0 && $target < count($items)) {
            [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
        }
        $db->prepare('UPDATE agenda_tasks SET subtasks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->bind(json_encode(array_values($items), JSON_UNESCAPED_UNICODE), $taskId)->run();
    }

    private static function deleteAgendaSubtask(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $taskId = (int) Values::number($payload->taskId ?? 0);
        $subtaskId = (int) Values::number($payload->subtaskId ?? 0);
        $task = $db->prepare('SELECT subtasks FROM agenda_tasks WHERE id=? AND user_id=?')->bind($taskId, $uid)->first();
        if (! $task) {
            throw new \RuntimeException('La tarea de agenda ya no existe.');
        }
        $items = self::parseSubtasks($task->subtasks ?? '[]');
        $filtered = array_values(array_filter($items, fn ($x) => (int) ($x->id ?? 0) !== $subtaskId));
        if (count($filtered) === count($items)) {
            throw new \RuntimeException('La subtarea ya no existe.');
        }
        $db->batch([
            $db->prepare('DELETE FROM agenda_task_comments WHERE task_id=? AND subtask_id=?')->bind($taskId, $subtaskId),
            $db->prepare('UPDATE agenda_tasks SET subtasks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->bind(json_encode($filtered, JSON_UNESCAPED_UNICODE), $taskId),
        ]);
    }

    private static function saveAgendaTaskComment(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $taskId = (int) Values::number($payload->taskId ?? 0);
        $subtaskId = (int) Values::number($payload->subtaskId ?? 0);
        $content = substr(Values::text($payload->content ?? null), 0, 1200);
        $task = $db->prepare('SELECT subtasks FROM agenda_tasks WHERE id=? AND user_id=?')->bind($taskId, $uid)->first();
        if (! $task) {
            throw new \RuntimeException('La tarea de agenda ya no existe.');
        }
        if ($content === '') {
            throw new \RuntimeException('Escribe un comentario.');
        }
        if ($subtaskId) {
            $items = self::parseSubtasks($task->subtasks ?? '[]');
            $exists = false;
            foreach ($items as $item) {
                if ((int) ($item->id ?? 0) === $subtaskId) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                throw new \RuntimeException('La subtarea ya no existe.');
            }
        }
        $db->prepare("INSERT INTO agenda_task_comments(task_id,subtask_id,content) SELECT ?,?,? WHERE NOT EXISTS (SELECT 1 FROM agenda_task_comments WHERE task_id=? AND subtask_id=? AND content=? AND created_at>=datetime('now','-12 seconds'))")
            ->bind($taskId, $subtaskId, $content, $taskId, $subtaskId, $content)->run();
    }

    private static function editAgendaTaskComment(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $content = substr(Values::text($payload->content ?? null), 0, 1200);
        if ($content === '') {
            throw new \RuntimeException('El comentario no puede estar vacío.');
        }
        if (! $db->prepare('SELECT c.id FROM agenda_task_comments c JOIN agenda_tasks a ON a.id=c.task_id WHERE c.id=? AND a.user_id=?')->bind($id, $uid)->first()) {
            throw new \RuntimeException('El comentario ya no existe.');
        }
        $db->prepare('UPDATE agenda_task_comments SET content=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->bind($content, $id)->run();
    }

    private static function deleteAgendaTaskComment(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        if (! $db->prepare('SELECT c.id FROM agenda_task_comments c JOIN agenda_tasks a ON a.id=c.task_id WHERE c.id=? AND a.user_id=?')->bind($id, $uid)->first()) {
            throw new \RuntimeException('El comentario ya no existe.');
        }
        $db->prepare('DELETE FROM agenda_task_comments WHERE id=?')->bind($id)->run();
    }

    private static function deleteAgendaTask(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $task = $db->prepare('SELECT id FROM agenda_tasks WHERE id=? AND user_id=?')->bind($id, $uid)->first();
        if (! $task) {
            throw new \RuntimeException('La tarea de agenda ya no existe.');
        }
        $db->batch([
            $db->prepare('DELETE FROM agenda_task_comments WHERE task_id=?')->bind($id),
            $db->prepare('DELETE FROM agenda_tasks WHERE id=? AND user_id=?')->bind($id, $uid),
        ]);
    }

    private static function reorderAgendaTasks(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $taskDate = Values::text($payload->taskDate ?? null);
        $ids = array_slice(Values::idList($payload->ids ?? null), 0, 300);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $taskDate) || ! $ids) {
            throw new \RuntimeException('No se pudo guardar el nuevo orden.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $found = $db->prepare('SELECT COUNT(*) total FROM agenda_tasks WHERE user_id=? AND task_date=? AND id IN ('.$placeholders.')')->bind($uid, $taskDate, ...$ids)->first();
        if ((int) ($found->total ?? 0) !== count($ids)) {
            throw new \RuntimeException('Alguna tarea ya no pertenece a este día.');
        }
        $db->batch(array_map(
            fn (int $id, int $position) => $db->prepare('UPDATE agenda_tasks SET position=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND task_date=? AND user_id=?')->bind($position, $id, $taskDate, $uid),
            $ids,
            array_keys($ids),
        ));
    }

    private static function copyAgendaTasks(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $fromDate = Values::text($payload->fromDate ?? null);
        $toDate = Values::text($payload->toDate ?? null);
        $ids = array_slice(Values::idList($payload->ids ?? null), 0, 300);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) || ! $ids || $fromDate === $toDate) {
            throw new \RuntimeException('No se pudieron copiar las tareas pendientes.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sourceRows = $db->prepare('SELECT id,content,link_type linkType,link_id linkId,subtasks FROM agenda_tasks WHERE user_id=? AND task_date=? AND is_done=0 AND id IN ('.$placeholders.') ORDER BY position,id')->bind($uid, $fromDate, ...$ids)->all()->results;
        if (count($sourceRows) !== count($ids)) {
            throw new \RuntimeException('Alguna tarea pendiente ya no pertenece al día anterior.');
        }
        $max = $db->prepare('SELECT COALESCE(MAX(position),-1)+1 position FROM agenda_tasks WHERE task_date=? AND user_id=?')->bind($toDate, $uid)->first();
        $position = (int) ($max->position ?? 0);
        $statements = [];
        foreach ($sourceRows as $row) {
            $copiedSubtasks = [];
            try {
                $copiedSubtasks = json_decode((string) ($row->subtasks ?? '[]'), true);
            } catch (\Throwable) {
                $copiedSubtasks = [];
            }
            if (! is_array($copiedSubtasks)) {
                $copiedSubtasks = [];
            }
            $copiedSubtasks = array_values(array_filter(array_map(function ($x) {
                $item = is_array($x) ? $x : (array) $x;
                $content = substr(Values::text($item['content'] ?? null), 0, 500);

                return $content !== '' ? ['id' => (int) ($item['id'] ?? 0), 'content' => $content, 'isDone' => false] : null;
            }, $copiedSubtasks)));
            $statements[] = $db->prepare('INSERT OR IGNORE INTO agenda_tasks(task_date,content,position,is_done,link_type,link_id,copied_from_id,subtasks,user_id) VALUES(?,?,?,0,?,?,?,?,?)')
                ->bind($toDate, (string) $row->content, $position, (string) ($row->linkType ?? ''), $row->linkId ?: null, (int) $row->id, json_encode($copiedSubtasks, JSON_UNESCAPED_UNICODE), $uid);
            $position++;
        }
        $db->batch($statements);
    }

    private static function saveReminder(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $content = Values::text($payload->content ?? null);
        $dueDate = Values::text($payload->dueDate ?? null);
        $today = Values::madridDateKey();
        if ($content === '') {
            throw new \RuntimeException('Escribe qué quieres recordar.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            throw new \RuntimeException('Elige una fecha válida.');
        }
        if ($dueDate < $today) {
            throw new \RuntimeException('No puedes crear un recordatorio en una fecha pasada.');
        }
        $db->prepare('INSERT INTO reminders(content,due_date,user_id) VALUES(?,?,?)')->bind($content, $dueDate, $uid)->run();
    }

    private static function completeReminder(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): EarlyReturn|null
    {
        $id = (int) Values::number($payload->id ?? 0);
        $reminder = $db->prepare('SELECT due_date dueDate,is_done isDone FROM reminders WHERE id=? AND user_id=?')->bind($id, $uid)->first();
        if (! $reminder) {
            throw new \RuntimeException('El recordatorio ya no existe.');
        }
        if ($reminder->isDone) {
            return new EarlyReturn;
        }
        if ((string) $reminder->dueDate > Values::madridDateKey()) {
            throw new \RuntimeException('Este recordatorio todavía no puede marcarse como hecho.');
        }
        $db->prepare('UPDATE reminders SET is_done=1,completed_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->bind($id, $uid)->run();

        return null;
    }

    private static function saveBillingTicket(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $ticketId = (int) Values::number($payload->ticketId ?? 0);
        $workedMonth = Values::text($payload->workedMonth ?? null);
        $invoiceMonth = Values::text($payload->invoiceMonth ?? null);
        $minutes = (int) floor((float) Values::number($payload->minutes ?? 0));
        $projectValue = strtoupper(trim(Values::text($payload->project ?? null)));
        $project = $projectValue === 'BD' || $projectValue === 'HM' ? $projectValue : '';
        $notes = substr(Values::text($payload->notes ?? null), 0, 3000);
        $monthPattern = '/^\d{4}-(0[1-9]|1[0-2])$/';
        if (! $ticketId) {
            throw new \RuntimeException('Selecciona un ticket.');
        }
        if (! preg_match($monthPattern, $workedMonth) || ! preg_match($monthPattern, $invoiceMonth)) {
            throw new \RuntimeException('Selecciona meses válidos.');
        }
        if ($invoiceMonth <= $workedMonth) {
            throw new \RuntimeException('El mes del invoice debe ser posterior al mes en el que se trabajó el ticket.');
        }
        if ($minutes < 1 || $minutes > 600000) {
            throw new \RuntimeException('Indica un tiempo trabajado válido.');
        }
        if ($projectValue !== '' && $projectValue !== 'BD' && $projectValue !== 'HM') {
            throw new \RuntimeException('Selecciona BD o HM como proyecto.');
        }
        $ticket = $db->prepare('SELECT id,ticket_key ticketKey,title,jira_url jiraUrl FROM tickets WHERE id=? AND user_id=?')->bind($ticketId, $uid)->first();
        if (! $ticket) {
            throw new \RuntimeException('El ticket seleccionado ya no existe.');
        }
        $duplicate = $db->prepare('SELECT id FROM billing_carryovers WHERE user_id=? AND ticket_id=? AND worked_month=? AND id<>? LIMIT 1')->bind($uid, $ticketId, $workedMonth, $id ?: 0)->first();
        if ($duplicate) {
            throw new \RuntimeException('Este ticket ya tiene un seguimiento para el mes trabajado seleccionado.');
        }
        if ($id) {
            if (! $db->prepare('SELECT id FROM billing_carryovers WHERE id=? AND user_id=?')->bind($id, $uid)->first()) {
                throw new \RuntimeException('El seguimiento de facturación ya no existe.');
            }
            $db->prepare('UPDATE billing_carryovers SET ticket_id=?,ticket_key=?,ticket_title=?,jira_url=?,worked_month=?,invoice_month=?,minutes=?,project=?,notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')
                ->bind($ticketId, (string) $ticket->ticketKey, (string) $ticket->title, (string) ($ticket->jiraUrl ?? ''), $workedMonth, $invoiceMonth, $minutes, $project, $notes, $id, $uid)->run();
        } else {
            $db->prepare('INSERT INTO billing_carryovers(ticket_id,ticket_key,ticket_title,jira_url,worked_month,invoice_month,minutes,project,notes,user_id) VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->bind($ticketId, (string) $ticket->ticketKey, (string) $ticket->title, (string) ($ticket->jiraUrl ?? ''), $workedMonth, $invoiceMonth, $minutes, $project, $notes, $uid)->run();
        }
    }

    private static function postponeBillingTicket(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $invoiceMonth = Values::text($payload->invoiceMonth ?? null);
        $item = $db->prepare('SELECT invoice_month invoiceMonth,status FROM billing_carryovers WHERE id=? AND user_id=?')->bind($id, $uid)->first();
        if (! $item) {
            throw new \RuntimeException('El seguimiento de facturación ya no existe.');
        }
        if ($item->status === 'invoiced') {
            throw new \RuntimeException('Este ticket ya está incluido en el invoice.');
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $invoiceMonth) || $invoiceMonth <= (string) $item->invoiceMonth) {
            throw new \RuntimeException('El nuevo mes debe ser posterior al mes de facturación actual.');
        }
        $db->prepare('UPDATE billing_carryovers SET invoice_month=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->bind($invoiceMonth, $id, $uid)->run();
    }

    private static function setBillingInvoiced(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $ids = array_slice(Values::idList($payload->ids ?? null), 0, 200);
        $invoiced = ($payload->invoiced ?? null) !== false;
        if (! $ids) {
            throw new \RuntimeException('Selecciona al menos un ticket.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE billing_carryovers SET status=?,invoiced_at='.($invoiced ? 'COALESCE(invoiced_at,CURRENT_TIMESTAMP)' : 'NULL').',updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND id IN ('.$placeholders.')';
        $db->prepare($sql)->bind($invoiced ? 'invoiced' : 'pending', $uid, ...$ids)->run();
    }

    private static function savePermanentNote(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $title = substr(Values::text($payload->title ?? null), 0, 100);
        $content = substr(Values::text($payload->content ?? null), 0, 5000);
        $hasSelectedFiles = ($payload->hasFiles ?? null) === true;
        if ($id) {
            $note = $db->prepare('SELECT id FROM permanent_notes WHERE id=? AND user_id=?')->bind($id, $uid)->first();
            if (! $note) {
                throw new \RuntimeException('La nota permanente ya no existe.');
            }
            $attachmentCount = $db->prepare('SELECT COUNT(*) total FROM permanent_note_attachments WHERE note_id=?')->bind($id)->first();
            if ($content === '' && ! $hasSelectedFiles && ! (int) ($attachmentCount->total ?? 0)) {
                throw new \RuntimeException('Escribe una anotación o adjunta al menos un documento.');
            }
            $db->prepare('UPDATE permanent_notes SET title=?,content=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->bind($title, $content, $id, $uid)->run();
            $result['savedNoteId'] = $id;
        } else {
            if ($content === '' && ! $hasSelectedFiles) {
                throw new \RuntimeException('Escribe una anotación o adjunta al menos un documento.');
            }
            $row = $db->prepare('INSERT INTO permanent_notes(title,content,user_id) VALUES(?,?,?) RETURNING id')->bind($title, $content, $uid)->first();
            $result['savedNoteId'] = (int) Values::number($row->id ?? 0);
        }
    }

    private static function deletePermanentNote(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $note = $db->prepare('SELECT id FROM permanent_notes WHERE id=? AND user_id=?')->bind($id, $uid)->first();
        if (! $note) {
            throw new \RuntimeException('La nota permanente ya no existe.');
        }
        $attachments = $db->prepare('SELECT storage_key storageKey FROM permanent_note_attachments WHERE note_id=?')->bind($id)->all()->results;
        if ($attachments && ! $files) {
            throw new \RuntimeException('El almacenamiento de documentos no está disponible.');
        }
        foreach ($attachments as $file) {
            $files->delete((string) $file->storageKey);
        }
        $db->batch([
            $db->prepare('DELETE FROM permanent_note_attachments WHERE note_id=?')->bind($id),
            $db->prepare('DELETE FROM permanent_notes WHERE id=?')->bind($id),
        ]);
    }

    private static function deletePermanentNoteAttachment(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $attachment = $db->prepare('SELECT a.storage_key storageKey FROM permanent_note_attachments a JOIN permanent_notes n ON n.id=a.note_id WHERE a.id=? AND n.user_id=?')->bind($id, $uid)->first();
        if (! $attachment) {
            throw new \RuntimeException('El documento ya no existe.');
        }
        $files->delete((string) $attachment->storageKey);
        $db->prepare('DELETE FROM permanent_note_attachments WHERE id=?')->bind($id)->run();
    }

    private static function archivePermanentNote(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $note = $db->prepare('SELECT id FROM permanent_notes WHERE id=?')->bind($id)->first();
        if (! $note) {
            throw new \RuntimeException('La nota permanente ya no existe.');
        }
        $db->prepare('UPDATE permanent_notes SET archived_at=CASE WHEN ?=1 THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=? AND user_id=?')->bind(($payload->archived ?? null) ? 1 : 0, $id, $uid)->run();
    }

    private static function saveStandup(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $title = Values::text($payload->title ?? null);
        $standupDate = Values::text($payload->standupDate ?? null);
        $today = Values::madridDateKey();
        $allowed = ['points' => true, 'highlights' => true, 'deployed_bdonline' => true, 'pending_bdonline' => true];
        $rawItems = is_array($payload->items ?? null) ? $payload->items : [];
        $items = [];
        foreach ($rawItems as $item) {
            $row = is_object($item) ? $item : (object) $item;
            $mapped = [
                'section' => isset($allowed[$row->section ?? '']) ? $row->section : 'points',
                'content' => Values::text($row->content ?? null),
                'ticketKey' => strtoupper(Values::text($row->ticketKey ?? null)),
                'ticketTitle' => Values::text($row->ticketTitle ?? null),
                'isDone' => ($row->isDone ?? null) ? 1 : 0,
            ];
            if ($mapped['content'] !== '' || ($mapped['ticketKey'] !== '' && $mapped['ticketTitle'] !== '')) {
                $items[] = $mapped;
            }
            if (count($items) >= 100) {
                break;
            }
        }
        if ($title === '') {
            throw new \RuntimeException('Escribe un título para la guía.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $standupDate)) {
            throw new \RuntimeException('Elige una fecha válida para el standup.');
        }
        if (! $items) {
            throw new \RuntimeException('Añade al menos un punto, destacado o ticket de despliegue.');
        }
        if ($id) {
            $guide = $db->prepare('SELECT standup_date standupDate FROM standup_guides WHERE id=? AND user_id=?')->bind($id, $uid)->first();
            if (! $guide) {
                throw new \RuntimeException('La guía ya no existe.');
            }
            if ((string) $guide->standupDate < $today) {
                throw new \RuntimeException('Las guías archivadas son de solo lectura. Puedes duplicarla.');
            }
            if ($standupDate < $today) {
                throw new \RuntimeException('No puedes mover una guía a una fecha pasada.');
            }
            $db->prepare('UPDATE standup_guides SET title=?,standup_date=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->bind($title, $standupDate, $id, $uid)->run();
        } else {
            if ($standupDate < $today) {
                throw new \RuntimeException('No puedes crear una guía con una fecha pasada.');
            }
            $row = $db->prepare('INSERT INTO standup_guides(title,standup_date,user_id) VALUES(?,?,?) RETURNING id')->bind($title, $standupDate, $uid)->first();
            $payload->id = $row->id;
        }
        $guideId = (int) Values::number($payload->id ?? 0);
        $positions = ['points' => 0, 'highlights' => 0, 'deployed_bdonline' => 0, 'pending_bdonline' => 0];
        $statements = [$db->prepare('DELETE FROM standup_items WHERE guide_id=?')->bind($guideId)];
        foreach ($items as $item) {
            $section = $item['section'];
            $statements[] = $db->prepare('INSERT INTO standup_items(guide_id,section,position,content,ticket_key,ticket_title,is_done) VALUES(?,?,?,?,?,?,?)')
                ->bind($guideId, $section, $positions[$section]++, $item['content'], $item['ticketKey'], $item['ticketTitle'], $item['isDone']);
        }
        $db->batch($statements);
    }

    private static function toggleStandupItem(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $item = $db->prepare('SELECT si.id,sg.standup_date standupDate FROM standup_items si JOIN standup_guides sg ON sg.id=si.guide_id WHERE si.id=? AND sg.user_id=?')->bind($id, $uid)->first();
        if (! $item) {
            throw new \RuntimeException('Este punto ya no existe.');
        }
        if ((string) $item->standupDate < Values::madridDateKey()) {
            throw new \RuntimeException('Las guías archivadas son de solo lectura.');
        }
        $db->prepare('UPDATE standup_items SET is_done=CASE WHEN is_done=1 THEN 0 ELSE 1 END WHERE id=?')->bind($id)->run();
    }

    private static function deleteStandup(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $guide = $db->prepare('SELECT id FROM standup_guides WHERE id=? AND user_id=?')->bind($id, $uid)->first();
        if (! $guide) {
            throw new \RuntimeException('La guía ya no existe.');
        }
        $db->batch([
            $db->prepare('DELETE FROM standup_items WHERE guide_id=?')->bind($id),
            $db->prepare('DELETE FROM standup_guides WHERE id=? AND user_id=?')->bind($id, $uid),
        ]);
    }

    private static function saveStatus(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        if (! Values::text($payload->name ?? null)) {
            throw new \RuntimeException('Escribe un nombre.');
        }
        if ((int) Values::number($payload->id ?? 0)) {
            $db->prepare('UPDATE statuses SET name=?,color=?,is_done=? WHERE id=? AND user_id=?')->bind(Values::text($payload->name), Values::color($payload->color ?? null), ($payload->isDone ?? null) ? 1 : 0, (int) Values::number($payload->id), $uid)->run();
        } else {
            $max = $db->prepare('SELECT COALESCE(MAX(position),-1)+1 position FROM statuses WHERE user_id=?')->bind($uid)->first();
            $db->prepare('INSERT INTO statuses(name,color,is_done,position,user_id) VALUES(?,?,?,?,?)')->bind(Values::text($payload->name), Values::color($payload->color ?? null), ($payload->isDone ?? null) ? 1 : 0, (int) $max->position, $uid)->run();
        }
    }

    private static function deleteStatus(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $used = $db->prepare('SELECT COUNT(*) total FROM tickets WHERE status_id=? AND user_id=?')->bind((int) Values::number($payload->id ?? 0), $uid)->first();
        if ((int) $used->total) {
            throw new \RuntimeException('Este estado está siendo utilizado.');
        }
        $db->prepare('DELETE FROM statuses WHERE id=? AND user_id=?')->bind((int) Values::number($payload->id ?? 0), $uid)->run();
    }

    private static function saveLabel(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        if (! Values::text($payload->name ?? null)) {
            throw new \RuntimeException('Escribe un nombre.');
        }
        if ((int) Values::number($payload->id ?? 0)) {
            $db->prepare('UPDATE labels SET name=?,color=? WHERE id=? AND user_id=?')->bind(Values::text($payload->name), Values::color($payload->color ?? null), (int) Values::number($payload->id), $uid)->run();
        } else {
            $db->prepare('INSERT INTO labels(name,color,user_id) VALUES(?,?,?)')->bind(Values::text($payload->name), Values::color($payload->color ?? null), $uid)->run();
        }
    }

    private static function deleteLabel(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        if (! $db->prepare('SELECT id FROM labels WHERE id=? AND user_id=?')->bind($id, $uid)->first()) {
            throw new \RuntimeException('La label ya no existe.');
        }
        $db->batch([
            $db->prepare('DELETE FROM ticket_labels WHERE label_id=?')->bind($id),
            $db->prepare('DELETE FROM labels WHERE id=? AND user_id=?')->bind($id, $uid),
        ]);
    }

    private static function saveAttentionMarker(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        $markerName = Values::text($payload->name ?? null);
        if ($markerName === '') {
            throw new \RuntimeException('Escribe qué significa este llamador.');
        }
        if ($id) {
            if (! $db->prepare('SELECT id FROM attention_markers WHERE id=? AND user_id=?')->bind($id, $uid)->first()) {
                throw new \RuntimeException('El llamador ya no existe.');
            }
            $db->prepare('UPDATE attention_markers SET name=?,color=? WHERE id=? AND user_id=?')->bind($markerName, Values::color($payload->color ?? null), $id, $uid)->run();
        } else {
            $max = $db->prepare('SELECT COALESCE(MAX(position),-1)+1 position FROM attention_markers WHERE user_id=?')->bind($uid)->first();
            $db->prepare('INSERT INTO attention_markers(name,color,position,user_id) VALUES(?,?,?,?)')->bind($markerName, Values::color($payload->color ?? null), (int) $max->position, $uid)->run();
        }
    }

    private static function deleteAttentionMarker(SqliteStore $db, object $payload, FileStore $files, int $uid, array &$result): void
    {
        $id = (int) Values::number($payload->id ?? 0);
        if (! $db->prepare('SELECT id FROM attention_markers WHERE id=? AND user_id=?')->bind($id, $uid)->first()) {
            throw new \RuntimeException('El llamador ya no existe.');
        }
        $db->batch([
            $db->prepare('UPDATE tickets SET attention_marker_id=NULL WHERE attention_marker_id=? AND user_id=?')->bind($id, $uid),
            $db->prepare('DELETE FROM attention_markers WHERE id=? AND user_id=?')->bind($id, $uid),
        ]);
    }
}
