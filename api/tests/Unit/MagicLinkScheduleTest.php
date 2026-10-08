<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Muninn\Api\MagicLinks\MagicLinkSchedule;
use PHPUnit\Framework\TestCase;

/**
 * Magic Link dates and daily windows (D059), evaluated in an explicit time zone.
 */
final class MagicLinkScheduleTest extends TestCase
{
    private const STOCKHOLM = 'Europe/Stockholm';

    public function testDatesAreInclusiveStartAndExclusiveEnd(): void
    {
        $schedule = $this->schedule('2026-10-01 00:00:00', '2026-10-31 00:00:00');

        self::assertSame(MagicLinkSchedule::NOT_YET_VALID, $schedule->check($this->utc('2026-09-30 23:59:59')));
        self::assertSame(MagicLinkSchedule::USABLE, $schedule->check($this->utc('2026-10-01 00:00:00')));
        self::assertSame(MagicLinkSchedule::USABLE, $schedule->check($this->utc('2026-10-30 23:59:59')));
        self::assertSame(MagicLinkSchedule::EXPIRED, $schedule->check($this->utc('2026-10-31 00:00:00')));
    }

    public function testDailyWindowIsEvaluatedInTheConfiguredTimeZoneNotUtc(): void
    {
        // 07:00-18:00 Stockholm. On 2026-10-08 Stockholm is UTC+2 (summer time).
        $schedule = $this->schedule('2026-10-01 00:00:00', '2026-12-31 00:00:00', '07:00', '18:00');

        self::assertSame(MagicLinkSchedule::OUTSIDE_DAILY_WINDOW, $schedule->check($this->utc('2026-10-08 04:59:00')), '06:59 local');
        self::assertSame(MagicLinkSchedule::USABLE, $schedule->check($this->utc('2026-10-08 05:00:00')), '07:00 local');
        self::assertSame(MagicLinkSchedule::USABLE, $schedule->check($this->utc('2026-10-08 15:59:00')), '17:59 local');
        self::assertSame(MagicLinkSchedule::OUTSIDE_DAILY_WINDOW, $schedule->check($this->utc('2026-10-08 16:00:00')), '18:00 local');
    }

    public function testDailyWindowFollowsDaylightSavingTime(): void
    {
        // After 2026-10-25 Stockholm is UTC+1, so 07:00 local is 06:00 UTC.
        $schedule = $this->schedule('2026-10-01 00:00:00', '2026-12-31 00:00:00', '07:00', '18:00');

        self::assertSame(MagicLinkSchedule::OUTSIDE_DAILY_WINDOW, $schedule->check($this->utc('2026-11-02 05:30:00')), '06:30 local');
        self::assertSame(MagicLinkSchedule::USABLE, $schedule->check($this->utc('2026-11-02 06:00:00')), '07:00 local');
    }

    public function testWindowOverMidnight(): void
    {
        // 22:00-06:00 Stockholm (UTC+1 in November).
        $schedule = $this->schedule('2026-11-01 00:00:00', '2026-12-31 00:00:00', '22:00', '06:00');

        self::assertSame(MagicLinkSchedule::USABLE, $schedule->check($this->utc('2026-11-02 21:30:00')), '22:30 local');
        self::assertSame(MagicLinkSchedule::USABLE, $schedule->check($this->utc('2026-11-03 04:59:00')), '05:59 local');
        self::assertSame(MagicLinkSchedule::OUTSIDE_DAILY_WINDOW, $schedule->check($this->utc('2026-11-03 05:00:00')), '06:00 local');
        self::assertSame(MagicLinkSchedule::OUTSIDE_DAILY_WINDOW, $schedule->check($this->utc('2026-11-03 11:00:00')), 'noon local');
    }

    public function testDatesWinOverTheDailyWindow(): void
    {
        $schedule = $this->schedule('2026-10-01 00:00:00', '2026-10-02 00:00:00', '00:00', '23:59');

        self::assertSame(MagicLinkSchedule::EXPIRED, $schedule->check($this->utc('2026-10-05 10:00:00')));
    }

    public function testTimeOfDayParsing(): void
    {
        self::assertSame('07:05', MagicLinkSchedule::parseTimeOfDay('07:05'));
        self::assertSame('23:59', MagicLinkSchedule::parseTimeOfDay(' 23:59 '));
        self::assertNull(MagicLinkSchedule::parseTimeOfDay('24:00'));
        self::assertNull(MagicLinkSchedule::parseTimeOfDay('7:05'));
        self::assertNull(MagicLinkSchedule::parseTimeOfDay('07:60'));
        self::assertNull(MagicLinkSchedule::parseTimeOfDay('07:05:00'));
        self::assertSame(450, MagicLinkSchedule::minuteOfDay('07:30:00'));
    }

    private function schedule(string $validFromUtc, string $validUntilUtc, ?string $dailyStart = null, ?string $dailyEnd = null): MagicLinkSchedule
    {
        return new MagicLinkSchedule($this->utc($validFromUtc), $this->utc($validUntilUtc), $dailyStart, $dailyEnd, new DateTimeZone(self::STOCKHOLM));
    }

    private function utc(string $dateTime): DateTimeImmutable
    {
        return new DateTimeImmutable($dateTime, new DateTimeZone('UTC'));
    }
}
