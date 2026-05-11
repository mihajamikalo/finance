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

namespace Gibbon\Module\FinanceCustom\Service;

use Gibbon\Services\Format;

class ReceiptGenerator
{
    public function outputReceipt(array $data, string $filename = 'receipt.pdf'): void
    {
        if (!class_exists('\TCPDF')) {
            throw new \RuntimeException('TCPDF is not available in this Gibbon installation.');
        }

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Gibbon');
        $pdf->SetAuthor($data['generatedBy'] ?? 'Gibbon');
        $pdf->SetTitle($data['receiptNumber'] ?? 'Receipt');
        $pdf->SetMargins(12, 14, 12, true);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // Render a native PDF layout inspired by the paper receipt template
        // (without drawing the source image as background).
        $html = $this->renderHtml($data);
        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->Output($filename, 'I');
    }

    private function renderHtml(array $d): string
    {
        $schoolName = htmlspecialchars($d['schoolName'] ?? 'School', ENT_QUOTES, 'UTF-8');
        $studentName = htmlspecialchars($d['studentName'] ?? '', ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($d['paymentTitle'] ?? '', ENT_QUOTES, 'UTF-8');
        $receiptNumber = htmlspecialchars($d['receiptNumber'] ?? '', ENT_QUOTES, 'UTF-8');
        $amountPaid = number_format(floatval($d['amountPaid'] ?? 0), 2, '.', ',');
        $paymentDate = !empty($d['paymentDate']) ? htmlspecialchars(Format::date($d['paymentDate']), ENT_QUOTES, 'UTF-8') : '';
        $logoPath = realpath(__DIR__.'/../../img/Logo.png');
        $logoHtml = (!empty($logoPath) && is_file($logoPath))
            ? '<img src="'.htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8').'" style="height:30mm;" />'
            : '<div class="logo-fallback">'.$schoolName.'</div><div class="sub">BUSINESS SCHOOL</div>';

        return "
            <style>
                body { font-family: helvetica, sans-serif; color: #222; }
                .wrapper { padding: 12mm 10mm 0 10mm; }
                .top { width: 100%; }
                .logo-fallback { color: #d84c74; font-size: 24px; font-weight: bold; letter-spacing: 1px; }
                .sub { font-size: 9px; color: #555; letter-spacing: 2px; }
                .date-value {
                    font-size: 12px;
                    font-weight: bold;
                    padding-left: 4mm;
                }
                .spacer { height: 8mm; }
                table.form { width: 100%; border-collapse: collapse; }
                table.form td { font-size: 15px; padding: 4mm 0; vertical-align: bottom; }
                .label { width: 36mm; }
                .line {
                    border-bottom: 1px dotted #999;
                    font-weight: bold;
                    padding-left: 3mm;
                }
                .footer { margin-top: 30mm; width: 100%; }
                .sign { width: 50mm; border-bottom: 1px dotted #999; }
                .sign-label { padding-top: 2mm; font-size: 12px; color: #333; }
                .meta { margin-top: 8mm; font-size: 10px; color: #666; }
            </style>
            <div class='wrapper'>
                <table class='top'>
                    <tr>
                        <td style='vertical-align: middle;'>
                            {$logoHtml}
                        </td>
                        <td style='width: 78mm; text-align: right; vertical-align: middle;'>
                            <table style='width:100%; border-collapse:collapse;'>
                                <tr>
                                    <td style='width:24mm; background-color:#d83a56; color:#ffffff; text-align:center; font-weight:bold; font-size:12px; padding:2.2mm 0; vertical-align:middle;'>DATE</td>
                                    <td class='date-value' style='vertical-align:middle;'>{$paymentDate}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <div class='spacer'></div>
                <table class='form'>
                    <tr>
                        <td class='label'>Reçu de :</td>
                        <td class='line'>{$studentName}</td>
                    </tr>
                    <tr>
                        <td class='label'>La somme de :</td>
                        <td class='line'>{$amountPaid}</td>
                    </tr>
                    <tr>
                        <td class='label'>Pour :</td>
                        <td class='line'>{$title}</td>
                    </tr>
                </table>

                <table class='footer'>
                    <tr>
                        <td class='sign'></td>
                        <td style='text-align:right; font-size: 11px;'>N° {$receiptNumber}</td>
                    </tr>
                    <tr>
                        <td class='sign-label'>Signature</td>
                        <td></td>
                    </tr>
                </table>
            </div>
        ";
    }
}

