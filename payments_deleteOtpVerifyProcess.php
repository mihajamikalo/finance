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

$baseURL   = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php';
$verifyURL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/payments_deleteOtpVerify.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/payments_deleteOtpVerify.php') == false) {
    header("Location: {$baseURL}&return=error0");
    exit;
}

$otpCode = preg_replace('/\D+/', '', strval($_POST['otpCode'] ?? ''));
$state   = financeMgmtGetOtpSessionState();

// Vérifier que la session OTP appartient bien à l'utilisateur courant.
$isStateUserMatch = isset($state['gibbonPersonID'])
    && strval($state['gibbonPersonID']) === strval($session->get('gibbonPersonID'));

if (empty($state) || !$isStateUserMatch) {
    financeMgmtClearOtpSessionState();
    header("Location: {$verifyURL}&return=otpExpired");
    exit;
}

// Vérifier l'expiration.
if (!isset($state['expiresAt']) || intval($state['expiresAt']) < time()) {
    financeMgmtClearOtpSessionState();
    header("Location: {$verifyURL}&return=otpExpired");
    exit;
}

// Bloquer après 5 tentatives incorrectes.
$attempts = intval($state['attempts'] ?? 0);
if ($attempts >= 5) {
    financeMgmtClearOtpSessionState();
    header("Location: {$verifyURL}&return=otpTooManyAttempts");
    exit;
}

// Comparer le hash du code soumis avec le hash stocké (timing-safe).
$providedHash = hash('sha256', $otpCode);
$expectedHash = strval($state['hash'] ?? '');

if ($otpCode === '' || $expectedHash === '' || !hash_equals($expectedHash, $providedHash)) {
    $state['attempts'] = $attempts + 1;
    financeMgmtSetOtpSessionState($state);
    header("Location: {$verifyURL}&return=otpInvalid");
    exit;
}

// OTP valide — supprimer tout l'historique des paiements.
try {
    $connection2->beginTransaction();

    $connection2->exec("DELETE FROM gibbonFinanceMgmtInstallmentLedger");
    $connection2->exec("DELETE FROM gibbonFinanceMgmtPaymentPlan");
    $connection2->exec("DELETE FROM gibbonFinanceMgmtStudentPayment");

    financeMgmtLog(
        $connection2,
        'PAYMENT_HISTORY_DELETE_ALL_OTP',
        null,
        strval($session->get('gibbonPersonID')),
        json_encode([
            'sentTo'     => $state['sentTo'] ?? [],
            'verifiedAt' => date('c'),
        ])
    );

    $connection2->commit();
} catch (PDOException $e) {
    if ($connection2->inTransaction()) {
        $connection2->rollBack();
    }
    financeMgmtClearOtpSessionState();
    header("Location: {$baseURL}&return=error2");
    exit;
}

financeMgmtClearOtpSessionState();
header("Location: {$baseURL}&return=paymentHistoryDeleted");
exit;
