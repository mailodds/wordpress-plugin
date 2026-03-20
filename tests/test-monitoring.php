<?php
/**
 * Tests for MailOdds_Monitoring, MailOdds_Deliverability, and MailOdds_Spam_Check classes.
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

class MonitoringTest extends MailOdds_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof WP_Error;
		} );
	}

	// =========================================================================
	// MailOdds_Monitoring
	// =========================================================================

	public function test_monitoring_registers_ajax_hooks() {
		Actions\expectAdded( 'wp_ajax_mailodds_list_dmarc' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_add_dmarc' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_verify_dmarc' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_dmarc_sources' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_dmarc_recommendation' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_list_blacklist' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_add_blacklist' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_check_blacklist' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_blacklist_history' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_run_server_test' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_get_server_test' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_list_alerts' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_create_alert' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_delete_alert' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Monitoring( $api );
	}

	public function test_monitoring_registers_menu_page() {
		Actions\expectAdded( 'admin_menu' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Monitoring( $api );
	}

	public function test_monitoring_ajax_add_dmarc_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Monitoring( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-monitoring-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_add_dmarc();
	}

	public function test_monitoring_ajax_add_blacklist_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Monitoring( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-monitoring-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_add_blacklist();
	}

	public function test_monitoring_ajax_run_server_test_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Monitoring( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-monitoring-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_run_server_test();
	}

	public function test_monitoring_ajax_create_alert_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Monitoring( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-monitoring-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_create_alert();
	}

	public function test_monitoring_ajax_delete_alert_checks_capability() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Monitoring( $api );

		Functions\expect( 'check_ajax_referer' )->once()->andReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_delete_alert();
	}

	// =========================================================================
	// MailOdds_Deliverability
	// =========================================================================

	public function test_deliverability_registers_ajax_hooks() {
		Actions\expectAdded( 'wp_ajax_mailodds_bounce_stats' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_complaint_assessment' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_sender_health' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_sending_stats' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_create_bounce_analysis' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_get_reputation' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Deliverability( $api );
	}

	public function test_deliverability_ajax_bounce_stats_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Deliverability( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-deliverability-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_bounce_stats();
	}

	public function test_deliverability_ajax_checks_capability() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Deliverability( $api );

		Functions\expect( 'check_ajax_referer' )->once()->andReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_bounce_stats();
	}

	// =========================================================================
	// MailOdds_Spam_Check
	// =========================================================================

	public function test_spam_check_registers_ajax_hooks() {
		Actions\expectAdded( 'wp_ajax_mailodds_run_spam_check' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_classify_content' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Spam_Check( $api );
	}

	public function test_spam_check_ajax_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Spam_Check( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-spam-check-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_run_spam_check();
	}

	public function test_classify_content_ajax_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Spam_Check( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-spam-check-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_classify_content();
	}
}
