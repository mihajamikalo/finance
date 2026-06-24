<?php
/*
Gibbon: the flexible, open school platform
*/

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/FinanceCustom/admin_access.php') == false) {
    $page->addError(__('Vous n\'avez pas accès à cette action.'));
    return;
}

$page->breadcrumbs->add(__('Accès Administrateur Finance'));

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCode = strval($_POST['adminAccessCode'] ?? '');

    if (financeMgmtVerifyAdminAccessCode($submittedCode)) {
        financeMgmtGrantAdminCodeSessionAccess($session);
        header('Location: '.$session->get('absoluteURL').'/index.php?q=/modules/FinanceCustom/admin_console.php');
        exit;
    }

    $error = __('Code d\'accès invalide.');
    financeMgmtClearAdminCodeSessionAccess();
}

echo '<h2>'.__('Accès Admin Finance Restreint').'</h2>';
echo '<p>'.__('Saisissez le code d\'accès administrateur Finance pour accéder aux pages restreintes.').'</p>';

if (!empty($error)) {
    echo "<div class='error'>".htmlPrep($error)."</div>";
}
if (!empty($success)) {
    echo "<div class='success'>".htmlPrep($success)."</div>";
}

echo "<form method='post' action='".$session->get('absoluteURL')."/index.php?q=/modules/FinanceCustom/admin_access.php'>";
echo "<table class='smallIntBorder' cellspacing='0' style='width: 100%'>";
echo "<tr><td style='width: 30%'><b>".__('Code d\'accès')."</b></td><td><input type='password' name='adminAccessCode' maxlength='64' required style='width: 100%'/></td></tr>";
echo "<tr><td></td><td><input type='submit' value='".__('Continuer')."'/></td></tr>";
echo "</table>";
echo "</form>";
