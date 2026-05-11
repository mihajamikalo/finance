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

$filename = 'finance-history-'.$dateStart.'-to-'.$dateEnd.'.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
if ($output === false) {
    exit;
}

fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
    __('Payment Date'),
    __('Receipt No.'),
    __('Student ID'),
    __('Student Name'),
    __('Year Group'),
    __('Payment Title'),
    __('Amount Paid'),
]);

foreach ($rows as $row) {
    $studentName = trim(($row['preferredName'] ?? '').' '.($row['surname'] ?? ''));
    fputcsv($output, [
        $csvSafe(Format::date($row['paymentDate'] ?? '')),
        $csvSafe(strval($row['receiptNumber'] ?? '')),
        $csvSafe(strval($row['studentID'] ?? '')),
        $csvSafe($studentName),
        $csvSafe(strval($row['yearGroup'] ?? '')),
        $csvSafe(strval($row['paymentTitle'] ?? '')),
        number_format(floatval($row['amountPaid'] ?? 0), 2, '.', ''),
    ]);
}

fclose($output);
exit;
