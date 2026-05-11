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

namespace Gibbon\Module\FinanceCustom\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

class StudentPaymentGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonFinanceMgmtStudentPayment';
    private static $primaryKey = 'gibbonFinanceMgmtStudentPaymentID';
    private static $searchableColumns = ['paymentTitle'];

    public function queryPaymentsByStudent(QueryCriteria $criteria, int $gibbonPersonIDStudent, int $gibbonSchoolYearID)
    {
        $query = $this
            ->newQuery()
            ->from($this->getTableName())
            ->cols([
                'gibbonFinanceMgmtStudentPaymentID',
                'gibbonPersonIDStudent',
                'gibbonSchoolYearID',
                'gibbonYearGroupID',
                'paymentTitle',
                'amountPaid',
                'paymentDate',
                'receiptPrinted',
                'receiptNumber',
                'gibbonPersonIDCreatedBy',
                'createdAt',
                'creator.surname AS createdBySurname',
                'creator.preferredName AS createdByPreferredName',
            ])
            ->leftJoin('gibbonPerson AS creator', 'gibbonFinanceMgmtStudentPayment.gibbonPersonIDCreatedBy=creator.gibbonPersonID')
            ->where('gibbonFinanceMgmtStudentPayment.gibbonPersonIDStudent=:gibbonPersonIDStudent')
            ->where('gibbonFinanceMgmtStudentPayment.gibbonSchoolYearID=:gibbonSchoolYearID')
            ->bindValue('gibbonPersonIDStudent', $gibbonPersonIDStudent)
            ->bindValue('gibbonSchoolYearID', $gibbonSchoolYearID);

        $query->orderBy(['paymentDate DESC', 'gibbonFinanceMgmtStudentPaymentID DESC']);

        return $this->runQuery($query, $criteria);
    }

    public function getPaymentByID(int $paymentID)
    {
        return $this->selectBy([$this->getPrimaryKey() => $paymentID])->fetch();
    }
}

