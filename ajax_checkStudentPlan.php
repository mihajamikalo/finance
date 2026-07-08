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

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_GET = $container->get(Validator::class)->sanitize($_GET);

header('Content-Type: application/json; charset=utf-8');

// Sécurité : même permission que le formulaire d'ajout de paiement.
if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/payments_add.php') == false) {
    echo json_encode(['error' => 'access_denied']);
    exit;
}

$gibbonPersonIDStudent = intval($_GET['gibbonPersonIDStudent'] ?? 0);
if ($gibbonPersonIDStudent <= 0) {
    echo json_encode(['error' => 'invalid_student']);
    exit;
}

$gibbonSchoolYearID = intval($session->get('gibbonSchoolYearID'));

// Vérifier l'existence d'un plan de paiement pour cet élève cette année.
$plan         = financeMgmtGetStudentPaymentPlan($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);
$paymentCount = financeMgmtCountStudentPayments($connection2, $gibbonPersonIDStudent, $gibbonSchoolYearID);

$planTypeLabels = [
    'FULL'          => 'Paiement intégral (remise 10 %)',
    'INSTALLMENT_4' => '4 mensualités',
    'INSTALLMENT_8' => '8 mensualités',
    'LEGACY'        => 'Historique (sans plan)',
];

// Un étudiant ayant déjà des paiements (même sans plan formel) ne doit plus
// pouvoir choisir un plan — on considère que son parcours est déjà engagé.
$hasExistingPlan = ($plan !== null) || ($paymentCount > 0);

if ($hasExistingPlan) {
    echo json_encode([
        'hasExistingPlan' => true,
        'planType'        => $plan['planType'] ?? 'LEGACY',
        'planLabel'       => isset($plan['planType']) ? ($planTypeLabels[$plan['planType']] ?? $plan['planType']) : 'Historique',
        'paymentCount'    => $paymentCount,
    ]);
} else {
    echo json_encode([
        'hasExistingPlan' => false,
        'planType'        => null,
        'planLabel'       => null,
        'paymentCount'    => $paymentCount,
    ]);
}
exit;
