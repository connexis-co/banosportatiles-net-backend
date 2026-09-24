<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

/**
 * Pure time helpers for the daily rebuild ("HH:MM" in the site timezone, America/Bogota by default).
 */
final class DailySchedule
{
    /** "4:00", "04:00" or "04:00:00" (SCF time picker) → "04:00"; anything else → null. */
    public static function normalize(string $value): ?string
    {
        if (preg_match('/^\s*(\d{1,2}):(\d{2})(?::\d{2})?\s*$/', $value, $m) !== 1) {
            return null;
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];

        return ($hour <= 23 && $minute <= 59) ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    /** Unix timestamp of the next "HH:MM" in $timezone strictly after $now. */
    public static function nextRun(string $time, \DateTimeZone $timezone, int $now): int
    {
        $time = self::normalize($time) ?? '04:00';
        [$hour, $minute] = array_map('intval', explode(':', $time));

        $today = (new \DateTimeImmutable('@'.$now))->setTimezone($timezone)->setTime($hour, $minute);

        return $today->getTimestamp() > $now ? $today->getTimestamp() : $today->modify('+1 day')->getTimestamp();
    }
}
