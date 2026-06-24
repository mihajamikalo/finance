<?php
// USE ;end TO SEPARATE SQL STATEMENTS. DON'T USE ;end IN ANY OTHER PLACES!

$sql = [];
$count = 0;

// v0.0.00
$sql[$count][0] = "0.0.00";
$sql[$count][1] = "-- First version, nothing to update";

// v1.0.00
$count++;
$sql[$count][0] = "1.0.00";
$sql[$count][1] = "-- Initial production version";


// v0.0.0x
$count++;
$sql[$count][0] = "0.0.0x";
$sql[$count][1] = "-- One block for each subsequent version, place sql statements here for version, seperated by ;end";

// v1.1.00
$count++;
$sql[$count][0] = "1.1.00";
$sql[$count][1] = "INSERT INTO gibbonSetting (`scope`, `name`, `nameDisplay`, `description`, `value`, `type`)
SELECT 'FinanceCustom', 'adminAccessCode', 'Finance Admin Access Code', 'Hidden admin code required for advanced FinanceCustom admin pages. Store this code securely and rotate it if needed.', CONCAT(UPPER(SUBSTRING(MD5(UUID()),1,6)), '-', UPPER(SUBSTRING(MD5(RAND()),1,6))), 'text'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM gibbonSetting WHERE scope='FinanceCustom' AND name='adminAccessCode'
);end";

// v1.2.00 – Instalment plans, ledger, deposit setting
$count++;
$sql[$count][0] = "1.2.00";
$sql[$count][1] = "
CREATE TABLE IF NOT EXISTS `gibbonFinanceMgmtPaymentPlan` (
  `gibbonFinanceMgmtPaymentPlanID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
  `gibbonPersonIDStudent`          int(10) UNSIGNED ZEROFILL NOT NULL,
  `gibbonSchoolYearID`             int(3)  UNSIGNED ZEROFILL NOT NULL,
  `gibbonYearGroupID`              int(3)  UNSIGNED ZEROFILL NOT NULL,
  `planType`            varchar(20)      NOT NULL DEFAULT 'LEGACY',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;end
CREATE TABLE IF NOT EXISTS `gibbonFinanceMgmtInstallmentLedger` (
  `gibbonFinanceMgmtInstallmentLedgerID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
  `gibbonFinanceMgmtPaymentPlanID`       int(12) UNSIGNED ZEROFILL NOT NULL,
  `gibbonFinanceMgmtStudentPaymentID`    int(12) UNSIGNED ZEROFILL DEFAULT NULL,
  `installmentNumber` int(4)     UNSIGNED NOT NULL,
  `dueDate`           date                NOT NULL,
  `expectedAmount`    decimal(14,2)       NOT NULL DEFAULT 0.00,
  `creditBefore`      decimal(14,2)       NOT NULL DEFAULT 0.00,
  `payableAmount`     decimal(14,2)       NOT NULL DEFAULT 0.00,
  `appliedAmount`     decimal(14,2)       NOT NULL DEFAULT 0.00,
  `creditAfter`       decimal(14,2)       NOT NULL DEFAULT 0.00,
  `outstandingAfter`  decimal(14,2)       NOT NULL DEFAULT 0.00,
  `isLate`            enum('Y','N')       NOT NULL DEFAULT 'N',
  `snapshotAt`        datetime            NOT NULL,
  PRIMARY KEY (`gibbonFinanceMgmtInstallmentLedgerID`),
  KEY `plan_number` (`gibbonFinanceMgmtPaymentPlanID`,`installmentNumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;end
INSERT INTO gibbonSetting (`scope`, `name`, `nameDisplay`, `description`, `value`)
SELECT 'FinanceCustom', 'installmentInitialDeposit', 'Required Initial Deposit',
       'Initial deposit required when choosing an instalment plan.', '0'
FROM DUAL WHERE NOT EXISTS (
    SELECT 1 FROM gibbonSetting WHERE scope='FinanceCustom' AND name='installmentInitialDeposit'
);end";

// v1.3.00 – Mode de paiement (Banque / Mobile Banking / Espèces)
$count++;
$sql[$count][0] = "1.3.00";
$sql[$count][1] = "ALTER TABLE `gibbonFinanceMgmtStudentPayment`
  ADD COLUMN `paymentMethod` ENUM('BANK','MOBILE','CASH','OTHER') NOT NULL DEFAULT 'CASH'
  AFTER `paymentDate`;end";
