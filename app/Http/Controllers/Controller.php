<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Support\Context;
use App\Support\Money;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function ctx(): Context
    {
        return app(Context::class);
    }

    protected function biz(): Business
    {
        return $this->ctx()->business ?? abort(404);
    }

    protected function require(string $permission): void
    {
        if (! $this->ctx()->can($permission)) {
            abort(403, 'You do not have permission to do that in this business.');
        }
    }

    protected function cents(mixed $value): int
    {
        try {
            return Money::parse(is_scalar($value) || $value === null ? $value : '');
        } catch (\InvalidArgumentException) {
            throw new DomainException('Enter a valid dollar amount.');
        }
    }

    /** Combine picked contact checkboxes (recipient_emails[]) and typed addresses into the `recipients` field. */
    protected function mergeRecipients(Request $request): void
    {
        $picked = array_filter((array) $request->input('recipient_emails', []), 'is_string');
        $typed = preg_split('/[,;\s]+/', (string) $request->input('recipients', '')) ?: [];
        $all = array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), array_merge($picked, $typed)))));
        $request->merge(['recipients' => implode(', ', $all)]);
    }

    protected function to(string $path, ?string $message = null)
    {
        $r = redirect('/b/'.$this->biz()->ulid.$path);

        return $message ? $r->with('status', $message) : $r;
    }

    protected function dateRange(Request $request): array
    {
        $b = $this->biz();
        $today = $b->localToday();
        $preset = $request->query('range', 'ytd');
        [$from, $to] = match ($preset) {
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$today->copy()->firstOfQuarter(), $today->copy()->lastOfQuarter()],
            'last_year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
            'custom' => [\Illuminate\Support\Carbon::parse($request->query('from', $today->copy()->startOfYear()->toDateString())), \Illuminate\Support\Carbon::parse($request->query('to', $today->toDateString()))],
            default => [app(\App\Services\Reports\ReportService::class)->fiscalYearStart($b, $today), $today],
        };

        return [$from->toDateString(), $to->toDateString(), $preset];
    }
}
