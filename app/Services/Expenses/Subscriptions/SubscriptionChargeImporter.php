<?php

namespace App\Services\Expenses\Subscriptions;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\Expense;
use App\Models\PrivateDocument;
use App\Services\Audit;
use App\Services\Documents\DocumentStore;
use Illuminate\Support\Carbon;

/**
 * Registry of subscription charge sources plus the "pull" step: charges are fetched once and frozen in a
 * private JSON snapshot, which the regular expense-import review and commit then work from.
 */
class SubscriptionChargeImporter
{
    public const DOC_KIND = 'import_subscription';
    public const BATCH_KIND = 'expense_subscription';

    public function __construct(private readonly DocumentStore $docs) {}

    /** @return array<string, SubscriptionSource> keyed by source key, in configured order */
    public function sources(): array
    {
        $out = [];
        foreach ((array) config('finance.subscription_sources', []) as $class) {
            $s = app($class);
            if (! $s instanceof SubscriptionSource) throw new \LogicException($class.' must implement '.SubscriptionSource::class);
            $out[$s->key()] = $s;
        }

        return $out;
    }

    /** Per-source status for the import page. */
    public function overview(Business $b): array
    {
        $today = $b->localToday();

        return array_map(function (SubscriptionSource $s) use ($b, $today) {
            $q = Expense::where('business_id', $b->id)->whereNull('voided_at')->where('external_id', 'like', 'sub:'.$s->key().':%');
            $last = (clone $q)->max('expense_date');

            return [
                'key' => $s->key(), 'name' => $s->name(), 'summary' => $s->summary(), 'mode' => $s->mode(), 'configured' => $s->isConfigured($b),
                'next' => $s->nextChargeDate($b, $today),
                'last_imported' => $last ? Carbon::parse($last) : null,
                'imported_count' => $q->count(),
            ];
        }, $this->sources());
    }

    /** Fetch charges from the chosen sources and store them as a snapshot document for review. */
    public function pull(Business $b, array $keys, Carbon $from, ?Carbon $to = null): PrivateDocument
    {
        $sources = array_intersect_key($this->sources(), array_flip($keys));
        if (! $sources) throw new DomainException('Choose at least one source.');
        $to ??= $b->localToday();
        $charges = [];
        foreach ($sources as $s) {
            if (! $s->isConfigured($b)) throw new DomainException($s->name().' is not connected yet.');
            foreach ($s->charges($b, $from->copy(), $to->copy()) as $c) {
                $charges[] = $c->toArray($s->name());
            }
        }
        usort($charges, fn ($a, $z) => [$a['date'], $a['source']] <=> [$z['date'], $z['source']]);
        $snapshot = [
            'pulled_at' => now()->toIso8601String(),
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'sources' => array_map(fn ($s) => ['key' => $s->key(), 'name' => $s->name(), 'mode' => $s->mode()], array_values($sources)),
            'charges' => $charges,
        ];
        $doc = $this->docs->put($b, self::DOC_KIND, 'subscription-charges-'.$from->toDateString().'-to-'.$to->toDateString().'.json', json_encode($snapshot, JSON_THROW_ON_ERROR), 'application/json');
        Audit::log($b, 'expenses.subscriptions_pulled', $doc, null, ['sources' => array_keys($sources), 'charges' => count($charges), 'from' => $snapshot['from'], 'to' => $snapshot['to']]);

        return $doc;
    }

    public static function snapshot(PrivateDocument $doc): array
    {
        return json_decode($doc->contents(), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function label(array $snapshot): string
    {
        return 'Subscriptions: '.implode(', ', array_column($snapshot['sources'] ?? [], 'name')).' · '
            .Carbon::parse($snapshot['from'])->format('M j').' – '.Carbon::parse($snapshot['to'])->format('M j, Y');
    }
}
