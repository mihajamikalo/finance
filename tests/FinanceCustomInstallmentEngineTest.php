<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!function_exists('__')) {
    function __($value)
    {
        return $value;
    }
}

require_once __DIR__ . '/../moduleFunctions.php';

final class FinanceCustomInstallmentEngineTest extends TestCase
{
    public function testFullPaymentDiscountPlanProducesSingleInstallment(): void
    {
        $plan = [
            'planStartDate' => '2026-01-10',
            'tuitionFeeFinal' => 13050000.00,
            'requiredDeposit' => 13050000.00,
            'installmentCount' => 0,
        ];

        $schedule = financeMgmtBuildInstallmentSchedule($plan);

        $this->assertCount(1, $schedule);
        $this->assertSame(13050000.00, floatval($schedule[0]['expectedAmount']));
    }

    public function testInstallmentEvaluationAppliesCarryForwardCredit(): void
    {
        $plan = [
            'planStartDate' => '2026-01-01',
            'tuitionFeeFinal' => 14500000.00,
            'requiredDeposit' => 4000000.00,
            'installmentCount' => 8,
        ];

        $payments = [
            ['gibbonFinanceMgmtStudentPaymentID' => 1, 'amountPaid' => 6000000.00, 'paymentDate' => '2026-01-05'],
        ];

        $evaluation = financeMgmtEvaluatePlan($plan, $payments, '2026-02-20');
        $installments = $evaluation['installments'];

        $this->assertGreaterThan(0, $installments[1]['creditBefore']);
        $this->assertLessThan($installments[1]['expectedAmount'], $installments[1]['payableAmount']);
    }
}
