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

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/payments_add.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Add Payment'));

echo '<h2>'.__('Record Payment').'</h2>';

// Show the configured deposit so the Finance team knows what to expect.
$configuredDeposit = financeMgmtGetConfiguredInitialDeposit();
echo "<p class='text-sm text-gray-600' style='margin-bottom:12px'>"
    . sprintf(
        __('Configured initial deposit for instalment plans: <strong>%1$s</strong>'),
        number_format($configuredDeposit, 2, '.', ',')
    )
    . "</p>";

$form = Form::create('financePaymentAdd', $session->get('absoluteURL').'/modules/FinanceCustom/payments_addProcess.php');
$form->addHiddenValue('address', $session->get('address'));

$row = $form->addRow();
    $row->addLabel('paymentTitle', __('Payment Title'));
    $row->addTextField('paymentTitle')->maxLength(100)->required();

$ajaxUrl = $session->get('absoluteURL').'/modules/FinanceCustom/ajax_studentSearch.php';
$row = $form->addRow();
    $row->addLabel('gibbonPersonIDStudent', __('Student'));
    $row->addFinder('gibbonPersonIDStudent')
        ->fromAjax($ajaxUrl)
        ->setParameter('tokenLimit', 1)
        ->setParameter('minChars', 2)
        ->required();

$row = $form->addRow();
    $row->addLabel('amountPaid', __('Amount Paid'));
    $row->addNumber('amountPaid')->decimalPlaces(2)->required()->minimum(0.01);

$row = $form->addRow();
    $row->addLabel('paymentDate', __('Payment Date'));
    $row->addDate('paymentDate')->setValue(Format::date(date('Y-m-d')))->required();

// Payment-plan selector — only shown on the first payment; the process script
// auto-detects whether a plan already exists and ignores this field if so.
$row = $form->addRow();
    $row->addLabel('paymentOption', __('Payment Plan'))
        ->description(__('Required for the very first payment. Ignored for subsequent payments.'));
    $row->addSelect('paymentOption')
        ->fromArray([
            ''     => __('— choose a plan (first payment only) —'),
            'FULL' => __('Full payment with 10 % discount'),
            '4'    => __('4 monthly instalments'),
            '8'    => __('8 monthly instalments'),
        ]);

$form->addRow()->addSubmit(__('Save & Print Receipt'));

echo $form->getOutput();

