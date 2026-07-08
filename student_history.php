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
use Gibbon\Domain\QueryCriteria;
use Gibbon\Module\FinanceCustom\Domain\StudentPaymentGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/student_history.php') == false) {
    $page->addError(__('Vous n\'avez pas accès à cette action.'));
    return;
}

$page->breadcrumbs->add(__('Historique des paiements'));

$gibbonSchoolYearID = intval($session->get('gibbonSchoolYearID'));
$gibbonPersonIDStudent = intval($_GET['gibbonPersonIDStudent'] ?? ($_POST['gibbonPersonIDStudent'] ?? 0));

echo '<h2>'.__('Historique des paiements').'</h2>';

$ajaxUrl = $session->get('absoluteURL').'/modules/FinanceCustom/ajax_studentSearch.php';
$form = Form::create('studentHistory', $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/student_history.php');

$row = $form->addRow();
    $row->addLabel('gibbonPersonIDStudent', __('Élève'));
    $row->addFinder('gibbonPersonIDStudent')->fromAjax($ajaxUrl)->setParameter('tokenLimit', 1)->setParameter('minChars', 2)->required();

$form->addRow()->addSubmit(__('Afficher'));
echo $form->getOutput();

if ($gibbonPersonIDStudent <= 0) {
    return;
}

// Finder returns token string, but here we accept query param too. Normalize.
$gibbonPersonIDStudent = intval(explode(',', strval($gibbonPersonIDStudent))[0] ?? 0);

try {
    $stmtStudent = $connection2->prepare("SELECT preferredName, surname, studentID FROM gibbonPerson WHERE gibbonPersonID=:id");
    $stmtStudent->execute(['id' => $gibbonPersonIDStudent]);
    $student = $stmtStudent->fetch();
} catch (PDOException $e) {
    $student = false;
}

if (empty($student)) {
    $page->addError(__('L\'enregistrement spécifié est introuvable.'));
    return;
}

$totals   = financeMgmtGetStudentTotals($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
$plan     = financeMgmtGetStudentPaymentPlan($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
$pmtRows  = financeMgmtGetStudentPayments($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
$evaluation = ($plan !== null) ? financeMgmtEvaluatePlan($plan, $pmtRows, date('Y-m-d')) : null;

echo '<h3>'.Format::name('', $student['preferredName'], $student['surname'], 'Student', true)
    .' <span style="font-size: 85%; font-style: italic">('.htmlPrep($student['studentID']).')</span></h3>';

// ── Summary card ─────────────────────────────────────────────────────────────
echo "<table class='smallIntBorder' cellspacing='0' style='width: 100%'>";
echo "<tr class='head'>"
    . "<th>".__('Frais de scolarité total')."</th>"
    . "<th>".__('Total versé')."</th>"
    . "<th>".__('Solde restant')."</th>"
    . "<th>".__('Statut')."</th>"
    . "</tr>";

$statusTranslations = [
    'Unpaid'        => 'Impayé',
    'Partial'       => 'Partiel',
    'Paid'          => 'Payé',
    'Unconfigured'  => 'Non configuré',
];
$statusLabel = $statusTranslations[$totals['status']] ?? $totals['status'];

echo "<tr>";
echo "<td>".financeMgmtFormatMoney($totals['totalFee'])."</td>";
echo "<td>".financeMgmtFormatMoney($totals['totalPaid'])."</td>";
echo "<td>".financeMgmtFormatMoney($totals['balance'])."</td>";
echo "<td><b>".$statusLabel."</b></td>";
echo "</tr>";
echo "</table>";

// ── Détails du plan de paiement ───────────────────────────────────────────────
if ($plan !== null) {
    echo '<h2>'.__('Plan de paiement').'</h2>';

    $planTypeLabels = [
        'FULL'          => __('Paiement intégral (remise 10 %)'),
        'INSTALLMENT_4' => __('4 mensualités'),
        'INSTALLMENT_8' => __('8 mensualités'),
        'LEGACY'        => __('Historique (sans plan)'),
    ];
    $planLabel = $planTypeLabels[$plan['planType']] ?? htmlPrep($plan['planType']);

    echo "<table class='smallIntBorder' cellspacing='0' style='width: 100%'>";
    echo "<tr class='head'>"
        . "<th>".__('Type de plan')."</th>"
        . "<th style='text-align:right'>".__('Frais initiaux')."</th>"
        . "<th style='text-align:right'>".__('Remise (10 %)')."</th>"
        . "<th style='text-align:right'>".__('Frais final')."</th>"
        . "<th style='text-align:right'>".__('Acompte initial')."</th>"
        . "<th style='text-align:right'>".__('Mensualité')."</th>"
        . "</tr>";
    echo "<tr>";
    echo "<td>".$planLabel."</td>";
    echo "<td style='text-align:right'>".financeMgmtFormatMoney(floatval($plan['tuitionFeeOriginal']))."</td>";
    echo "<td style='text-align:right'>".financeMgmtFormatMoney(floatval($plan['discountAmount']))."</td>";
    echo "<td style='text-align:right'><b>".financeMgmtFormatMoney(floatval($plan['tuitionFeeFinal']))."</b></td>";
    echo "<td style='text-align:right'>".financeMgmtFormatMoney(floatval($plan['requiredDeposit']))."</td>";
    echo "<td style='text-align:right'>"
        . (floatval($plan['installmentAmount']) > 0 ? financeMgmtFormatMoney(floatval($plan['installmentAmount'])) : __('N/D'))
        . "</td>";
    echo "</tr>";
    echo "</table>";

    // ── Calendrier des échéances avec report de crédit ────────────────────────
    if ($evaluation !== null && !empty($evaluation['installments'])) {
        echo '<h3>'.__('Calendrier des échéances').'</h3>';
        echo '<p class="text-sm text-gray-600">'
            . __('Crédit reporté : tout surplus d\'un paiement est automatiquement déduit de la prochaine échéance.')
            . '</p>';

        echo "<table class='smallIntBorder' cellspacing='0' style='width: 100%'>";
        echo "<tr class='head'>"
            . "<th>#</th>"
            . "<th>".__('Libellé')."</th>"
            . "<th>".__('Date d\'échéance')."</th>"
            . "<th style='text-align:right'>".__('Attendu')."</th>"
            . "<th style='text-align:right'>".__('Crédit reporté')."</th>"
            . "<th style='text-align:right'>".__('Exigible')."</th>"
            . "<th style='text-align:right'>".__('Crédit résiduel')."</th>"
            . "<th style='text-align:right'>".__('Restant dû')."</th>"
            . "<th>".__('Statut')."</th>"
            . "</tr>";

        foreach ($evaluation['installments'] as $item) {
            $isLate = ($item['isLate'] === 'Y');
            $isPaid = ($item['outstandingAmount'] <= 0.009);
            $isFuture = ($item['isDue'] === 'N');

            if ($isLate) {
                $statusLabel = __('En retard');
                $statusColor = '#e74c3c';
            } elseif ($isPaid) {
                $statusLabel = __('Payé');
                $statusColor = '#27ae60';
            } elseif ($isFuture) {
                $statusLabel = __('À venir');
                $statusColor = '#95a5a6';
            } else {
                $statusLabel = __('Échu');
                $statusColor = '#f39c12';
            }

            echo "<tr>";
            echo "<td>".intval($item['installmentNumber'])."</td>";
            echo "<td>".htmlPrep($item['label'])."</td>";
            echo "<td>".Format::date($item['dueDate'])."</td>";
            echo "<td style='text-align:right'>".number_format($item['expectedAmount'], 2, '.', ',')."</td>";
            echo "<td style='text-align:right'>".number_format($item['creditBefore'], 2, '.', ',')."</td>";
            echo "<td style='text-align:right'><b>".number_format($item['payableAmount'], 2, '.', ',')."</b></td>";
            echo "<td style='text-align:right'>".number_format($item['creditAfter'], 2, '.', ',')."</td>";
            echo "<td style='text-align:right'>".number_format($item['outstandingAmount'], 2, '.', ',')."</td>";
            echo "<td><span style='color:{$statusColor}; font-weight:bold'>".$statusLabel."</span></td>";
            echo "</tr>";
        }

        echo "</table>";
    }
}

/** @var StudentPaymentGateway $paymentGateway */
$paymentGateway = $container->get(StudentPaymentGateway::class);
$criteria = (new QueryCriteria())->sortBy('paymentDate', 'DESC')->sortBy('gibbonFinanceMgmtStudentPaymentID', 'DESC');

$payments = $paymentGateway->queryPaymentsByStudent($criteria, $gibbonPersonIDStudent, $gibbonSchoolYearID);
$rows = $payments->toArray();

// Calculate running remaining balance after each transaction (descending dates is tricky: we compute ascending then map)
$running = [];
if (!empty($rows) && $totals['totalFee'] !== null) {
    $asc = $rows;
    usort($asc, function ($a, $b) {
        if ($a['paymentDate'] == $b['paymentDate']) {
            return intval($a['gibbonFinanceMgmtStudentPaymentID']) <=> intval($b['gibbonFinanceMgmtStudentPaymentID']);
        }
        return strcmp($a['paymentDate'], $b['paymentDate']);
    });

    $paid = 0.0;
    foreach ($asc as $p) {
        $paid += floatval($p['amountPaid']);
        $running[intval($p['gibbonFinanceMgmtStudentPaymentID'])] = max(0, floatval($totals['totalFee']) - $paid);
    }
}

echo '<h2>'.__('Paiements').'</h2>';

$deleteUrl = $session->get('absoluteURL') . '/modules/FinanceCustom/payments_deleteProcess.php';
$returnUrl = $session->get('absoluteURL') . '/index.php?q=/modules/FinanceCustom/student_history.php'
           . '&gibbonPersonIDStudent=' . $gibbonPersonIDStudent;

$methodBadge = function (string $method): string {
    $icon  = '<span class="material-icons" style="font-size:14px;vertical-align:middle;margin-right:3px">%s</span>';
    $tpl   = '<span style="display:inline-flex;align-items:center;background:%s;color:%s;'
           . 'padding:2px 8px;border-radius:10px;font-size:12px;font-weight:bold">'
           . $icon . '%s</span>';
    $map = [
        'BANK'   => ['#d4e6f1', '#1a5276', 'account_balance', 'Banque'],
        'MOBILE' => ['#d5f5e3', '#1e8449', 'smartphone',      'Mobile Banking'],
        'CASH'   => ['#fef9e7', '#b7950b', 'payments',        'Espèces'],
        'OTHER'  => ['#f2f3f4', '#555',    'more_horiz',      'Autre'],
    ];
    $m = strtoupper($method);
    $c = $map[$m] ?? ['#f2f3f4', '#555', 'more_horiz', htmlspecialchars($method)];
    return sprintf($tpl, $c[0], $c[1], $c[2], $c[3]);
};

echo '<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons"/>';
echo "<table class='smallIntBorder' cellspacing='0' style='width:100%'>";
echo "<tr class='head'>"
    . "<th>".__('Date')."</th>"
    . "<th>".__('Libellé')."</th>"
    . "<th>".__('Mode')."</th>"
    . "<th style='text-align:right'>".__('Montant')."</th>"
    . "<th style='text-align:right'>".__('Restant')."</th>"
    . "<th>".__('Reçu')."</th>"
    . "<th>".__('Saisi par')."</th>"
    . "<th style='text-align:center'>".__('Action')."</th>"
    . "</tr>";

foreach ($rows as $row) {
    $pid    = intval($row['gibbonFinanceMgmtStudentPaymentID']);
    $amount = number_format(floatval($row['amountPaid']), 2, '.', ',');
    $remain = isset($running[$pid]) ? number_format($running[$pid], 2, '.', ',') : __('N/D');
    $dateF  = Format::date($row['paymentDate']);
    $label  = htmlPrep($row['paymentTitle']);
    $mode   = $methodBadge(strtoupper($row['paymentMethod'] ?? 'CASH'));
    $saisie = Format::name('', $row['createdByPreferredName'] ?? '', $row['createdBySurname'] ?? '', 'Staff', false, true);

    if ($row['receiptPrinted'] === 'Y') {
        $rLink = $session->get('absoluteURL').'/modules/FinanceCustom/receipt_print.php?gibbonFinanceMgmtStudentPaymentID='.$pid;
        $recu  = "<a href='".htmlspecialchars($rLink)."'>".__('Réimprimer')."</a>"
               . "<br/><span style='font-size:85%;font-style:italic'>".htmlPrep($row['receiptNumber'] ?? '')."</span>";
    } else {
        $recu = __('Non généré');
    }

    $dateRaw = $row['paymentDate'] ?? '';
    $dateFmt = $dateRaw ? date('d/m/Y', strtotime($dateRaw)) : '';

    echo "<tr>";
    echo "<td>".$dateF."</td>";
    echo "<td>".$label."</td>";
    echo "<td>".$mode."</td>";
    echo "<td style='text-align:right'>".$amount."</td>";
    echo "<td style='text-align:right'>".$remain."</td>";
    echo "<td>".$recu."</td>";
    echo "<td>".$saisie."</td>";
    echo "<td style='text-align:center'>"
        . "<button type='button' class='fc-del-btn'"
        . " data-id='".$pid."'"
        . " data-date='".htmlspecialchars($dateFmt)."'"
        . " data-amount='".htmlspecialchars($amount)."'"
        . " data-label='".htmlspecialchars($row['paymentTitle'] ?? '')."'"
        . " style='background:none;border:none;cursor:pointer;padding:2px 6px;color:#c0392b;'"
        . " title='".__('Supprimer ce paiement')."'>"
        . "<span class='material-icons' style='font-size:20px;vertical-align:middle'>delete</span>"
        . "</button>"
        . "</td>";
    echo "</tr>";
}

if (empty($rows)) {
    echo "<tr><td colspan='8' style='text-align:center;color:#888;padding:12px'>".__('Aucun paiement enregistré.')."</td></tr>";
}

echo "</table>";

// ── Modale de confirmation de suppression ─────────────────────────────────────
$deleteUrl = $session->get('absoluteURL') . '/modules/FinanceCustom/payments_deleteProcess.php';
echo '
<div id="fcDelOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,.25);width:420px;max-width:95vw;overflow:hidden;">
    <div style="background:#c0392b;color:#fff;padding:14px 20px;font-size:16px;font-weight:bold;display:flex;align-items:center;gap:8px;">
      <span class="material-icons" style="font-size:20px">warning</span>
      '.__('Confirmer la suppression').'
    </div>
    <div style="padding:20px;">
      <p style="margin:0 0 14px">'.__('Êtes-vous sûr de vouloir supprimer ce paiement ? Cette action est irréversible.').'</p>
      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <tr><td style="color:#666;padding:5px 6px;border-bottom:1px solid #eee;width:40%">'.__('Date').'</td>
            <td id="fcDelDate" style="font-weight:bold;padding:5px 6px;border-bottom:1px solid #eee"></td></tr>
        <tr><td style="color:#666;padding:5px 6px;border-bottom:1px solid #eee">'.__('Libellé').'</td>
            <td id="fcDelLabel" style="font-weight:bold;padding:5px 6px;border-bottom:1px solid #eee"></td></tr>
        <tr><td style="color:#666;padding:5px 6px">'.__('Montant').'</td>
            <td id="fcDelAmount" style="font-weight:bold;font-size:18px;color:#c0392b;padding:5px 6px"></td></tr>
      </table>
    </div>
    <div style="padding:14px 20px;background:#f8f8f8;display:flex;justify-content:flex-end;gap:10px;">
      <button type="button" id="fcDelCancel"
        style="padding:8px 18px;border:1px solid #ccc;border-radius:4px;background:#fff;cursor:pointer;font-size:14px;">
        <span class="material-icons" style="font-size:15px;vertical-align:middle;margin-right:3px">close</span>'.__('Annuler').'
      </button>
      <form id="fcDelForm" method="post" action="'.htmlspecialchars($deleteUrl).'" style="display:inline">
        <input type="hidden" name="address" value="'.htmlspecialchars($session->get('address')).'">
        <input type="hidden" name="gibbonFinanceMgmtStudentPaymentID" id="fcDelPaymentID" value="">
        <input type="hidden" name="returnTo" value="'.htmlspecialchars($returnUrl).'">
        <button type="submit"
          style="padding:8px 18px;border:none;border-radius:4px;background:#c0392b;color:#fff;cursor:pointer;font-size:14px;font-weight:bold;">
          <span class="material-icons" style="font-size:15px;vertical-align:middle;margin-right:3px">delete</span>'.__('Supprimer').'
        </button>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
    var overlay = document.getElementById("fcDelOverlay");

    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".fc-del-btn");
        if (!btn) return;
        document.getElementById("fcDelDate").textContent   = btn.dataset.date;
        document.getElementById("fcDelLabel").textContent  = btn.dataset.label;
        document.getElementById("fcDelAmount").textContent = btn.dataset.amount;
        document.getElementById("fcDelPaymentID").value    = btn.dataset.id;
        overlay.style.display = "flex";
    });

    document.getElementById("fcDelCancel").addEventListener("click", function () {
        overlay.style.display = "none";
    });

    overlay.addEventListener("click", function (e) {
        if (e.target === overlay) overlay.style.display = "none";
    });
})();
</script>';

