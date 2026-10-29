<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Database stores UTC; staff see restaurant time (Asia/Kolkata by default). */
final class LocalTime
{
    public static function tz(): string
    {
        return config('restaurant.timezone');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz());
    }

    public static function of(?DateTimeInterface $dt): ?CarbonImmutable
    {
        return $dt ? CarbonImmutable::instance($dt)->setTimezone(self::tz()) : null;
    }

    public static function format(?DateTimeInterface $dt, string $format = 'd M Y, g:i A'): string
    {
        return $dt ? self::of($dt)->format($format) : '—';
    }

    /** "5 min ago" style, relative to now. */
    public static function ago(?DateTimeInterface $dt): string
    {
        return $dt ? self::of($dt)->diffForHumans(['short' => true]) : '—';
    }

    /** Start and end (exclusive) of a local calendar day, converted to UTC for DB queries. */
    public static function dayRangeUtc(CarbonImmutable $localDay): array
    {
        $start = $localDay->setTimezone(self::tz())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }
}
