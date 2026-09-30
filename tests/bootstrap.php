<?php
/**
 * Plain-PHP test harness — no WordPress, no PHPUnit. From the plugin's folder:
 *   php tests/run.php [name-filter]
 *   php tests/scenarios.php [name-filter]
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'SMPW_TEST_PLUGIN', dirname( __DIR__ ) );
const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS   = 3600;
const DAY_IN_SECONDS    = 86400;

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'SMPW_' ) ) {
			return;
		}
		$file = SMPW_TEST_PLUGIN . '/includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

$GLOBALS['smpw_tests']   = array();
$GLOBALS['smpw_options'] = array();

final class SMPW_Test_Failure extends Exception {}

function test( string $name, callable $fn ): void {
	$GLOBALS['smpw_tests'][ $name ] = $fn;
}

function assert_same( $expected, $actual, string $message = '' ): void {
	if ( $expected !== $actual ) {
		throw new SMPW_Test_Failure( ( '' !== $message ? $message . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function assert_true( $condition, string $message = '' ): void {
	if ( true !== $condition ) {
		throw new SMPW_Test_Failure( '' !== $message ? $message : 'expected true' );
	}
}

function assert_contains( string $needle, string $haystack, string $message = '' ): void {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new SMPW_Test_Failure( ( '' !== $message ? $message . ': ' : '' ) . "'" . $needle . "' not in '" . substr( $haystack, 0, 400 ) . "'" );
	}
}

function smpw_test_run( string $filter ): int {
	$pass = 0;
	$fail = 0;
	foreach ( $GLOBALS['smpw_tests'] as $name => $fn ) {
		if ( '' !== $filter && false === stripos( $name, $filter ) ) {
			continue;
		}
		$GLOBALS['smpw_options'] = array();
		try {
			$fn();
			++$pass;
			echo "  ok    {$name}\n";
		} catch ( Throwable $e ) {
			++$fail;
			echo "  FAIL  {$name}\n        " . $e->getMessage() . "\n";
		}
	}
	echo "\n{$pass} passed, {$fail} failed\n";
	return $fail > 0 ? 1 : 0;
}

// --- WordPress stubs: only what the code paths under test call. ---

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
	public function get_error_message(): string {
		return $this->message;
	}
	public function get_error_code(): string {
		return $this->code;
	}
	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags );
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme;
}

function get_option( string $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['smpw_options'] ) ? $GLOBALS['smpw_options'][ $name ] : $default;
}

function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['smpw_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['smpw_options'][ $name ] );
	return true;
}

function apply_filters( string $hook, $value, ...$args ) {
	return $value;
}

// Translations: the tests read the English source strings.
function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function get_bloginfo( string $show = '' ): string {
	return 'name' === $show ? 'Test Shop' : '';
}

function wp_specialchars_decode( string $text, $quote_style = ENT_NOQUOTES ): string {
	return htmlspecialchars_decode( $text, ENT_QUOTES );
}
