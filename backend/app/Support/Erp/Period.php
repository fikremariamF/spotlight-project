<?php

namespace App\Support\Erp;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A date range in Addis Ababa days: today, the last 7 or 30 days, this month, last month, this year
 * so far, or custom from/to.
 */
final class Period
{
    private function __construct(
        public readonly string $name,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function fromRequest(Request $request, string $default = '30d'): self
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:today,7d,30d,month,last_month,year,custom'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $tz = config('marketplace.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $name = $data['period'] ?? (isset($data['from']) || isset($data['to']) ? 'custom' : $default);

        [$from, $to] = match ($name) {
            'today' => [$today, $today],
            '7d' => [$today->subDays(6), $today],
            '30d' => [$today->subDays(29), $today],
            'month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'year' => [$today->startOfYear(), $today],
            'custom' => [
                isset($data['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay() : $today->subDays(29),
                isset($data['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $tz)->startOfDay() : $today,
            ],
        };
        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['from' => 'Pick a range of one year or less.']);
        }

        return new self($name, $from, $to);
    }

    /** UTC bounds for database queries. @return array{CarbonImmutable, CarbonImmutable} */
    public function bounds(): array
    {
        return [$this->from->utc(), $this->to->endOfDay()->utc()];
    }

    /** Every local day in the range, as Y-m-d. @return list<string> */
    public function days(): array
    {
        $days = [];
        for ($d = $this->from; $d <= $this->to; $d = $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return $days;
    }

    public function dayOf(?\DateTimeInterface $at): ?string
    {
        return $at ? CarbonImmutable::instance($at)->setTimezone(config('marketplace.timezone'))->toDateString() : null;
    }

    /** @return array{period: string, from: string, to: string, timezone: string} */
    public function toArray(): array
    {
        return ['period' => $this->name, 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'timezone' => config('marketplace.timezone')];
    }
}
