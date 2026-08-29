<?php
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/runner.php';

// Load the whole plugin through its bootstrap (registers hooks against stubs).
require_once dirname( __DIR__ ) . '/mmg-syndication-feeds/mmg-syndication-feeds.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $t ) require $t;

run_all_tests();
