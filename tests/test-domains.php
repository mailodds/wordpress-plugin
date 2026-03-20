<?php
/**
 * Tests for MailOdds_Domains class.
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

class DomainsTest extends MailOdds_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof WP_Error;
		} );
	}

	public function test_registers_ajax_hooks() {
		Actions\expectAdded( 'wp_ajax_mailodds_add_domain' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_verify_domain' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_delete_domain' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_domain_score' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Domains( $api );
	}

	public function test_registers_menu_page() {
		Actions\expectAdded( 'admin_menu' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Domains( $api );
	}

	public function test_ajax_add_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Domains( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-domain-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_add_domain();
	}

	public function test_ajax_add_checks_capability() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Domains( $api );

		Functions\expect( 'check_ajax_referer' )->once()->andReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_add_domain();
	}

	public function test_ajax_verify_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Domains( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-domain-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_verify_domain();
	}

	public function test_ajax_delete_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Domains( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-domain-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_delete_domain();
	}

	public function test_ajax_score_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Domains( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-domain-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_domain_score();
	}
}
