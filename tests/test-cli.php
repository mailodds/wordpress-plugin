<?php
/**
 * Tests for MailOdds_CLI class.
 *
 * Uses namespace blocks to define WP_CLI\Utils\format_items and a global
 * WP_CLI stub class, since neither is available outside a real WordPress
 * + WP-CLI environment.
 */

namespace WP_CLI\Utils {
	/**
	 * Stub for WP_CLI\Utils\format_items that captures calls in the spy.
	 */
	function format_items( $format, $items, $fields ) {
		\MailOdds_CLI_Test_Spy::$format_items_calls[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {

	/**
	 * Spy that records WP_CLI\Utils\format_items calls for assertion.
	 */
	class MailOdds_CLI_Test_Spy {
		/** @var array */
		public static $format_items_calls = array();

		public static function reset() {
			self::$format_items_calls = array();
		}
	}

	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Minimal WP_CLI stub for unit testing.
		 *
		 * error() throws RuntimeException to simulate exit().
		 * All other output methods are silent to satisfy PHPUnit strict mode.
		 */
		class WP_CLI {
			/** @var array Registered command names. */
			public static $commands = array();

			public static function add_command( $name, $callable ) {
				self::$commands[] = $name;
			}

			public static function error( $msg ) {
				throw new \RuntimeException( 'WP_CLI::error: ' . $msg );
			}

			public static function success( $msg ) {
				// Silent in tests.
			}

			public static function warning( $msg ) {
				// Silent in tests.
			}

			public static function line( $msg = '' ) {
				// Silent in tests.
			}

			public static function reset() {
				self::$commands = array();
			}
		}
	}

	// Load CLI class (not included by bootstrap.php since WP_CLI is optional).
	require_once dirname( __DIR__ ) . '/includes/class-mailodds-cli.php';

	use Brain\Monkey\Functions;

	class CLITest extends MailOdds_TestCase {

		protected function setUp(): void {
			parent::setUp();
			WP_CLI::reset();
			MailOdds_CLI_Test_Spy::reset();
		}

		public function test_cli_can_be_registered() {
			$api = Mockery::mock( 'MailOdds_API' );
			MailOdds_CLI::register( $api );

			$this->assertCount( 3, WP_CLI::$commands );
			$this->assertContains( 'mailodds validate', WP_CLI::$commands );
			$this->assertContains( 'mailodds bulk', WP_CLI::$commands );
			$this->assertContains( 'mailodds status', WP_CLI::$commands );
		}

		public function test_validate_no_key_calls_error() {
			$api = Mockery::mock( 'MailOdds_API' );
			$api->shouldReceive( 'has_key' )->once()->andReturn( false );

			$cli = new MailOdds_CLI( $api );

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'API key not configured' );
			$cli->validate( array( 'test@example.com' ), array() );
		}

		public function test_validate_formats_flat_fields() {
			$api = Mockery::mock( 'MailOdds_API' );
			$api->shouldReceive( 'has_key' )->once()->andReturn( true );
			$api->shouldReceive( 'validate' )
				->once()
				->andReturn( array(
					'email'         => 'test@example.com',
					'status'        => 'valid',
					'action'        => 'accept',
					'sub_status'    => '',
					'free_provider' => true,
					'disposable'    => false,
					'role_account'  => false,
					'mx_found'      => true,
					'depth'         => 'enhanced',
				) );

			Functions\when( 'is_wp_error' )->justReturn( false );

			$cli = new MailOdds_CLI( $api );
			$cli->validate( array( 'test@example.com' ), array() );

			// format_items should have been called exactly once for the table display.
			$this->assertCount( 1, MailOdds_CLI_Test_Spy::$format_items_calls );

			$call   = MailOdds_CLI_Test_Spy::$format_items_calls[0];
			$items  = $call['items'];
			$fields = array_column( $items, 'Field' );

			// Verify boolean fields are present in the display.
			$this->assertContains( 'free_provider', $fields );
			$this->assertContains( 'disposable', $fields );
			$this->assertContains( 'role_account', $fields );
			$this->assertContains( 'mx_found', $fields );
			$this->assertContains( 'depth', $fields );

			// Verify boolean values are formatted as string literals.
			$value_map = array();
			foreach ( $items as $item ) {
				$value_map[ $item['Field'] ] = $item['Value'];
			}
			$this->assertSame( 'true', $value_map['free_provider'] );
			$this->assertSame( 'false', $value_map['disposable'] );
			$this->assertSame( 'false', $value_map['role_account'] );
			$this->assertSame( 'true', $value_map['mx_found'] );
			$this->assertSame( 'enhanced', $value_map['depth'] );
		}

		public function test_status_shows_config() {
			$api = Mockery::mock( 'MailOdds_API' );
			$api->shouldReceive( 'has_key' )->once()->andReturn( true );
			$api->shouldReceive( 'is_test_mode' )->once()->andReturn( false );

			Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
				$map = array(
					'mailodds_depth'             => 'enhanced',
					'mailodds_action_threshold'  => 'reject',
					'mailodds_policy_id'         => 0,
					'mailodds_cron_enabled'      => false,
					'mailodds_daily_stats'       => array(),
					'mailodds_integrations'      => array( 'wp_registration' => true ),
				);
				return array_key_exists( $name, $map ) ? $map[ $name ] : $default;
			} );

			Functions\when( 'get_users' )->justReturn( array() );

			$cli = new MailOdds_CLI( $api );
			$cli->status( array(), array() );

			// format_items should have been called once for the settings table.
			$this->assertCount( 1, MailOdds_CLI_Test_Spy::$format_items_calls );

			$call     = MailOdds_CLI_Test_Spy::$format_items_calls[0];
			$items    = $call['items'];
			$settings = array();
			foreach ( $items as $item ) {
				$settings[ $item['Setting'] ] = $item['Value'];
			}

			$this->assertSame( MAILODDS_VERSION, $settings['Version'] );
			$this->assertSame( 'Configured', $settings['API Key'] );
			$this->assertSame( 'No', $settings['Test Mode'] );
			$this->assertSame( 'enhanced', $settings['Depth'] );
			$this->assertSame( 'reject', $settings['Block Threshold'] );
			$this->assertSame( 'Default', $settings['Policy ID'] );
			$this->assertSame( 'Disabled', $settings['Weekly Cron'] );
			$this->assertSame( 'wp_registration', $settings['Integrations'] );
			$this->assertSame( 0, $settings['Users Validated'] );
		}
	}
}
