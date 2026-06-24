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

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$paymentID = intval($_POST['gibbonFinanceMgmtStudentPaymentID'] ?? 0);
$returnTo = trim($_POST['returnTo'] ?? $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php');

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/payments_deleteProcess.php') == false) {
    header("Location: {$returnTo}&return=error0");
    exit;
}

if ($paymentID <= 0) {
    header("Location: {$returnTo}&return=error3");
    exit;
}

try {
    $connection2->beginTransaction();

    $stmt = $connection2->prepare("SELECT * FROM gibbonFinanceMgmtStudentPayment WHERE gibbonFinanceMgmtStudentPaymentID=:id");
    $stmt->execute(['id' => $paymentID]);
    $payment = $stmt->fetch();
    if (empty($payment)) {
        $connection2->rollBack();
        header("Location: {$returnTo}&return=error3");
        exit;
    }

    $stmtDel = $connection2->prepare("DELETE FROM gibbonFinanceMgmtStudentPayment WHERE gibbonFinanceMgmtStudentPaymentID=:id");
    $stmtDel->execute(['id' => $paymentID]);

    financeMgmtLog(
        $connection2,
        'PAYMENT_DELETE',
        strval($paymentID),
        strval($session->get('gibbonPersonID')),
        json_encode(['deletedPayment' => $payment])
    );

    // Rebuild instalment ledger after deletion.
    $plan = financeMgmtGetStudentPaymentPlan(
        $connection2,
        intval($payment['gibbonPersonIDStudent']),
        intval($payment['gibbonSchoolYearID'])
    );
    if ($plan !== null) {
        financeMgmtRebuildPlanLedger($connection2, $plan);
    }

    $connection2->commit();
} catch (PDOException $e) {
    if ($connection2->inTransaction()) $connection2->rollBack();
    header("Location: {$returnTo}&return=error2");
    exit;
}

header("Location: {$returnTo}&return=success0");
exit;

