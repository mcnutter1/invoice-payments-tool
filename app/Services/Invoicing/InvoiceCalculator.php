<?php

namespace App\Services\Invoicing;

use App\Exceptions\DomainException;
use App\Support\Money;
use Brick\Math\BigDecimal;

/**
 * Deterministic invoice math (see docs/ACCOUNTING_POLICIES.md#rounding):
 * 1. line amount = round_half_up(qty × unit price) to cents
 * 2. document discount allocated proportionally to line amounts, residual cents by largest remainder (ties: line order)
 * 3. exclusive tax per line = round_half_up(discounted line × rate)
 * 4. total = Σ discounted lines + Σ line tax
 */
class InvoiceCalculator
{
    /**
     * @param  list<array{quantity:string|int|float, unit_price:string|int|float, tax_rate?:string|int|float|null, tax_rate_id?:int|null, tax_name?:string|null}>  $lines
     * @return array{lines:list<array>, subtotal_cents:int, discount_cents:int, tax_cents:int, total_cents:int, tax_lines:list<array>}
     */
    public function calculate(array $lines, string $discountType = 'none', string|int|float $discountValue = 0, bool $taxExempt = false): array
    {
        $out = [];
        $amounts = [];
        foreach (array_values($lines) as $i => $l) {
            $qty = $this->dec($l['quantity'] ?? 0);
            $price = $this->dec($l['unit_price'] ?? 0);
            $amount = Money::extend($qty, $price);
            if ($amount < 0) {
                throw new DomainException('Line '.($i + 1).' is negative. Use a credit note instead of negative invoice lines.');
            }
            $amounts[$i] = $amount;
            $out[$i] = $l + ['quantity' => $qty, 'unit_price' => $price, 'amount_cents' => $amount];
        }
        $subtotal = array_sum($amounts);

        $discount = 0;
        $discountValue = $this->dec($discountValue);
        if ($discountType === 'percent') {
            if (BigDecimal::of($discountValue)->isLessThan(0) || BigDecimal::of($discountValue)->isGreaterThan(100)) {
                throw new DomainException('Discount percent must be between 0 and 100.');
            }
            $discount = Money::percentOf($subtotal, $discountValue);
        } elseif ($discountType === 'amount') {
            $discount = Money::parse($discountValue);
            if ($discount < 0) throw new DomainException('Discount cannot be negative.');
            $discount = min($discount, $subtotal);
        }
        $alloc = Money::allocate($discount, $amounts);

        $taxGroups = [];
        $taxTotal = 0;
        foreach ($out as $i => &$l) {
            $l['discount_cents'] = $alloc[$i] ?? 0;
            $l['net_cents'] = $l['amount_cents'] - $l['discount_cents'];
            $rate = $taxExempt ? '0' : $this->dec($l['tax_rate'] ?? 0);
            if (BigDecimal::of($rate)->isLessThan(0)) throw new DomainException('Tax rate cannot be negative.');
            $l['tax_rate'] = $rate;
            $l['tax_cents'] = BigDecimal::of($rate)->isZero() ? 0 : Money::percentOf($l['net_cents'], $rate);
            $taxTotal += $l['tax_cents'];
            if ($l['tax_cents'] > 0 || ! BigDecimal::of($rate)->isZero()) {
                $key = ($l['tax_rate_id'] ?? 'x').'|'.$rate;
                $taxGroups[$key] ??= [
                    'tax_rate_id' => $l['tax_rate_id'] ?? null,
                    'name' => $l['tax_name'] ?? ('Tax '.rtrim(rtrim($rate, '0'), '.').'%'),
                    'rate' => $rate,
                    'taxable_cents' => 0,
                    'tax_cents' => 0,
                ];
                $taxGroups[$key]['taxable_cents'] += $l['net_cents'];
                $taxGroups[$key]['tax_cents'] += $l['tax_cents'];
            }
        }
        unset($l);

        return [
            'lines' => array_values($out),
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'tax_cents' => $taxTotal,
            'total_cents' => $subtotal - $discount + $taxTotal,
            'tax_lines' => array_values($taxGroups),
        ];
    }

    private function dec(string|int|float|null $v): string
    {
        $s = trim(str_replace([',', '$'], '', (string) ($v ?? '0')));
        if ($s === '') return '0';
        if (! preg_match('/^-?\d*(\.\d+)?$/', $s)) {
            throw new DomainException('Invalid number: '.$s);
        }

        return (string) BigDecimal::of($s);
    }
}
