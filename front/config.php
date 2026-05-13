<?php

Session::checkRight('config', UPDATE);

Html::redirect(
    \Config::getFormURL() . '?forcetab=' . urlencode(\GlpiPlugin\Bridge\Config::class . '$1')
);
