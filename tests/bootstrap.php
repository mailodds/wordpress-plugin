<?php
/**
 * PHPUnit bootstrap for MailOdds plugin tests.
 *
 * Sets up Brain Monkey for mocking WordPress functions,
 * defines required constants, and loads plugin classes.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use Brain\Monkey;

// Minimal WP_Error stub shared across all test files.
class WP_Error {
	private $errors = [];
	private $error_data = [];

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( $code ) {
			$this->errors[ $code ] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}

	public function add( $code, $message, $data = '' ) {
		$this->errors[ $code ] = $message;
		if ( '' !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}

	public function get_error_code() {
		return key( $this->errors ) ?: '';
	}

	public function get_error_message() {
		return reset( $this->errors ) ?: '';
	}

	public function get_error_codes() {
		return array_keys( $this->errors );
	}

	public function get_error_data( $code = '' ) {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}
		return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
	}
}

// WordPress constants the plugin expects.
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'MAILODDS_VERSION', '1.0.0' );
define( 'MAILODDS_PLUGIN_FILE', dirname( __DIR__ ) . '/mailodds.php' );
define( 'MAILODDS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'MAILODDS_PLUGIN_URL', 'http://localhost/wp-content/plugins/mailodds/' );
define( 'MAILODDS_API_BASE', 'https://api.mailodds.com' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

/**
 * Base test case that sets up and tears down Brain Monkey.
 */
abstract class MailOdds_TestCase extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default stubs for common WP functions used across all tests.
		Brain\Monkey\Functions\stubs( [
			'sanitize_email'      => function ( $email ) {
				return filter_var( trim( $email ), FILTER_VALIDATE_EMAIL ) ? trim( $email ) : '';
			},
			'sanitize_text_field' => function ( $str ) {
				return trim( strip_tags( $str ) );
			},
			'absint'              => function ( $val ) {
				return abs( (int) $val );
			},
			'wp_json_encode'      => function ( $data ) {
				return json_encode( $data );
			},
			'wp_strip_all_tags'   => function ( $str ) {
				return strip_tags( $str );
			},
			'wp_unslash'          => function ( $val ) {
				return stripslashes( $val );
			},
			'__'                  => function ( $text ) {
				return $text;
			},
			'esc_html'            => function ( $text ) {
				return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			},
			'esc_attr'            => function ( $text ) {
				return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			},
			'esc_url'             => function ( $url ) {
				return filter_var( $url, FILTER_SANITIZE_URL );
			},
			'current_time'        => function ( $type ) {
				if ( 'mysql' === $type ) {
					return gmdate( 'Y-m-d H:i:s' );
				}
				if ( 'Y-m-d' === $type ) {
					return gmdate( 'Y-m-d' );
				}
				return time();
			},
		] );
	}

	protected function assertPostConditions(): void {
		$container = \Mockery::getContainer();
		if ( $container ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}
		parent::assertPostConditions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}

// Load plugin classes (not the main plugin file, which has hooks).
require_once dirname( __DIR__ ) . '/includes/class-mailodds-api.php';
require_once dirname( __DIR__ ) . '/includes/class-mailodds-validator.php';
require_once dirname( __DIR__ ) . '/includes/class-mailodds-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-mailodds-bulk.php';
