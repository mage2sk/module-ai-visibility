<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Report;

/**
 * An inclusive range of UTC days for the dashboard reports.
 *
 * Built from the request filters: a preset period (7, 30 or 90 days ending today) or a custom
 * from/to pair of 'Y-m-d' dates. Invalid input falls back to the default period; a custom range
 * is swapped when reversed, never ends after today and is cut to at most MAX_DAYS days.
 * All calculations use UTC, whatever the PHP default timezone is.
 */
class DateRange
{
    public const PERIOD_CUSTOM = 'custom';
    public const PRESETS = ['7', '30', '90'];
    public const DEFAULT_PERIOD = '30';
    public const MAX_DAYS = 400;

    /**
     * @param string $from First day, 'Y-m-d'
     * @param string $to Last day (inclusive), 'Y-m-d'
     * @param string $period One of PRESETS or PERIOD_CUSTOM
     * @param bool $adjusted True when the requested range was invalid, swapped or shortened
     */
    public function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly string $period,
        private readonly bool $adjusted = false
    ) {
    }

    /**
     * Build a range from raw request values
     *
     * @param mixed $period '7' | '30' | '90' | 'custom'
     * @param mixed $from 'Y-m-d' (custom only)
     * @param mixed $to 'Y-m-d' (custom only)
     * @param int|null $now Unix time used as "today" (null = current time)
     * @return self
     */
    public static function fromInput($period, $from = null, $to = null, ?int $now = null): self
    {
        $utc = new \DateTimeZone('UTC');
        $today = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($utc)->setTime(0, 0);
        $period = is_scalar($period) ? trim((string)$period) : '';

        if ($period === self::PERIOD_CUSTOM) {
            $start = self::parseDay($from, $utc);
            $end = self::parseDay($to, $utc);
            if ($start !== null && $end !== null) {
                return self::custom($start, $end, $today);
            }
            return self::preset(self::DEFAULT_PERIOD, $today, true);
        }
        if (in_array($period, self::PRESETS, true)) {
            return self::preset($period, $today, false);
        }
        return self::preset(self::DEFAULT_PERIOD, $today, $period !== '');
    }

    /**
     * First day, 'Y-m-d'
     *
     * @return string
     */
    public function from(): string
    {
        return $this->from;
    }

    /**
     * Last day (inclusive), 'Y-m-d'
     *
     * @return string
     */
    public function to(): string
    {
        return $this->to;
    }

    /**
     * Selected period: '7' | '30' | '90' | 'custom'
     *
     * @return string
     */
    public function period(): string
    {
        return $this->period;
    }

    /**
     * Whether the range was entered as custom dates
     *
     * @return bool
     */
    public function isCustom(): bool
    {
        return $this->period === self::PERIOD_CUSTOM;
    }

    /**
     * Whether the requested range had to be corrected (invalid, reversed, too long or in the future)
     *
     * @return bool
     */
    public function wasAdjusted(): bool
    {
        return $this->adjusted;
    }

    /**
     * Start of the first day: 'Y-m-d 00:00:00' (UTC)
     *
     * @return string
     */
    public function startDateTime(): string
    {
        return $this->from . ' 00:00:00';
    }

    /**
     * Start of the day after the last day: 'Y-m-d 00:00:00' (UTC, exclusive upper bound)
     *
     * @return string
     */
    public function endDateTimeExclusive(): string
    {
        return $this->day($this->to)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    /**
     * Number of days in the range
     *
     * @return int
     */
    public function dayCount(): int
    {
        return (int)$this->day($this->from)->diff($this->day($this->to))->days + 1;
    }

    /**
     * Every day of the range in order, 'Y-m-d'
     *
     * @return string[]
     */
    public function days(): array
    {
        $days = [];
        $current = $this->day($this->from);
        $count = $this->dayCount();
        for ($i = 0; $i < $count; $i++) {
            $days[] = $current->format('Y-m-d');
            $current = $current->modify('+1 day');
        }
        return $days;
    }

    /**
     * Range for the preset period ending today
     *
     * @param string $period
     * @param \DateTimeImmutable $today
     * @param bool $adjusted
     * @return self
     */
    private static function preset(string $period, \DateTimeImmutable $today, bool $adjusted): self
    {
        $start = $today->modify('-' . ((int)$period - 1) . ' days');
        return new self($start->format('Y-m-d'), $today->format('Y-m-d'), $period, $adjusted);
    }

    /**
     * Custom range: swapped when reversed, not after today, at most MAX_DAYS long
     *
     * @param \DateTimeImmutable $start
     * @param \DateTimeImmutable $end
     * @param \DateTimeImmutable $today
     * @return self
     */
    private static function custom(\DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $today): self
    {
        $adjusted = false;
        if ($start > $end) {
            [$start, $end] = [$end, $start];
            $adjusted = true;
        }
        if ($end > $today) {
            $end = $today;
            $adjusted = true;
        }
        if ($start > $end) {
            $start = $end;
        }
        if ((int)$start->diff($end)->days + 1 > self::MAX_DAYS) {
            $start = $end->modify('-' . (self::MAX_DAYS - 1) . ' days');
            $adjusted = true;
        }
        return new self($start->format('Y-m-d'), $end->format('Y-m-d'), self::PERIOD_CUSTOM, $adjusted);
    }

    /**
     * Strictly parse a 'Y-m-d' day in UTC, null when invalid
     *
     * @param mixed $value
     * @param \DateTimeZone $utc
     * @return \DateTimeImmutable|null
     */
    private static function parseDay($value, \DateTimeZone $utc): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) {
            return null;
        }
        if ((int)$m[1] < 2000 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $utc);
        return $date === false ? null : $date;
    }

    /**
     * Day as a UTC date object
     *
     * @param string $day
     * @return \DateTimeImmutable
     */
    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone('UTC'));
    }
}
