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
    $page->addError(__('Vous n\'avez pas accès à cette action.'));
    return;
}

$page->breadcrumbs->add(__('Gérer les frais de scolarité'));
$gibbonSchoolYearID = intval($session->get('gibbonSchoolYearID'));

echo '<h2>'.__('Frais de scolarité par niveau').'</h2>';
echo '<p>'.__('Définissez un montant de frais de scolarité par niveau pour l\'année scolaire en cours.').'</p>';

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
    $page->addError(__('Une erreur de base de données s\'est produite.'));
    return;
}

$currentDeposit = financeMgmtGetConfiguredInitialDeposit();

$form = Form::create('tuitionFees', $session->get('absoluteURL').'/modules/FinanceCustom/tuitionFees_manageProcess.php');
$form->addHiddenValue('address', $session->get('address'));

$form->addRow()->addHeading(__('Paramètres des versements échelonnés'));

$row = $form->addRow();
    $row->addLabel('installmentInitialDeposit', __('Acompte initial requis'))
        ->description(__('Montant en Ariary (Ar) que les élèves doivent verser lors du choix d\'un plan en 4 ou 8 mensualités.'));
    $row->addContent('
        <div style="display:flex;align-items:center;gap:8px;">
            <input type="number" name="installmentInitialDeposit" id="installmentInitialDeposit"
                   step="1" min="0" value="'.htmlspecialchars(strval($currentDeposit)).'"
                   style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;width:200px;">
            <span style="font-size:14px;font-weight:600;color:#555;background:#f5f5f5;
                         padding:6px 12px;border:1px solid #ddd;border-radius:4px;">Ar</span>
        </div>
    ');

$form->addRow()->addHeading(__('Frais'));

$form->addRow()->addContent('
    <div style="background:#fffbe6;border:1px solid #f0c040;border-radius:6px;
                padding:10px 14px;font-size:13px;color:#7a5200;margin-bottom:8px;
                display:flex;align-items:center;gap:8px;">
        <span class="material-icons" style="font-size:17px;vertical-align:middle;">info</span>
        '.__('Les frais de scolarité sont saisis en <strong>Euros (€)</strong>. Le cours d\'échange sera demandé lors du premier paiement de chaque élève.').'
    </div>
');

foreach ($yearGroups as $yg) {
    $id = intval($yg['gibbonYearGroupID']);
    $amount = isset($fees[$id]['amount']) ? floatval($fees[$id]['amount']) : '';
    $active = isset($fees[$id]['active']) ? strval($fees[$id]['active']) : 'Y';

    $row = $form->addRow();
        $row->addLabel('fee_'.$id, $yg['name'].' ('.$yg['nameShort'].')');
        $row->addContent('
            <div style="display:flex;align-items:center;gap:8px;">
                <input type="number" name="fee['.$id.']" step="0.01" min="0" required
                       value="'.htmlspecialchars(strval($amount)).'"
                       style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;width:200px;">
                <span style="font-size:14px;font-weight:600;color:#1a5276;background:#eaf3fb;
                             padding:6px 12px;border:1px solid #b8d4ef;border-radius:4px;">€</span>
            </div>
        ');

    $row = $form->addRow();
        $row->addLabel('active_'.$id, __('Actif'));
        $row->addYesNo('active['.$id.']')->selected($active);
}

$form->addRow()->addSubmit(__('Enregistrer'));
echo $form->getOutput();

