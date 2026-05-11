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

header('Content-Type: application/json; charset=utf-8');

// Permission gate: tied to Add Payment action
if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/payments_add.php') == false) {
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$gibbonSchoolYearID = intval($session->get('gibbonSchoolYearID'));

try {
    $data = [
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
        'q' => "%{$q}%",
        'q2' => "{$q}%",
    ];

    $sql = "SELECT per.gibbonPersonID AS id,
            per.preferredName, per.surname, per.studentID,
            yg.nameShort AS yearGroup
        FROM gibbonPerson AS per
        JOIN gibbonStudentEnrolment AS se ON (se.gibbonPersonID=per.gibbonPersonID AND se.gibbonSchoolYearID=:gibbonSchoolYearID)
        JOIN gibbonYearGroup AS yg ON (se.gibbonYearGroupID=yg.gibbonYearGroupID)
        WHERE per.status='Full'
            AND (
                per.surname LIKE :q
                OR per.preferredName LIKE :q
                OR per.officialName LIKE :q
                OR per.studentID LIKE :q2
            )
        ORDER BY per.surname, per.preferredName
        LIMIT 20";

    $stmt = $connection2->prepare($sql);
    $stmt->execute($data);

    $results = [];
    while ($row = $stmt->fetch()) {
        $label = Format::name('', $row['preferredName'], $row['surname'], 'Student', true);
        if (!empty($row['studentID'])) {
            $label .= " ({$row['studentID']})";
        }
        if (!empty($row['yearGroup'])) {
            $label .= " - {$row['yearGroup']}";
        }
        $results[] = [
            'id' => $row['id'],
            'name' => $label,
        ];
    }

    echo json_encode($results);
} catch (PDOException $e) {
    echo json_encode([]);
}

