<?php
/**
 * Tests for MailOdds_Bulk class.
 *
 * Covers constructor hook registration and AJAX handler security guards
 * (nonce, capability, API key). The wp_send_json_error/check_ajax_referer
 * functions call exit() in WordPress, so we throw RuntimeException to
 * simulate that behavior and verify the guard clauses fire in order.
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

class BulkTest extends MailOdds_TestCase {

	public function test_registers_ajax_hook() {
		Actions\expectAdded( 'wp_ajax_mailodds_bulk_validate' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Bulk( $api );
	}

	public function test_registers_tools_page() {
		Actions\expectAdded( 'admin_menu' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Bulk( $api );
	}

	public function test_ajax_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$bulk = new MailOdds_Bulk( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-bulk-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'check_ajax_referer called' );
		$bulk->ajax_bulk_validate();
	}

	public function test_ajax_checks_capability() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$bulk = new MailOdds_Bulk( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-bulk-nonce', 'nonce' )
			->andReturn( true );

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( false );

		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$bulk->ajax_bulk_validate();
	}

	public function test_ajax_checks_api_key() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->once()->andReturn( false );
		$bulk = new MailOdds_Bulk( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-bulk-nonce', 'nonce' )
			->andReturn( true );

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( true );

		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$bulk->ajax_bulk_validate();
	}
}
