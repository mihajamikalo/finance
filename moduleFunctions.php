<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;

/**
 * Finance Management module helper functions.
 *
 * Notes:
 * - This module stores tuition fees per School Year + Year Group.
 * - Payments are immutable: editing is not provided and deletes are restricted + audited.
 */

function financeMgmtGetTuitionFeeAmount(PDO $connection2, int $gibbonSchoolYearID, int $gibbonYearGroupID): ?float
{
    $data = [
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
        'gibbonYearGroupID' => $gibbonYearGroupID,
    ];

    $sql = "SELECT amount
        FROM gibbonFinanceMgmtTuitionFee
        WHERE gibbonSchoolYearID=:gibbonSchoolYearID
            AND gibbonYearGroupID=:gibbonYearGroupID
            AND active='Y'
        LIMIT 1";

    $result = $connection2->prepare($sql);
    $result->execute($data);
    $value = $result->fetchColumn(0);

    return ($value === false) ? null : floatval($value);
}

function financeMgmtGetStudentYearGroup(PDO $connection2, int $gibbonPersonIDStudent, int $gibbonSchoolYearID): ?int
{
    $data = [
        'gibbonPersonID' => $gibbonPersonIDStudent,
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
    ];

    $sql = "SELECT gibbonYearGroupID
        FROM gibbonStudentEnrolment
        WHERE gibbonPersonID=:gibbonPersonID
            AND gibbonSchoolYearID=:gibbonSchoolYearID
        LIMIT 1";

    $result = $connection2->prepare($sql);
    $result->execute($data);
    $value = $result->fetchColumn(0);

    return ($value === false) ? null : intval($value);
}

