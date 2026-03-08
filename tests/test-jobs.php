<?php
/**
 * Tests for MailOdds bulk validation jobs API and smart routing.
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

class JobsTest extends MailOdds_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof WP_Error;
		} );
	}

	// =========================================================================
	// AJAX poll: security
	// =========================================================================

	public function test_ajax_poll_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$bulk = new MailOdds_Bulk( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-bulk-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$bulk->ajax_poll_job_status();
	}

	public function test_ajax_poll_checks_capability() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$bulk = new MailOdds_Bulk( $api );

		Functions\expect( 'check_ajax_referer' )->once()->andReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$bulk->ajax_poll_job_status();
	}

	// =========================================================================
	// AJAX apply: security
	// =========================================================================

	public function test_ajax_apply_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$bulk = new MailOdds_Bulk( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-bulk-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$bulk->ajax_apply_job_results();
	}

	// =========================================================================
	// AJAX cancel: security
	// =========================================================================

	public function test_ajax_cancel_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$bulk = new MailOdds_Bulk( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-bulk-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$bulk->ajax_cancel_job();
	}

	// =========================================================================
	// Job-based routing
	// =========================================================================

	public function test_registers_job_ajax_hooks() {
		Actions\expectAdded( 'wp_ajax_mailodds_poll_job_status' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_apply_job_results' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_cancel_job' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Bulk( $api );
	}
}
