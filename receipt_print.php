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

$_GET = $container->get(Validator::class)->sanitize($_GET);

// Access: Add Payment permission OR Admin delete permission (for reprints)
$canAdd = isActionAccessible($guid, $connection2, '/modules/FinanceCustom/payments_add.php');
$canAdmin = isActionAccessible($guid, $connection2, '/modules/FinanceCustom/payments_deleteProcess.php');
if (!$canAdd && !$canAdmin) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$paymentID = intval($_GET['gibbonFinanceMgmtStudentPaymentID'] ?? 0);
if ($paymentID <= 0) {
    $page->addError(__('The specified record cannot be found.'));
    return;
}

try {
    $sql = "SELECT p.*, per.preferredName, per.surname, per.studentID
        FROM gibbonFinanceMgmtStudentPayment AS p
        JOIN gibbonPerson AS per ON (p.gibbonPersonIDStudent=per.gibbonPersonID)
        WHERE p.gibbonFinanceMgmtStudentPaymentID=:paymentID";
    $stmt = $connection2->prepare($sql);
    $stmt->execute(['paymentID' => $paymentID]);
    $payment = $stmt->fetch();
} catch (PDOException $e) {
    $payment = false;
}

if (empty($payment)) {
    $page->addError(__('The specified record cannot be found.'));
    return;
}

$allowReprint = financeMgmtGetSettingValue('FinanceCustom', 'receiptAllowReprint', 'N') === 'Y';
if ($payment['receiptPrinted'] === 'Y' && !$allowReprint && !$canAdmin) {
    $page->addError(__('This receipt has already been generated.'));
    return;
}

if ($payment['receiptPrinted'] === 'Y' && !$canAdmin) {
    $page->addError(__('You do not have access to reprint receipts.'));
    return;
}

$gibbonSchoolYearID = intval($payment['gibbonSchoolYearID']);
$totalsBefore = financeMgmtGetStudentTotals($connection2, intval($payment['gibbonPersonIDStudent']), $gibbonSchoolYearID);

// Remaining balance after this transaction:
try {
    $sqlPaidToHere = "SELECT COALESCE(SUM(amountPaid),0)
        FROM gibbonFinanceMgmtStudentPayment
        WHERE gibbonPersonIDStudent=:student
            AND gibbonSchoolYearID=:schoolYear
            AND (
                paymentDate < :paymentDate
                OR (paymentDate = :paymentDate AND gibbonFinanceMgmtStudentPaymentID <= :paymentID)
            )";
    $stmtPaid = $connection2->prepare($sqlPaidToHere);
    $stmtPaid->execute([
        'student' => $payment['gibbonPersonIDStudent'],
        'schoolYear' => $gibbonSchoolYearID,
        'paymentDate' => $payment['paymentDate'],
        'paymentID' => $paymentID,
    ]);
    $paidToHere = floatval($stmtPaid->fetchColumn(0) ?: 0);
} catch (PDOException $e) {
    $paidToHere = floatval($totalsBefore['totalPaid']);
}

$remainingBalance = ($totalsBefore['totalFee'] === null) ? null : max(0, floatval($totalsBefore['totalFee']) - $paidToHere);

$backgroundRel = strval(financeMgmtGetSettingValue('FinanceCustom', 'receiptBackgroundImage', ''));
$backgroundAbs = '';
if (!empty($backgroundRel)) {
    $backgroundAbs = rtrim($session->get('absolutePath'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($backgroundRel, DIRECTORY_SEPARATOR);
}

if (!empty($payment['receiptNumber'])) {
    $receiptNumber = $payment['receiptNumber'];
} else {
    $prefix = strval(financeMgmtGetSettingValue('FinanceCustom', 'receiptNumberPrefix', 'RCP'));
    $receiptNumber = financeMgmtReceiptNumberFromPaymentID($prefix, $paymentID, $payment['paymentDate']);
}

if ($payment['receiptPrinted'] === 'Y') {
    financeMgmtLog(
        $connection2,
        'RECEIPT_REPRINT',
        strval($paymentID),
        strval($session->get('gibbonPersonID')),
        json_encode(['receiptNumber' => $receiptNumber])
    );
}

$generator = new ReceiptGenerator();
$generator->outputReceipt([
    'schoolName' => $session->get('systemName'),
    'studentName' => Format::name('', $payment['preferredName'], $payment['surname'], 'Student', true),
    'studentID' => $payment['studentID'] ?? '',
    'paymentTitle' => $payment['paymentTitle'],
    'amountPaid' => floatval($payment['amountPaid']),
    'paymentDate' => $payment['paymentDate'],
    'remainingBalance' => $remainingBalance,
    'receiptNumber' => $receiptNumber,
    'generatedBy' => Format::name('', $session->get('preferredName'), $session->get('surname'), 'Staff', false, true),
    'backgroundImagePath' => $backgroundAbs,
], "receipt-{$receiptNumber}.pdf");

exit;

