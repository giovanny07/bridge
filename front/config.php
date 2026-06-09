<?php

use GlpiPlugin\Bridge\Page\ConfigPage;

Session::checkRight('config', UPDATE);

// Standalone connections management page. Rendered as a normal page (not a GLPI
// tab) so that ?bridge_connection_id=N is available to ConfigPage::show() — GLPI
// loads tab content via an AJAX endpoint that does not forward query params, so
// editing a specific connection never worked from the Config tab.
Html::header(__('Bridge', 'bridge'), '', 'config', 'plugins');

ConfigPage::show();

Html::footer();
