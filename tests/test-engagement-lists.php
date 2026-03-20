<?php
/**
 * Tests for MailOdds_Engagement and MailOdds_Lists classes.
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

class EngagementListsTest extends MailOdds_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof WP_Error;
		} );
	}

	// =========================================================================
	// MailOdds_Engagement
	// =========================================================================

	public function test_engagement_registers_ajax_hooks() {
		Actions\expectAdded( 'wp_ajax_mailodds_engagement_summary' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_engagement_score' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_disengaged' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_suppress_disengaged' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_list_ooo' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_check_ooo' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_batch_ooo' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_delete_ooo' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Engagement( $api );
	}

	public function test_engagement_registers_menu_page() {
		Actions\expectAdded( 'admin_menu' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Engagement( $api );
	}

	public function test_engagement_ajax_summary_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Engagement( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-engagement-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_engagement_summary();
	}

	public function test_engagement_ajax_checks_capability() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Engagement( $api );

		Functions\expect( 'check_ajax_referer' )->once()->andReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_engagement_summary();
	}

	public function test_engagement_ajax_check_ooo_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Engagement( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-engagement-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_check_ooo();
	}

	public function test_engagement_ajax_delete_ooo_checks_nonce() {
		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Engagement( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-engagement-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_delete_ooo();
	}

	// =========================================================================
	// MailOdds_Lists
	// =========================================================================

	public function test_lists_registers_ajax_hooks() {
		Functions\when( 'add_shortcode' )->justReturn( true );

		Actions\expectAdded( 'wp_ajax_mailodds_list_subscriber_lists' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_create_subscriber_list' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_delete_subscriber_list' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_get_subscribers' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_subscribe_email' )->once();
		Actions\expectAdded( 'wp_ajax_nopriv_mailodds_public_subscribe' )->once();
		Actions\expectAdded( 'wp_ajax_mailodds_public_subscribe' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Lists( $api );
	}

	public function test_lists_registers_menu_page() {
		Functions\when( 'add_shortcode' )->justReturn( true );
		Actions\expectAdded( 'admin_menu' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Lists( $api );
	}

	public function test_lists_ajax_create_checks_nonce() {
		Functions\when( 'add_shortcode' )->justReturn( true );

		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Lists( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-lists-nonce', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_create_subscriber_list();
	}

	public function test_lists_ajax_delete_checks_capability() {
		Functions\when( 'add_shortcode' )->justReturn( true );

		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Lists( $api );

		Functions\expect( 'check_ajax_referer' )->once()->andReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function () {
				throw new \RuntimeException( 'wp_send_json_error called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_delete_subscriber_list();
	}

	public function test_public_subscribe_checks_nonce() {
		Functions\when( 'add_shortcode' )->justReturn( true );

		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Lists( $api );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'mailodds-public-subscribe', 'nonce' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'check_ajax_referer called' );
			} );

		$this->expectException( \RuntimeException::class );
		$page->ajax_public_subscribe();
	}

	public function test_shortcode_requires_list_id() {
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
			return array_merge( $defaults, $atts );
		} );
		Functions\when( 'esc_html__' )->alias( function ( $text ) {
			return $text;
		} );

		$api  = Mockery::mock( 'MailOdds_API' );
		$page = new MailOdds_Lists( $api );

		$output = $page->subscribe_shortcode( array() );
		$this->assertStringContainsString( 'list_id attribute is required', $output );
	}
}
