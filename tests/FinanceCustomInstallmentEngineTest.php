<?php
/**
 * Unit tests for the FinanceCustom instalment-plan calculation engine.
 *
 * Run from the module root:
 *   vendor/bin/phpunit tests/FinanceCustomInstallmentEngineTest.php
 *
 * The __ helper is stubbed so the file can be loaded without a Gibbon bootstrap.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Stub Gibbon's translation helper when running outside the platform.
if (!function_exists('__')) {
    function __(string $text): string { return $text; }
}

// Load the module's business-logic functions (no DB calls, pure PHP).
require_once __DIR__ . '/../moduleFunctions.php';

final class FinanceCustomInstallmentEngineTest extends TestCase
{
    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Minimal plan array with sane defaults that tests may override. */
    private function makePlan(array $overrides = []): array
    {
        return array_merge([
            'planStartDate'     => '2026-01-10',
            'tuitionFeeOriginal'=> 14_500_000.00,
            'discountRate'      => 0.00,
            'discountAmount'    => 0.00,
            'tuitionFeeFinal'   => 14_500_000.00,
            'requiredDeposit'   => 0.00,
            'installmentCount'  => 0,
            'installmentAmount' => 0.00,
            'planType'          => 'LEGACY',
        ], $overrides);
    }

    /** Minimal payment row. */
    private function makePayment(int $id, float $amount, string $date): array
    {
        return [
            'gibbonFinanceMgmtStudentPaymentID' => $id,
            'amountPaid'  => $amount,
            'paymentDate' => $date,
        ];
    }

    // ── financeMgmtSplitAmountEvenly ──────────────────────────────────────────

    public function testSplitAmountEvenlyExact(): void
    {
        $parts = financeMgmtSplitAmountEvenly(10_500_000.00, 8);
        $this->assertCount(8, $parts);
        // Each part must be 1 312 500.00
        foreach ($parts as $p) {
            $this->assertSame(1_312_500.00, $p);
        }
        $this->assertSame(10_500_000.00, array_sum($parts));
    }

    public function testSplitAmountEvenlyWithRemainder(): void
    {
        // 100 / 3 = 33.33... → two parts of 33.34 and one of 33.33 (cent-level spread)
        $parts = financeMgmtSplitAmountEvenly(100.00, 3);
        $this->assertCount(3, $parts);
        $this->assertEqualsWithDelta(100.00, array_sum($parts), 0.001);
    }

    public function testSplitAmountEvenlyZeroParts(): void
    {
        $this->assertSame([], financeMgmtSplitAmountEvenly(1000.00, 0));
    }

    // ── financeMgmtBuildInstallmentSchedule ───────────────────────────────────

    public function testFullPaymentScheduleHasSingleRow(): void
    {
        $plan = $this->makePlan([
            'tuitionFeeFinal'  => 13_050_000.00,
            'requiredDeposit'  => 13_050_000.00, // Full plan: deposit = total
            'installmentCount' => 0,
        ]);
        $schedule = financeMgmtBuildInstallmentSchedule($plan);

        $this->assertCount(1, $schedule, 'Full-payment plan must produce exactly 1 schedule row.');
        $this->assertSame(13_050_000.00, $schedule[0]['expectedAmount']);
    }

    public function test8InstalmentScheduleHas9Rows(): void
    {
        // Deposit + 8 monthly → 9 rows total
        $plan = $this->makePlan([
            'tuitionFeeFinal'  => 14_500_000.00,
            'requiredDeposit'  => 4_000_000.00,
            'installmentCount' => 8,
        ]);
        $schedule = financeMgmtBuildInstallmentSchedule($plan);

        $this->assertCount(9, $schedule);
        // First row is the deposit
        $this->assertSame('Initial Deposit', $schedule[0]['label']);
        $this->assertSame(4_000_000.00, $schedule[0]['expectedAmount']);
        // Remaining 8 rows sum to 10 500 000
        $monthlySum = array_sum(array_column(array_slice($schedule, 1), 'expectedAmount'));
        $this->assertEqualsWithDelta(10_500_000.00, $monthlySum, 0.02);
    }

    public function test4InstalmentScheduleHas5Rows(): void
    {
        $plan = $this->makePlan([
            'tuitionFeeFinal'  => 14_500_000.00,
            'requiredDeposit'  => 4_000_000.00,
            'installmentCount' => 4,
        ]);
        $schedule = financeMgmtBuildInstallmentSchedule($plan);
        $this->assertCount(5, $schedule);
    }

    public function testDueDatesAreOneMonthApart(): void
    {
        $plan = $this->makePlan([
            'planStartDate'    => '2026-01-15',
            'tuitionFeeFinal'  => 12_000_000.00,
            'requiredDeposit'  => 2_000_000.00,
            'installmentCount' => 4,
        ]);
        $schedule = financeMgmtBuildInstallmentSchedule($plan);

        // Instalment 2 due date: 2026-02-15
        $this->assertSame('2026-02-15', $schedule[1]['dueDate']);
        // Instalment 5 due date: 2026-05-15
        $this->assertSame('2026-05-15', $schedule[4]['dueDate']);
    }

    // ── financeMgmtEvaluatePlan ───────────────────────────────────────────────

    public function testCarryForwardReducesNextInstalment(): void
    {
        // Deposit 4 000 000, then 8 × 1 312 500 monthly.
        $plan = $this->makePlan([
            'planStartDate'    => '2026-01-01',
            'tuitionFeeFinal'  => 14_500_000.00,
            'requiredDeposit'  => 4_000_000.00,
            'installmentCount' => 8,
        ]);

        // Student pays 6 000 000 on Jan 5 (deposit = 4 000 000, surplus = 2 000 000).
        $payments = [$this->makePayment(1, 6_000_000.00, '2026-01-05')];

        $eval = financeMgmtEvaluatePlan($plan, $payments, '2026-02-20');
        $items = $eval['installments'];

        // Row 0 = deposit
        $this->assertSame(0.0, $items[0]['creditBefore'], 'No credit before the first instalment.');
        $this->assertSame(4_000_000.00, $items[0]['appliedAmount']);
        $this->assertSame(2_000_000.00, $items[0]['creditAfter']);

        // Row 1 = first monthly instalment — credit of 2 000 000 should carry forward
        $this->assertSame(2_000_000.00, $items[1]['creditBefore']);
        // Payable = 1 312 500 − 2 000 000 → 0 (cannot go negative)
        $this->assertSame(0.0, $items[1]['payableAmount']);
        // Outstanding should also be 0
        $this->assertSame(0.0, $items[1]['outstandingAmount']);
    }

    public function testExactExampleFromSpec(): void
    {
        /**
         * Spec example:
         *   Instalment amount = 1 312 500
         *   Month 1 paid: 2 000 000  → surplus = 687 500
         *   Month 2 payable = 1 312 500 − 687 500 = 625 000
         */
        $plan = $this->makePlan([
            'planStartDate'    => '2026-01-01',
            'tuitionFeeFinal'  => 10_500_000.00,
            'requiredDeposit'  => 0.00,
            'installmentCount' => 8,
        ]);

        $payments = [$this->makePayment(1, 2_000_000.00, '2026-01-15')];

        $eval  = financeMgmtEvaluatePlan($plan, $payments, '2026-02-20');
        $items = $eval['installments'];

        // Month 1: expected 1 312 500, paid 2 000 000 → surplus 687 500
        $this->assertEqualsWithDelta(1_312_500.0,  $items[0]['expectedAmount'], 1.0);
        $this->assertEqualsWithDelta(687_500.0,    $items[0]['creditAfter'],    1.0);

        // Month 2: credit 687 500 → payable = 1 312 500 − 687 500 = 625 000
        $this->assertEqualsWithDelta(687_500.0,  $items[1]['creditBefore'], 1.0);
        $this->assertEqualsWithDelta(625_000.0,  $items[1]['payableAmount'], 1.0);
    }

    public function testOverpayingEliminatesMultipleInstalments(): void
    {
        $plan = $this->makePlan([
            'planStartDate'    => '2026-01-01',
            'tuitionFeeFinal'  => 10_500_000.00,
            'requiredDeposit'  => 0.00,
            'installmentCount' => 8,
        ]);

        // Pay 5 × monthly amount upfront
        $payments = [$this->makePayment(1, 5 * 1_312_500.0, '2026-01-05')];
        $eval     = financeMgmtEvaluatePlan($plan, $payments, '2026-06-20');
        $items    = $eval['installments'];

        // First 5 instalments should be fully covered
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(0.0, $items[$i]['outstandingAmount'], "Instalment ".($i+1)." should be fully covered.");
        }
        // Instalment 6 still has something outstanding
        $this->assertGreaterThan(0.0, $items[5]['outstandingAmount']);
    }

    public function testLateDetectionRespectsCreditCarryForward(): void
    {
        $plan = $this->makePlan([
            'planStartDate'    => '2026-01-01',
            'tuitionFeeFinal'  => 14_500_000.00,
            'requiredDeposit'  => 4_000_000.00,
            'installmentCount' => 8,
        ]);

        // Full deposit paid → no overdue even though the due date has passed.
        $payments = [$this->makePayment(1, 4_000_000.00, '2026-01-05')];
        $eval     = financeMgmtEvaluatePlan($plan, $payments, '2026-02-20');

        // Deposit instalment must NOT be late.
        $this->assertSame('N', $eval['installments'][0]['isLate']);
        // Instalment 2 (Feb) IS due on 2026-02-01 and not covered → should be late.
        $this->assertSame('Y', $eval['installments'][1]['isLate']);
    }

    public function testNoPaymentsProducesCorrectOverdue(): void
    {
        $plan = $this->makePlan([
            'planStartDate'    => '2026-01-01',
            'tuitionFeeFinal'  => 10_000_000.00,
            'requiredDeposit'  => 0.00,
            'installmentCount' => 4,
        ]);

        // Evaluate 3 months after plan start — 3 instalments should be late.
        $eval = financeMgmtEvaluatePlan($plan, [], '2026-04-05');
        $this->assertSame(3, $eval['totals']['lateMonths']);
        $this->assertGreaterThan(0.0, $eval['totals']['overdueAmount']);
    }

    // ── financeMgmtSplitAmountEvenly edge cases ───────────────────────────────

    public function testSplitSinglePart(): void
    {
        $parts = financeMgmtSplitAmountEvenly(999.99, 1);
        $this->assertCount(1, $parts);
        $this->assertSame(999.99, $parts[0]);
    }
}
