<?php
/*
Gibbon: the flexible, open school platform
*/

use Gibbon\Data\Validator;
use Gibbon\Services\Format;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$returnTo = trim($_POST['returnTo'] ?? $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_console.php');
$paymentID = intval($_POST['gibbonFinanceMgmtStudentPaymentID'] ?? 0);
$paymentTitle = trim($_POST['paymentTitle'] ?? '');
$amountPaid = floatval($_POST['amountPaid'] ?? 0);
$paymentDate = Format::dateConvert($_POST['paymentDate'] ?? '');

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/admin_console.php') == false) {
    header("Location: {$returnTo}&return=error0");
    exit;
}

if (!financeMgmtHasAdminCodeSessionAccess($session)) {
    header('Location: '.$session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_access.php');
    exit;
}

if ($paymentID <= 0 || $paymentTitle === '' || $amountPaid <= 0 || empty($paymentDate)) {
    header("Location: {$returnTo}&return=error3");
    exit;
}

try {
    $connection2->beginTransaction();

    $stmt = $connection2->prepare("SELECT * FROM gibbonFinanceMgmtStudentPayment WHERE gibbonFinanceMgmtStudentPaymentID=:id");
    $stmt->execute(['id' => $paymentID]);
    $existing = $stmt->fetch();
    if (empty($existing)) {
        $connection2->rollBack();
        header("Location: {$returnTo}&return=error3");
        exit;
    }

    $sqlUpdate = "UPDATE gibbonFinanceMgmtStudentPayment
        SET paymentTitle=:paymentTitle, amountPaid=:amountPaid, paymentDate=:paymentDate
        WHERE gibbonFinanceMgmtStudentPaymentID=:id";
    $stmtUpdate = $connection2->prepare($sqlUpdate);
    $stmtUpdate->execute([
        'paymentTitle' => $paymentTitle,
        'amountPaid'   => $amountPaid,
        'paymentDate'  => $paymentDate,
        'id'           => $paymentID,
    ]);

    financeMgmtLog(
        $connection2,
        'PAYMENT_EDIT',
        strval($paymentID),
        strval($session->get('gibbonPersonID')),
        json_encode([
            'before' => $existing,
            'after'  => ['paymentTitle' => $paymentTitle, 'amountPaid' => $amountPaid, 'paymentDate' => $paymentDate],
        ])
    );

    // Rebuild instalment ledger to reflect the edited amount.
    $plan = financeMgmtGetStudentPaymentPlan(
        $connection2,
        intval($existing['gibbonPersonIDStudent']),
        intval($existing['gibbonSchoolYearID'])
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
