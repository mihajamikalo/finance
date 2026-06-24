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
// Mode de paiement — obligatoire
$row = $form->addRow();
$row->addLabel('paymentMethod', __('Mode de paiement'));
$row->addContent('
<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons"/>
<div id="paymentMethodGroup" style="display:flex; gap:10px; flex-wrap:wrap; margin-top:4px;">
  <label class="fc-method-btn" data-value="BANK">
    <input type="radio" name="paymentMethod" value="BANK" required style="display:none"/>
    <span class="fc-btn-inner">
      <span class="material-icons fc-mi">account_balance</span> '.__('Banque').'
    </span>
  </label>
  <label class="fc-method-btn" data-value="MOBILE">
    <input type="radio" name="paymentMethod" value="MOBILE" style="display:none"/>
    <span class="fc-btn-inner">
      <span class="material-icons fc-mi">smartphone</span> '.__('Mobile Banking').'
    </span>
  </label>
  <label class="fc-method-btn" data-value="CASH">
    <input type="radio" name="paymentMethod" value="CASH" style="display:none"/>
    <span class="fc-btn-inner">
      <span class="material-icons fc-mi">payments</span> '.__('Espèces').'
    </span>
  </label>
</div>
<style>
.fc-mi { font-size:18px; vertical-align:middle; margin-right:3px; }
.fc-method-btn { cursor:pointer; }
.fc-btn-inner {
  display:inline-flex;
  align-items:center;
  gap:4px;
  padding:9px 18px;
  border:2px solid #ccc;
  border-radius:6px;
  font-size:14px;
  font-weight:500;
  background:#f9f9f9;
  transition:all .15s;
  user-select:none;
}
.fc-method-btn:hover .fc-btn-inner { border-color:#1a7abf; background:#e8f3fb; }
.fc-method-btn.selected .fc-btn-inner {
  border-color:#1a7abf;
  background:#1a7abf;
  color:#fff;
}
</style>
<script>
document.querySelectorAll("#paymentMethodGroup .fc-method-btn").forEach(function(lbl){
  lbl.addEventListener("click", function(){
    document.querySelectorAll("#paymentMethodGroup .fc-method-btn").forEach(function(l){ l.classList.remove("selected"); });
    lbl.classList.add("selected");
    lbl.querySelector("input").checked = true;
  });
});
</script>
');

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

// Bouton personnalisé qui déclenche la modale de confirmation
$row = $form->addRow();
$row->addContent('
<button type="button" id="btnConfirmPayment"
    style="background:#1a7abf; color:#fff; border:none; padding:8px 18px; border-radius:4px; font-size:14px; cursor:pointer;">
    '.__('Enregistrer et imprimer le reçu').'
</button>
');

echo $form->getOutput();

// ── Modale de confirmation du montant ────────────────────────────────────────
echo '
<style>
#fcModalOverlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
#fcModalOverlay.fc-open { display: flex; }
#fcModal {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 8px 32px rgba(0,0,0,.25);
    width: 420px;
    max-width: 95vw;
    padding: 0;
    overflow: hidden;
}
#fcModalHeader {
    background: #1a7abf;
    color: #fff;
    padding: 14px 20px;
    font-size: 16px;
    font-weight: bold;
}
#fcModalBody {
    padding: 20px;
}
#fcModalBody table {
    width: 100%;
    border-collapse: collapse;
}
#fcModalBody td {
    padding: 7px 6px;
    border-bottom: 1px solid #eee;
    font-size: 14px;
}
#fcModalBody td:first-child { color: #666; width: 45%; }
#fcModalBody td:last-child { font-weight: bold; }
#fcAmountBig {
    font-size: 26px;
    color: #1a7abf;
    text-align: center;
    margin: 12px 0 4px;
    font-weight: bold;
}
#fcModalFooter {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 14px 20px;
    border-top: 1px solid #eee;
}
#btnFcCancel {
    background: #e0e0e0;
    border: none;
    padding: 8px 20px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
}
#btnFcConfirm {
    background: #27ae60;
    color: #fff;
    border: none;
    padding: 8px 20px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
}
#btnFcConfirm:hover { background: #219a52; }
#btnFcCancel:hover  { background: #ccc; }
</style>

