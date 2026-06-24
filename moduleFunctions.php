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
    $plan = financeMgmtGetStudentPaymentPlan($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
    if (!empty($plan)) {
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

function financeMgmtGetConfiguredInitialDeposit(): float
{
    return max(0, floatval(financeMgmtGetSettingValue('FinanceCustom', 'installmentInitialDeposit', '0')));
}

function financeMgmtCountStudentPayments(PDO $connection2, int $gibbonPersonIDStudent, int $gibbonSchoolYearID): int
{
    $stmt = $connection2->prepare("SELECT COUNT(*)
        FROM gibbonFinanceMgmtStudentPayment
        WHERE gibbonPersonIDStudent=:gibbonPersonIDStudent
            AND gibbonSchoolYearID=:gibbonSchoolYearID");
    $stmt->execute([
        'gibbonPersonIDStudent' => $gibbonPersonIDStudent,
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
    ]);

    return intval($stmt->fetchColumn(0) ?: 0);
}

function financeMgmtGetStudentPaymentPlan(PDO $connection2, int $gibbonPersonIDStudent, int $gibbonSchoolYearID): ?array
{
    $stmt = $connection2->prepare("SELECT *
        FROM gibbonFinanceMgmtPaymentPlan
        WHERE gibbonPersonIDStudent=:gibbonPersonIDStudent
            AND gibbonSchoolYearID=:gibbonSchoolYearID
        LIMIT 1");
    $stmt->execute([
        'gibbonPersonIDStudent' => $gibbonPersonIDStudent,
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
    ]);
    $plan = $stmt->fetch();

    return empty($plan) ? null : $plan;
}

function financeMgmtSplitAmountEvenly(float $amount, int $parts): array
{
    if ($parts <= 0) {
        return [];
    }

    $totalCents = max(0, intval(round($amount * 100)));
    $base = intdiv($totalCents, $parts);
    $remainder = $totalCents - ($base * $parts);
    $chunks = [];

    for ($i = 0; $i < $parts; $i++) {
        $chunks[] = ($base + ($i < $remainder ? 1 : 0)) / 100;
    }

    return $chunks;
}

function financeMgmtBuildInstallmentSchedule(array $plan): array
{
    $startDate = new DateTimeImmutable($plan['planStartDate']);
    $tuitionFinal = floatval($plan['tuitionFeeFinal']);
    $requiredDeposit = max(0, floatval($plan['requiredDeposit']));
    $installmentCount = max(0, intval($plan['installmentCount']));
    $schedule = [];
    $number = 1;

    if ($requiredDeposit > 0.00001) {
        $schedule[] = [
            'installmentNumber' => $number++,
            'label' => __('Initial Deposit'),
            'dueDate' => $startDate->format('Y-m-d'),
            'expectedAmount' => min($requiredDeposit, $tuitionFinal),
        ];
    }

    $remainingAfterDeposit = max(0, $tuitionFinal - $requiredDeposit);
    if ($installmentCount <= 0) {
        if (empty($schedule)) {
            $schedule[] = [
                'installmentNumber' => $number,
                'label' => __('Single Payment'),
                'dueDate' => $startDate->format('Y-m-d'),
                'expectedAmount' => $tuitionFinal,
            ];
        } elseif ($remainingAfterDeposit > 0.00001) {
            $schedule[] = [
                'installmentNumber' => $number,
                'label' => __('Final Payment'),
                'dueDate' => $startDate->modify('+1 month')->format('Y-m-d'),
                'expectedAmount' => $remainingAfterDeposit,
            ];
        }

        return $schedule;
    }

    $monthlyParts = financeMgmtSplitAmountEvenly($remainingAfterDeposit, $installmentCount);
    foreach ($monthlyParts as $i => $monthlyAmount) {
        $schedule[] = [
            'installmentNumber' => $number++,
            'label' => sprintf(__('Installment %1$s'), strval($i + 1)),
            'dueDate' => $startDate->modify('+'.strval($i + 1).' month')->format('Y-m-d'),
            'expectedAmount' => $monthlyAmount,
        ];
    }

    return $schedule;
}

function financeMgmtEvaluatePlan(array $plan, array $payments, ?string $asOfDate = null): array
{
    $asOfDate = $asOfDate ?: date('Y-m-d');
    $schedule = financeMgmtBuildInstallmentSchedule($plan);

    usort($payments, function ($a, $b) {
        if ($a['paymentDate'] === $b['paymentDate']) {
            return intval($a['gibbonFinanceMgmtStudentPaymentID']) <=> intval($b['gibbonFinanceMgmtStudentPaymentID']);
        }

        return strcmp($a['paymentDate'], $b['paymentDate']);
    });

    $paidTotal = 0.0;
    $paidThisMonth = 0.0;
    $expectedCurrentMonth = 0.0;
    $expectedDueToDate = 0.0;
    $allocatedBefore = 0.0;
    $lateMonths = 0;
    $installments = [];
    $asOfMonth = substr($asOfDate, 0, 7);
    $lastPaymentDate = null;

    foreach ($payments as $payment) {
        if ($payment['paymentDate'] <= $asOfDate) {
            $paidTotal += floatval($payment['amountPaid']);
            $lastPaymentDate = $payment['paymentDate'];
        }
        if (substr($payment['paymentDate'], 0, 7) === $asOfMonth) {
            $paidThisMonth += floatval($payment['amountPaid']);
        }
    }

    foreach ($schedule as $row) {
        $expected = floatval($row['expectedAmount']);
        $expectedBefore = $allocatedBefore;
        $creditBefore = max(0, $paidTotal - $expectedBefore);
        $applied = min($expected, max(0, $creditBefore));
        $outstanding = max(0, $expected - $applied);
        $creditAfter = max(0, $creditBefore - $expected);
        $isDue = ($row['dueDate'] <= $asOfDate);

        if (substr($row['dueDate'], 0, 7) === $asOfMonth) {
            $expectedCurrentMonth += $expected;
        }
        if ($isDue) {
            $expectedDueToDate += $expected;
            if ($outstanding > 0.009) {
                $lateMonths++;
            }
        }

        $installments[] = [
            'installmentNumber' => intval($row['installmentNumber']),
            'label' => strval($row['label']),
            'dueDate' => $row['dueDate'],
            'expectedAmount' => $expected,
            'creditBefore' => $creditBefore,
            'payableAmount' => max(0, $expected - $creditBefore),
            'appliedAmount' => $applied,
            'creditAfter' => $creditAfter,
            'outstandingAmount' => $outstanding,
            'isDue' => $isDue ? 'Y' : 'N',
            'isLate' => ($isDue && $outstanding > 0.009) ? 'Y' : 'N',
        ];

        $allocatedBefore += $expected;
    }

    $overdueAmount = max(0, $expectedDueToDate - $paidTotal);
    $nextDue = null;
    foreach ($installments as $row) {
        if ($row['outstandingAmount'] > 0.009) {
            $nextDue = $row;
            break;
        }
    }

    return [
        'plan' => $plan,
        'installments' => $installments,
        'totals' => [
            'tuitionFeeOriginal' => floatval($plan['tuitionFeeOriginal']),
            'discountAmount' => floatval($plan['discountAmount']),
            'tuitionFeeFinal' => floatval($plan['tuitionFeeFinal']),
            'paidTotal' => $paidTotal,
            'balanceTotal' => max(0, floatval($plan['tuitionFeeFinal']) - $paidTotal),
            'expectedCurrentMonth' => $expectedCurrentMonth,
            'paidCurrentMonth' => $paidThisMonth,
            'overdueAmount' => $overdueAmount,
            'lateMonths' => $lateMonths,
            'lastPaymentDate' => $lastPaymentDate,
            'nextDueAmount' => $nextDue ? floatval($nextDue['payableAmount']) : 0.0,
        ],
    ];
}

function financeMgmtCreateStudentPaymentPlan(
    PDO $connection2,
    int $gibbonPersonIDStudent,
    int $gibbonSchoolYearID,
    int $gibbonYearGroupID,
    float $tuitionFeeAmount,
    string $paymentOption,
    string $planStartDate,
    int $gibbonPersonIDCreatedBy
): int {
    $paymentOption = trim($paymentOption);
    $discountRate = 0.0;
    $installmentCount = 0;
    $requiredDeposit = 0.0;
    $planType = 'LEGACY';

    if ($paymentOption === 'FULL') {
        $discountRate = 10.0;
        $planType = 'FULL';
    } elseif ($paymentOption === '4') {
        $installmentCount = 4;
        $requiredDeposit = financeMgmtGetConfiguredInitialDeposit();
        $planType = 'INSTALLMENT_4';
    } elseif ($paymentOption === '8') {
        $installmentCount = 8;
        $requiredDeposit = financeMgmtGetConfiguredInitialDeposit();
        $planType = 'INSTALLMENT_8';
    }

    $requiredDeposit = min(max(0, $requiredDeposit), max(0, $tuitionFeeAmount));
    $discountAmount = round(($tuitionFeeAmount * $discountRate) / 100, 2);
    $tuitionFeeFinal = max(0, $tuitionFeeAmount - $discountAmount);
    if ($planType === 'FULL') {
        $requiredDeposit = $tuitionFeeFinal;
    }

    $remainingAfterDeposit = max(0, $tuitionFeeFinal - $requiredDeposit);
    $installmentAmount = ($installmentCount > 0)
        ? round($remainingAfterDeposit / $installmentCount, 2)
        : 0.0;

    $now = date('Y-m-d H:i:s');
    $stmt = $connection2->prepare("INSERT INTO gibbonFinanceMgmtPaymentPlan
        SET gibbonPersonIDStudent=:gibbonPersonIDStudent,
            gibbonSchoolYearID=:gibbonSchoolYearID,
            gibbonYearGroupID=:gibbonYearGroupID,
            planType=:planType,
            tuitionFeeOriginal=:tuitionFeeOriginal,
            discountRate=:discountRate,
            discountAmount=:discountAmount,
            tuitionFeeFinal=:tuitionFeeFinal,
            requiredDeposit=:requiredDeposit,
            installmentCount=:installmentCount,
            installmentAmount=:installmentAmount,
            planStartDate=:planStartDate,
            status='ACTIVE',
            gibbonPersonIDCreatedBy=:gibbonPersonIDCreatedBy,
            createdAt=:createdAt,
            updatedAt=:updatedAt");
    $stmt->execute([
        'gibbonPersonIDStudent' => $gibbonPersonIDStudent,
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
        'gibbonYearGroupID' => $gibbonYearGroupID,
        'planType' => $planType,
        'tuitionFeeOriginal' => $tuitionFeeAmount,
        'discountRate' => $discountRate,
        'discountAmount' => $discountAmount,
        'tuitionFeeFinal' => $tuitionFeeFinal,
        'requiredDeposit' => $requiredDeposit,
        'installmentCount' => $installmentCount,
        'installmentAmount' => $installmentAmount,
        'planStartDate' => $planStartDate,
        'gibbonPersonIDCreatedBy' => $gibbonPersonIDCreatedBy,
        'createdAt' => $now,
        'updatedAt' => $now,
    ]);

    return intval($connection2->lastInsertId());
}

function financeMgmtGetStudentPayments(PDO $connection2, int $gibbonPersonIDStudent, int $gibbonSchoolYearID): array
{
    $stmt = $connection2->prepare("SELECT gibbonFinanceMgmtStudentPaymentID, amountPaid, paymentDate
        FROM gibbonFinanceMgmtStudentPayment
        WHERE gibbonPersonIDStudent=:gibbonPersonIDStudent
            AND gibbonSchoolYearID=:gibbonSchoolYearID
        ORDER BY paymentDate ASC, gibbonFinanceMgmtStudentPaymentID ASC");
    $stmt->execute([
        'gibbonPersonIDStudent' => $gibbonPersonIDStudent,
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
    ]);

    return $stmt->fetchAll() ?: [];
}

function financeMgmtRebuildPlanLedger(PDO $connection2, array $plan): void
{
    $payments = financeMgmtGetStudentPayments(
        $connection2,
        intval($plan['gibbonPersonIDStudent']),
        intval($plan['gibbonSchoolYearID'])
    );
    $stmtDelete = $connection2->prepare("DELETE FROM gibbonFinanceMgmtInstallmentLedger WHERE gibbonFinanceMgmtPaymentPlanID=:planID");
    $stmtDelete->execute(['planID' => intval($plan['gibbonFinanceMgmtPaymentPlanID'])]);

    $insert = $connection2->prepare("INSERT INTO gibbonFinanceMgmtInstallmentLedger
        SET gibbonFinanceMgmtPaymentPlanID=:gibbonFinanceMgmtPaymentPlanID,
            gibbonFinanceMgmtStudentPaymentID=:gibbonFinanceMgmtStudentPaymentID,
            installmentNumber=:installmentNumber,
            dueDate=:dueDate,
            expectedAmount=:expectedAmount,
            creditBefore=:creditBefore,
            payableAmount=:payableAmount,
            appliedAmount=:appliedAmount,
            creditAfter=:creditAfter,
            outstandingAfter=:outstandingAfter,
            isLate=:isLate,
            snapshotAt=:snapshotAt");

    if (empty($payments)) {
        $evaluation = financeMgmtEvaluatePlan($plan, [], date('Y-m-d'));
        foreach ($evaluation['installments'] as $item) {
            $insert->execute([
                'gibbonFinanceMgmtPaymentPlanID' => intval($plan['gibbonFinanceMgmtPaymentPlanID']),
                'gibbonFinanceMgmtStudentPaymentID' => null,
                'installmentNumber' => intval($item['installmentNumber']),
                'dueDate' => $item['dueDate'],
                'expectedAmount' => floatval($item['expectedAmount']),
                'creditBefore' => floatval($item['creditBefore']),
                'payableAmount' => floatval($item['payableAmount']),
                'appliedAmount' => floatval($item['appliedAmount']),
                'creditAfter' => floatval($item['creditAfter']),
                'outstandingAfter' => floatval($item['outstandingAmount']),
                'isLate' => $item['isLate'],
                'snapshotAt' => date('Y-m-d H:i:s'),
            ]);
        }

        return;
    }

    foreach ($payments as $payment) {
        $evaluation = financeMgmtEvaluatePlan($plan, $payments, $payment['paymentDate']);
        foreach ($evaluation['installments'] as $item) {
            $insert->execute([
                'gibbonFinanceMgmtPaymentPlanID' => intval($plan['gibbonFinanceMgmtPaymentPlanID']),
                'gibbonFinanceMgmtStudentPaymentID' => intval($payment['gibbonFinanceMgmtStudentPaymentID']),
                'installmentNumber' => intval($item['installmentNumber']),
                'dueDate' => $item['dueDate'],
                'expectedAmount' => floatval($item['expectedAmount']),
                'creditBefore' => floatval($item['creditBefore']),
                'payableAmount' => floatval($item['payableAmount']),
                'appliedAmount' => floatval($item['appliedAmount']),
                'creditAfter' => floatval($item['creditAfter']),
                'outstandingAfter' => floatval($item['outstandingAmount']),
                'isLate' => $item['isLate'],
                'snapshotAt' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

function financeMgmtGetOrCreateLegacyPlan(
    PDO $connection2,
    int $gibbonPersonIDStudent,
    int $gibbonSchoolYearID,
    int $gibbonYearGroupID,
    float $tuitionFeeAmount,
    int $gibbonPersonIDCreatedBy
): array {
    $existing = financeMgmtGetStudentPaymentPlan($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
    if ($existing !== null) {
        return $existing;
    }

    financeMgmtCreateStudentPaymentPlan(
        $connection2,
        $gibbonPersonIDStudent,
        $gibbonSchoolYearID,
        $gibbonYearGroupID,
        $tuitionFeeAmount,
        'LEGACY',
        date('Y-m-d'),
        $gibbonPersonIDCreatedBy
    );

    return financeMgmtGetStudentPaymentPlan($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID) ?? [];
}

