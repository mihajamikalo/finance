<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Data\Validator;
use Gibbon\Services\Format;
use Gibbon\Module\FinanceCustom\Service\ReceiptGenerator;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/payments_add.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/payments_add.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

$paymentTitle = trim($_POST['paymentTitle'] ?? '');
$studentToken = trim($_POST['gibbonPersonIDStudent'] ?? '');
$amountPaid = floatval($_POST['amountPaid'] ?? 0);
$paymentDate = Format::dateConvert($_POST['paymentDate'] ?? '');
$paymentOption = trim($_POST['paymentOption'] ?? '');

// Finder stores tokens as comma-separated values, enforce single selection
$gibbonPersonIDStudent = intval(explode(',', $studentToken)[0] ?? 0);

if (empty($paymentTitle) || $gibbonPersonIDStudent <= 0 || $amountPaid <= 0 || empty($paymentDate)) {
    $URL .= '&return=error3';
    header("Location: {$URL}");
    exit;
}

$gibbonSchoolYearID = intval($session->get('gibbonSchoolYearID'));
$gibbonPersonIDCreatedBy = intval($session->get('gibbonPersonID'));

// Determine year group
$gibbonYearGroupID = financeMgmtGetStudentYearGroup($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
if (empty($gibbonYearGroupID)) {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

$totals = financeMgmtGetStudentTotals($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
if ($totals['totalFee'] === null) {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

try {
    $connection2->beginTransaction();

    $existingPlan = financeMgmtGetStudentPaymentPlan($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
    $paymentCount = financeMgmtCountStudentPayments($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);

    if ($existingPlan === null) {
        if ($paymentCount === 0) {
            if (!in_array($paymentOption, ['FULL', '4', '8'], true)) {
                $connection2->rollBack();
                $URL .= '&return=error3';
                header("Location: {$URL}");
                exit;
            }

            financeMgmtCreateStudentPaymentPlan(
                $connection2,
                $gibbonPersonIDStudent,
                $gibbonSchoolYearID,
                $gibbonYearGroupID,
                floatval($totals['totalFee']),
                $paymentOption,
                $paymentDate,
                $gibbonPersonIDCreatedBy
            );
        } else {
            financeMgmtCreateStudentPaymentPlan(
                $connection2,
                $gibbonPersonIDStudent,
                $gibbonSchoolYearID,
                $gibbonYearGroupID,
                floatval($totals['totalFee']),
                'LEGACY',
                $paymentDate,
                $gibbonPersonIDCreatedBy
            );
        }
    }

    $data = [
        'gibbonPersonIDStudent' => $gibbonPersonIDStudent,
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
        'gibbonYearGroupID' => $gibbonYearGroupID,
        'paymentTitle' => $paymentTitle,
        'amountPaid' => $amountPaid,
        'paymentDate' => $paymentDate,
        'receiptPrinted' => 'N',
        'receiptNumber' => null,
        'gibbonPersonIDCreatedBy' => $gibbonPersonIDCreatedBy,
        'createdAt' => date('Y-m-d H:i:s'),
    ];

    $sql = "INSERT INTO gibbonFinanceMgmtStudentPayment
        SET gibbonPersonIDStudent=:gibbonPersonIDStudent,
            gibbonSchoolYearID=:gibbonSchoolYearID,
            gibbonYearGroupID=:gibbonYearGroupID,
            paymentTitle=:paymentTitle,
            amountPaid=:amountPaid,
            paymentDate=:paymentDate,
            receiptPrinted=:receiptPrinted,
            receiptNumber=:receiptNumber,
            gibbonPersonIDCreatedBy=:gibbonPersonIDCreatedBy,
            createdAt=:createdAt";
    $stmt = $connection2->prepare($sql);
    $stmt->execute($data);

    $paymentID = intval($connection2->lastInsertId());

    $prefix = strval(financeMgmtGetSettingValue('FinanceCustom', 'receiptNumberPrefix', 'RCP'));
    $receiptNumber = financeMgmtReceiptNumberFromPaymentID($prefix, $paymentID, $paymentDate);

    $sqlUpdate = "UPDATE gibbonFinanceMgmtStudentPayment
        SET receiptPrinted='Y', receiptNumber=:receiptNumber
        WHERE gibbonFinanceMgmtStudentPaymentID=:paymentID";
    $stmtUpdate = $connection2->prepare($sqlUpdate);
    $stmtUpdate->execute([
        'receiptNumber' => $receiptNumber,
        'paymentID' => $paymentID,
    ]);

    financeMgmtLog(
        $connection2,
        'PAYMENT_CREATE',
        strval($paymentID),
        strval($gibbonPersonIDCreatedBy),
        json_encode([
            'student' => $gibbonPersonIDStudent,
            'amountPaid' => $amountPaid,
            'paymentDate' => $paymentDate,
            'receiptNumber' => $receiptNumber,
            'paymentOption' => $paymentOption,
        ])
    );

    $planAfterInsert = financeMgmtGetOrCreateLegacyPlan(
        $connection2,
        $gibbonPersonIDStudent,
        $gibbonSchoolYearID,
        $gibbonYearGroupID,
        floatval($totals['totalFee']),
        $gibbonPersonIDCreatedBy
    );
    if (!empty($planAfterInsert)) {
        financeMgmtRebuildPlanLedger($connection2, $planAfterInsert);
    }

    $connection2->commit();
} catch (PDOException $e) {
    if ($connection2->inTransaction()) $connection2->rollBack();
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

// Fetch student details for receipt
try {
    $sqlStudent = "SELECT preferredName, surname, studentID
        FROM gibbonPerson
        WHERE gibbonPersonID=:gibbonPersonID";
    $stmtStudent = $connection2->prepare($sqlStudent);
    $stmtStudent->execute(['gibbonPersonID' => $gibbonPersonIDStudent]);
    $student = $stmtStudent->fetch();
} catch (PDOException $e) {
    $student = [];
}

$totalsAfter = financeMgmtGetStudentTotals($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
$remainingBalance = max(0, floatval($totalsAfter['totalFee']) - floatval($totalsAfter['totalPaid']));

$backgroundRel = strval(financeMgmtGetSettingValue('FinanceCustom', 'receiptBackgroundImage', ''));
$backgroundAbs = '';
if (!empty($backgroundRel)) {
    $backgroundAbs = rtrim($session->get('absolutePath'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($backgroundRel, DIRECTORY_SEPARATOR);
}

$generator = new ReceiptGenerator();
$generator->outputReceipt([
    'schoolName' => $session->get('systemName'),
    'studentName' => Format::name('', $student['preferredName'] ?? '', $student['surname'] ?? '', 'Student', true),
    'studentID' => $student['studentID'] ?? '',
    'paymentTitle' => $paymentTitle,
    'amountPaid' => $amountPaid,
    'paymentDate' => $paymentDate,
    'remainingBalance' => $remainingBalance,
    'receiptNumber' => $receiptNumber,
    'generatedBy' => Format::name('', $session->get('preferredName'), $session->get('surname'), 'Staff', false, true),
    'backgroundImagePath' => $backgroundAbs,
], "receipt-{$receiptNumber}.pdf");

exit;

