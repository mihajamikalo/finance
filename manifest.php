<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http:// www.gnu.org/licenses/>.
*/

// This file describes the module, including database tables

// Basic variables
$name        = 'FinanceCustom';     // Must match the module folder name.
$description = 'Custom finance management: tuition fees, installment payments, receipts, and reporting.'; // Short text description
$entryURL    = "index.php";   // The landing page for the unit, used in the main menu
$type        = "Additional";  // Do not change.
$category    = 'Finance';     // The main menu area to place the module in
$version     = '1.2.00';      // Version number
$author      = 'Custom Module'; // Your name
$url         = '';            // Your URL

// Module tables & gibbonSettings entries
$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonFinanceMgmtTuitionFee` (
  `gibbonFinanceMgmtTuitionFeeID` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
  `gibbonSchoolYearID` int(3) UNSIGNED ZEROFILL NOT NULL,
  `gibbonYearGroupID` int(3) UNSIGNED ZEROFILL NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `active` enum('Y','N') NOT NULL DEFAULT 'Y',
  `createdAt` datetime NOT NULL,
  `updatedAt` datetime NOT NULL,
  PRIMARY KEY (`gibbonFinanceMgmtTuitionFeeID`),
  UNIQUE KEY `schoolYear_yearGroup` (`gibbonSchoolYearID`,`gibbonYearGroupID`),
  KEY `gibbonSchoolYearID` (`gibbonSchoolYearID`),
  KEY `gibbonYearGroupID` (`gibbonYearGroupID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3";

