<?php

declare(strict_types=1);

namespace Muninn\Api\MagicLinks;

use DateTimeImmutable;
use DateTimeZone;

/**
 * When a Magic Link may be used (D059): between valid_from and valid_until (UTC), and, when a
 * daily window is set, only between its start and end time of day in the configured time zone.
 *
 * The time zone is always passed in explicitly (magic_links.timezone), never taken from PHP's or
 * the server's default, so "07:00-18:00" means the same thing on every machine (CLAUDE.md).
 * A window whose start is later than its end runs over midnight: "22:00-06:00" covers the night.
 * The start time is included and the end time is not, so 07:00-18:00 stops at 18:00:00 sharp.
 */
final class MagicLinkSchedule
{
    /** Answer of check(): the link may be used right now. */
    public const USABLE = 'usable';
    /** Answer of check(): valid_from is still in the future. */
    public const NOT_YET_VALID = 'not_yet_valid';
    /** Answer of check(): valid_until has passed. */
    public const EXPIRED = 'expired';
    /** Answer of check(): within the dates, but outside the daily window. */
    public const OUTSIDE_DAILY_WINDOW = 'outside_daily_window';

    /**
     * @param DateTimeImmutable $validFromUtc  First moment the link works.
     * @param DateTimeImmutable $validUntilUtc First moment the link no longer works.
     * @param string|null $dailyStartTime "HH:MM" or "HH:MM:SS" local time, or null for no window.
     * @param string|null $dailyEndTime   Same; set together with $dailyStartTime.
     */
    public function __construct(
        private readonly DateTimeImmutable $validFromUtc,
        private readonly DateTimeImmutable $validUntilUtc,
        private readonly ?string $dailyStartTime,
        private readonly ?string $dailyEndTime,
        private readonly DateTimeZone $windowTimeZone,
    ) {
    }

    /** Says whether the link may be used at $momentUtc, and if not, why. */
    public function check(DateTimeImmutable $momentUtc): string
    {
        if ($momentUtc < $this->validFromUtc) {
            return self::NOT_YET_VALID;
        }
        if ($momentUtc >= $this->validUntilUtc) {
            return self::EXPIRED;
        }
        if (!$this->isInsideDailyWindow($momentUtc)) {
            return self::OUTSIDE_DAILY_WINDOW;
        }

        return self::USABLE;
    }

    /** True when there is no daily window, or $momentUtc falls inside it in the window's time zone. */
    private function isInsideDailyWindow(DateTimeImmutable $momentUtc): bool
    {
        if ($this->dailyStartTime === null || $this->dailyEndTime === null) {
            return true;
        }

        // Compare minutes since local midnight, so no date arithmetic (or DST edge) is involved.
        $localMoment = $momentUtc->setTimezone($this->windowTimeZone);
        $localMinuteOfDay = (int) $localMoment->format('G') * 60 + (int) $localMoment->format('i');
        $startMinuteOfDay = self::minuteOfDay($this->dailyStartTime);
        $endMinuteOfDay = self::minuteOfDay($this->dailyEndTime);

        if ($startMinuteOfDay < $endMinuteOfDay) {
            // Same-day window, e.g. 07:00-18:00.
            return $localMinuteOfDay >= $startMinuteOfDay && $localMinuteOfDay < $endMinuteOfDay;
        }

        // Window over midnight, e.g. 22:00-06:00: late evening or early morning.
        return $localMinuteOfDay >= $startMinuteOfDay || $localMinuteOfDay < $endMinuteOfDay;
    }

    /** "07:30" or "07:30:00" → 450. The value always comes from the database or validated input. */
    public static function minuteOfDay(string $timeOfDay): int
    {
        $timeParts = explode(':', $timeOfDay);

        return (int) $timeParts[0] * 60 + (int) ($timeParts[1] ?? 0);
    }

    /**
     * Parses "HH:MM" (24-hour clock) from a client, or returns null when it is not a valid time.
     * The result is normalised to "HH:MM".
     */
    public static function parseTimeOfDay(string $timeInput): ?string
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($timeInput), $timeMatches)) {
            return null;
        }

        return $timeMatches[1] . ':' . $timeMatches[2];
    }
}