<div id="fcModalOverlay" role="dialog" aria-modal="true" aria-labelledby="fcModalHeader">
    <div id="fcModal">
        <div id="fcModalHeader"><span class="material-icons" style="font-size:20px;vertical-align:middle;margin-right:6px">receipt_long</span>'.__('Confirmation du paiement').'</div>
        <div id="fcModalBody">
            <div id="fcAmountBig"></div>
            <table>
                <tr>
                    <td>'.__('Libellé').'</td>
                    <td id="fcSummaryTitle">—</td>
                </tr>
                <tr>
                    <td>'.__('Élève').'</td>
                    <td id="fcSummaryStudent">—</td>
                </tr>
                <tr>
                    <td>'.__('Date').'</td>
                    <td id="fcSummaryDate">—</td>
                </tr>
                <tr>
                    <td>'.__('Mode de paiement').'</td>
                    <td id="fcSummaryMethod">—</td>
                </tr>
                <tr>
                    <td>'.__('Plan de paiement').'</td>
                    <td id="fcSummaryPlan">—</td>
                </tr>
            </table>
            <p style="margin-top:12px; font-size:12px; color:#e74c3c; font-style:italic">
                '.__('Vérifiez le montant et les détails avant de valider. Cette opération génèrera un reçu.').'
            </p>
        </div>
        <div id="fcModalFooter">
            <button id="btnFcCancel" type="button">'.__('Corriger').'</button>
            <button id="btnFcConfirm" type="button"><span class="material-icons" style="font-size:16px;vertical-align:middle;margin-right:4px">check</span>'.__('Confirmer et enregistrer').'</button>
        </div>
    </div>
</div>

<script>
(function () {
    var planLabels = {
        "":     "—",
        "FULL": "'.__('Paiement intégral (remise 10 %)').'",
        "4":    "'.__('4 mensualités').'",
        "8":    "'.__('8 mensualités').'"
    };
    var methodLabels = {
        "BANK":   "<span class=\"material-icons\" style=\"font-size:16px;vertical-align:middle;margin-right:4px\">account_balance</span>'.__('Banque').'",
        "MOBILE": "<span class=\"material-icons\" style=\"font-size:16px;vertical-align:middle;margin-right:4px\">smartphone</span>'.__('Mobile Banking').'",
        "CASH":   "<span class=\"material-icons\" style=\"font-size:16px;vertical-align:middle;margin-right:4px\">payments</span>'.__('Espèces').'"
    };

    function formatAmount(val) {
        var n = parseFloat(val);
        if (isNaN(n)) return "—";
        return n.toLocaleString("fr-FR", {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function getFinderLabel(fieldId) {
        var el = document.getElementById(fieldId);
        if (!el) return "—";
        // Gibbon Finder stores tokens; try to read the displayed text from chosen tokens
        var tokens = el.closest(".chosen-container") || el.parentNode;
        var chosen = tokens ? tokens.querySelector(".search-choice span, .token-label") : null;
        if (chosen) return chosen.textContent.trim();
        // Fallback: raw value
        return el.value || "—";
    }

    document.getElementById("btnConfirmPayment").addEventListener("click", function () {
        var amount     = document.querySelector("[name=amountPaid]").value;
        var title      = document.querySelector("[name=paymentTitle]").value;
        var dateVal    = document.querySelector("[name=paymentDate]").value;
        var planVal    = document.querySelector("[name=paymentOption]").value;
        var methodEl   = document.querySelector("[name=paymentMethod]:checked");
        var methodVal  = methodEl ? methodEl.value : "";

        if (!amount || parseFloat(amount) <= 0) {
            alert("'.__('Veuillez saisir un montant valide.').'");
            document.querySelector("[name=amountPaid]").focus();
            return;
        }
        if (!title.trim()) {
            alert("'.__('Veuillez saisir un libellé.').'");
            document.querySelector("[name=paymentTitle]").focus();
            return;
        }
        if (!dateVal.trim()) {
            alert("'.__('Veuillez saisir une date.').'");
            return;
        }
        if (!methodVal) {
            alert("'.__('Veuillez sélectionner un mode de paiement.').'");
            return;
        }

        document.getElementById("fcAmountBig").textContent = formatAmount(amount);
        document.getElementById("fcSummaryTitle").textContent   = title || "—";
        document.getElementById("fcSummaryDate").textContent    = dateVal || "—";
        document.getElementById("fcSummaryStudent").textContent = getFinderLabel("gibbonPersonIDStudent");
        document.getElementById("fcSummaryMethod").innerHTML  = methodLabels[methodVal] || methodVal || "—";
        document.getElementById("fcSummaryPlan").textContent    = planLabels[planVal] || planVal || "—";

        document.getElementById("fcModalOverlay").classList.add("fc-open");
    });

    document.getElementById("btnFcCancel").addEventListener("click", function () {
        document.getElementById("fcModalOverlay").classList.remove("fc-open");
    });

    document.getElementById("btnFcConfirm").addEventListener("click", function () {
        document.getElementById("fcModalOverlay").classList.remove("fc-open");
        document.getElementById("financePaymentAdd").submit();
    });

    // Fermer sur clic en dehors de la modale
    document.getElementById("fcModalOverlay").addEventListener("click", function (e) {
        if (e.target === this) {
            this.classList.remove("fc-open");
        }
    });

    // Fermer avec Escape
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            document.getElementById("fcModalOverlay").classList.remove("fc-open");
        }
    });
})();
</script>
';

