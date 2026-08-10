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

// Bouton personnalisé qui déclenche la modale de confirmation
$row = $form->addRow();
$row->addContent('
<button type="button" id="btnConfirmPayment"
    style="background:#1a7abf; color:#fff; border:none; padding:8px 18px; border-radius:4px; font-size:14px; cursor:pointer;">
    '.__('Enregistrer et imprimer le reçu').'
</button>
');

// ── Formulaire Gibbon (sans les champs dynamiques du plan) ──────────────────
echo $form->getOutput();

// ── Section plan de paiement en HTML natif (IDs stables, sans regex PHP) ───
$depositFmt = number_format(financeMgmtGetConfiguredInitialDeposit(), 2, '.', ',');
echo '
<div id="fcPlanSection" style="display:none;margin:-4px 0 0 0;border-top:1px solid #e0e0e0;">
  <table style="width:100%;border-collapse:collapse;">

    <!-- Ligne : sélecteur de plan -->
    <tr>
      <td style="padding:10px 10px 10px 0;vertical-align:top;width:30%;font-weight:bold;font-size:13px;">
        '.__('Plan de paiement').'
        <div style="font-weight:normal;font-size:11px;color:#888;margin-top:3px;">'.__('Obligatoire lors du premier paiement. Ignoré pour les paiements suivants.').'</div>
      </td>
      <td style="padding:10px 0;vertical-align:middle;">
        <select name="paymentOption" id="fcPaymentOption"
                form="financePaymentAdd"
                style="padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;min-width:260px;">
          <option value="">— '.__('choisir un plan').' —</option>
          <option value="FULL">'.__('Paiement intégral avec remise de 10 %').'</option>
          <option value="4">'.__('4 mensualités').'</option>
          <option value="8">'.__('8 mensualités').'</option>
          <option value="CUSTOM">'.__('Plan de paiement libre').'</option>
        </select>
      </td>
    </tr>

    <!-- Ligne : date du 1er versement (4 ou 8 mensualités) -->
    <tr id="fcFirstInstRow" style="display:none;">
      <td style="padding:8px 10px 8px 0;vertical-align:top;font-weight:bold;font-size:13px;">
        '.__('Date du 1er versement mensuel').'
        <div style="font-weight:normal;font-size:11px;color:#888;margin-top:3px;">'.__('Jour du mois choisi pour la 1re mensualité. Les suivantes tombent le même jour.').'</div>
      </td>
      <td style="padding:8px 0;vertical-align:middle;">
        <input type="date" name="firstInstallmentDate" id="firstInstallmentDate"
               form="financePaymentAdd"
               style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;">
      </td>
    </tr>

    <!-- Ligne : plan de paiement libre -->
    <tr id="fcCustomRow" style="display:none;">
      <td colspan="2" style="padding:8px 0 4px 0;">
        <div style="background:#f0f7ff;border:1px solid #aac8ea;border-radius:8px;padding:14px 16px;">
          <div style="font-weight:bold;color:#1a5276;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
            <span class="material-icons" style="font-size:18px;vertical-align:middle">event_note</span>
            '.__('Plan de paiement libre').'
          </div>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
            <label style="font-size:13px;color:#555;min-width:220px">'.__('Nombre de versements après acompte').' :</label>
            <input type="number" id="fcCustomCount" min="1" max="36" value="2"
                   style="width:70px;padding:5px 8px;border:1px solid #aac8ea;border-radius:4px;font-size:14px;">
          </div>
          <div id="fcCustomRows" style="display:flex;flex-direction:column;gap:6px;"></div>
          <div style="margin-top:10px;padding-top:10px;border-top:1px solid #cce0f5;font-size:13px;color:#444;">
            '.__('Total versements libres').' :
            <strong id="fcCustomTotal" style="color:#1a5276">0,00</strong>
            &nbsp;&mdash;&nbsp;
            '.__('Acompte').' : <strong style="color:#1e8449">'.$depositFmt.'</strong>
          </div>
        </div>
      </td>
    </tr>

  </table>
</div>';

// ── Logique AJAX + events plan de paiement ────────────────────────────────────
$checkPlanUrl = $session->get('absoluteURL').'/modules/FinanceCustom/ajax_checkStudentPlan.php';
echo '
<script>
(function () {
    var CHECK_URL = '.json_encode($checkPlanUrl).';

    /* Références aux éléments (IDs stables définis dans notre HTML natif) */
    var planSection  = document.getElementById("fcPlanSection");   // div conteneur du plan
    var planSel      = document.getElementById("fcPaymentOption"); // <select>
    var firstInstRow = document.getElementById("fcFirstInstRow");  // <tr> date 1er versement
    var customRow    = document.getElementById("fcCustomRow");     // <tr> plan libre

    /* Badge de statut : inséré juste après la ligne Élève */
    var finderInput = document.getElementById("gibbonPersonIDStudent");
    var finderRow   = finderInput ? finderInput.closest("tr") : null;
    if (finderRow && finderRow.parentNode) {
        var statusTr = document.createElement("tr");
        statusTr.innerHTML = "<td colspan=\"2\" style=\"padding:2px 0 4px 0;border:none\">"
            + "<div id=\"fcPlanStatus\"></div></td>";
        finderRow.parentNode.insertBefore(statusTr, finderRow.nextSibling);
    }

    /* ── Gestion des sous-champs en fonction du plan choisi ─────────────── */
    function onPlanChange() {
        var val = planSel ? planSel.value : "";

        /* Date du 1er versement : seulement pour 4 ou 8 mensualités */
        if (firstInstRow) {
            firstInstRow.style.display = (val === "4" || val === "8") ? "table-row" : "none";
            if (val !== "4" && val !== "8") {
                var df = document.getElementById("firstInstallmentDate");
                if (df) df.value = "";
            }
        }

        /* Section plan libre */
        if (customRow) {
            if (val === "CUSTOM") {
                customRow.style.display = "table-row";
                buildCustomRows();
            } else {
                customRow.style.display = "none";
                /* Désactiver le required sur les inputs cachés pour ne pas bloquer le submit */
                customRow.querySelectorAll("input[required]").forEach(function(i){ i.required = false; });
            }
        }
    }

    /* ── Construction dynamique des lignes du plan libre ─────────────────── */
    function buildCustomRows() {
        var countInput = document.getElementById("fcCustomCount");
        var count = Math.max(1, Math.min(36, parseInt(countInput ? countInput.value : 2) || 2));

        var container = document.getElementById("fcCustomRows");
        if (!container) return;

        var savedDates = [], savedAmounts = [];
        container.querySelectorAll(".fc-cust-row").forEach(function (r, i) {
            var d = r.querySelector(".fc-cust-date"),  a = r.querySelector(".fc-cust-amount");
            savedDates[i] = d ? d.value : "";  savedAmounts[i] = a ? a.value : "";
        });

        container.innerHTML = "";
        for (var i = 0; i < count; i++) {
            var div = document.createElement("div");
            div.className = "fc-cust-row";
            div.style.cssText = "display:flex;align-items:center;flex-wrap:wrap;gap:8px;"
                + "padding:8px 10px;background:#fff;border:1px solid #cce0f5;border-radius:6px;";
            div.innerHTML =
                "<span style=\"min-width:90px;font-weight:bold;font-size:13px;color:#1a5276\">Versement " + (i + 1) + "</span>"
                + "<label style=\"font-size:12px;color:#666\">Date :</label>"
                + "<input type=\"date\" name=\"customDates[]\" class=\"fc-cust-date\" form=\"financePaymentAdd\""
                + " value=\"" + (savedDates[i] || "") + "\""
                + " style=\"border:1px solid #aac8ea;border-radius:4px;padding:4px 8px;font-size:13px;\">"
                + "<label style=\"font-size:12px;color:#666;margin-left:6px\">Montant attendu :</label>"
                + "<input type=\"number\" name=\"customAmounts[]\" class=\"fc-cust-amount\" form=\"financePaymentAdd\""
                + " value=\"" + (savedAmounts[i] || "") + "\""
                + " min=\"0.01\" step=\"0.01\""
                + " style=\"width:160px;border:1px solid #aac8ea;border-radius:4px;padding:4px 8px;font-size:13px;\">";
            container.appendChild(div);
        }
        container.addEventListener("input", updateCustomTotal);
        updateCustomTotal();
    }

    function updateCustomTotal() {
        var total = 0;
        document.querySelectorAll(".fc-cust-amount").forEach(function (inp) {
            total += parseFloat(inp.value) || 0;
        });
        var el = document.getElementById("fcCustomTotal");
        if (el) el.textContent = total.toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /* ── Event listeners sur le select du plan ─────────────────────────── */
    if (planSel) {
        planSel.addEventListener("change", onPlanChange);
        planSel.addEventListener("input",  onPlanChange);
    }
    var customCountInput = document.getElementById("fcCustomCount");
    if (customCountInput) {
        customCountInput.addEventListener("change", buildCustomRows);
        customCountInput.addEventListener("input",  buildCustomRows);
    }

    /* ── Afficher / masquer la section plan ─────────────────────────────── */
    function hidePlanSection() {
        if (planSection) planSection.style.display = "none";
        if (firstInstRow) firstInstRow.style.display = "none";
        if (customRow)    customRow.style.display    = "none";
        if (planSel) { planSel.required = false; planSel.value = ""; }
        onPlanChange(); /* nettoie les sous-sections */
    }

    function showPlanSection() {
        if (planSection) planSection.style.display = "block";
        if (planSel) planSel.required = true;
        onPlanChange(); /* applique l\'état selon le plan déjà sélectionné */
    }

    /* ── Vérification AJAX du plan de l\'élève sélectionné ─────────────── */
    function checkPlan(studentId) {
        var badge = document.getElementById("fcPlanStatus");
        if (!studentId) {
            if (badge) badge.innerHTML = "";
            hidePlanSection();
            return;
        }
        if (badge) badge.innerHTML = "<span style=\"color:#888;font-size:12px\">'.__('Vérification en cours...').'</span>";

        var xhr = new XMLHttpRequest();
        xhr.open("GET", CHECK_URL + "?gibbonPersonIDStudent=" + encodeURIComponent(studentId), true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            var badge = document.getElementById("fcPlanStatus");
            if (xhr.status !== 200) { if (badge) badge.innerHTML = ""; return; }
            var data;
            try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
            if (data.error) { if (badge) badge.innerHTML = ""; return; }

            if (data.hasExistingPlan) {
                hidePlanSection();
                if (badge) badge.innerHTML =
                    "<span style=\"display:inline-flex;align-items:center;gap:5px;"
                    + "background:#d5f5e3;color:#1e8449;padding:4px 10px;"
                    + "border-radius:12px;font-size:12px;font-weight:bold\">"
                    + "<span class=\"material-icons\" style=\"font-size:14px\">check_circle</span>"
                    + "'.__('Plan actif').': " + (data.planLabel || data.planType)
                    + " &mdash; '.__('paiement').' \u0023" + (data.paymentCount + 1) + "</span>";
            } else {
                showPlanSection();
                if (badge) badge.innerHTML =
                    "<span style=\"display:inline-flex;align-items:center;gap:5px;"
                    + "background:#fef9e7;color:#b7950b;padding:4px 10px;"
                    + "border-radius:12px;font-size:12px;font-weight:bold\">"
                    + "<span class=\"material-icons\" style=\"font-size:14px\">info</span>"
                    + "'.__('Premier paiement — sélectionnez un plan ci-dessous').'</span>";
            }
        };
        xhr.send();
    }

    /* ── Détection de l\'élève sélectionné (Finder Gibbon) ──────────────── */
    var finderHidden = document.getElementById("gibbonPersonIDStudent");
    var lastVal = "";

    function onFinderChange() {
        var raw = finderHidden ? finderHidden.value.trim() : "";
        var id  = raw ? parseInt(raw.split(",")[0], 10) : 0;
        var key = String(id);
        if (key === lastVal) return;
        lastVal = key;
        checkPlan(id > 0 ? id : 0);
    }

    if (finderHidden) {
        finderHidden.addEventListener("change", onFinderChange);
        var observer = new MutationObserver(onFinderChange);
        observer.observe(finderHidden, { attributes: true, attributeFilter: ["value"] });
        setInterval(onFinderChange, 500);
    }
})();
</script>
';

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

        // Vérifier si la ligne Plan est visible
        var planRowEl   = document.getElementById("fcPlanTr");
        var planVisible = planRowEl && planRowEl.style.display !== "none";

        document.getElementById("fcAmountBig").textContent = formatAmount(amount);
        document.getElementById("fcSummaryTitle").textContent   = title || "—";
        document.getElementById("fcSummaryDate").textContent    = dateVal || "—";
        document.getElementById("fcSummaryStudent").textContent = getFinderLabel("gibbonPersonIDStudent");
        document.getElementById("fcSummaryMethod").innerHTML  = methodLabels[methodVal] || methodVal || "—";

        // Ligne plan dans la modale : afficher "—" si plan déjà existant
        var planSummaryRow = document.getElementById("fcSummaryPlan")
            ? document.getElementById("fcSummaryPlan").closest("tr") : null;
        if (planSummaryRow) planSummaryRow.style.display = planVisible ? "" : "none";
        document.getElementById("fcSummaryPlan").textContent = planVisible
            ? (planLabels[planVal] || planVal || "—")
            : "—";

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

