<?php

use GlpiPlugin\Bridge\Config;
use GlpiPlugin\Bridge\Connection;

Session::checkRight('config', UPDATE);

$connection = new Connection();

if (isset($_POST['add'])) {
    $connection->check(-1, CREATE, $_POST);
    $id = $connection->addFromForm($_POST);
    Html::redirect(Connection::getConfigURL((int) $id));
}

if (isset($_POST['update'])) {
    $connection->check((int) $_POST['id'], UPDATE);
    $connection->updateFromForm($_POST);
    Html::redirect(Connection::getConfigURL((int) $_POST['id']));
}

if (isset($_POST['purge'])) {
    $connection->check((int) $_POST['id'], PURGE);
    $connection->purge(['id' => (int) $_POST['id']]);
    Html::redirect(Connection::getConfigURL());
}

Html::redirect(Connection::getConfigURL());
