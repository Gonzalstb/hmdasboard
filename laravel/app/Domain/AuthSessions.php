<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\SqliteStore;
use App\Support\Values;

final class AuthSessions
{
    public static function create(SqliteStore $db, int $userId): string
    {
        $db->prepare("DELETE FROM sessions WHERE expires_at < datetime('now')")->run();
        $token = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO sessions(user_id,token,expires_at) VALUES(?,?,datetime('now','+30 days'))")->bind($userId, $token)->run();

        return $token;
    }

    public static function user(SqliteStore $db, ?string $token): ?object
    {
        if (! $token) {
            return null;
        }
        $db->prepare("DELETE FROM sessions WHERE expires_at < datetime('now')")->run();

        return $db->prepare("SELECT u.id,u.email,u.name,u.created_at createdAt FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.expires_at >= datetime('now')")
            ->bind($token)->first();
    }

    public static function destroy(SqliteStore $db, ?string $token): void
    {
        if ($token) {
            $db->prepare('DELETE FROM sessions WHERE token=?')->bind($token)->run();
        }
    }
}
