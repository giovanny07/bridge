<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Page\ConfigPage;
use GlpiPlugin\Bridge\Profile;

Profile::checkConfigure();

header('Content-Type: text/html; charset=UTF-8');

$id         = (int) ($_GET['id'] ?? 0);
$connection = null;

if ($id > 0) {
    $conn = new Connection();
    if ($conn->getFromDB($id)) {
        $connection = $conn;
    }
}

ConfigPage::showConnectionForm($connection);
