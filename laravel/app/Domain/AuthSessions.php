<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\SqliteStore;

final class AuthSessions
{
    public static function create(SqliteStore $db, int $userId): string
    {
        $now = $db->nowExpr();
        $expires = $db->nowPlusDays(30);
        $db->prepare("DELETE FROM sessions WHERE expires_at < {$now}")->run();
        $token = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO sessions(user_id,token,expires_at) VALUES(?,?,{$expires})")->bind($userId, $token)->run();

        return $token;
    }

    public static function user(SqliteStore $db, ?string $token): ?object
    {
        if (! $token) {
            return null;
        }
        $now = $db->nowExpr();
        $db->prepare("DELETE FROM sessions WHERE expires_at < {$now}")->run();

        return $db->prepare("SELECT u.id,u.email,u.name,u.created_at createdAt FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.expires_at >= {$now}")
            ->bind($token)->first();
    }

    public static function destroy(SqliteStore $db, ?string $token): void
    {
        if ($token) {
            $db->prepare('DELETE FROM sessions WHERE token=?')->bind($token)->run();
        }
    }
}
