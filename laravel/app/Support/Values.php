<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\UserAccess;

final class Values
{
    public static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    public static function number(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : 0;
        }
        $number = is_numeric($value) ? $value + 0 : 0;

        return is_finite((float) $number) ? $number : 0;
    }

    public static function color(mixed $value): string
    {
        $text = self::text($value);

        return preg_match('/^#[0-9a-f]{6}$/i', $text) === 1 ? $text : '#64748B';
    }

    /** @return list<int> */
    public static function idList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $number = self::number($item);
            if (is_int($number) && $number > 0 && ! in_array($number, $ids, true)) {
                $ids[] = $number;
            }
        }

        return $ids;
    }

    public static function madridDateKey(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m-d');
    }

    public static function normalizeEmail(mixed $value): string
    {
        return strtolower(self::text($value));
    }

    public static function validEmail(string $value): bool
    {
        return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', self::normalizeEmail($value));
    }

    public static function publicUser(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'email' => (string) $row->email,
            'name' => (string) ($row->name ?? ''),
            'role' => UserAccess::roleOf($row),
            'createdAt' => $row->createdAt ?? $row->created_at ?? null,
        ];
    }
}
