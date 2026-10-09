<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Counts audit events per weekday and hour of day for the administrator's overview (D060).
 *
 * The database groups events per UTC hour; PHP then moves each hour into the display time zone.
 * Every time zone offset Muninn cares about is a whole number of hours, so one UTC hour always
 * lands in exactly one local hour, and MariaDB's own time zone tables (often missing on a NAS)
 * are never needed.
 */
final class ActivityHeatMap
{
    /** Days in a week and hours in a day: the size of the grid. */
    private const DAYS_PER_WEEK = 7;
    private const HOURS_PER_DAY = 24;

    public function __construct(
        private readonly PDO $database,
        private readonly DateTimeZone $displayTimeZone,
    ) {
    }

    /**
     * A 7 × 24 grid of event counts over the last $weekCount weeks: row 0 is Monday, column 0 is
     * 00:00-00:59 local time.
     *
     * @return list<list<int>>
     */
    public function grid(string $eventType, int $weekCount): array
    {
        $grid = array_fill(0, self::DAYS_PER_WEEK, array_fill(0, self::HOURS_PER_DAY, 0));
        foreach ($this->countsPerUtcHour($eventType, $weekCount) as $utcHour => $eventCount) {
            $localTime = (new DateTimeImmutable($utcHour . ':00:00', new DateTimeZone('UTC')))->setTimezone($this->displayTimeZone);
            // 'N' is 1 (Monday) to 7 (Sunday); 'G' is the hour 0-23.
            $weekdayIndex = (int) $localTime->format('N') - 1;
            $hourIndex = (int) $localTime->format('G');
            $grid[$weekdayIndex][$hourIndex] += $eventCount;
        }

        return $grid;
    }

    /**
     * Event counts keyed by UTC hour ("2026-10-08 14").
     *
     * @return array<string, int>
     */
    private function countsPerUtcHour(string $eventType, int $weekCount): array
    {
        // Uses the (event_type, created_at) index of audit_log.
        $countStatement = $this->database->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H') AS utc_hour, COUNT(*) AS event_count
             FROM audit_log
             WHERE event_type = :event_type AND created_at >= UTC_TIMESTAMP() - INTERVAL :day_count DAY
             GROUP BY utc_hour"
        );
        $countStatement->bindValue('event_type', $eventType);
        $countStatement->bindValue('day_count', $weekCount * self::DAYS_PER_WEEK, PDO::PARAM_INT);
        $countStatement->execute();

        $countsPerHour = [];
        foreach ($countStatement->fetchAll(PDO::FETCH_ASSOC) as $hourRow) {
            $countsPerHour[(string) $hourRow['utc_hour']] = (int) $hourRow['event_count'];
        }

        return $countsPerHour;
    }
}
