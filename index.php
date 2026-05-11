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
use Gibbon\UI\Chart\Chart;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/index.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Finance Dashboard'));
$gibbonSchoolYearID = intval($session->get('gibbonSchoolYearID'));

$dateStartInput = $_GET['dateStart'] ?? ($_POST['dateStart'] ?? '');
$dateEndInput = $_GET['dateEnd'] ?? ($_POST['dateEnd'] ?? '');
$dateStart = !empty($dateStartInput) ? Format::dateConvert($dateStartInput) : date('Y-m-d', strtotime('-30 days'));
$dateEnd = !empty($dateEndInput) ? Format::dateConvert($dateEndInput) : date('Y-m-d');

if ($dateStart > $dateEnd) {
    $swap = $dateStart;
    $dateStart = $dateEnd;
    $dateEnd = $swap;
}

echo '<h2>'.__('Overview').'</h2>';

$form = Form::create('financeDashboardFilters', $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php');
$form->addRow()->addHeading(__('Filters'));

$row = $form->addRow();
    $row->addLabel('dateStart', __('Start Date'));
    $row->addDate('dateStart')->setValue(Format::date($dateStart))->required();

$row = $form->addRow();
    $row->addLabel('dateEnd', __('End Date'));
    $row->addDate('dateEnd')->setValue(Format::date($dateEnd))->required();

$form->addRow()->addSubmit(__('Apply'));
echo $form->getOutput();

// Totals
try {
    $data = [
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
        'dateStart' => $dateStart,
        'dateEnd' => $dateEnd,
    ];

    $sqlTotal = "SELECT COALESCE(SUM(amountPaid),0) AS total
        FROM gibbonFinanceMgmtStudentPayment
        WHERE gibbonSchoolYearID=:gibbonSchoolYearID
            AND paymentDate>=:dateStart
            AND paymentDate<=:dateEnd";
    $stmtTotal = $connection2->prepare($sqlTotal);
    $stmtTotal->execute($data);
    $totalPayments = floatval($stmtTotal->fetchColumn(0) ?: 0);

    $sqlRecent = "SELECT p.gibbonFinanceMgmtStudentPaymentID, p.paymentTitle, p.amountPaid, p.paymentDate, p.receiptNumber,
            per.preferredName, per.surname, per.studentID
        FROM gibbonFinanceMgmtStudentPayment AS p
        JOIN gibbonPerson AS per ON (p.gibbonPersonIDStudent=per.gibbonPersonID)
        WHERE p.gibbonSchoolYearID=:gibbonSchoolYearID
        ORDER BY p.paymentDate DESC, p.gibbonFinanceMgmtStudentPaymentID DESC
        LIMIT 10";
    $stmtRecent = $connection2->prepare($sqlRecent);
    $stmtRecent->execute(['gibbonSchoolYearID' => $gibbonSchoolYearID]);
    $recent = $stmtRecent->fetchAll();

    $sqlByDate = "SELECT paymentDate, COALESCE(SUM(amountPaid),0) AS total
        FROM gibbonFinanceMgmtStudentPayment
        WHERE gibbonSchoolYearID=:gibbonSchoolYearID
            AND paymentDate>=:dateStart
            AND paymentDate<=:dateEnd
        GROUP BY paymentDate
        ORDER BY paymentDate";
    $stmtByDate = $connection2->prepare($sqlByDate);
    $stmtByDate->execute($data);
    $byDate = $stmtByDate->fetchAll();

    $sqlByYearGroup = "SELECT yg.nameShort AS label, COALESCE(SUM(p.amountPaid),0) AS total
        FROM gibbonFinanceMgmtStudentPayment AS p
        JOIN gibbonYearGroup AS yg ON (p.gibbonYearGroupID=yg.gibbonYearGroupID)
        WHERE p.gibbonSchoolYearID=:gibbonSchoolYearID
            AND p.paymentDate>=:dateStart
            AND p.paymentDate<=:dateEnd
        GROUP BY yg.nameShort, yg.sequenceNumber
        ORDER BY yg.sequenceNumber";
    $stmtByYG = $connection2->prepare($sqlByYearGroup);
    $stmtByYG->execute($data);
    $byYG = $stmtByYG->fetchAll();

    // Outstanding (configured fees only)
    $unpaidPage = max(1, intval($_GET['unpaidPage'] ?? 1));
    $unpaidPerPage = 15;
    $unpaidOffset = ($unpaidPage - 1) * $unpaidPerPage;

    $sqlOutstandingCount = "SELECT COUNT(*)
        FROM gibbonStudentEnrolment AS se
        JOIN gibbonPerson AS per ON (se.gibbonPersonID=per.gibbonPersonID AND per.status='Full')
        JOIN gibbonFinanceMgmtTuitionFee AS tf ON (tf.gibbonSchoolYearID=se.gibbonSchoolYearID AND tf.gibbonYearGroupID=se.gibbonYearGroupID AND tf.active='Y')
        LEFT JOIN (
            SELECT gibbonPersonIDStudent, gibbonSchoolYearID, SUM(amountPaid) AS totalPaid
            FROM gibbonFinanceMgmtStudentPayment
            GROUP BY gibbonPersonIDStudent, gibbonSchoolYearID
        ) AS paid ON (paid.gibbonPersonIDStudent=se.gibbonPersonID AND paid.gibbonSchoolYearID=se.gibbonSchoolYearID)
        WHERE se.gibbonSchoolYearID=:gibbonSchoolYearID
            AND (tf.amount - COALESCE(paid.totalPaid, 0)) > 0.01";
    $stmtOutCount = $connection2->prepare($sqlOutstandingCount);
    $stmtOutCount->execute(['gibbonSchoolYearID' => $gibbonSchoolYearID]);
    $outstandingCount = intval($stmtOutCount->fetchColumn(0) ?: 0);
    $outstandingPages = max(1, intval(ceil($outstandingCount / $unpaidPerPage)));
    $unpaidPage = min($unpaidPage, $outstandingPages);
    $unpaidOffset = ($unpaidPage - 1) * $unpaidPerPage;

    $sqlOutstanding = "SELECT per.gibbonPersonID, per.surname, per.preferredName, per.studentID, yg.nameShort AS yearGroup,
            tf.amount AS tuitionFee,
            COALESCE(paid.totalPaid, 0) AS totalPaid,
            (tf.amount - COALESCE(paid.totalPaid, 0)) AS balance
        FROM gibbonStudentEnrolment AS se
        JOIN gibbonPerson AS per ON (se.gibbonPersonID=per.gibbonPersonID AND per.status='Full')
        JOIN gibbonYearGroup AS yg ON (se.gibbonYearGroupID=yg.gibbonYearGroupID)
        JOIN gibbonFinanceMgmtTuitionFee AS tf ON (tf.gibbonSchoolYearID=se.gibbonSchoolYearID AND tf.gibbonYearGroupID=se.gibbonYearGroupID AND tf.active='Y')
        LEFT JOIN (
            SELECT gibbonPersonIDStudent, gibbonSchoolYearID, SUM(amountPaid) AS totalPaid
            FROM gibbonFinanceMgmtStudentPayment
            GROUP BY gibbonPersonIDStudent, gibbonSchoolYearID
        ) AS paid ON (paid.gibbonPersonIDStudent=se.gibbonPersonID AND paid.gibbonSchoolYearID=se.gibbonSchoolYearID)
        WHERE se.gibbonSchoolYearID=:gibbonSchoolYearID
            AND (tf.amount - COALESCE(paid.totalPaid, 0)) > 0.01
        ORDER BY balance DESC
        LIMIT :limit OFFSET :offset";
    $stmtOut = $connection2->prepare($sqlOutstanding);
    $stmtOut->bindValue(':gibbonSchoolYearID', $gibbonSchoolYearID, PDO::PARAM_INT);
    $stmtOut->bindValue(':limit', $unpaidPerPage, PDO::PARAM_INT);
    $stmtOut->bindValue(':offset', $unpaidOffset, PDO::PARAM_INT);
    $stmtOut->execute();
    $outstanding = $stmtOut->fetchAll();
} catch (PDOException $e) {
    $page->addError(__('A database error occurred.'));
    return;
}

echo "<div class='flex flex-wrap gap-4 my-4'>";
echo "<div class='w-full md:w-1/3 bg-white border rounded p-4'>";
echo "<div class='text-sm text-gray-600'>".__('Total Payments Received')."</div>";
echo "<div class='text-2xl font-bold'>".number_format($totalPayments, 2, '.', ',')."</div>";
echo "<div class='text-xs text-gray-500 mt-1'>".sprintf(__('From %1$s to %2$s'), Format::date($dateStart), Format::date($dateEnd))."</div>";
echo "</div>";
echo "</div>";

// Charts
$page->scripts->add('chart');

$labelsDate = array_map(fn($r) => Format::date($r['paymentDate']), $byDate);
$dataDate = array_map(fn($r) => floatval($r['total']), $byDate);
$chartDate = Chart::create('paymentsByDate', 'line')
    ->setLabels($labelsDate)
    ->setLegend(['display' => false])
    ->setOptions([
        'responsive' => true,
        'maintainAspectRatio' => false,
        'height' => '220px',
    ]);
$chartDate->addDataset('payments')->setData($dataDate);

$labelsYG = array_map(fn($r) => $r['label'], $byYG);
$dataYG = array_map(fn($r) => floatval($r['total']), $byYG);
$chartYG = Chart::create('paymentsByYearGroup', 'bar')
    ->setLabels($labelsYG)
    ->setLegend(['display' => false])
    ->setOptions([
        'responsive' => true,
        'maintainAspectRatio' => false,
        'height' => '220px',
    ])
    ->setColorOpacity(0.7);
$chartYG->addDataset('payments')->setData($dataYG);

echo '<div class="flex flex-wrap gap-6 mt-6">';
echo '<div class="w-full md:w-1/2 bg-white border rounded p-4">';
echo '<h3 class="mb-2">'.__('Payments by Date').'</h3>';
echo '<div style="height: 240px">'.$chartDate->render().'</div>';
echo '</div>';
echo '<div class="w-full md:w-1/2 bg-white border rounded p-4">';
echo '<h3 class="mb-2">'.__('Payments by Year Group').'</h3>';
echo '<div style="height: 240px">'.$chartYG->render().'</div>';
echo '</div>';
echo '</div>';

echo '<div class="flex flex-wrap gap-6 mt-6">';
echo '<div class="w-full md:w-1/2 bg-white border rounded p-4">';
echo '<h3 class="mb-3">'.__('Recent Transactions').'</h3>';
if (empty($recent)) {
    echo "<div class='text-sm text-gray-600'>".__('There are no records to display.')."</div>";
} else {
    echo "<table class='smallIntBorder' cellspacing='0' style='width:100%'>";
    echo "<tr class='head'><th>".__('Date')."</th><th>".__('Student')."</th><th>".__('Title')."</th><th style='text-align:right'>".__('Amount')."</th></tr>";
    foreach ($recent as $r) {
        $student = Format::name('', $r['preferredName'], $r['surname'], 'Student', true).' <span class="text-xs text-gray-500">('.htmlPrep($r['studentID']).')</span>';
        echo "<tr>";
        echo "<td>".Format::date($r['paymentDate'])."</td>";
        echo "<td>".$student."</td>";
        echo "<td>".htmlPrep($r['paymentTitle'])."</td>";
        echo "<td style='text-align:right'>".number_format($r['amountPaid'], 2, '.', ',')."</td>";
        echo "</tr>";
    }
    echo "</table>";

    if ($outstandingPages > 1) {
        $baseLink = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php'
            .'&dateStart='.urlencode(Format::date($dateStart))
            .'&dateEnd='.urlencode(Format::date($dateEnd));

        echo "<div style='margin-top: 10px; text-align: right'>";
        if ($unpaidPage > 1) {
            echo "<a href='".htmlPrep($baseLink.'&unpaidPage='.($unpaidPage - 1))."'>".__('Previous')."</a> ";
        }
        echo "<span style='margin: 0 8px'>".sprintf(__('Page %1$s of %2$s'), strval($unpaidPage), strval($outstandingPages))."</span>";
        if ($unpaidPage < $outstandingPages) {
            echo " <a href='".htmlPrep($baseLink.'&unpaidPage='.($unpaidPage + 1))."'>".__('Next')."</a>";
        }
        echo "</div>";
    }
}
echo '</div>';

echo '<div class="w-full md:w-1/2 bg-white border rounded p-4">';
echo '<h3 class="mb-3">'.__('Students With Unpaid Balances').'</h3>';
if (empty($outstanding)) {
    echo "<div class='text-sm text-gray-600'>".__('There are no records to display.')."</div>";
} else {
    echo "<table class='smallIntBorder' cellspacing='0' style='width:100%'>";
    echo "<tr class='head'><th>".__('Student')."</th><th>".__('Year Group')."</th><th style='text-align:right'>".__('Balance')."</th></tr>";
    foreach ($outstanding as $o) {
        $studentLabel = Format::name('', $o['preferredName'], $o['surname'], 'Student', true).' <span class="text-xs text-gray-500">('.htmlPrep($o['studentID']).')</span>';
        $balance = number_format($o['balance'], 2, '.', ',');
        $link = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/student_history.php&gibbonPersonIDStudent='.$o['gibbonPersonID'];
        echo "<tr>";
        echo "<td><a href='".htmlPrep($link)."'>".$studentLabel."</a></td>";
        echo "<td>".htmlPrep($o['yearGroup'])."</td>";
        echo "<td style='text-align:right'><b>".$balance."</b></td>";
        echo "</tr>";
    }
    echo "</table>";
}
echo '</div>';
echo '</div>';

