<?php

namespace App\Services\Expenses\Subscriptions\Sources;

use App\Models\Business;
use App\Services\Expenses\Subscriptions\SampleSubscriptionSource;
use App\Services\Expenses\Subscriptions\SubscriptionCharge;
use Illuminate\Support\Carbon;

/** Amazon Web Services usage bill, $245.66–$305.88 per cycle, itemized by service. */
class AwsSampleSource extends SampleSubscriptionSource
{
    private const MIN_CENTS = 24566;
    private const MAX_CENTS = 30588;

    public function key(): string { return 'aws'; }
    public function name(): string { return 'Amazon Web Services'; }
    public function summary(): string { return 'Cloud hosting usage — EC2, RDS, S3, CloudFront, Route 53'; }
    protected function epochOffset(): int { return 1; }

    protected function build(Business $b, int $cycle, Carbon $date, Carbon $periodStart, Carbon $periodEnd): SubscriptionCharge
    {
        $total = self::MIN_CENTS + (int) round($this->unit($b, 'total'.$cycle) * (self::MAX_CENTS - self::MIN_CENTS));
        $share = fn (string $svc, float $lo, float $hi) => (int) round($total * ($lo + $this->unit($b, $svc.$cycle) * ($hi - $lo)));
        $lines = [
            'Amazon Relational Database Service (RDS) — db.t4g.medium, Multi-AZ' => $share('rds', 0.20, 0.24),
            'Amazon Simple Storage Service (S3) — storage & requests' => $share('s3', 0.055, 0.075),
            'Amazon CloudFront — data transfer out & requests' => $share('cf', 0.04, 0.06),
            'AWS Data Transfer — inter-AZ & internet egress' => $share('dt', 0.05, 0.07),
            'Amazon CloudWatch — metrics, logs & alarms' => $share('cw', 0.022, 0.034),
            'Amazon Route 53 — 3 hosted zones & DNS queries' => 150 + $this->rand($b, 'r53'.$cycle, 4, 62),
        ];
        $lines = ['Amazon Elastic Compute Cloud (EC2) — t3.large & t3.medium instances, EBS volumes' => $total - array_sum($lines)] + $lines;
        $invoice = $this->digits($b, 'inv'.$cycle, 10);

        return new SubscriptionCharge(
            source: $this->key(),
            externalId: 'sub:aws:'.$invoice,
            date: $date,
            vendor: 'Amazon Web Services',
            category: 'Cloud Hosting & Software',
            paidWith: 'Business Platinum Card® (...'.$this->last4($b, 'card').')',
            statement: 'AMAZON WEB SERVICES AWS.AMAZON.CO WA',
            description: 'AWS invoice '.$invoice.' · usage '.self::period($periodStart, $periodEnd),
            amountCents: $total,
            invoiceNumber: $invoice,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            lines: array_map(fn ($d, $c) => ['description' => $d, 'amount_cents' => $c], array_keys($lines), $lines),
        );
    }
}
