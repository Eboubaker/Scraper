<?php
namespace Eboubaker\Scrapper;
@ob_end_clean();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once dirname(__FILE__, 2) . '/vendor/autoload.php';
exit(App::run($argv));