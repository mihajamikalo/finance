<?php
/*
Gibbon: the flexible, open school platform
*/

use Gibbon\Data\Validator;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$paymentID = intval($_POST['gibbonFinanceMgmtStudentPaymentID'] ?? 0);
$returnTo = trim($_POST['returnTo'] ?? $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_console.php');

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/admin_console.php') == false) {
    header("Location: {$returnTo}&return=error0");
    exit;
}

if (!financeMgmtHasAdminCodeSessionAccess($session)) {
    header('Location: '.$session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_access.php');
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
        'PAYMENT_DELETE_HIDDEN_ADMIN',
        strval($paymentID),
        strval($session->get('gibbonPersonID')),
        json_encode(['deletedPayment' => $payment])
    );

    // Rebuild instalment ledger after admin deletion.
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
