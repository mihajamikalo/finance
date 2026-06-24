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
    $page->addError(__('Vous n\'avez pas accès à cette action.'));
    return;
}

$page->breadcrumbs->add(__('Enregistrer un paiement'));

echo '<h2>'.__('Saisie de paiement').'</h2>';

// Afficher l'acompte configuré pour que l'équipe Finance sache ce qui est attendu.
$configuredDeposit = financeMgmtGetConfiguredInitialDeposit();
echo "<p class='text-sm text-gray-600' style='margin-bottom:12px'>"
    . sprintf(
        __('Acompte initial configuré pour les plans de paiement : <strong>%1$s</strong>'),
        number_format($configuredDeposit, 2, '.', ',')
    )
    . "</p>";

$form = Form::create('financePaymentAdd', $session->get('absoluteURL').'/modules/FinanceCustom/payments_addProcess.php');
$form->addHiddenValue('address', $session->get('address'));

$row = $form->addRow();
    $row->addLabel('paymentTitle', __('Libellé du paiement'));
    $row->addTextField('paymentTitle')->maxLength(100)->required();

$ajaxUrl = $session->get('absoluteURL').'/modules/FinanceCustom/ajax_studentSearch.php';
$row = $form->addRow();
    $row->addLabel('gibbonPersonIDStudent', __('Élève'));
    $row->addFinder('gibbonPersonIDStudent')
        ->fromAjax($ajaxUrl)
        ->setParameter('tokenLimit', 1)
        ->setParameter('minChars', 2)
        ->required();

$row = $form->addRow();
    $row->addLabel('amountPaid', __('Montant versé'));
    $row->addNumber('amountPaid')->decimalPlaces(2)->required()->minimum(0.01);

$row = $form->addRow();
    $row->addLabel('paymentDate', __('Date du paiement'));
    $row->addDate('paymentDate')->setValue(Format::date(date('Y-m-d')))->required();

// Sélecteur de plan — seulement au premier paiement ; le script de traitement
// détecte automatiquement si un plan existe déjà et ignore ce champ si c'est le cas.
$row = $form->addRow();
    $row->addLabel('paymentOption', __('Plan de paiement'))
        ->description(__('Obligatoire lors du premier paiement. Ignoré pour les paiements suivants.'));
    $row->addSelect('paymentOption')
        ->fromArray([
            ''     => __('— choisir un plan (premier paiement uniquement) —'),
            'FULL' => __('Paiement intégral avec remise de 10 %'),
            '4'    => __('4 mensualités'),
            '8'    => __('8 mensualités'),
        ]);

$form->addRow()->addSubmit(__('Enregistrer et imprimer le reçu'));

echo $form->getOutput();

