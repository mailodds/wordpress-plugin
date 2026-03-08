<?php
/**
 * Tests for MailOdds_Policies class.
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

class PoliciesTest extends MailOdds_TestCase {

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
		Actions\expectAdded( 'wp_ajax_mailodds_create_policy' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_create_preset' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_delete_policy' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_test_policy' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_add_rule' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_delete_rule' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Policies( $api );
	}

	public function test_registers_menu_page() {
		Actions\expectAdded( 'admin_menu' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Policies( $api );
	}

	// =========================================================================
	// AJAX security tests
	// =========================================================================

	public function test_ajax_create_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Policies( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-policy-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_create_policy();
	}

	public function test_ajax_create_checks_capability() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Policies( $api );

		Functions\expect( 'check_ajax_referer' )->once()->andReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_create_policy();
	}

	public function test_ajax_delete_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Policies( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-policy-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_delete_policy();
	}

	public function test_ajax_test_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Policies( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-policy-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_test_policy();
	}

	public function test_ajax_add_rule_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Policies( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-policy-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_add_rule();
	}

	public function test_ajax_delete_rule_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Policies( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-policy-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_delete_rule();
	}
}
