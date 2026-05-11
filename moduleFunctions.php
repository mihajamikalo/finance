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

function financeMgmtGenerateOtpCode(int $length = 6): string
{
    $digits = '';
    for ($i = 0; $i < $length; $i++) {
        $digits .= strval(random_int(0, 9));
    }

    return $digits;
}

function financeMgmtGetOtpSessionState(): array
{
    if (!isset($_SESSION['financeCustomDeleteHistoryOtp']) || !is_array($_SESSION['financeCustomDeleteHistoryOtp'])) {
        return [];
    }

    return $_SESSION['financeCustomDeleteHistoryOtp'];
}

function financeMgmtSetOtpSessionState(array $state): void
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return;
    }

    $_SESSION['financeCustomDeleteHistoryOtp'] = $state;
}

function financeMgmtClearOtpSessionState(): void
{
    if (isset($_SESSION['financeCustomDeleteHistoryOtp'])) {
        unset($_SESSION['financeCustomDeleteHistoryOtp']);
    }
}

function financeMgmtGetOtpAdminEmails(PDO $connection2): array
{
    $configured = trim(strval(financeMgmtGetSettingValue('FinanceCustom', 'deleteOtpAdminEmails', '')));
    if ($configured !== '') {
        $emails = preg_split('/[,;]+/', $configured) ?: [];
        $emails = array_values(array_filter(array_map('trim', $emails), function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }));

        if (!empty($emails)) {
            return $emails;
        }
    }

    $emails = [];
    try {
        $sql = "SELECT DISTINCT p.email
            FROM gibbonPerson AS p
            JOIN gibbonRole AS r ON (p.gibbonRoleIDPrimary=r.gibbonRoleID)
            WHERE p.status='Full'
                AND p.email IS NOT NULL
                AND p.email<>''
                AND (r.name LIKE '%Admin%' OR r.nameShort LIKE '%Admin%')
            ORDER BY p.email";
        $stmt = $connection2->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        foreach ($rows as $email) {
            $email = trim(strval($email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[$email] = $email;
            }
        }
    } catch (PDOException $e) {
        // Ignore fallback lookup errors.
    }

    return array_values($emails);
}

function financeMgmtSendOtpEmail(array $emails, string $otpCode, string $requestorName, string $expiresLabel): int
{
    if (empty($emails)) {
        return 0;
    }

    $subject = '[FinanceCustom] OTP - Delete Payment History';
    $body = "A request to delete all payment history was initiated in FinanceCustom.\n\n"
        ."Confirmation code: {$otpCode}\n"
        ."Requested by: {$requestorName}\n"
        ."Expires in: {$expiresLabel}\n\n"
        ."If you did not expect this request, please contact your system administrator immediately.";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = 0;
    foreach ($emails as $email) {
        if (mail($email, $subject, $body, $headers)) {
            $sent++;
        }
    }

    return $sent;
}

