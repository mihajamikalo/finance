<?php
/*
Gibbon: the flexible, open school platform
*/

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/admin_access.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Finance Admin Access'));

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCode = strval($_POST['adminAccessCode'] ?? '');

    if (financeMgmtVerifyAdminAccessCode($submittedCode)) {
        financeMgmtGrantAdminCodeSessionAccess($session);
        header('Location: '.$session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_console.php');
        exit;
    }

    $error = __('Invalid access code.');
    financeMgmtClearAdminCodeSessionAccess();
}

echo '<h2>'.__('Finance Hidden Admin Access').'</h2>';
echo '<p>'.__('Enter the Finance admin access code to continue to hidden admin pages.').'</p>';

if (!empty($error)) {
    echo "<div class='error'>".htmlPrep($error)."</div>";
}
if (!empty($success)) {
    echo "<div class='success'>".htmlPrep($success)."</div>";
}

echo "<form method='post' action='".$session->get('absoluteURL')."/index.php?q=/modules/FinanceCustom/admin_access.php'>";
echo "<table class='smallIntBorder' cellspacing='0' style='width: 100%'>";
echo "<tr><td style='width: 30%'><b>".__('Access Code')."</b></td><td><input type='password' name='adminAccessCode' maxlength='64' required style='width: 100%'/></td></tr>";
echo "<tr><td></td><td><input type='submit' value='".__('Continue')."'/></td></tr>";
echo "</table>";
echo "</form>";
