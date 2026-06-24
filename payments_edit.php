<?php
/*
Gibbon: the flexible, open school platform
*/

use Gibbon\Forms\Form;
use Gibbon\Services\Format;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/admin_console.php') == false) {
    $page->addError(__('Vous n\'avez pas accès à cette action.'));
    return;
}

if (!financeMgmtHasAdminCodeSessionAccess($session)) {
    header('Location: '.$session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_access.php');
    exit;
}

$paymentID = intval($_GET['gibbonFinanceMgmtStudentPaymentID'] ?? 0);
if ($paymentID <= 0) {
    $page->addError(__('The specified record cannot be found.'));
    return;
}

try {
    $stmt = $connection2->prepare("SELECT p.*, per.preferredName, per.surname, per.studentID
        FROM gibbonFinanceMgmtStudentPayment AS p
        JOIN gibbonPerson AS per ON (p.gibbonPersonIDStudent=per.gibbonPersonID)
        WHERE p.gibbonFinanceMgmtStudentPaymentID=:id");
    $stmt->execute(['id' => $paymentID]);
    $payment = $stmt->fetch();
} catch (PDOException $e) {
    $payment = false;
}

if (empty($payment)) {
    $page->addError(__('L\'enregistrement spécifié est introuvable.'));
    return;
}

$page->breadcrumbs->add(__('Modifier le paiement'));

echo '<h2>'.__('Modifier le paiement').'</h2>';
echo '<p><b>'.__('Élève').' :</b> '.Format::name('', $payment['preferredName'], $payment['surname'], 'Student', true).' ('.htmlPrep($payment['studentID']).')</p>';

$form = Form::create('financePaymentEdit', $session->get('absoluteURL').'/modules/FinanceCustom/payments_editProcess.php');
$form->addHiddenValue('address', $session->get('address'));
$form->addHiddenValue('gibbonFinanceMgmtStudentPaymentID', strval($paymentID));
$form->addHiddenValue('returnTo', $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_console.php');

$row = $form->addRow();
    $row->addLabel('paymentTitle', __('Libellé du paiement'));
    $row->addTextField('paymentTitle')->maxLength(100)->setValue(strval($payment['paymentTitle']))->required();

$row = $form->addRow();
    $row->addLabel('amountPaid', __('Montant versé'));
    $row->addNumber('amountPaid')->decimalPlaces(2)->setValue(strval($payment['amountPaid']))->required()->minimum(0.01);

$row = $form->addRow();
    $row->addLabel('paymentDate', __('Date du paiement'));
    $row->addDate('paymentDate')->setValue(Format::date($payment['paymentDate']))->required();

$form->addRow()->addSubmit(__('Enregistrer les modifications'));
echo $form->getOutput();