$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonFinanceMgmtStudentPayment` (
  `gibbonFinanceMgmtStudentPaymentID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
  `gibbonPersonIDStudent` int(10) UNSIGNED ZEROFILL NOT NULL,
  `gibbonSchoolYearID` int(3) UNSIGNED ZEROFILL NOT NULL,
  `gibbonYearGroupID` int(3) UNSIGNED ZEROFILL NOT NULL,
  `paymentTitle` varchar(100) NOT NULL,
  `amountPaid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paymentDate` date NOT NULL,
  `receiptPrinted` enum('Y','N') NOT NULL DEFAULT 'N',
  `receiptNumber` varchar(30) DEFAULT NULL,
  `gibbonPersonIDCreatedBy` int(10) UNSIGNED ZEROFILL NOT NULL,
  `createdAt` datetime NOT NULL,
  PRIMARY KEY (`gibbonFinanceMgmtStudentPaymentID`),
  KEY `student_schoolYear` (`gibbonPersonIDStudent`,`gibbonSchoolYearID`),
  KEY `gibbonSchoolYearID` (`gibbonSchoolYearID`),
  KEY `gibbonYearGroupID` (`gibbonYearGroupID`),
  KEY `paymentDate` (`paymentDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3";

$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonFinanceMgmtAuditLog` (
  `gibbonFinanceMgmtAuditLogID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
  `action` varchar(40) NOT NULL,
  `recordID` int(12) UNSIGNED ZEROFILL DEFAULT NULL,
  `gibbonPersonID` int(10) UNSIGNED ZEROFILL DEFAULT NULL,
  `details` text,
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`gibbonFinanceMgmtAuditLogID`),
  KEY `action` (`action`),
  KEY `recordID` (`recordID`),
  KEY `timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3";

$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonFinanceMgmtPaymentPlan` (
  `gibbonFinanceMgmtPaymentPlanID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
  `gibbonPersonIDStudent`          int(10) UNSIGNED ZEROFILL NOT NULL,
  `gibbonSchoolYearID`             int(3)  UNSIGNED ZEROFILL NOT NULL,
  `gibbonYearGroupID`              int(3)  UNSIGNED ZEROFILL NOT NULL,
  `planType`            varchar(20)      NOT NULL DEFAULT 'LEGACY'
                        COMMENT 'FULL | INSTALLMENT_4 | INSTALLMENT_8 | LEGACY',
  `tuitionFeeOriginal`  decimal(14,2)    NOT NULL DEFAULT 0.00,
  `discountRate`        decimal(5,2)     NOT NULL DEFAULT 0.00,
  `discountAmount`      decimal(14,2)    NOT NULL DEFAULT 0.00,
  `tuitionFeeFinal`     decimal(14,2)    NOT NULL DEFAULT 0.00,
  `requiredDeposit`     decimal(14,2)    NOT NULL DEFAULT 0.00,
  `installmentCount`    tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `installmentAmount`   decimal(14,2)    NOT NULL DEFAULT 0.00,
  `planStartDate`       date             NOT NULL,
  `status`              enum('ACTIVE','CLOSED') NOT NULL DEFAULT 'ACTIVE',
  `gibbonPersonIDCreatedBy` int(10) UNSIGNED ZEROFILL NOT NULL,
  `createdAt`           datetime         NOT NULL,
  `updatedAt`           datetime         NOT NULL,
  PRIMARY KEY (`gibbonFinanceMgmtPaymentPlanID`),
  UNIQUE KEY `studentYear` (`gibbonPersonIDStudent`,`gibbonSchoolYearID`),
  KEY `gibbonSchoolYearID` (`gibbonSchoolYearID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3";

$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonFinanceMgmtInstallmentLedger` (
  `gibbonFinanceMgmtInstallmentLedgerID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
  `gibbonFinanceMgmtPaymentPlanID`       int(12) UNSIGNED ZEROFILL NOT NULL,
  `gibbonFinanceMgmtStudentPaymentID`    int(12) UNSIGNED ZEROFILL DEFAULT NULL,
  `installmentNumber` int(4)     UNSIGNED NOT NULL,
  `dueDate`           date                NOT NULL,
  `expectedAmount`    decimal(14,2)       NOT NULL DEFAULT 0.00,
  `creditBefore`      decimal(14,2)       NOT NULL DEFAULT 0.00
                      COMMENT 'Surplus carried forward from prior instalments',
  `payableAmount`     decimal(14,2)       NOT NULL DEFAULT 0.00
                      COMMENT 'Amount actually owed after applying credit',
  `appliedAmount`     decimal(14,2)       NOT NULL DEFAULT 0.00,
  `creditAfter`       decimal(14,2)       NOT NULL DEFAULT 0.00
                      COMMENT 'Surplus to carry forward to next instalment',
  `outstandingAfter`  decimal(14,2)       NOT NULL DEFAULT 0.00,
  `isLate`            enum('Y','N')       NOT NULL DEFAULT 'N',
  `snapshotAt`        datetime            NOT NULL,
  PRIMARY KEY (`gibbonFinanceMgmtInstallmentLedgerID`),
  KEY `plan_number` (`gibbonFinanceMgmtPaymentPlanID`,`installmentNumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3";

// Add gibbonSettings entries
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`, `type`) VALUES
('FinanceCustom', 'receiptNumberPrefix', 'Receipt Number Prefix', 'Prefix applied to receipt numbers generated by the FinanceCustom module.', 'RCP', 'text')";

$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`, `type`) VALUES
('FinanceCustom', 'receiptBackgroundImage', 'Receipt Background Image', 'Optional relative path to an image used as a background/watermark for receipts.', '', 'text')";

$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`, `type`) VALUES
('FinanceCustom', 'receiptAllowReprint', 'Allow Receipt Reprint', 'If enabled, authorized admins can reprint receipts (action is logged).', 'N', 'yesno')";

$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`, `type`) VALUES
('FinanceCustom', 'adminAccessCode', 'Finance Admin Access Code', 'Hidden admin code required for advanced FinanceCustom admin pages. Store this code securely and rotate it if needed.', CONCAT(UPPER(SUBSTRING(MD5(UUID()),1,6)), '-', UPPER(SUBSTRING(MD5(RAND()),1,6))), 'text')";

$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`, `type`) VALUES
('FinanceCustom', 'installmentInitialDeposit', 'Required Initial Deposit', 'Initial deposit amount required when a student chooses an instalment plan. Configure this before any first payment.', '0', 'text')";

// Action rows 
// One array per action
$actionRows[] = [
    'name'                      => 'Finance Dashboard', // The name of the action (appears to user in the right hand side module menu)
    'precedence'                => '0',// If it is a grouped action, the precedence controls which is highest action in group
    'category'                  => 'Dashboard', // Optional: subgroups for the right hand side module menu
    'description'               => 'Visual overview of tuition payments and outstanding balances.', // Text description
    'URLList'                   => 'index.php', // List of pages included in this action
    'entryURL'                  => 'index.php', // The landing action for the page.
    'entrySidebar'              => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow'                  => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin'    => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher'  => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent'  => 'N', // Default permission for built in role Student
    'defaultPermissionParent'   => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport'  => 'N', // Default permission for built in role Support
    'categoryPermissionStaff'   => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent'  => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther'   => 'N', // Should this action be available to user roles in the Other category?
];

$actionRows[] = [
    'name'                      => 'Add Payment',
    'precedence'                => '0',
    'category'                  => 'Payments',
    'description'               => 'Record an installment payment and generate a receipt.',
    'URLList'                   => 'payments_add.php,payments_addProcess.php,ajax_studentSearch.php,receipt_print.php',
    'entryURL'                  => 'payments_add.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Finance Hidden Admin Access',
    'precedence'                => '0',
    'category'                  => 'Admin',
    'description'               => 'Enter special finance admin access code.',
    'URLList'                   => 'admin_access.php',
    'entryURL'                  => 'admin_access.php',
    'entrySidebar'              => 'N',
    'menuShow'                  => 'N',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Finance Hidden Admin Console',
    'precedence'                => '0',
    'category'                  => 'Admin',
    'description'               => 'Hidden finance admin actions for high-risk operations.',
    'URLList'                   => 'admin_console.php,admin_deleteAllProcess.php,payments_edit.php,payments_editProcess.php,payments_deleteSecureProcess.php',
    'entryURL'                  => 'admin_console.php',
    'entrySidebar'              => 'N',
    'menuShow'                  => 'N',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Student Payment History',
    'precedence'                => '0',
    'category'                  => 'Payments',
    'description'               => 'View payment history and balances for a student.',
    'URLList'                   => 'student_history.php',
    'entryURL'                  => 'student_history.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Manage Tuition Fees',
    'precedence'                => '0',
    'category'                  => 'Settings',
    'description'               => 'Configure tuition fees per year group.',
    'URLList'                   => 'tuitionFees_manage.php,tuitionFees_manageProcess.php',
    'entryURL'                  => 'tuitionFees_manage.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Delete Payment (Admin)',
    'precedence'                => '0',
    'category'                  => 'Admin',
    'description'               => 'Delete payment records (logged).',
    'URLList'                   => 'payments_deleteProcess.php',
    'entryURL'                  => 'payments_deleteProcess.php',
    'entrySidebar'              => 'N',
    'menuShow'                  => 'N',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

// Hooks
$hooks[] = ''; // Serialised array to create hook and set options. See Hooks documentation online.
