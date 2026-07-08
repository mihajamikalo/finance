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
        ''     => __('— choisir un plan —'),
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

// Capturer le HTML du formulaire, injecter id="fcPlanTr" dans le TR du plan.
ob_start();
echo $form->getOutput();
$formHtml = ob_get_clean();

// Approche 1 : chercher name="paymentOption" (plus fiable que id=)
// et injecter id="fcPlanTr" sur le <tr> le plus proche.
$formHtml = preg_replace_callback(
    '/<tr(\b[^>]*)>((?:(?!<\/tr>)[\s\S])*?name=["\']paymentOption["\'](?:(?!<\/tr>)[\s\S])*?)<\/tr>/i',
    function ($m) {
        // Ne pas ajouter si l'id existe déjà
        if (strpos($m[1], 'id=') === false) {
            return '<tr id="fcPlanTr" style="display:none"' . $m[1] . '>' . $m[2] . '</tr>';
        }
        return str_replace('<tr' . $m[1] . '>', '<tr' . $m[1] . ' style="display:none">', $m[0]);
    },
    $formHtml
);

echo $formHtml;

// Approche 2 (CSS + JS synchrone) : filet de sécurité si le regex n'a pas matché.
// Le JS remonte depuis #paymentOption jusqu'au TR parent (max 5 niveaux).
echo '
<style>
/* Fallback CSS : si fcPlanTr existe, il est déjà caché via style inline.
   Cette règle est un double filet de sécurité. */
#fcPlanTr { display: none !important; }
</style>
<script>
(function () {
    // Si le regex PHP a réussi, #fcPlanTr existe déjà avec style="display:none".
    // Sinon, on le trouve dynamiquement et on l\'identifie.
    if (!document.getElementById("fcPlanTr")) {
        var sel = document.getElementById("paymentOption");
        if (sel) {
            var node = sel.parentNode;
            for (var i = 0; i < 5; i++) {
                if (!node || node === document.body) break;
                if (node.tagName && node.tagName.toUpperCase() === "TR") {
                    node.id = "fcPlanTr";
                    node.style.display = "none";
                    break;
                }
                node = node.parentNode;
            }
        }
    }
})();
</script>';

// ── Logique AJAX : afficher/masquer le plan selon le statut de l'élève ────────
$checkPlanUrl = $session->get('absoluteURL').'/modules/FinanceCustom/ajax_checkStudentPlan.php';
echo '
<script>
(function () {
    var CHECK_URL = '.json_encode($checkPlanUrl).';

    /* Le TR du plan a été identifié côté PHP et reçu avec style="display:none" */
    var planRow = document.getElementById("fcPlanTr");

    /* Insérer un div de statut après la ligne Élève */
    var finderInput = document.getElementById("gibbonPersonIDStudent");
    var finderRow   = finderInput ? finderInput.closest("tr") : null;
    if (finderRow && finderRow.parentNode) {
        var statusTr = document.createElement("tr");
        statusTr.innerHTML = "<td colspan=\"2\" style=\"padding:2px 0 4px 0;border:none\">"
            + "<div id=\"fcPlanStatus\"></div>"
            + "</td>";
        finderRow.parentNode.insertBefore(statusTr, finderRow.nextSibling);
    }

    function hidePlanRow() {
        if (!planRow) return;
        planRow.style.display = "none";
        var sel = document.getElementById("paymentOption");
        if (sel) { sel.required = false; sel.value = ""; }
    }

    function showPlanRow() {
        if (!planRow) return;
        planRow.style.display = "";
        var sel = document.getElementById("paymentOption");
        if (sel) sel.required = true;
    }

    // ── Fonction principale : vérifier le plan via AJAX ───────────────────
    function checkPlan(studentId) {
        var badge = document.getElementById("fcPlanStatus");

        if (!studentId) {
            if (badge) badge.innerHTML = "";
            hidePlanRow();
            return;
        }

        if (badge) {
            badge.innerHTML = "<span style=\"color:#888;font-size:12px\">'.__('Vérification en cours...').'</span>";
        }

        var xhr = new XMLHttpRequest();
        xhr.open("GET", CHECK_URL + "?gibbonPersonIDStudent=" + encodeURIComponent(studentId), true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            var badge = document.getElementById("fcPlanStatus");

            if (xhr.status !== 200) {
                if (badge) badge.innerHTML = "";
                return;
            }

            var data;
            try { data = JSON.parse(xhr.responseText); } catch (e) { return; }

            if (data.error) {
                if (badge) badge.innerHTML = "";
                return;
            }

            if (data.hasExistingPlan) {
                hidePlanRow();
                if (badge) {
                    badge.innerHTML =
                        "<span style=\"display:inline-flex;align-items:center;gap:5px;"
                        + "background:#d5f5e3;color:#1e8449;padding:4px 10px;"
                        + "border-radius:12px;font-size:12px;font-weight:bold\">"
                        + "<span class=\"material-icons\" style=\"font-size:14px\">check_circle</span>"
                        + "'.__('Plan actif').': " + (data.planLabel || data.planType)
                        + " &mdash; '.__('paiement').' \u0023" + (data.paymentCount + 1)
                        + "</span>";
                }
            } else {
                showPlanRow();
                if (badge) {
                    badge.innerHTML =
                        "<span style=\"display:inline-flex;align-items:center;gap:5px;"
                        + "background:#fef9e7;color:#b7950b;padding:4px 10px;"
                        + "border-radius:12px;font-size:12px;font-weight:bold\">"
                        + "<span class=\"material-icons\" style=\"font-size:14px\">info</span>"
                        + "'.__('Premier paiement — sélectionnez un plan ci-dessous').'</span>";
                }
            }
        };
        xhr.send();
    }

    // ── Détecter la sélection dans le Finder Gibbon ───────────────────────
    // Le Finder (tokenInput/Chosen) met à jour un <input hidden> ou <select hidden>.
    // On surveille via MutationObserver + event "change" + polling.

    var finderHidden = document.getElementById("gibbonPersonIDStudent");
    var lastVal      = "";

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

        // MutationObserver pour les cas où la valeur change sans déclencher "change"
        var observer = new MutationObserver(onFinderChange);
        observer.observe(finderHidden, { attributes: true, attributeFilter: ["value"] });

        // Polling léger (500 ms) en complément car tokenInput ne déclenche pas toujours "change"
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

