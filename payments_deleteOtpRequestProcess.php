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

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$baseURL   = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php';
$verifyURL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/payments_deleteOtpVerify.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/index.php') == false) {
    header("Location: {$baseURL}&return=error0");
    exit;
}

// Récupérer les emails administrateurs destinataires du code OTP.
$emails = financeMgmtGetOtpAdminEmails($connection2);
if (empty($emails)) {
    header("Location: {$baseURL}&return=otpNoEmail");
    exit;
}

// Générer un code OTP à 6 chiffres, valable 10 minutes.
$otpCode       = financeMgmtGenerateOtpCode(6);
$expiresAt     = time() + (10 * 60);
$requestorName = Format::name('', $session->get('preferredName'), $session->get('surname'), 'Staff', false, true);
$sentCount     = financeMgmtSendOtpEmail($emails, $otpCode, $requestorName, '10 minutes');

if ($sentCount < 1) {
    header("Location: {$baseURL}&return=otpSendFail");
    exit;
}

// Stocker le hash du code (jamais le code en clair) + métadonnées en session.
financeMgmtSetOtpSessionState([
    'hash'            => hash('sha256', $otpCode),
    'expiresAt'       => $expiresAt,
    'attempts'        => 0,
    'gibbonPersonID'  => strval($session->get('gibbonPersonID')),
    'sentTo'          => $emails,
]);

header("Location: {$verifyURL}&return=otpSent");
exit;
