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

        // Optional background template image
        if (!empty($data['backgroundImagePath']) && is_file($data['backgroundImagePath'])) {
            $pdf->Image($data['backgroundImagePath'], 0, 0, 210, 297, '', '', '', false, 300, '', false, false, 0);
        }

        $html = $this->renderHtml($data);
        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->Output($filename, 'I');
    }

    private function renderHtml(array $d): string
    {
        $schoolName = htmlspecialchars($d['schoolName'] ?? 'School', ENT_QUOTES, 'UTF-8');
        $studentName = htmlspecialchars($d['studentName'] ?? '', ENT_QUOTES, 'UTF-8');
        $studentID = htmlspecialchars($d['studentID'] ?? '', ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($d['paymentTitle'] ?? '', ENT_QUOTES, 'UTF-8');
        $receiptNumber = htmlspecialchars($d['receiptNumber'] ?? '', ENT_QUOTES, 'UTF-8');
        $generatedBy = htmlspecialchars($d['generatedBy'] ?? '', ENT_QUOTES, 'UTF-8');

        $amountPaid  = number_format(floatval($d['amountPaid'] ?? 0), 2, '.', ',');
        $balance     = ($d['remainingBalance'] === null) ? __('N/D') : number_format(floatval($d['remainingBalance']), 2, '.', ',');
        $paymentDate = !empty($d['paymentDate']) ? Format::date($d['paymentDate']) : '';

        $methodMap = [
            'BANK'   => __('Banque'),
            'MOBILE' => __('Mobile Banking'),
            'CASH'   => __('Espèces'),
            'OTHER'  => __('Autre'),
        ];
        $methodRaw  = strtoupper(trim($d['paymentMethod'] ?? ''));
        $methodLabel = htmlspecialchars($methodMap[$methodRaw] ?? $methodRaw, ENT_QUOTES, 'UTF-8');

        return "
            <style>
                h1 { font-size: 18px; margin: 0; padding: 0; }
                .muted { color: #666; font-size: 11px; }
                .block { border: 1px solid #ddd; padding: 10px; }
                table { width: 100%; border-collapse: collapse; }
                td { padding: 6px 4px; vertical-align: top; }
                .label { color: #555; width: 30%; }
                .value { font-weight: bold; }
                .big { font-size: 16px; font-weight: bold; }
                .right { text-align: right; }
            </style>
            <h1>{$schoolName} - ".__('Reçu de paiement')."</h1>
            <div class='muted'>".__('N° de reçu').": <b>{$receiptNumber}</b></div>
            <br/>
            <div class='block'>
                <table>
                    <tr>
                        <td class='label'>".__('Élève')."</td>
                        <td class='value'>{$studentName}</td>
                    </tr>
                    <tr>
                        <td class='label'>".__('N° élève')."</td>
                        <td class='value'>{$studentID}</td>
                    </tr>
                    <tr>
                        <td class='label'>".__('Libellé du paiement')."</td>
                        <td class='value'>{$title}</td>
                    </tr>
                    <tr>
                        <td class='label'>".__('Date du paiement')."</td>
                        <td class='value'>{$paymentDate}</td>
                    </tr>
                    <tr>
                        <td class='label'>".__('Mode de paiement')."</td>
                        <td class='value'>{$methodLabel}</td>
                    </tr>
                </table>
            </div>
            <br/>
            <div class='block'>
                <table>
                    <tr>
                        <td class='label big'>".__('Montant versé')."</td>
                        <td class='value big right'>{$amountPaid}</td>
                    </tr>
                    <tr>
                        <td class='label'>".__('Solde restant')."</td>
                        <td class='value right'>{$balance}</td>
                    </tr>
                </table>
            </div>
            <br/>
            <div class='muted'>".__('Établi par').": {$generatedBy}</div>
        ";
    }
}

