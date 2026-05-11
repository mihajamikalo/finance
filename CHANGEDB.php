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
