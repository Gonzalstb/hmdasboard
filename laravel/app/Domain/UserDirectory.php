<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\FileStore;
use App\Support\PasswordHasher;
use App\Support\SqliteStore;
use App\Support\Values;

final class UserDirectory
{
    public static function create(SqliteStore $db, object $payload): array
    {
        $role = UserAccess::normalize($payload->role ?? UserAccess::USER);

        return SchemaInstaller::createUser($db, $payload, true, $role);
    }

    public static function update(SqliteStore $db, int $actorId, object $payload): void
    {
        UserAccess::requireSuperadmin($db, $actorId);
        $id = (int) Values::number($payload->id ?? 0);
        $target = UserAccess::find($db, $id);
        if (! $target) {
            throw new \RuntimeException('El usuario ya no existe.');
        }
        $email = Values::normalizeEmail($payload->email ?? '');
        $name = substr(Values::text($payload->name ?? null), 0, 80);
        $role = UserAccess::normalize($payload->role ?? $target->role);
        if ($name === '') {
            throw new \RuntimeException('Escribe un nombre.');
        }
        if (! Values::validEmail($email)) {
            throw new \RuntimeException('Escribe un correo válido.');
        }
        $taken = $db->prepare('SELECT id FROM users WHERE email=? AND id<>?')->bind($email, $id)->first();
        if ($taken) {
            throw new \RuntimeException('Ese correo ya está registrado.');
        }
        if (UserAccess::isSuperadmin($target) && $role !== UserAccess::SUPERADMIN && UserAccess::superadminCount($db, $id) === 0) {
            throw new \RuntimeException('Debe quedar al menos un superusuario.');
        }
        $db->prepare('UPDATE users SET email=?,name=?,role=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->bind($email, $name, $role, $id)->run();
    }

    public static function resetPassword(SqliteStore $db, int $actorId, object $payload): void
    {
        UserAccess::requireSuperadmin($db, $actorId);
        $id = (int) Values::number($payload->id ?? 0);
        $password = (string) ($payload->password ?? '');
        if (! UserAccess::find($db, $id)) {
            throw new \RuntimeException('El usuario ya no existe.');
        }
        self::assertPassword($password);
        $hashed = PasswordHasher::hash($password);
        $db->prepare('UPDATE users SET password_hash=?,password_salt=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->bind($hashed['hash'], $hashed['salt'], $id)->run();
        $db->prepare('DELETE FROM sessions WHERE user_id=?')->bind($id)->run();
    }

    public static function delete(SqliteStore $db, FileStore $files, int $actorId, object $payload): void
    {
        UserAccess::requireSuperadmin($db, $actorId);
        $id = (int) Values::number($payload->id ?? 0);
        $target = UserAccess::find($db, $id);
        if (! $target) {
            throw new \RuntimeException('El usuario ya no existe.');
        }
        if ($id === $actorId) {
            throw new \RuntimeException('No puedes eliminar tu propio usuario.');
        }
        if (UserAccess::isSuperadmin($target) && UserAccess::superadminCount($db, $id) === 0) {
            throw new \RuntimeException('Debe quedar al menos un superusuario.');
        }
        $attachments = $db->prepare('SELECT a.storage_key storageKey FROM permanent_note_attachments a JOIN permanent_notes n ON n.id=a.note_id WHERE n.user_id=?')
            ->bind($id)->all()->results;
        foreach ($attachments as $file) {
            $files->delete((string) $file->storageKey);
        }
        $db->batch([
            $db->prepare('DELETE FROM ticket_labels WHERE ticket_id IN (SELECT id FROM tickets WHERE user_id=?)')->bind($id),
            $db->prepare('DELETE FROM comments WHERE ticket_id IN (SELECT id FROM tickets WHERE user_id=?)')->bind($id),
            $db->prepare('DELETE FROM ticket_status_history WHERE ticket_id IN (SELECT id FROM tickets WHERE user_id=?)')->bind($id),
            $db->prepare('DELETE FROM agenda_task_comments WHERE task_id IN (SELECT id FROM agenda_tasks WHERE user_id=?)')->bind($id),
            $db->prepare('DELETE FROM standup_items WHERE guide_id IN (SELECT id FROM standup_guides WHERE user_id=?)')->bind($id),
            $db->prepare('DELETE FROM permanent_note_attachments WHERE note_id IN (SELECT id FROM permanent_notes WHERE user_id=?)')->bind($id),
            $db->prepare('DELETE FROM tickets WHERE user_id=?')->bind($id),
            $db->prepare('DELETE FROM reminders WHERE user_id=?')->bind($id),
            $db->prepare('DELETE FROM permanent_notes WHERE user_id=?')->bind($id),
            $db->prepare('DELETE FROM standup_guides WHERE user_id=?')->bind($id),
            $db->prepare('DELETE FROM agenda_tasks WHERE user_id=?')->bind($id),
            $db->prepare('DELETE FROM billing_carryovers WHERE user_id=?')->bind($id),
            $db->prepare('DELETE FROM statuses WHERE user_id=?')->bind($id),
            $db->prepare('DELETE FROM labels WHERE user_id=?')->bind($id),
            $db->prepare('DELETE FROM attention_markers WHERE user_id=?')->bind($id),
            $db->prepare('DELETE FROM sessions WHERE user_id=?')->bind($id),
            $db->prepare('DELETE FROM users WHERE id=?')->bind($id),
        ]);
    }

    public static function assertPassword(string $password): void
    {
        $length = strlen($password);
        if ($length < 6) {
            throw new \RuntimeException('La contraseña debe tener al menos 6 caracteres.');
        }
        if ($length > 200) {
            throw new \RuntimeException('La contraseña es demasiado larga.');
        }
    }
}
