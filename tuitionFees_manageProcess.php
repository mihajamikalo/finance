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

$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/tuitionFees_manage.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/tuitionFees_manage.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

$gibbonSchoolYearID = intval($session->get('gibbonSchoolYearID'));
$fees = $_POST['fee'] ?? [];
$actives = $_POST['active'] ?? [];

try {
    $connection2->beginTransaction();

    foreach ($fees as $gibbonYearGroupID => $amount) {
        $gibbonYearGroupID = intval($gibbonYearGroupID);
        $amount = floatval($amount);
        $active = (($actives[$gibbonYearGroupID] ?? 'Y') === 'N') ? 'N' : 'Y';

        $data = [
            'gibbonSchoolYearID' => $gibbonSchoolYearID,
            'gibbonYearGroupID' => $gibbonYearGroupID,
            'amount' => $amount,
            'active' => $active,
            'now' => date('Y-m-d H:i:s'),
        ];

        $sql = "INSERT INTO gibbonFinanceMgmtTuitionFee
            SET gibbonSchoolYearID=:gibbonSchoolYearID,
                gibbonYearGroupID=:gibbonYearGroupID,
                amount=:amount,
                active=:active,
                createdAt=:now,
                updatedAt=:now
            ON DUPLICATE KEY UPDATE
                amount=VALUES(amount),
                active=VALUES(active),
                updatedAt=VALUES(updatedAt)";

        $stmt = $connection2->prepare($sql);
        $stmt->execute($data);
    }

    financeMgmtLog(
        $connection2,
        'TUITION_FEE_UPDATE',
        null,
        strval($session->get('gibbonPersonID')),
        json_encode(['gibbonSchoolYearID' => $gibbonSchoolYearID])
    );

    $connection2->commit();
} catch (PDOException $e) {
    if ($connection2->inTransaction()) $connection2->rollBack();
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

$URL .= '&return=success0';
header("Location: {$URL}");
exit;

