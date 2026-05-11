<?php
/*
Gibbon: the flexible, open school platform
*/

use Gibbon\Services\Format;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/admin_console.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

if (!financeMgmtHasAdminCodeSessionAccess($session)) {
    header('Location: '.$session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_access.php');
    exit;
}

$page->breadcrumbs->add(__('Finance Hidden Admin'));

echo '<h2>'.__('Finance Hidden Admin Console').'</h2>';
echo "<div class='warning'>".__('High-risk operations are available below. Access expires after a short period.')."</div>";

$returnTo = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_console.php';

echo '<h3>'.__('Danger Zone').'</h3>';
echo "<form method='post' action='".$session->get('absoluteURL')."/modules/FinanceCustom/admin_deleteAllProcess.php' onsubmit=\"return confirm('".__('This will permanently delete all finance records. Continue?')."');\">";
echo "<input type='hidden' name='address' value='".htmlPrep($session->get('address'))."'/>";
echo "<input type='hidden' name='returnTo' value='".htmlPrep($returnTo)."'/>";
echo "<input type='submit' value='".__('Delete All Finance Data')."' style='background:#c0392b; color:#fff'/>";
echo "</form>";

try {
    $sql = "SELECT p.gibbonFinanceMgmtStudentPaymentID, p.paymentTitle, p.amountPaid, p.paymentDate,
            per.preferredName, per.surname, per.studentID
        FROM gibbonFinanceMgmtStudentPayment AS p
        JOIN gibbonPerson AS per ON (p.gibbonPersonIDStudent=per.gibbonPersonID)
        ORDER BY p.paymentDate DESC, p.gibbonFinanceMgmtStudentPaymentID DESC
        LIMIT 30";
    $stmt = $connection2->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    $rows = [];
}

echo '<h3>'.__('Recent Payments (Hidden Admin)').'</h3>';
if (empty($rows)) {
    echo "<div class='message'>".__('There are no records to display.')."</div>";
    return;
}

echo "<table class='smallIntBorder' cellspacing='0' style='width:100%'>";
echo "<tr class='head'><th>".__('Date')."</th><th>".__('Student')."</th><th>".__('Title')."</th><th>".__('Amount')."</th><th>".__('Actions')."</th></tr>";
foreach ($rows as $row) {
    $paymentID = intval($row['gibbonFinanceMgmtStudentPaymentID']);
    $student = Format::name('', $row['preferredName'], $row['surname'], 'Student', true).' <span style="font-size:85%">('.htmlPrep($row['studentID']).')</span>';
    $editLink = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/payments_edit.php&gibbonFinanceMgmtStudentPaymentID='.$paymentID;

    echo '<tr>';
    echo '<td>'.Format::date($row['paymentDate']).'</td>';
    echo '<td>'.$student.'</td>';
    echo '<td>'.htmlPrep($row['paymentTitle']).'</td>';
    echo '<td style="text-align:right">'.number_format($row['amountPaid'], 2, '.', ',').'</td>';
    echo "<td>
        <a href='".htmlPrep($editLink)."'>".__('Edit')."</a>
        <form method='post' action='".$session->get('absoluteURL')."/modules/FinanceCustom/payments_deleteSecureProcess.php' style='display:inline; margin-left:8px' onsubmit=\"return confirm('".__('Delete this payment?')."');\">
            <input type='hidden' name='address' value='".htmlPrep($session->get('address'))."'/>
            <input type='hidden' name='returnTo' value='".htmlPrep($returnTo)."'/>
            <input type='hidden' name='gibbonFinanceMgmtStudentPaymentID' value='".$paymentID."'/>
            <input type='submit' value='".__('Delete')."'/>
        </form>
    </td>";
    echo '</tr>';
}
echo '</table>';
