<?php
/*
Gibbon: the flexible, open school platform
*/

use Gibbon\Data\Validator;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$returnTo = trim($_POST['returnTo'] ?? $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_console.php');

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/admin_console.php') == false) {
    header("Location: {$returnTo}&return=error0");
    exit;
}

if (!financeMgmtHasAdminCodeSessionAccess($session)) {
    header('Location: '.$session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_access.php');
    exit;
}

try {
    $connection2->beginTransaction();

    $connection2->exec("DELETE FROM gibbonFinanceMgmtStudentPayment");
    $connection2->exec("DELETE FROM gibbonFinanceMgmtInstallmentLedger");
    $connection2->exec("DELETE FROM gibbonFinanceMgmtPaymentPlan");
    $connection2->exec("DELETE FROM gibbonFinanceMgmtTuitionFee");
    $connection2->exec("DELETE FROM gibbonFinanceMgmtAuditLog");

    financeMgmtLog(
        $connection2,
        'FINANCE_DELETE_ALL',
        null,
        strval($session->get('gibbonPersonID')),
        json_encode(['note' => 'All finance module data deleted from hidden admin console'])
    );

    $connection2->commit();
} catch (PDOException $e) {
    if ($connection2->inTransaction()) {
        $connection2->rollBack();
    }
    header("Location: {$returnTo}&return=error2");
    exit;
}

header("Location: {$returnTo}&return=success0");
exit;
