<?php
/*
Gibbon: the flexible, open school platform
*/

use Gibbon\Data\Validator;
use Gibbon\Services\Format;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$baseURL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php';
$verifyURL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/payments_deleteOtpVerify.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/index.php') == false) {
    header("Location: {$baseURL}&return=error0");
    exit;
}

$emails = financeMgmtGetOtpAdminEmails($connection2);
if (empty($emails)) {
    header("Location: {$baseURL}&return=otpNoEmail");
    exit;
}

$otpCode = financeMgmtGenerateOtpCode(6);
$expiresAt = time() + (10 * 60);
$requestorName = Format::name('', $session->get('preferredName'), $session->get('surname'), 'Staff', false, true);
$sentCount = financeMgmtSendOtpEmail($emails, $otpCode, $requestorName, '10 minutes');

if ($sentCount < 1) {
    header("Location: {$baseURL}&return=otpSendFail");
    exit;
}

financeMgmtSetOtpSessionState([
    'hash' => hash('sha256', $otpCode),
    'expiresAt' => $expiresAt,
    'attempts' => 0,
    'gibbonPersonID' => strval($session->get('gibbonPersonID')),
    'sentTo' => $emails,
]);

header("Location: {$verifyURL}&return=otpSent");
exit;
