<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\SqliteStore;

final class UserAccess
{
    public const SUPERADMIN = 'superadmin';

    public const USER = 'user';

    public static function normalize(mixed $value): string
    {
        return (string) $value === self::SUPERADMIN ? self::SUPERADMIN : self::USER;
    }

    public static function isSuperadmin(?object $user): bool
    {
        return self::normalize($user->role ?? null) === self::SUPERADMIN;
    }

    public static function roleOf(?object $user): string
    {
        return self::normalize($user->role ?? null);
    }

    public static function find(SqliteStore $db, int $userId): ?object
    {
        return $db->prepare('SELECT id,email,name,role,created_at createdAt FROM users WHERE id=?')->bind($userId)->first();
    }

    public static function requireSuperadmin(SqliteStore $db, int $userId): object
    {
        $user = self::find($db, $userId);
        if (! $user || ! self::isSuperadmin($user)) {
            throw new ForbiddenException('No tienes permiso para gestionar usuarios.');
        }

        return $user;
    }

    public static function superadminCount(SqliteStore $db, ?int $exceptId = null): int
    {
        $sql = 'SELECT COUNT(*) total FROM users WHERE role=?';
        $row = $exceptId
            ? $db->prepare($sql.' AND id<>?')->bind(self::SUPERADMIN, $exceptId)->first()
            : $db->prepare($sql)->bind(self::SUPERADMIN)->first();

        return (int) ($row->total ?? 0);
    }
}
