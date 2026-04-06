<?php
/**
 * Tests for MailOdds_Connect (one-click connect flow).
 *
 * @group connect
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class TestMailOddsConnect extends MailOdds_TestCase {

	/**
	 * Stub default options used by the constructor.
	 */
	private function stub_default_options() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			$options = array(
				'mailodds_api_base' => 'https://api.mailodds.com',
				'mailodds_api_key'  => '',
			);
			return isset( $options[ $key ] ) ? $options[ $key ] : $default;
		} );
	}

	// -------------------------------------------------------------------------
	// PKCE Tests
	// -------------------------------------------------------------------------

	public function test_generate_code_verifier_length() {
		$verifier = MailOdds_Connect::generate_code_verifier();
		// 32 bytes base64url-encoded = 43 chars
		$this->assertGreaterThanOrEqual( 40, strlen( $verifier ) );
	}

	public function test_generate_code_verifier_charset() {
		$verifier = MailOdds_Connect::generate_code_verifier();
		// Only unreserved URI characters (RFC 7636)
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $verifier );
	}

	public function test_generate_code_verifier_randomness() {
		$v1 = MailOdds_Connect::generate_code_verifier();
		$v2 = MailOdds_Connect::generate_code_verifier();
		$this->assertNotEquals( $v1, $v2 );
	}

	public function test_compute_code_challenge_s256() {
		// Test vector from RFC 7636 Appendix B:
		// verifier = "dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"
		// S256 challenge = "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM"
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = MailOdds_Connect::compute_code_challenge( $verifier );
		$this->assertEquals( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $challenge );
	}

	public function test_code_challenge_is_base64url() {
		$verifier  = MailOdds_Connect::generate_code_verifier();
		$challenge = MailOdds_Connect::compute_code_challenge( $verifier );
		// No +, /, or = characters (base64url encoding)
		$this->assertStringNotContainsString( '+', $challenge );
		$this->assertStringNotContainsString( '/', $challenge );
		$this->assertStringNotContainsString( '=', $challenge );
	}

	// -------------------------------------------------------------------------
	// Initiate Connect Tests
	// -------------------------------------------------------------------------

	public function test_initiate_connect_requires_nonce() {
		$this->stub_default_options();

		Functions\expect( 'check_ajax_referer' )->once()->with( 'mailodds_connect_nonce', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->andReturn( true );
		Functions\expect( 'home_url' )->once()->andReturn( 'https://mystore.com' );
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://mystore.com/wp-admin/admin-ajax.php' );

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array(
			'response' => array( 'code' => 201 ),
			'body'     => wp_json_encode( array(
				'setup_id'      => 'test-setup-id',
				'authorize_url' => 'https://mailodds.com/connect/authorize?setup_id=test-setup-id',
			) ),
		) );

		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 201 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( wp_json_encode( array(
			'setup_id'      => 'test-setup-id',
			'authorize_url' => 'https://mailodds.com/connect/authorize?setup_id=test-setup-id',
		) ) );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->times( 3 );
		Functions\expect( 'wp_send_json_success' )->once();

		$connect = new MailOdds_Connect();
		$connect->initiate_connect();
	}

	public function test_initiate_connect_woocommerce_platform() {
		$this->stub_default_options();

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\expect( 'current_user_can' )->once()->andReturn( true );
		Functions\expect( 'home_url' )->once()->andReturn( 'https://mystore.com' );
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://mystore.com/wp-admin/admin-ajax.php' );

		// WooCommerce class exists check
		// Note: class_exists('WooCommerce') will return false in test env
		// We test the default path (wordpress platform)

		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing( function ( $url, $args ) {
			$body = json_decode( $args['body'], true );
			// Without WC active, should send 'wordpress' platform
			$this->assertEquals( 'wordpress', $body['platform'] );
			return array(
				'response' => array( 'code' => 201 ),
				'body'     => wp_json_encode( array(
					'setup_id'      => 'wp-setup',
					'authorize_url' => 'https://mailodds.com/connect/authorize?setup_id=wp-setup',
				) ),
			);
		} );

		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 201 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( wp_json_encode( array(
			'setup_id'      => 'wp-setup',
			'authorize_url' => 'https://mailodds.com/connect/authorize?setup_id=wp-setup',
		) ) );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->times( 3 );
		Functions\expect( 'wp_send_json_success' )->once();

		$connect = new MailOdds_Connect();
		$connect->initiate_connect();
	}

	public function test_initiate_connect_non_https_site() {
		$this->stub_default_options();

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\expect( 'current_user_can' )->once()->andReturn( true );
		Functions\expect( 'home_url' )->once()->andReturn( 'http://mystore.com' );

		Functions\expect( 'wp_send_json_error' )->once()->andReturnUsing( function ( $data ) {
			$this->assertStringContainsString( 'HTTPS', $data['message'] );
		} );

		$connect = new MailOdds_Connect();
		$connect->initiate_connect();
	}

	public function test_initiate_connect_setup_failure() {
		$this->stub_default_options();

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\expect( 'current_user_can' )->once()->andReturn( true );
		Functions\expect( 'home_url' )->once()->andReturn( 'https://mystore.com' );
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://mystore.com/wp-admin/admin-ajax.php' );

		$wp_error = new WP_Error( 'http_request_failed', 'Connection timeout' );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( $wp_error );
		Functions\expect( 'is_wp_error' )->once()->andReturn( true );

		Functions\expect( 'wp_send_json_error' )->once()->andReturnUsing( function ( $data ) {
			$this->assertStringContainsString( 'Could not reach MailOdds', $data['message'] );
		} );

		$connect = new MailOdds_Connect();
		$connect->initiate_connect();
	}

	// -------------------------------------------------------------------------
	// Connection Health Tests
	// -------------------------------------------------------------------------

	public function test_health_not_configured() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( 'mailodds_api_key' === $key ) {
				return '';
			}
			return $default;
		} );

		$connect = new MailOdds_Connect();
		$this->assertEquals( 'not_configured', $connect->check_connection_health() );
	}

	public function test_health_connected() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			$options = array(
				'mailodds_api_key'  => 'mo_live_testkey123',
				'mailodds_api_base' => 'https://api.mailodds.com',
			);
			return isset( $options[ $key ] ) ? $options[ $key ] : $default;
		} );

		Functions\expect( 'get_transient' )->once()->with( 'mailodds_connection_health' )->andReturn( false );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
		) );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'set_transient' )->once()->with( 'mailodds_connection_health', 'connected', 300 );

		$connect = new MailOdds_Connect();
		$this->assertEquals( 'connected', $connect->check_connection_health() );
	}

	public function test_health_disconnected() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			$options = array(
				'mailodds_api_key'  => 'mo_live_revokedkey',
				'mailodds_api_base' => 'https://api.mailodds.com',
			);
			return isset( $options[ $key ] ) ? $options[ $key ] : $default;
		} );

		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 401 ),
		) );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 401 );
		Functions\expect( 'set_transient' )->once()->with( 'mailodds_connection_health', 'disconnected', 300 );

		$connect = new MailOdds_Connect();
		$this->assertEquals( 'disconnected', $connect->check_connection_health() );
	}

	public function test_health_cached() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			return 'mailodds_api_key' === $key ? 'mo_live_key' : $default;
		} );

		Functions\expect( 'get_transient' )->once()->with( 'mailodds_connection_health' )->andReturn( 'connected' );
		// wp_remote_get should NOT be called (cached)

		$connect = new MailOdds_Connect();
		$this->assertEquals( 'connected', $connect->check_connection_health() );
	}

	public function test_health_degraded() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			$options = array(
				'mailodds_api_key'  => 'mo_live_key',
				'mailodds_api_base' => 'https://api.mailodds.com',
			);
			return isset( $options[ $key ] ) ? $options[ $key ] : $default;
		} );

		Functions\expect( 'get_transient' )->once()->andReturn( false );

		$wp_error = new WP_Error( 'http_request_failed', 'Timeout' );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $wp_error );
		Functions\expect( 'is_wp_error' )->once()->andReturn( true );
		Functions\expect( 'set_transient' )->once()->with( 'mailodds_connection_health', 'degraded', 300 );

		$connect = new MailOdds_Connect();
		$this->assertEquals( 'degraded', $connect->check_connection_health() );
	}

	// -------------------------------------------------------------------------
	// Disconnect Tests
	// -------------------------------------------------------------------------

	public function test_disconnect_calls_backend() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			$options = array(
				'mailodds_api_key'  => 'mo_live_testkey',
				'mailodds_api_base' => 'https://api.mailodds.com',
				'mailodds_store_id' => 'store-123',
			);
			return isset( $options[ $key ] ) ? $options[ $key ] : $default;
		} );

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\expect( 'current_user_can' )->once()->andReturn( true );

		// Should call /v1/connect/disconnect
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing( function ( $url ) {
			$this->assertStringContainsString( '/v1/connect/disconnect', $url );
			return array( 'response' => array( 'code' => 200 ) );
		} );

		// Handshake disconnect calls wp_remote_request for backend DELETE
		Functions\expect( 'wp_remote_request' )->once()->andReturn( array( 'response' => array( 'code' => 200 ) ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );

		Functions\expect( 'delete_option' )->atLeast()->times( 3 );
		Functions\expect( 'delete_transient' )->atLeast()->once();
		Functions\expect( 'wp_send_json_success' )->once();

		$connect = new MailOdds_Connect();
		$connect->ajax_disconnect();
	}

	public function test_disconnect_clears_options() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			$options = array(
				'mailodds_api_key'  => 'mo_live_key',
				'mailodds_api_base' => 'https://api.mailodds.com',
				'mailodds_store_id' => 'store-456',
			);
			return isset( $options[ $key ] ) ? $options[ $key ] : $default;
		} );

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\expect( 'current_user_can' )->once()->andReturn( true );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array( 'response' => array( 'code' => 200 ) ) );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( array( 'response' => array( 'code' => 200 ) ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );

		// Track which options are deleted
		$deleted = array();
		Functions\expect( 'delete_option' )->andReturnUsing( function ( $key ) use ( &$deleted ) {
			$deleted[] = $key;
		} );
		Functions\expect( 'delete_transient' )->atLeast()->once();
		Functions\expect( 'wp_send_json_success' )->once();

		$connect = new MailOdds_Connect();
		$connect->ajax_disconnect();

		$this->assertContains( 'mailodds_webhook_secret', $deleted );
		$this->assertContains( 'mailodds_pixel_uuid', $deleted );
		$this->assertContains( 'mailodds_connected_via', $deleted );
	}
}
