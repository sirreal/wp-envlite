<?php
define('ENVLITE_NO_AUTORUN', true);
require __DIR__ . '/harness.php';
require __DIR__ . '/../envlite.php';
exit(envlite_test_run(__DIR__));
