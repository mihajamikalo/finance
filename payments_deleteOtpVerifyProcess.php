<?php
/*
Gibbon: the flexible, open school platform
*/

use Gibbon\Data\Validator;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$baseURL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php';
$verifyURL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/payments_deleteOtpVerify.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/index.php') == false) {
    header("Location: {$baseURL}&return=error0");
    exit;
}

$otpCode = preg_replace('/\D+/', '', strval($_POST['otpCode'] ?? ''));
$state = financeMgmtGetOtpSessionState();

$isStateUserMatch = isset($state['gibbonPersonID']) && strval($state['gibbonPersonID']) === strval($session->get('gibbonPersonID'));
if (empty($state) || !$isStateUserMatch) {
    financeMgmtClearOtpSessionState();
    header("Location: {$verifyURL}&return=otpExpired");
    exit;
}

if (!isset($state['expiresAt']) || intval($state['expiresAt']) < time()) {
    financeMgmtClearOtpSessionState();
    header("Location: {$verifyURL}&return=otpExpired");
    exit;
}

$attempts = intval($state['attempts'] ?? 0);
if ($attempts >= 5) {
    financeMgmtClearOtpSessionState();
    header("Location: {$verifyURL}&return=otpTooManyAttempts");
    exit;
}

$providedHash = hash('sha256', $otpCode);
$expectedHash = strval($state['hash'] ?? '');
if ($otpCode === '' || $expectedHash === '' || !hash_equals($expectedHash, $providedHash)) {
    $state['attempts'] = $attempts + 1;
    financeMgmtSetOtpSessionState($state);
    header("Location: {$verifyURL}&return=otpInvalid");
    exit;
}

try {
    $connection2->beginTransaction();

    $connection2->exec("DELETE FROM gibbonFinanceMgmtStudentPayment");

    financeMgmtLog(
        $connection2,
        'PAYMENT_HISTORY_DELETE_ALL_OTP',
        null,
        strval($session->get('gibbonPersonID')),
        json_encode([
            'sentTo' => $state['sentTo'] ?? [],
            'verifiedAt' => date('c'),
        ])
    );

    $connection2->commit();
} catch (PDOException $e) {
    if ($connection2->inTransaction()) {
        $connection2->rollBack();
    }
    header("Location: {$baseURL}&return=error2");
    exit;
}

financeMgmtClearOtpSessionState();
header("Location: {$baseURL}&return=paymentHistoryDeleted");
exit;
