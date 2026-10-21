<?php

declare(strict_types=1);

namespace App\Service;

class MemoryLimit
{
    /**
     * Raise `memory_limit` to at least the given php.ini shorthand value (`512M`, `1G`).
     *
     * The limit is never lowered, and an unlimited limit (0 or less) is left alone.
     */
    public static function raiseTo(string $required): void
    {
        $currentLimitInBytes = self::toBytes(ini_get('memory_limit'));

        if ($currentLimitInBytes > 0 && $currentLimitInBytes < self::toBytes($required)) {
            ini_set('memory_limit', $required);
        }
    }

    /**
     * Resolve a php.ini shorthand byte value (`512M`, `1G`, `-1`) to bytes.
     */
    private static function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $amount = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $amount * 1024 * 1024 * 1024,
            'm' => $amount * 1024 * 1024,
            'k' => $amount * 1024,
            default => $amount,
        };
    }
}
