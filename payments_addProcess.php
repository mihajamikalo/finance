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

$paymentTitle          = trim($_POST['paymentTitle'] ?? '');
$studentToken          = trim($_POST['gibbonPersonIDStudent'] ?? '');
$amountPaid            = floatval($_POST['amountPaid'] ?? 0);
$paymentDate           = Format::dateConvert($_POST['paymentDate'] ?? '');
$paymentOption         = trim($_POST['paymentOption'] ?? '');
$firstInstallmentDate  = Format::dateConvert($_POST['firstInstallmentDate'] ?? '');
$customDates           = $_POST['customDates']   ?? [];
$customAmounts         = $_POST['customAmounts'] ?? [];
$validMethods   = ['BANK', 'MOBILE', 'CASH', 'OTHER'];
$paymentMethod  = in_array(trim($_POST['paymentMethod'] ?? ''), $validMethods, true)
                    ? trim($_POST['paymentMethod'])
                    : 'CASH';

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

// Determine whether a plan already exists for this student/year.
$existingPlan  = financeMgmtGetStudentPaymentPlan($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
$paymentCount  = financeMgmtCountStudentPayments($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
$isFirstPayment = ($existingPlan === null && $paymentCount === 0);

// Premier paiement : un plan valide est obligatoire.
$validOptions = ['FULL', '4', '8', 'CUSTOM'];
if ($isFirstPayment && !in_array($paymentOption, $validOptions, true)) {
    $URL .= '&return=error3';
    header("Location: {$URL}");
    exit;
}

// Pour un plan libre, valider et construire le JSON du calendrier.
$customScheduleJson = '';
if ($paymentOption === 'CUSTOM') {
    if (empty($customDates) || count($customDates) !== count($customAmounts)) {
        $URL .= '&return=error3';
        header("Location: {$URL}");
        exit;
    }
    $customSchedule = [];
    foreach ($customDates as $i => $rawDate) {
        $date   = trim($rawDate);
        $amount = floatval($customAmounts[$i] ?? 0);
        // Valider format date YYYY-MM-DD (renvoyé par <input type="date">)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $amount <= 0) {
            $URL .= '&return=error3';
            header("Location: {$URL}");
            exit;
        }
        $customSchedule[] = [
            'installmentNumber' => $i + 1,
            'label'             => 'Versement ' . ($i + 1),
            'dueDate'           => $date,
            'expectedAmount'    => $amount,
        ];
    }
    $customScheduleJson = json_encode($customSchedule, JSON_UNESCAPED_UNICODE);
}

try {
    $connection2->beginTransaction();

    // Create payment plan on first payment.
    if ($existingPlan === null) {
        $option = in_array($paymentOption, $validOptions, true) ? $paymentOption : 'LEGACY';
        financeMgmtCreateStudentPaymentPlan(
            $connection2,
            $gibbonPersonIDStudent,
            $gibbonSchoolYearID,
            $gibbonYearGroupID,
            floatval($totals['totalFee']),
            $option,
            $paymentDate,
            $gibbonPersonIDCreatedBy,
            $firstInstallmentDate,
            $customScheduleJson
        );
        // Re-fetch plan so the effective fee (with discount) is used below.
        $existingPlan = financeMgmtGetStudentPaymentPlan($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
    }

    $data = [
        'gibbonPersonIDStudent'  => $gibbonPersonIDStudent,
        'gibbonSchoolYearID'     => $gibbonSchoolYearID,
        'gibbonYearGroupID'      => $gibbonYearGroupID,
        'paymentTitle'           => $paymentTitle,
        'amountPaid'             => $amountPaid,
        'paymentDate'            => $paymentDate,
        'paymentMethod'          => $paymentMethod,
        'receiptPrinted'         => 'N',
        'receiptNumber'          => null,
        'gibbonPersonIDCreatedBy'=> $gibbonPersonIDCreatedBy,
        'createdAt'              => date('Y-m-d H:i:s'),
    ];

    $sql = "INSERT INTO gibbonFinanceMgmtStudentPayment
        SET gibbonPersonIDStudent=:gibbonPersonIDStudent,
            gibbonSchoolYearID=:gibbonSchoolYearID,
            gibbonYearGroupID=:gibbonYearGroupID,
            paymentTitle=:paymentTitle,
            amountPaid=:amountPaid,
            paymentDate=:paymentDate,
            paymentMethod=:paymentMethod,
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
            'student'       => $gibbonPersonIDStudent,
            'amountPaid'    => $amountPaid,
            'paymentDate'   => $paymentDate,
            'receiptNumber' => $receiptNumber,
            'paymentOption' => $paymentOption,
            'paymentMethod' => $paymentMethod,
        ])
    );

    // Rebuild instalment ledger snapshot after each payment.
    if (!empty($existingPlan)) {
        financeMgmtRebuildPlanLedger($connection2, $existingPlan);
    }

    $connection2->commit();
} catch (PDOException $e) {
    if ($connection2->inTransaction()) $connection2->rollBack();
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

// Fetch student details for receipt.
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

// Use the plan's final (discounted) fee when computing remaining balance.
$effectiveFee     = !empty($existingPlan) ? floatval($existingPlan['tuitionFeeFinal']) : floatval($totals['totalFee']);
$remainingBalance = max(0.0, $effectiveFee - (floatval($totals['totalPaid']) + $amountPaid));

$backgroundRel = strval(financeMgmtGetSettingValue('FinanceCustom', 'receiptBackgroundImage', ''));
$backgroundAbs = '';
if (!empty($backgroundRel)) {
    $backgroundAbs = rtrim($session->get('absolutePath'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($backgroundRel, DIRECTORY_SEPARATOR);
}

$generator = new ReceiptGenerator();
$generator->outputReceipt([
    'schoolName'         => $session->get('systemName'),
    'studentName'        => Format::name('', $student['preferredName'] ?? '', $student['surname'] ?? '', 'Student', true),
    'studentID'          => $student['studentID'] ?? '',
    'paymentTitle'       => $paymentTitle,
    'amountPaid'         => $amountPaid,
    'paymentDate'        => $paymentDate,
    'paymentMethod'      => $paymentMethod,
    'remainingBalance'   => $remainingBalance,
    'receiptNumber'      => $receiptNumber,
    'generatedBy'        => Format::name('', $session->get('preferredName'), $session->get('surname'), 'Staff', false, true),
    'backgroundImagePath'=> $backgroundAbs,
], "receipt-{$receiptNumber}.pdf");

exit;

