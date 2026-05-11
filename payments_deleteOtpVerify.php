<?php
/*
Gibbon: the flexible, open school platform
*/

use Gibbon\Forms\Form;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/index.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Finance Dashboard'), 'index.php');
$page->breadcrumbs->add(__('OTP Verification'));

$state = financeMgmtGetOtpSessionState();
$isValidState = !empty($state)
    && isset($state['gibbonPersonID'])
    && strval($state['gibbonPersonID']) === strval($session->get('gibbonPersonID'))
    && isset($state['expiresAt'])
    && intval($state['expiresAt']) >= time();

echo '<h2>'.__('Verify OTP Before Deleting Payment History').'</h2>';

$return = strval($_GET['return'] ?? '');
if ($return === 'otpSent') {
    $page->addSuccess(__('An OTP has been sent to administrator email(s). Enter it below to continue.'));
} elseif ($return === 'otpInvalid') {
    $page->addError(__('Invalid OTP code. Please try again.'));
} elseif ($return === 'otpExpired') {
    $page->addError(__('OTP expired. Request a new code from the dashboard.'));
} elseif ($return === 'otpTooManyAttempts') {
    $page->addError(__('Too many invalid attempts. Request a new OTP code from the dashboard.'));
}

if (!$isValidState) {
    echo "<div class='warning'>".__('No valid OTP request found. Please return to the dashboard and request a new code.')."</div>";
    $backURL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php';
    echo "<div style='margin-top:10px'><a class='button' href='".htmlPrep($backURL)."'>".__('Back to Dashboard')."</a></div>";
    return;
}

$remainingSeconds = max(1, intval($state['expiresAt']) - time());
echo "<div class='message'>".sprintf(__('This code will expire in about %1$s minute(s).'), strval(intval(ceil($remainingSeconds / 60))))."</div>";

$form = Form::create('financeDeleteOtpVerify', $session->get('absoluteURL').'/modules/FinanceCustom/payments_deleteOtpVerifyProcess.php');
$form->addHiddenValue('address', $session->get('address'));

$row = $form->addRow();
    $row->addLabel('otpCode', __('OTP Code'));
    $row->addTextField('otpCode')->maxLength(6)->required();

$form->addRow()->addSubmit(__('Verify & Delete Payment History'));
echo $form->getOutput();
