<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Resolves a reporting window from a preset name or an explicit custom range.
 *
 * Reporting must never accept an unbounded window silently: a report without a
 * range would scan the whole table, so an unknown/absent preset falls back to
 * "this month" and the resolved bounds are always explicit.
 */
final class ReportPeriod
{
    public const PRESETS = ['today', 'yesterday', 'week', 'month', 'quarter', 'year', 'custom'];

    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $preset,
    ) {}

    public static function resolve(?string $preset, ?string $from, ?string $to): self
    {
        $now = now();
        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'month';

        if ($preset === 'custom' && $from && $to) {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();

            // A reversed range is normalised instead of producing an empty report.
            if ($start->gt($end)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }

            return new self($start, $end, 'custom');
        }

        [$start, $end] = match ($preset) {
            'today'     => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'week'      => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month'     => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'quarter'   => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'year'      => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default     => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };

        return new self($start, $end, $preset);
    }

    public function label(): string
    {
        if ($this->preset === 'custom') {
            return $this->from->format('d M Y').' – '.$this->to->format('d M Y');
        }

        return match ($this->preset) {
            'today'     => 'Today',
            'yesterday' => 'Yesterday',
            'week'      => 'This week',
            'month'     => 'This month',
            'quarter'   => 'This quarter',
            'year'      => 'This year',
            default     => 'Custom range',
        };
    }

    /** The equally long window immediately before this one, for period-over-period comparison. */
    public function previous(): self
    {
        $length = $this->from->diffInSeconds($this->to);

        return new self(
            $this->from->copy()->subSeconds($length + 1),
            $this->from->copy()->subSecond(),
            $this->preset,
        );
    }
}