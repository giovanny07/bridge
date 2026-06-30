<?php

use GlpiPlugin\Bridge\Config;
use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Profile;

Profile::checkConfigure();

$connection = new Connection();

// config.php always redirects 302 to the GLPI Config tab; use the canonical
// tab URL directly so the browser lands on the right page in one hop.
$tabUrl = \Config::getFormURL() . '?forcetab=GlpiPlugin\\Bridge\\Config$1';

if (isset($_POST['add'])) {
    $connection->check(-1, CREATE, $_POST);
    $connection->addFromForm($_POST);
    Html::redirect($tabUrl);
}

if (isset($_POST['update'])) {
    $connection->check((int) $_POST['id'], UPDATE);
    $connection->updateFromForm($_POST);
    Html::redirect($tabUrl);
}

if (isset($_POST['purge'])) {
    $connection->check((int) $_POST['id'], PURGE);
    $connection->purge(['id' => (int) $_POST['id']]);
    Html::redirect($tabUrl);
}

Html::redirect($tabUrl);
