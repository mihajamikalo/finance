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

use Gibbon\Forms\Form;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/payments_deleteOtpVerify.php') == false) {
    $page->addError(__('Vous n\'avez pas accès à cette action.'));
    return;
}

$page->breadcrumbs->add(__('Tableau de bord Finance'), 'index.php');
$page->breadcrumbs->add(__('Vérification OTP'));

$state = financeMgmtGetOtpSessionState();
$isValidState = !empty($state)
    && isset($state['gibbonPersonID'])
    && strval($state['gibbonPersonID']) === strval($session->get('gibbonPersonID'))
    && isset($state['expiresAt'])
    && intval($state['expiresAt']) >= time();

echo '<h2>'.__('Vérification OTP — Suppression de l\'historique des paiements').'</h2>';

$return = strval($_GET['return'] ?? '');
if ($return === 'otpSent') {
    $page->addSuccess(__('Un code OTP a été envoyé aux administrateurs par email. Saisissez-le ci-dessous pour continuer.'));
} elseif ($return === 'otpInvalid') {
    $page->addError(__('Code OTP invalide. Veuillez réessayer.'));
} elseif ($return === 'otpExpired') {
    $page->addError(__('Code OTP expiré. Veuillez en demander un nouveau depuis le tableau de bord.'));
} elseif ($return === 'otpTooManyAttempts') {
    $page->addError(__('Trop de tentatives invalides. Veuillez demander un nouveau code OTP depuis le tableau de bord.'));
}

if (!$isValidState) {
    echo "<div class='warning'>".__('Aucune demande OTP valide en cours. Retournez au tableau de bord et demandez un nouveau code.')."</div>";
    $backURL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php';
    echo "<div style='margin-top:10px'><a class='button' href='".htmlPrep($backURL)."'>".__('Retour au tableau de bord')."</a></div>";
    return;
}

$remainingSeconds = max(1, intval($state['expiresAt']) - time());
echo "<div class='message'>".sprintf(__('Ce code expire dans environ %1$s minute(s).'), strval(intval(ceil($remainingSeconds / 60))))."</div>";

$form = Form::create(
    'financeDeleteOtpVerify',
    $session->get('absoluteURL').'/modules/FinanceCustom/payments_deleteOtpVerifyProcess.php'
);
$form->addHiddenValue('address', $session->get('address'));

$row = $form->addRow();
    $row->addLabel('otpCode', __('Code OTP'));
    $row->addTextField('otpCode')->maxLength(6)->required()
        ->setAttribute('placeholder', '000000')
        ->setAttribute('autocomplete', 'off');

$form->addRow()->addSubmit(__('Vérifier et supprimer l\'historique'));
echo $form->getOutput();
