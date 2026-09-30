<?php
/**
 * Runs every test-*.php in this folder: php tests/run.php [name-filter]
 * Exit code 0 = all passed.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $smpw_test_file ) {
	require $smpw_test_file;
}

exit( smpw_test_run( (string) ( $argv[1] ?? '' ) ) );
