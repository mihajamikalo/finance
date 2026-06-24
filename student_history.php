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
use Gibbon\Tables\DataTable;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Module\FinanceCustom\Domain\StudentPaymentGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/student_history.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Student Payment History'));

$gibbonSchoolYearID = intval($session->get('gibbonSchoolYearID'));
$gibbonPersonIDStudent = intval($_GET['gibbonPersonIDStudent'] ?? ($_POST['gibbonPersonIDStudent'] ?? 0));

echo '<h2>'.__('Student Payment History').'</h2>';

$ajaxUrl = $session->get('absoluteURL').'/modules/FinanceCustom/ajax_studentSearch.php';
$form = Form::create('studentHistory', $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/student_history.php');

$row = $form->addRow();
    $row->addLabel('gibbonPersonIDStudent', __('Student'));
    $row->addFinder('gibbonPersonIDStudent')->fromAjax($ajaxUrl)->setParameter('tokenLimit', 1)->setParameter('minChars', 2)->required();

$form->addRow()->addSubmit(__('View'));
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
    $page->addError(__('The specified record cannot be found.'));
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
    . "<th>".__('Total Tuition Fee')."</th>"
    . "<th>".__('Total Paid')."</th>"
    . "<th>".__('Remaining Balance')."</th>"
    . "<th>".__('Status')."</th>"
    . "</tr>";
echo "<tr>";
echo "<td>".financeMgmtFormatMoney($totals['totalFee'])."</td>";
echo "<td>".financeMgmtFormatMoney($totals['totalPaid'])."</td>";
echo "<td>".financeMgmtFormatMoney($totals['balance'])."</td>";
echo "<td><b>".__($totals['status'])."</b></td>";
echo "</tr>";
echo "</table>";

// ── Payment plan details ──────────────────────────────────────────────────────
if ($plan !== null) {
    echo '<h2>'.__('Payment Plan').'</h2>';

    $planTypeLabels = [
        'FULL'          => __('Full payment (10 % discount)'),
        'INSTALLMENT_4' => __('4 monthly instalments'),
        'INSTALLMENT_8' => __('8 monthly instalments'),
        'LEGACY'        => __('Legacy (no plan)'),
    ];
    $planLabel = $planTypeLabels[$plan['planType']] ?? htmlPrep($plan['planType']);

    echo "<table class='smallIntBorder' cellspacing='0' style='width: 100%'>";
    echo "<tr class='head'>"
        . "<th>".__('Plan Type')."</th>"
        . "<th style='text-align:right'>".__('Original Fee')."</th>"
        . "<th style='text-align:right'>".__('Discount (10 %)')."</th>"
        . "<th style='text-align:right'>".__('Final Fee')."</th>"
        . "<th style='text-align:right'>".__('Initial Deposit')."</th>"
        . "<th style='text-align:right'>".__('Monthly Instalment')."</th>"
        . "</tr>";
    echo "<tr>";
    echo "<td>".$planLabel."</td>";
    echo "<td style='text-align:right'>".financeMgmtFormatMoney(floatval($plan['tuitionFeeOriginal']))."</td>";
    echo "<td style='text-align:right'>".financeMgmtFormatMoney(floatval($plan['discountAmount']))."</td>";
    echo "<td style='text-align:right'><b>".financeMgmtFormatMoney(floatval($plan['tuitionFeeFinal']))."</b></td>";
    echo "<td style='text-align:right'>".financeMgmtFormatMoney(floatval($plan['requiredDeposit']))."</td>";
    echo "<td style='text-align:right'>"
        . (floatval($plan['installmentAmount']) > 0 ? financeMgmtFormatMoney(floatval($plan['installmentAmount'])) : __('N/A'))
        . "</td>";
    echo "</tr>";
    echo "</table>";

    // ── Instalment schedule with carry-forward credit ─────────────────────────
    if ($evaluation !== null && !empty($evaluation['installments'])) {
        echo '<h3>'.__('Instalment Schedule').'</h3>';
        echo '<p class="text-sm text-gray-600">'
            . __('Credit carried forward: any surplus from a payment is automatically deducted from the next instalment.')
            . '</p>';

        echo "<table class='smallIntBorder' cellspacing='0' style='width: 100%'>";
        echo "<tr class='head'>"
            . "<th>#</th>"
            . "<th>".__('Label')."</th>"
            . "<th>".__('Due Date')."</th>"
            . "<th style='text-align:right'>".__('Expected')."</th>"
            . "<th style='text-align:right'>".__('Credit Carried')."</th>"
            . "<th style='text-align:right'>".__('Payable')."</th>"
            . "<th style='text-align:right'>".__('Credit After')."</th>"
            . "<th style='text-align:right'>".__('Outstanding')."</th>"
            . "<th>".__('Status')."</th>"
            . "</tr>";

        foreach ($evaluation['installments'] as $item) {
            $isLate = ($item['isLate'] === 'Y');
            $isPaid = ($item['outstandingAmount'] <= 0.009);
            $isFuture = ($item['isDue'] === 'N');

            if ($isLate) {
                $statusLabel = __('Late');
                $statusColor = '#e74c3c';
            } elseif ($isPaid) {
                $statusLabel = __('Paid');
                $statusColor = '#27ae60';
            } elseif ($isFuture) {
                $statusLabel = __('Upcoming');
                $statusColor = '#95a5a6';
            } else {
                $statusLabel = __('Due');
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

echo '<h2>'.__('Payments').'</h2>';

$table = DataTable::create('studentPayments');
$table->addColumn('paymentDate', __('Date'))->format(function ($row) {
    return Format::date($row['paymentDate']);
});
$table->addColumn('paymentTitle', __('Title'))->format(function ($row) {
    return htmlPrep($row['paymentTitle']);
});
$table->addColumn('amountPaid', __('Amount'))->format(function ($row) {
    return "<div style='text-align:right'>".number_format($row['amountPaid'], 2, '.', ',')."</div>";
});
$table->addColumn('remaining', __('Remaining'))->format(function ($row) use ($running) {
    $id = intval($row['gibbonFinanceMgmtStudentPaymentID']);
    return "<div style='text-align:right'>".(isset($running[$id]) ? number_format($running[$id], 2, '.', ',') : __('N/A'))."</div>";
});
$table->addColumn('receiptPrinted', __('Receipt'))->format(function ($row) use ($session) {
    if ($row['receiptPrinted'] === 'Y') {
        $receiptNumber = htmlPrep($row['receiptNumber'] ?? '');
        $link = $session->get('absoluteURL').'/modules/FinanceCustom/receipt_print.php?gibbonFinanceMgmtStudentPaymentID='.$row['gibbonFinanceMgmtStudentPaymentID'];
        return "<a href='".htmlPrep($link)."'>".__('Reprint')."</a><br/><span style='font-size: 85%; font-style: italic'>{$receiptNumber}</span>";
    }
    return __('Not Generated');
});
$table->addColumn('createdBy', __('Recorded By'))->format(function ($row) {
    return Format::name('', $row['createdByPreferredName'] ?? '', $row['createdBySurname'] ?? '', 'Staff', false, true);
});

echo $table->render($rows);

