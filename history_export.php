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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

$autoloadCandidates = [
    dirname(__DIR__, 2).'/vendor/autoload.php',
    __DIR__.'/vendor/autoload.php',
];
foreach ($autoloadCandidates as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        break;
    }
}

if (!class_exists(Spreadsheet::class)) {
    $URL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/index.php&return=error2';
    header("Location: {$URL}");
    exit;
}

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
        Format::date($row['paymentDate'] ?? ''),
        strval($row['receiptNumber'] ?? ''),
        strval($row['studentID'] ?? ''),
        $studentName,
        strval($row['yearGroup'] ?? ''),
        strval($row['paymentTitle'] ?? ''),
        number_format(floatval($row['amountPaid'] ?? 0), 2, '.', ''),
    ];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Payment History');

$sheet->fromArray($headers, null, 'A1');

$rowNumber = 2;
foreach ($exportRows as $exportRow) {
    $sheet->setCellValueExplicit('A'.$rowNumber, strval($exportRow[0]), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B'.$rowNumber, strval($exportRow[1]), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('C'.$rowNumber, strval($exportRow[2]), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('D'.$rowNumber, strval($exportRow[3]), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('E'.$rowNumber, strval($exportRow[4]), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('F'.$rowNumber, strval($exportRow[5]), DataType::TYPE_STRING);
    $sheet->setCellValue('G'.$rowNumber, floatval($exportRow[6]));
    $rowNumber++;
}

$lastRow = max(1, $rowNumber - 1);

$sheet->getStyle('A1:G1')->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['argb' => 'FF000000'],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['argb' => 'FFD9E1F2'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getStyle('A1:G'.$lastRow)->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF000000'],
        ],
    ],
]);

if ($lastRow >= 2) {
    $sheet->getStyle('G2:G'.$lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('G2:G'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
}

foreach (range('A', 'G') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$sheet->setAutoFilter('A1:G1');
$sheet->freezePane('A2');

$filename = 'finance-history-'.$dateStart.'-to-'.$dateEnd.'.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');
header('Pragma: public');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
unset($spreadsheet);

exit;
