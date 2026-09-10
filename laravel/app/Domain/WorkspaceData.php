<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\SqliteStore;
use App\Support\Values;

final class WorkspaceData
{
    public static function get(SqliteStore $db, int $userId): array
    {
        SchemaInstaller::ensure($db);
        $uid = $userId;
        [
            $statuses,
            $labels,
            $attentionMarkers,
            $tickets,
            $ticketLabels,
            $comments,
            $statusHistory,
            $reminders,
            $permanentNotes,
            $permanentNoteAttachments,
            $standups,
            $standupItems,
            $billingItems,
            $agendaTasks,
            $agendaTaskComments,
            $users,
        ] = $db->batch([
            $db->prepare('SELECT id,name,color,is_done isDone,position FROM statuses WHERE user_id=? ORDER BY position,id')->bind($uid),
            $db->prepare('SELECT id,name,color FROM labels WHERE user_id=? ORDER BY name COLLATE NOCASE')->bind($uid),
            $db->prepare('SELECT id,name,color,position FROM attention_markers WHERE user_id=? ORDER BY position,id')->bind($uid),
            $db->prepare('SELECT t.id,t.ticket_key ticketKey,t.title,t.summary,t.status_id statusId,t.priority,t.next_action nextAction,t.attention_marker_id attentionMarkerId,t.avatar_key avatarKey,t.is_focus isFocus,t.due_date dueDate,t.jira_url jiraUrl,t.created_at createdAt,t.updated_at updatedAt,t.completed_at completedAt,t.cancelled_at cancelledAt,COUNT(c.id) commentCount FROM tickets t LEFT JOIN comments c ON c.ticket_id=t.id WHERE t.user_id=? GROUP BY t.id ORDER BY t.updated_at DESC,t.id DESC')->bind($uid),
            $db->prepare('SELECT tl.ticket_id ticketId,tl.label_id labelId FROM ticket_labels tl JOIN tickets t ON t.id=tl.ticket_id WHERE t.user_id=?')->bind($uid),
            $db->prepare('SELECT c.id,c.ticket_id ticketId,c.content,c.created_at createdAt FROM comments c JOIN tickets t ON t.id=c.ticket_id WHERE t.user_id=? ORDER BY c.created_at DESC,c.id DESC')->bind($uid),
            $db->prepare('SELECT h.id,h.ticket_id ticketId,h.from_status_id fromStatusId,h.from_status_name fromStatusName,h.from_status_color fromStatusColor,h.to_status_id toStatusId,h.to_status_name toStatusName,h.to_status_color toStatusColor,h.event_type eventType,h.changed_at changedAt FROM ticket_status_history h JOIN tickets t ON t.id=h.ticket_id WHERE t.user_id=? ORDER BY h.changed_at DESC,h.id DESC')->bind($uid),
            $db->prepare('SELECT id,content,due_date dueDate,is_done isDone,created_at createdAt,completed_at completedAt FROM reminders WHERE user_id=? ORDER BY is_done,due_date,id')->bind($uid),
            $db->prepare('SELECT id,title,content,created_at createdAt,updated_at updatedAt,archived_at archivedAt FROM permanent_notes WHERE user_id=? ORDER BY archived_at IS NOT NULL,updated_at DESC,id DESC')->bind($uid),
            $db->prepare('SELECT a.id,a.note_id noteId,a.file_name fileName,a.content_type contentType,a.size_bytes sizeBytes,a.created_at createdAt FROM permanent_note_attachments a JOIN permanent_notes n ON n.id=a.note_id WHERE n.user_id=? ORDER BY a.created_at,a.id')->bind($uid),
            $db->prepare('SELECT id,title,standup_date standupDate,created_at createdAt,updated_at updatedAt FROM standup_guides WHERE user_id=? ORDER BY standup_date DESC,id DESC')->bind($uid),
            $db->prepare('SELECT i.id,i.guide_id guideId,i.section,i.position,i.content,i.ticket_key ticketKey,i.ticket_title ticketTitle,i.is_done isDone,i.created_at createdAt FROM standup_items i JOIN standup_guides g ON g.id=i.guide_id WHERE g.user_id=? ORDER BY i.guide_id,i.section,i.position,i.id')->bind($uid),
            $db->prepare('SELECT id,ticket_id ticketId,ticket_key ticketKey,ticket_title ticketTitle,jira_url jiraUrl,worked_month workedMonth,invoice_month invoiceMonth,minutes,client,project,notes,defer_reason deferReason,status,invoiced_at invoicedAt,created_at createdAt,updated_at updatedAt FROM billing_carryovers WHERE user_id=? ORDER BY status,invoice_month,id')->bind($uid),
            $db->prepare('SELECT id,task_date taskDate,content,position,is_done isDone,link_type linkType,link_id linkId,copied_from_id copiedFromId,subtasks,created_at createdAt,updated_at updatedAt,completed_at completedAt FROM agenda_tasks WHERE user_id=? ORDER BY task_date,position,id')->bind($uid),
            $db->prepare('SELECT c.id,c.task_id taskId,c.subtask_id subtaskId,c.content,c.created_at createdAt,c.updated_at updatedAt FROM agenda_task_comments c JOIN agenda_tasks a ON a.id=c.task_id WHERE a.user_id=? ORDER BY c.created_at DESC,c.id DESC')->bind($uid),
            $db->prepare('SELECT id,email,name,created_at createdAt FROM users ORDER BY id'),
        ]);
        $current = $db->prepare('SELECT id,email,name,created_at createdAt FROM users WHERE id=?')->bind($uid)->first();

        return [
            'user' => Values::publicUser($current),
            'users' => array_map(fn (object $row) => Values::publicUser($row), $users->results),
            'statuses' => $statuses->results,
            'labels' => $labels->results,
            'attentionMarkers' => $attentionMarkers->results,
            'tickets' => $tickets->results,
            'ticketLabels' => $ticketLabels->results,
            'comments' => $comments->results,
            'statusHistory' => $statusHistory->results,
            'reminders' => $reminders->results,
            'permanentNotes' => $permanentNotes->results,
            'permanentNoteAttachments' => $permanentNoteAttachments->results,
            'standups' => $standups->results,
            'standupItems' => $standupItems->results,
            'billingItems' => $billingItems->results,
            'agendaTasks' => $agendaTasks->results,
            'agendaTaskComments' => $agendaTaskComments->results,
        ];
    }
}
