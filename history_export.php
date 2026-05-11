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

use Gibbon\Data\Validator;
use Gibbon\Services\Format;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_GET = $container->get(Validator::class)->sanitize($_GET);

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/index.php') == false) {
    $URL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php&return=error0';
    header("Location: {$URL}");
    exit;
}

$toISODate = function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $converted = Format::dateConvert($value);
    if (!empty($converted)) {
        return $converted;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
    }

    return '';
};

$dateStart = $toISODate(strval($_GET['dateStart'] ?? '')) ?: date('Y-m-d', strtotime('-30 days'));
$dateEnd = $toISODate(strval($_GET['dateEnd'] ?? '')) ?: date('Y-m-d');

if ($dateStart > $dateEnd) {
    $swap = $dateStart;
    $dateStart = $dateEnd;
    $dateEnd = $swap;
}

$gibbonSchoolYearID = intval($session->get('gibbonSchoolYearID'));

try {
    $sql = "SELECT p.paymentDate, p.receiptNumber, p.paymentTitle, p.amountPaid,
            per.studentID, per.preferredName, per.surname, yg.nameShort AS yearGroup
        FROM gibbonFinanceMgmtStudentPayment AS p
        JOIN gibbonPerson AS per ON (p.gibbonPersonIDStudent=per.gibbonPersonID)
        LEFT JOIN gibbonYearGroup AS yg ON (p.gibbonYearGroupID=yg.gibbonYearGroupID)
        WHERE p.gibbonSchoolYearID=:gibbonSchoolYearID
            AND p.paymentDate>=:dateStart
            AND p.paymentDate<=:dateEnd
        ORDER BY p.paymentDate DESC, p.gibbonFinanceMgmtStudentPaymentID DESC";

    $stmt = $connection2->prepare($sql);
    $stmt->execute([
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
        'dateStart' => $dateStart,
        'dateEnd' => $dateEnd,
    ]);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    $URL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php&return=error2';
    header("Location: {$URL}");
    exit;
}

$csvSafe = function (string $value): string {
    $first = substr($value, 0, 1);
    if (in_array($first, ['=', '+', '-', '@'], true)) {
        return "'".$value;
    }
    return $value;
};

$filename = 'finance-history-'.$dateStart.'-to-'.$dateEnd.'.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

$cellSafe = function ($value): string {
    return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8');
};

$headers = [
    __('Payment Date'),
    __('Receipt No.'),
    __('Student ID'),
    __('Student Name'),
    __('Year Group'),
    __('Payment Title'),
    __('Amount Paid'),
];

$exportRows = [];
foreach ($rows as $row) {
    $studentName = trim(($row['preferredName'] ?? '').' '.($row['surname'] ?? ''));
    $exportRows[] = [
        $csvSafe(Format::date($row['paymentDate'] ?? '')),
        $csvSafe(strval($row['receiptNumber'] ?? '')),
        $csvSafe(strval($row['studentID'] ?? '')),
        $csvSafe($studentName),
        $csvSafe(strval($row['yearGroup'] ?? '')),
        $csvSafe(strval($row['paymentTitle'] ?? '')),
        number_format(floatval($row['amountPaid'] ?? 0), 2, '.', ''),
    ];
}

$columnWidths = [];
$lengthFn = function (string $text): int {
    if (function_exists('mb_strlen')) {
        return mb_strlen($text);
    }
    return strlen($text);
};

foreach ($headers as $i => $headerText) {
    $maxLen = $lengthFn(strval($headerText));
    foreach ($exportRows as $exportRow) {
        $maxLen = max($maxLen, $lengthFn(strval($exportRow[$i] ?? '')));
    }

    // Approximate Excel width using character count, clamped for readability.
    $columnWidths[$i] = max(90, min(420, ($maxLen * 7) + 22));
}

echo "\xEF\xBB\xBF";
echo '<html><head><meta charset="UTF-8">';
echo '<style>
table{border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt;}
th,td{border:1px solid #000;padding:6px 8px;}
th{background:#D9E1F2;font-weight:bold;text-align:center;}
td.amount{text-align:right;}
</style>';
echo '</head><body>';
echo '<table>';
echo '<colgroup>';
foreach ($columnWidths as $widthPx) {
    echo '<col style="width:'.$widthPx.'px">';
}
echo '</colgroup>';
echo '<tr>';
foreach ($headers as $headerText) {
    echo '<th>'.$cellSafe($headerText).'</th>';
}
echo '</tr>';

foreach ($exportRows as $exportRow) {
    echo '<tr>';
    echo '<td>'.$cellSafe($exportRow[0]).'</td>';
    echo '<td>'.$cellSafe($exportRow[1]).'</td>';
    echo '<td>'.$cellSafe($exportRow[2]).'</td>';
    echo '<td>'.$cellSafe($exportRow[3]).'</td>';
    echo '<td>'.$cellSafe($exportRow[4]).'</td>';
    echo '<td>'.$cellSafe($exportRow[5]).'</td>';
    echo '<td class="amount">'.$cellSafe($exportRow[6]).'</td>';
    echo '</tr>';
}

echo '</table></body></html>';
exit;
