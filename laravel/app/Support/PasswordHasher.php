<?php

declare(strict_types=1);

namespace App\Support;

final class PasswordHasher
{
    private const ITERATIONS = 120000;

    /** @return array{hash: string, salt: string} */
    public static function hash(string $password, ?string $saltB64 = null): array
    {
        $salt = $saltB64 !== null && $saltB64 !== ''
            ? self::b64ToBytes($saltB64)
            : random_bytes(16);
        $raw = hash_pbkdf2('sha256', $password, $salt, self::ITERATIONS, 32, true);

        return [
            'hash' => base64_encode($raw),
            'salt' => base64_encode($salt),
        ];
    }

    public static function verify(string $password, mixed $hash, mixed $salt): bool
    {
        $next = self::hash($password, (string) $salt);

        return self::timingSafeEqual($next['hash'], (string) $hash);
    }

    public static function timingSafeEqual(string $left, string $right): bool
    {
        $len = max(strlen($left), strlen($right));
        $diff = strlen($left) ^ strlen($right);
        for ($i = 0; $i < $len; $i++) {
            $diff |= (ord($left[$i] ?? "\0") ^ ord($right[$i] ?? "\0"));
        }

        return $diff === 0;
    }

    private static function b64ToBytes(string $value): string
    {
        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }
}
