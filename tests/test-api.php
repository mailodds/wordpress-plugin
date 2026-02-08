<?php
/**
 * Tests for MailOdds_API client.
 *
 * Covers single/batch validation, caching, error handling, request building,
 * and local stats tracking.
 */

use Brain\Monkey\Functions;


class Test_MailOdds_API extends MailOdds_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof WP_Error;
		} );
	}

	/**
	 * Stub get_option for the standard set of plugin options.
	 *
	 * @param array $overrides Key/value overrides merged on top of defaults.
	 */
	private function stub_default_options( $overrides = array() ) {
		$defaults = array(
			'mailodds_depth'       => 'enhanced',
			'mailodds_policy_id'   => 0,
			'mailodds_daily_stats' => array(),
		);
		$opts = array_merge( $defaults, $overrides );
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) use ( $opts ) {
			return isset( $opts[ $name ] ) ? $opts[ $name ] : $default;
		} );
	}

	/**
	 * Stub the wp_remote_retrieve_* helpers so they extract code/body from
	 * the array returned by wp_remote_post.
	 */
	private function stub_response_extractors() {
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( function ( $resp ) {
				return $resp['response']['code'];
			} );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( function ( $resp ) {
				return $resp['body'];
			} );
	}

	// ------------------------------------------------------------------
	// 1. Flat response
	// ------------------------------------------------------------------

	public function test_validate_returns_flat_response() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"email":"test@example.com","status":"valid","action":"accept"}',
			) );

		$api    = new MailOdds_API( 'mo_live_testkey123' );
		$result = $api->validate( 'test@example.com' );

		$this->assertIsArray( $result );
		$this->assertSame( 'valid', $result['status'] );
		$this->assertSame( 'accept', $result['action'] );
		$this->assertSame( 'test@example.com', $result['email'] );
		$this->assertArrayNotHasKey( 'result', $result );
	}

	// ------------------------------------------------------------------
	// 2. Caching on success
	// ------------------------------------------------------------------

	public function test_validate_caches_successful_result() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"email":"test@example.com","status":"valid","action":"accept"}',
			) );

		$expected_data = array(
			'email'  => 'test@example.com',
			'status' => 'valid',
			'action' => 'accept',
		);

		Functions\expect( 'set_transient' )
			->once()
			->with(
				\Mockery::on( function ( $key ) {
					return 0 === strpos( $key, 'mailodds_' );
				} ),
				$expected_data,
				DAY_IN_SECONDS
			)
			->andReturn( true );

		$api = new MailOdds_API( 'mo_live_testkey123' );
		$api->validate( 'test@example.com' );
	}

	// ------------------------------------------------------------------
	// 3. Cached result returned directly
	// ------------------------------------------------------------------

	public function test_validate_returns_cached_result() {
		$this->stub_default_options();

		$cached_data = array(
			'email'  => 'test@example.com',
			'status' => 'valid',
			'action' => 'accept',
		);
		Functions\when( 'get_transient' )->justReturn( $cached_data );
		Functions\expect( 'wp_remote_post' )->never();

		$api    = new MailOdds_API( 'mo_live_testkey123' );
		$result = $api->validate( 'test@example.com' );

		$this->assertIsArray( $result );
		$this->assertSame( 'valid', $result['status'] );
		$this->assertTrue( $result['_cached'] );
	}

	// ------------------------------------------------------------------
	// 4. skip_cache bypasses transient
	// ------------------------------------------------------------------

	public function test_validate_skip_cache_option() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"email":"test@example.com","status":"valid","action":"accept"}',
			) );

		$api    = new MailOdds_API( 'mo_live_testkey123' );
		$result = $api->validate( 'test@example.com', array( 'skip_cache' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'valid', $result['status'] );
	}

	// ------------------------------------------------------------------
	// 5. Empty email
	// ------------------------------------------------------------------

	public function test_validate_empty_email_returns_error() {
		Functions\expect( 'wp_remote_post' )->never();

		$api    = new MailOdds_API( 'mo_live_testkey123' );
		$result = $api->validate( '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mailodds_invalid_email', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// 6. No API key
	// ------------------------------------------------------------------

	public function test_validate_no_api_key_returns_error() {
		Functions\expect( 'wp_remote_post' )->never();

		$api    = new MailOdds_API( '' );
		$result = $api->validate( 'test@example.com' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mailodds_no_api_key', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// 7. API error (non-2xx)
	// ------------------------------------------------------------------

	public function test_validate_api_error_returns_wp_error() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'get_transient' )->justReturn( false );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"error":"Invalid API key"}',
			) );

		$api    = new MailOdds_API( 'mo_live_testkey123' );
		$result = $api->validate( 'test@example.com' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mailodds_api_error', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// 8. Network error (wp_remote_post returns WP_Error)
	// ------------------------------------------------------------------

	public function test_validate_network_error_returns_wp_error() {
		$this->stub_default_options();
		Functions\when( 'get_transient' )->justReturn( false );

		$wp_error = new WP_Error( 'http_request_failed', 'Connection timed out' );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( $wp_error );

		$api    = new MailOdds_API( 'mo_live_testkey123' );
		$result = $api->validate( 'test@example.com' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// 9. Explicit depth=standard sent in body
	// ------------------------------------------------------------------

	public function test_validate_sends_depth_standard() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );

		$captured_body = null;

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing( function ( $url, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"email":"test@example.com","status":"valid","action":"accept"}',
				);
			} );

		$api = new MailOdds_API( 'mo_live_testkey123' );
		$api->validate( 'test@example.com', array( 'depth' => 'standard' ) );

		$this->assertSame( 'standard', $captured_body['depth'] );
	}

	// ------------------------------------------------------------------
	// 10. Enhanced depth (default) omits depth from body
	// ------------------------------------------------------------------

	public function test_validate_sends_depth_enhanced_by_default() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );

		$captured_body = null;

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing( function ( $url, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"email":"test@example.com","status":"valid","action":"accept"}',
				);
			} );

		$api = new MailOdds_API( 'mo_live_testkey123' );
		$api->validate( 'test@example.com' );

		$this->assertArrayNotHasKey( 'depth', $captured_body );
	}

	// ------------------------------------------------------------------
	// 11. Policy ID included in request body
	// ------------------------------------------------------------------

	public function test_validate_sends_policy_id() {
		$this->stub_default_options( array( 'mailodds_policy_id' => 42 ) );
		$this->stub_response_extractors();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );

		$captured_body = null;

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing( function ( $url, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"email":"test@example.com","status":"valid","action":"accept"}',
				);
			} );

		$api = new MailOdds_API( 'mo_live_testkey123' );
		$api->validate( 'test@example.com' );

		$this->assertSame( 42, $captured_body['policy_id'] );
	}

	// ------------------------------------------------------------------
	// 12. Batch returns array of results
	// ------------------------------------------------------------------

	public function test_validate_batch_returns_array() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"results":[{"email":"a@b.com","status":"valid","action":"accept"}]}',
			) );

		$api    = new MailOdds_API( 'mo_live_testkey123' );
		$result = $api->validate_batch( array( 'a@b.com' ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'valid', $result[0]['status'] );
	}

	// ------------------------------------------------------------------
	// 13. Batch caches each result individually
	// ------------------------------------------------------------------

	public function test_validate_batch_caches_each_result() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"results":[{"email":"a@b.com","status":"valid","action":"accept"},{"email":"c@d.com","status":"invalid","action":"reject"}]}',
			) );

		Functions\expect( 'set_transient' )
			->twice()
			->andReturn( true );

		$api = new MailOdds_API( 'mo_live_testkey123' );
		$api->validate_batch( array( 'a@b.com', 'c@d.com' ) );
	}

	// ------------------------------------------------------------------
	// 14. Different depths produce different cache keys
	// ------------------------------------------------------------------

	public function test_cache_key_is_depth_aware() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'wp_remote_post' )
			->twice()
			->andReturn( array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"email":"test@example.com","status":"valid","action":"accept"}',
			) );

		$keys = array();
		Functions\expect( 'set_transient' )
			->twice()
			->andReturnUsing( function ( $key ) use ( &$keys ) {
				$keys[] = $key;
				return true;
			} );

		$api = new MailOdds_API( 'mo_live_testkey123' );
		$api->validate( 'test@example.com', array( 'depth' => 'standard' ) );
		$api->validate( 'test@example.com', array( 'depth' => 'enhanced' ) );

		$this->assertCount( 2, $keys );
		$this->assertNotSame( $keys[0], $keys[1] );
	}

	// ------------------------------------------------------------------
	// 15. Case differences produce identical cache keys
	// ------------------------------------------------------------------

	public function test_cache_key_is_case_insensitive() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'wp_remote_post' )
			->twice()
			->andReturn( array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"email":"user@example.com","status":"valid","action":"accept"}',
			) );

		$keys = array();
		Functions\expect( 'set_transient' )
			->twice()
			->andReturnUsing( function ( $key ) use ( &$keys ) {
				$keys[] = $key;
				return true;
			} );

		$api = new MailOdds_API( 'mo_live_testkey123' );
		$api->validate( 'User@Example.com' );
		$api->validate( 'user@example.com' );

		$this->assertCount( 2, $keys );
		$this->assertSame( $keys[0], $keys[1] );
	}

	// ------------------------------------------------------------------
	// 16. Stats tracking increments daily counters
	// ------------------------------------------------------------------

	public function test_track_validation_increments_stats() {
		$this->stub_default_options();
		$this->stub_response_extractors();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"email":"test@example.com","status":"valid","action":"accept"}',
			) );

		$captured_stats = null;
		Functions\expect( 'update_option' )
			->once()
			->with( 'mailodds_daily_stats', \Mockery::type( 'array' ), false )
			->andReturnUsing( function ( $name, $stats ) use ( &$captured_stats ) {
				$captured_stats = $stats;
				return true;
			} );

		$api = new MailOdds_API( 'mo_live_testkey123' );
		$api->validate( 'test@example.com' );

		$today = gmdate( 'Y-m-d' );
		$this->assertArrayHasKey( $today, $captured_stats );
		$this->assertSame( 1, $captured_stats[ $today ]['total'] );
		$this->assertSame( 1, $captured_stats[ $today ]['valid'] );
		$this->assertSame( 0, $captured_stats[ $today ]['invalid'] );
	}

	// ------------------------------------------------------------------
	// 17. has_key() and is_test_mode() with various key formats
	// ------------------------------------------------------------------

	public function test_has_key_and_test_mode() {
		// Live key: has key, not test mode.
		$live = new MailOdds_API( 'mo_live_abc123' );
		$this->assertTrue( $live->has_key() );
		$this->assertFalse( $live->is_test_mode() );

		// Test key: has key, is test mode.
		$test = new MailOdds_API( 'mo_test_abc123' );
		$this->assertTrue( $test->has_key() );
		$this->assertTrue( $test->is_test_mode() );

		// Empty key: no key, not test mode.
		$empty = new MailOdds_API( '' );
		$this->assertFalse( $empty->has_key() );
		$this->assertFalse( $empty->is_test_mode() );

		// Arbitrary string: has key, not test mode.
		$other = new MailOdds_API( 'some_random_key' );
		$this->assertTrue( $other->has_key() );
		$this->assertFalse( $other->is_test_mode() );
	}
}
