<?php

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Services\Invoicing\InvoiceCalculator;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

/** Golden numeric fixtures for invoice math (docs/ACCOUNTING_POLICIES.md#rounding). */
class InvoiceMathTest extends TestCase
{
    public function test_money_parse_and_format(): void
    {
        $this->assertSame(123456, Money::parse('1,234.56'));
        $this->assertSame(1001, Money::parse('10.005'));       // half-up
        $this->assertSame(-1001, Money::parse('-10.005'));
        $this->assertSame('$1,234.56', Money::format(123456));
        $this->assertSame('-$0.05', Money::format(-5));
    }

    public function test_allocate_largest_remainder_with_ties_in_order(): void
    {
        $this->assertSame([34, 33, 33], array_values(Money::allocate(100, [1, 1, 1])));
        $this->assertSame([0 => 50, 1 => 50], Money::allocate(100, [0 => 10, 1 => 10]));
        $r = Money::allocate(1000, [3333, 3333, 3334]);
        $this->assertSame(1000, array_sum($r));
        $this->assertSame([333, 333, 334], array_values($r));
    }

    public function test_line_rounding_half_up(): void
    {
        $r = (new InvoiceCalculator)->calculate([['quantity' => '3', 'unit_price' => '0.335']]);
        $this->assertSame(101, $r['lines'][0]['amount_cents']); // 1.005 -> 1.01
        $this->assertSame(101, $r['total_cents']);
    }

    public function test_mixed_taxable_and_non_taxable_lines(): void
    {
        $r = (new InvoiceCalculator)->calculate([
            ['quantity' => '1', 'unit_price' => '1000', 'tax_rate' => '0'],
            ['quantity' => '2', 'unit_price' => '49.99', 'tax_rate' => '6.25', 'tax_rate_id' => 7, 'tax_name' => 'MA'],
        ]);
        $this->assertSame(109998, $r['subtotal_cents']);
        $this->assertSame(625, $r['tax_cents']); // 99.98 * 6.25% = 6.24875 -> 6.25
        $this->assertSame(110623, $r['total_cents']);
        $this->assertCount(1, $r['tax_lines']);
        $this->assertSame(9998, $r['tax_lines'][0]['taxable_cents']);
    }

    public function test_discount_allocated_before_tax_with_deterministic_residual(): void
    {
        $r = (new InvoiceCalculator)->calculate([
            ['quantity' => '1', 'unit_price' => '100', 'tax_rate' => '10'],
            ['quantity' => '1', 'unit_price' => '100', 'tax_rate' => '0'],
            ['quantity' => '1', 'unit_price' => '100', 'tax_rate' => '10'],
        ], 'amount', '10.00');
        $this->assertSame(1000, $r['discount_cents']);
        $this->assertSame([334, 333, 333], array_column($r['lines'], 'discount_cents'));
        $this->assertSame([967, 0, 967], array_column($r['lines'], 'tax_cents')); // 96.66*10% = 9.666 -> 9.67 ; 96.67*10% -> 9.667 -> 9.67
        $this->assertSame(30000 - 1000 + 1934, $r['total_cents']);
    }

    public function test_percent_discount_and_tax_exempt_client(): void
    {
        $r = (new InvoiceCalculator)->calculate([['quantity' => '1', 'unit_price' => '200', 'tax_rate' => '8']], 'percent', '12.5', true);
        $this->assertSame(2500, $r['discount_cents']);
        $this->assertSame(0, $r['tax_cents']);
        $this->assertSame(17500, $r['total_cents']);
    }

    public function test_zero_total_allowed_negative_lines_rejected(): void
    {
        $this->assertSame(0, (new InvoiceCalculator)->calculate([['quantity' => '1', 'unit_price' => '0']])['total_cents']);
        $this->expectException(DomainException::class);
        (new InvoiceCalculator)->calculate([['quantity' => '-1', 'unit_price' => '10']]);
    }
}
