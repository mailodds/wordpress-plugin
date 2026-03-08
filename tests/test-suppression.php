<?php
/**
 * Tests for MailOdds_Suppression class and suppression pre-check.
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

class SuppressionTest extends MailOdds_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof WP_Error;
		} );
	}

	// =========================================================================
	// Constructor hooks
	// =========================================================================

	public function test_registers_ajax_hooks() {
		Actions\expectAdded( 'wp_ajax_mailodds_add_suppression' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_remove_suppression' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Suppression( $api );
	}

	public function test_registers_menu_page() {
		Actions\expectAdded( 'admin_menu' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Suppression( $api );
	}

	// =========================================================================
	// AJAX add: security
	// =========================================================================

	public function test_ajax_add_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$supp = new MailOdds_Suppression( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-suppression-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$supp->ajax_add_suppression();
	}

	public function test_ajax_add_checks_capability() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$supp = new MailOdds_Suppression( $api );

		Functions\expect( 'check_ajax_referer' )->once()->andReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$supp->ajax_add_suppression();
	}

	// =========================================================================
	// AJAX remove: security
	// =========================================================================

	public function test_ajax_remove_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$supp = new MailOdds_Suppression( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-suppression-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$supp->ajax_remove_suppression();
	}

	public function test_ajax_remove_checks_capability() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$supp = new MailOdds_Suppression( $api );

		Functions\expect( 'check_ajax_referer' )->once()->andReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$supp->ajax_remove_suppression();
	}

	// =========================================================================
	// Validator suppression pre-check
	// =========================================================================

	public function test_suppression_precheck_blocks_suppressed_email() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'check_suppression' )
			->once()
			->andReturn( array( 'suppressed' => true, 'type' => 'hard_bounce' ) );
		$api->shouldNotReceive( 'validate' );

		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_integrations' === $name ) {
				return array( 'wp_registration' => true );
			}
			if ( 'mailodds_check_suppression' === $name ) {
				return true;
			}
			return $default;
		} );

		$validator = new MailOdds_Validator( $api );
		$errors    = new WP_Error();
		$result    = $validator->validate_wp_registration( $errors, 'testuser', 'suppressed@example.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertStringContainsString( 'suppressed', $result->get_error_message() );
	}

	public function test_suppression_precheck_failopen_on_api_error() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'check_suppression' )
			->once()
			->andReturn( new WP_Error( 'mailodds_api_error', 'Service unavailable' ) );
		$api->shouldReceive( 'validate' )
			->once()
			->andReturn( array( 'status' => 'valid', 'action' => 'accept' ) );

		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_integrations' === $name ) {
				return array( 'wp_registration' => true );
			}
			if ( 'mailodds_check_suppression' === $name ) {
				return true;
			}
			if ( 'mailodds_action_threshold' === $name ) {
				return 'reject';
			}
			return $default;
		} );
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'error_log' )->justReturn( true );

		$validator = new MailOdds_Validator( $api );
		$errors    = new WP_Error();
		$result    = $validator->validate_wp_registration( $errors, 'testuser', 'user@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	public function test_suppression_precheck_skipped_when_disabled() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldNotReceive( 'check_suppression' );
		$api->shouldReceive( 'validate' )
			->once()
			->andReturn( array( 'status' => 'valid', 'action' => 'accept' ) );

		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_integrations' === $name ) {
				return array( 'wp_registration' => true );
			}
			if ( 'mailodds_check_suppression' === $name ) {
				return false;
			}
			if ( 'mailodds_action_threshold' === $name ) {
				return 'reject';
			}
			return $default;
		} );

		$validator = new MailOdds_Validator( $api );
		$errors    = new WP_Error();
		$result    = $validator->validate_wp_registration( $errors, 'testuser', 'user@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	public function test_suppression_precheck_allows_non_suppressed() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'check_suppression' )
			->once()
			->andReturn( array( 'suppressed' => false ) );
		$api->shouldReceive( 'validate' )
			->once()
			->andReturn( array( 'status' => 'valid', 'action' => 'accept' ) );

		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_integrations' === $name ) {
				return array( 'wp_registration' => true );
			}
			if ( 'mailodds_check_suppression' === $name ) {
				return true;
			}
			if ( 'mailodds_action_threshold' === $name ) {
				return 'reject';
			}
			return $default;
		} );

		$validator = new MailOdds_Validator( $api );
		$errors    = new WP_Error();
		$result    = $validator->validate_wp_registration( $errors, 'testuser', 'clean@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}
}
