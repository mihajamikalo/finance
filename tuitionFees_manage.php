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
use Gibbon\Services\Format;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/tuitionFees_manage.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Manage Tuition Fees'));
$gibbonSchoolYearID = intval($session->get('gibbonSchoolYearID'));

echo '<h2>'.__('Tuition Fees by Year Group').'</h2>';
echo '<p>'.__('Set one tuition fee per year group for the current school year.').'</p>';

try {
    $sqlYG = "SELECT gibbonYearGroupID, name, nameShort
        FROM gibbonYearGroup
        ORDER BY sequenceNumber";
    $yearGroups = $connection2->query($sqlYG)->fetchAll();

    $stmtFee = $connection2->prepare("SELECT gibbonYearGroupID, amount, active
        FROM gibbonFinanceMgmtTuitionFee
        WHERE gibbonSchoolYearID=:gibbonSchoolYearID");
    $stmtFee->execute(['gibbonSchoolYearID' => $gibbonSchoolYearID]);
    $feesRaw = $stmtFee->fetchAll();
    $fees = [];
    foreach ($feesRaw as $f) {
        $fees[intval($f['gibbonYearGroupID'])] = $f;
    }
} catch (PDOException $e) {
    $page->addError(__('A database error occurred.'));
    return;
}

$currentDeposit = financeMgmtGetConfiguredInitialDeposit();

$form = Form::create('tuitionFees', $session->get('absoluteURL').'/modules/FinanceCustom/tuitionFees_manageProcess.php');
$form->addHiddenValue('address', $session->get('address'));

$form->addRow()->addHeading(__('Instalment Settings'));

$row = $form->addRow();
    $row->addLabel('installmentInitialDeposit', __('Required Initial Deposit'))
        ->description(__('Amount that students must pay upfront when choosing a 4- or 8-instalment plan.'));
    $row->addNumber('installmentInitialDeposit')
        ->decimalPlaces(2)
        ->minimum(0)
        ->setValue(strval($currentDeposit));

$form->addRow()->addHeading(__('Fees'));

foreach ($yearGroups as $yg) {
    $id = intval($yg['gibbonYearGroupID']);
    $amount = isset($fees[$id]['amount']) ? floatval($fees[$id]['amount']) : '';
    $active = isset($fees[$id]['active']) ? strval($fees[$id]['active']) : 'Y';

    $row = $form->addRow();
        $row->addLabel('fee_'.$id, $yg['name'].' ('.$yg['nameShort'].')');
        $row->addNumber('fee['.$id.']')->decimalPlaces(2)->setValue($amount)->required()->minimum(0);

    $row = $form->addRow();
        $row->addLabel('active_'.$id, __('Active'));
        $row->addYesNo('active['.$id.']')->selected($active);
}

$form->addRow()->addSubmit(__('Save'));
echo $form->getOutput();