function financeMgmtGetStudentTotals(PDO $connection2, int $gibbonPersonIDStudent, int $gibbonSchoolYearID): array
{
    $yearGroupID = financeMgmtGetStudentYearGroup($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
    $tuitionFee = ($yearGroupID !== null)
        ? financeMgmtGetTuitionFeeAmount($connection2, $gibbonSchoolYearID, $yearGroupID)
        : null;

    // If a payment plan exists, use its final (discounted) fee instead of the raw tuition fee.
    $plan = financeMgmtGetStudentPaymentPlan($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
    if ($plan !== null) {
        $tuitionFee = floatval($plan['tuitionFeeFinal']);
    }

    $data = [
        'gibbonPersonIDStudent' => $gibbonPersonIDStudent,
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
    ];

    $sql = "SELECT COALESCE(SUM(amountPaid), 0) AS totalPaid
        FROM gibbonFinanceMgmtStudentPayment
        WHERE gibbonPersonIDStudent=:gibbonPersonIDStudent
            AND gibbonSchoolYearID=:gibbonSchoolYearID";
    $result = $connection2->prepare($sql);
    $result->execute($data);
    $totalPaid = floatval($result->fetchColumn(0) ?: 0);

    $totalFee = ($tuitionFee === null) ? null : floatval($tuitionFee);
    $balance = ($totalFee === null) ? null : max(0, $totalFee - $totalPaid);

    if ($totalFee === null) {
        $status = 'Unconfigured';
    } elseif ($totalPaid <= 0) {
        $status = 'Unpaid';
    } elseif ($totalPaid + 0.00001 < $totalFee) {
        $status = 'Partial';
    } else {
        $status = 'Paid';
    }

    return [
        'gibbonYearGroupID' => $yearGroupID,
        'plan' => $plan,
        'totalFee' => $totalFee,
        'totalPaid' => $totalPaid,
        'balance' => $balance,
        'status' => $status,
    ];
}

function financeMgmtFormatMoney(?float $amount): string
{
    if ($amount === null) return __('N/A');
    return number_format($amount, 2, '.', ',');
}

function financeMgmtLog(PDO $connection2, string $action, ?string $recordID, ?string $gibbonPersonID, string $details = ''): void
{
    $data = [
        'action' => $action,
        'recordID' => $recordID,
        'gibbonPersonID' => $gibbonPersonID,
        'details' => $details,
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    $sql = "INSERT INTO gibbonFinanceMgmtAuditLog
        SET action=:action, recordID=:recordID, gibbonPersonID=:gibbonPersonID, details=:details, timestamp=:timestamp";

    $stmt = $connection2->prepare($sql);
    $stmt->execute($data);
}

/**
 * Generates a receipt number.
 *
 * Uses the payment row's ID to ensure uniqueness and avoid race conditions.
 */
function financeMgmtReceiptNumberFromPaymentID(string $prefix, int $paymentID, string $paymentDate): string
{
    $year = substr($paymentDate, 0, 4);
    return sprintf('%s-%s-%06d', preg_replace('/[^A-Za-z0-9]/', '', $prefix), $year, $paymentID);
}

function financeMgmtGetSettingValue(string $scope, string $name, $default = '')
{
    global $container;
    if (empty($container)) return $default;

    $settingGateway = $container->get(SettingGateway::class);
    $value = $settingGateway->getSettingByScope($scope, $name);
    return ($value === null || $value === '') ? $default : $value;
}

function financeMgmtGetAdminAccessCode(): string
{
    return strval(financeMgmtGetSettingValue('FinanceCustom', 'adminAccessCode', ''));
}

function financeMgmtVerifyAdminAccessCode(string $plainCode): bool
{
    $expectedCode = financeMgmtGetAdminAccessCode();
    $providedCode = trim($plainCode);
    if ($expectedCode === '' || $providedCode === '') {
        return false;
    }

    return hash_equals($expectedCode, $providedCode);
}

function financeMgmtGrantAdminCodeSessionAccess($session): void
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return;
    }

    $_SESSION['financeCustomAdminCodeAccess'] = [
        'gibbonPersonID' => strval($session->get('gibbonPersonID')),
        'expiresAt' => time() + (15 * 60),
    ];
}

function financeMgmtClearAdminCodeSessionAccess(): void
{
    if (isset($_SESSION['financeCustomAdminCodeAccess'])) {
        unset($_SESSION['financeCustomAdminCodeAccess']);
    }
}

function financeMgmtHasAdminCodeSessionAccess($session): bool
{
    if (!isset($_SESSION['financeCustomAdminCodeAccess']) || !is_array($_SESSION['financeCustomAdminCodeAccess'])) {
        return false;
    }

    $access = $_SESSION['financeCustomAdminCodeAccess'];
    $isSameUser = isset($access['gibbonPersonID']) && strval($access['gibbonPersonID']) === strval($session->get('gibbonPersonID'));
    $isValid = isset($access['expiresAt']) && intval($access['expiresAt']) >= time();

    if (!$isSameUser || !$isValid) {
        financeMgmtClearAdminCodeSessionAccess();
        return false;
    }

    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// INSTALLMENT PLAN ENGINE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the configured initial deposit amount (set in Finance Settings).
 */
function financeMgmtGetConfiguredInitialDeposit(): float
{
    return max(0.0, floatval(financeMgmtGetSettingValue('FinanceCustom', 'installmentInitialDeposit', '0')));
}

/**
 * Returns the number of payments already recorded for a student in a school year.
 */
function financeMgmtCountStudentPayments(PDO $connection2, int $gibbonPersonIDStudent, int $gibbonSchoolYearID): int
{
    $stmt = $connection2->prepare(
        "SELECT COUNT(*) FROM gibbonFinanceMgmtStudentPayment
         WHERE gibbonPersonIDStudent=:s AND gibbonSchoolYearID=:y"
    );
    $stmt->execute(['s' => $gibbonPersonIDStudent, 'y' => $gibbonSchoolYearID]);
    return intval($stmt->fetchColumn(0) ?: 0);
}

/**
 * Fetches the payment plan row for a student / school year, or null if none exists.
 */
function financeMgmtGetStudentPaymentPlan(PDO $connection2, int $gibbonPersonIDStudent, int $gibbonSchoolYearID): ?array
{
    $stmt = $connection2->prepare(
        "SELECT * FROM gibbonFinanceMgmtPaymentPlan
         WHERE gibbonPersonIDStudent=:s AND gibbonSchoolYearID=:y LIMIT 1"
    );
    $stmt->execute(['s' => $gibbonPersonIDStudent, 'y' => $gibbonSchoolYearID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row === false || empty($row)) ? null : $row;
}

/**
 * Returns all payment rows for a student / school year sorted ascending by date then ID.
 */
function financeMgmtGetStudentPayments(PDO $connection2, int $gibbonPersonIDStudent, int $gibbonSchoolYearID): array
{
    $stmt = $connection2->prepare(
        "SELECT gibbonFinanceMgmtStudentPaymentID, amountPaid, paymentDate
         FROM gibbonFinanceMgmtStudentPayment
         WHERE gibbonPersonIDStudent=:s AND gibbonSchoolYearID=:y
         ORDER BY paymentDate ASC, gibbonFinanceMgmtStudentPaymentID ASC"
    );
    $stmt->execute(['s' => $gibbonPersonIDStudent, 'y' => $gibbonSchoolYearID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Splits an amount into N parts in cents, distributing any rounding remainder
 * across the first instalments.
 *
 * @return float[]
 */
function financeMgmtSplitAmountEvenly(float $amount, int $parts): array
{
    if ($parts <= 0) {
        return [];
    }
    $totalCents = max(0, intval(round($amount * 100)));
    $base       = intdiv($totalCents, $parts);
    $remainder  = $totalCents - $base * $parts;
    $chunks     = [];
    for ($i = 0; $i < $parts; $i++) {
        $chunks[] = ($base + ($i < $remainder ? 1 : 0)) / 100.0;
    }
    return $chunks;
}

/**
 * Builds the full instalment schedule for a plan (array of plan fields).
 * Each row: installmentNumber, label, dueDate, expectedAmount.
 *
 * @param array $plan  Row from gibbonFinanceMgmtPaymentPlan.
 * @return array[]
 */
function financeMgmtBuildInstallmentSchedule(array $plan): array
{
    $startDate        = new DateTimeImmutable($plan['planStartDate']);
    $tuitionFinal     = floatval($plan['tuitionFeeFinal']);
    $requiredDeposit  = max(0.0, floatval($plan['requiredDeposit']));
    $installmentCount = max(0, intval($plan['installmentCount']));

    $schedule = [];
    $number   = 1;

    // Deposit instalment (only when a non-zero deposit is configured).
    if ($requiredDeposit > 0.009) {
        $schedule[] = [
            'installmentNumber' => $number++,
            'label'             => __('Initial Deposit'),
            'dueDate'           => $startDate->format('Y-m-d'),
            'expectedAmount'    => min($requiredDeposit, $tuitionFinal),
        ];
    }

    $remaining = max(0.0, $tuitionFinal - $requiredDeposit);

    if ($installmentCount <= 0) {
        // Full-payment or no-split plan: single remaining amount.
        if ($remaining > 0.009) {
            $dueDate    = ($requiredDeposit > 0.009)
                ? $startDate->modify('+1 month')->format('Y-m-d')
                : $startDate->format('Y-m-d');
            $schedule[] = [
                'installmentNumber' => $number,
                'label'             => __('Full Payment'),
                'dueDate'           => $dueDate,
                'expectedAmount'    => $remaining,
            ];
        } elseif (empty($schedule)) {
            // Edge case: zero-amount plan.
            $schedule[] = [
                'installmentNumber' => $number,
                'label'             => __('Full Payment'),
                'dueDate'           => $startDate->format('Y-m-d'),
                'expectedAmount'    => $tuitionFinal,
            ];
        }
        return $schedule;
    }

    // Monthly instalments.
    $monthlyParts = financeMgmtSplitAmountEvenly($remaining, $installmentCount);
    foreach ($monthlyParts as $i => $monthAmount) {
        $schedule[] = [
            'installmentNumber' => $number++,
            'label'             => sprintf(__('Instalment %1$s'), $i + 1),
            'dueDate'           => $startDate->modify('+' . ($i + 1) . ' month')->format('Y-m-d'),
            'expectedAmount'    => $monthAmount,
        ];
    }

    return $schedule;
}

/**
 * Evaluates a payment plan against actual payments as of a given date.
 *
 * Implements sequential carry-forward credit logic:
 *   - Payments accumulate in a running total.
 *   - Each instalment is first offset by any credit carried from previous instalments.
 *   - Surplus above an instalment's due amount becomes credit for the next instalment.
 *   - Late detection: an instalment is late only if its net outstanding > 0 and its due date has passed.
 *
 * @param array  $plan      Row from gibbonFinanceMgmtPaymentPlan.
 * @param array  $payments  Rows from gibbonFinanceMgmtStudentPayment (any order).
 * @param string $asOfDate  Y-m-d date ceiling for "due" calculation (default: today).
 * @return array {plan, installments, totals}
 */
function financeMgmtEvaluatePlan(array $plan, array $payments, string $asOfDate = ''): array
{
    if ($asOfDate === '') {
        $asOfDate = date('Y-m-d');
    }

    $schedule = financeMgmtBuildInstallmentSchedule($plan);

    // Sum all payments up to asOfDate for total-paid; track current-month separately.
    $currentMonth    = substr($asOfDate, 0, 7);
    $paidTotal       = 0.0;
    $paidThisMonth   = 0.0;
    $lastPaymentDate = null;

    foreach ($payments as $p) {
        if ($p['paymentDate'] <= $asOfDate) {
            $paidTotal += floatval($p['amountPaid']);
            $lastPaymentDate = $p['paymentDate'];
        }
        if (substr($p['paymentDate'], 0, 7) === $currentMonth) {
            $paidThisMonth += floatval($p['amountPaid']);
        }
    }

    // Walk the schedule, applying credit carry-forward.
    $allocatedSoFar    = 0.0; // Sum of expectedAmounts of already-processed instalments.
    $expectedThisMonth = 0.0;
    $expectedDueToDate = 0.0;
    $lateMonths        = 0;
    $installments      = [];

    foreach ($schedule as $row) {
        $expected    = floatval($row['expectedAmount']);
        $creditBefore = max(0.0, $paidTotal - $allocatedSoFar);
        $payable     = max(0.0, $expected - $creditBefore);
        $applied     = min($expected, $creditBefore);
        $outstanding = max(0.0, $expected - $creditBefore);
        $creditAfter = max(0.0, $creditBefore - $expected);
        $isDue       = ($row['dueDate'] <= $asOfDate);

        if (substr($row['dueDate'], 0, 7) === $currentMonth) {
            $expectedThisMonth += $expected;
        }
        if ($isDue) {
            $expectedDueToDate += $expected;
            if ($outstanding > 0.009) {
                $lateMonths++;
            }
        }

        $installments[] = [
            'installmentNumber' => intval($row['installmentNumber']),
            'label'             => $row['label'],
            'dueDate'           => $row['dueDate'],
            'expectedAmount'    => $expected,
            'creditBefore'      => $creditBefore,
            'payableAmount'     => $payable,
            'appliedAmount'     => $applied,
            'creditAfter'       => $creditAfter,
            'outstandingAmount' => $outstanding,
            'isDue'             => $isDue ? 'Y' : 'N',
            'isLate'            => ($isDue && $outstanding > 0.009) ? 'Y' : 'N',
        ];

        $allocatedSoFar += $expected;
    }

    $tuitionFinal  = floatval($plan['tuitionFeeFinal']);
    $overdueAmount = max(0.0, $expectedDueToDate - $paidTotal);

    return [
        'plan'         => $plan,
        'installments' => $installments,
        'totals'       => [
            'tuitionFeeOriginal'   => floatval($plan['tuitionFeeOriginal']),
            'discountAmount'       => floatval($plan['discountAmount']),
            'tuitionFeeFinal'      => $tuitionFinal,
            'paidTotal'            => $paidTotal,
            'balanceTotal'         => max(0.0, $tuitionFinal - $paidTotal),
            'expectedCurrentMonth' => $expectedThisMonth,
            'paidCurrentMonth'     => $paidThisMonth,
            'overdueAmount'        => $overdueAmount,
            'lateMonths'           => $lateMonths,
            'lastPaymentDate'      => $lastPaymentDate,
        ],
    ];
}

/**
 * Creates a payment plan row.
 *
 * @param string $paymentOption  'FULL' | '4' | '8' | 'LEGACY'
 * @return int  ID of the newly created plan.
 */
function financeMgmtCreateStudentPaymentPlan(
    PDO    $connection2,
    int    $gibbonPersonIDStudent,
    int    $gibbonSchoolYearID,
    int    $gibbonYearGroupID,
    float  $tuitionFeeAmount,
    string $paymentOption,
    string $planStartDate,
    int    $gibbonPersonIDCreatedBy
): int {
    $discountRate     = 0.0;
    $installmentCount = 0;
    $requiredDeposit  = 0.0;

    switch ($paymentOption) {
        case 'FULL':
            $discountRate    = 10.0;
            $planType        = 'FULL';
            break;
        case '4':
            $installmentCount = 4;
            $requiredDeposit  = financeMgmtGetConfiguredInitialDeposit();
            $planType         = 'INSTALLMENT_4';
            break;
        case '8':
            $installmentCount = 8;
            $requiredDeposit  = financeMgmtGetConfiguredInitialDeposit();
            $planType         = 'INSTALLMENT_8';
            break;
        default:
            $planType = 'LEGACY';
            break;
    }

    // Cap deposit so it never exceeds total fee.
    $requiredDeposit = min(max(0.0, $requiredDeposit), max(0.0, $tuitionFeeAmount));

    $discountAmount  = round($tuitionFeeAmount * $discountRate / 100.0, 2);
    $tuitionFeeFinal = max(0.0, $tuitionFeeAmount - $discountAmount);

    // For a full-payment plan the "deposit" IS the full (discounted) amount.
    if ($planType === 'FULL') {
        $requiredDeposit = $tuitionFeeFinal;
    }

    $remaining          = max(0.0, $tuitionFeeFinal - $requiredDeposit);
    $installmentAmount  = ($installmentCount > 0) ? round($remaining / $installmentCount, 2) : 0.0;
    $now                = date('Y-m-d H:i:s');

    $stmt = $connection2->prepare(
        "INSERT INTO gibbonFinanceMgmtPaymentPlan
         SET gibbonPersonIDStudent=:student,
             gibbonSchoolYearID=:year,
             gibbonYearGroupID=:yg,
             planType=:planType,
             tuitionFeeOriginal=:feeOrig,
             discountRate=:discRate,
             discountAmount=:discAmt,
             tuitionFeeFinal=:feeFinal,
             requiredDeposit=:deposit,
             installmentCount=:instCount,
             installmentAmount=:instAmt,
             planStartDate=:startDate,
             status='ACTIVE',
             gibbonPersonIDCreatedBy=:createdBy,
             createdAt=:now,
             updatedAt=:now"
    );
    $stmt->execute([
        'student'   => $gibbonPersonIDStudent,
        'year'      => $gibbonSchoolYearID,
        'yg'        => $gibbonYearGroupID,
        'planType'  => $planType,
        'feeOrig'   => $tuitionFeeAmount,
        'discRate'  => $discountRate,
        'discAmt'   => $discountAmount,
        'feeFinal'  => $tuitionFeeFinal,
        'deposit'   => $requiredDeposit,
        'instCount' => $installmentCount,
        'instAmt'   => $installmentAmount,
        'startDate' => $planStartDate,
        'createdBy' => $gibbonPersonIDCreatedBy,
        'now'       => $now,
    ]);

    return intval($connection2->lastInsertId());
}

/**
 * Rebuilds the instalment ledger for a plan after any payment change.
 * Deletes all existing ledger rows then re-inserts based on current payments.
 */
function financeMgmtRebuildPlanLedger(PDO $connection2, array $plan): void
{
    $planID   = intval($plan['gibbonFinanceMgmtPaymentPlanID']);
    $payments = financeMgmtGetStudentPayments(
        $connection2,
        intval($plan['gibbonPersonIDStudent']),
        intval($plan['gibbonSchoolYearID'])
    );

    // Delete existing ledger snapshot for this plan.
    $stmtDel = $connection2->prepare(
        "DELETE FROM gibbonFinanceMgmtInstallmentLedger WHERE gibbonFinanceMgmtPaymentPlanID=:planID"
    );
    $stmtDel->execute(['planID' => $planID]);

    // Evaluate as of today.
    $evaluation   = financeMgmtEvaluatePlan($plan, $payments, date('Y-m-d'));
    $latestPmtID  = empty($payments) ? null : intval(end($payments)['gibbonFinanceMgmtStudentPaymentID']);
    $now          = date('Y-m-d H:i:s');

    $stmtIns = $connection2->prepare(
        "INSERT INTO gibbonFinanceMgmtInstallmentLedger
         SET gibbonFinanceMgmtPaymentPlanID=:planID,
             gibbonFinanceMgmtStudentPaymentID=:pmtID,
             installmentNumber=:num,
             dueDate=:dueDate,
             expectedAmount=:expected,
             creditBefore=:creditBefore,
             payableAmount=:payable,
             appliedAmount=:applied,
             creditAfter=:creditAfter,
             outstandingAfter=:outstanding,
             isLate=:isLate,
             snapshotAt=:now"
    );

    foreach ($evaluation['installments'] as $item) {
        $stmtIns->execute([
            'planID'      => $planID,
            'pmtID'       => $latestPmtID,
            'num'         => intval($item['installmentNumber']),
            'dueDate'     => $item['dueDate'],
            'expected'    => floatval($item['expectedAmount']),
            'creditBefore'=> floatval($item['creditBefore']),
            'payable'     => floatval($item['payableAmount']),
            'applied'     => floatval($item['appliedAmount']),
            'creditAfter' => floatval($item['creditAfter']),
            'outstanding' => floatval($item['outstandingAmount']),
            'isLate'      => $item['isLate'],
            'now'         => $now,
        ]);
    }
}

