<?php
/**
 * Tests for form integration hooks in MailOdds_Validator.
 *
 * Exercises the validate-and-block flow for WP registration,
 * WooCommerce checkout, and the hook registration logic when
 * integrations are enabled or disabled.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Brain\Monkey\Actions;

class FormIntegrationsTest extends MailOdds_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof WP_Error;
		} );
	}

	/**
	 * Build a MailOdds_Validator with option stubs.
	 *
	 * @param object $api          Mockery mock of MailOdds_API.
	 * @param array  $integrations Enabled integrations map.
	 * @param string $threshold    Action threshold (reject|caution).
	 * @return MailOdds_Validator
	 */
	private function make_validator( $api, $integrations = array(), $threshold = 'reject' ) {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) use ( $integrations, $threshold ) {
			if ( 'mailodds_integrations' === $name ) {
				return $integrations;
			}
			if ( 'mailodds_action_threshold' === $name ) {
				return $threshold;
			}
			return $default;
		} );

		return new MailOdds_Validator( $api );
	}

	// =========================================================================
	// WP Registration hook fires validation
	// =========================================================================

	public function test_wp_registration_hook_registers_when_enabled() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );

		Filters\expectAdded( 'registration_errors' )->once();

		$this->make_validator( $api, array( 'wp_registration' => true ) );
	}

	public function test_wp_registration_hook_not_registered_when_disabled() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );

		Filters\expectAdded( 'registration_errors' )->never();

		$this->make_validator( $api, array( 'wp_registration' => false ) );
	}

	public function test_wp_registration_reject_blocks_submission() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn( array(
			'status' => 'invalid',
			'action' => 'reject',
		) );

		$validator = $this->make_validator( $api, array( 'wp_registration' => true ) );

		$errors = new WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'bad@example.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertSame( 'mailodds_invalid_email', $result->get_error_code() );
	}

	public function test_wp_registration_accept_allows_submission() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn( array(
			'status' => 'valid',
			'action' => 'accept',
		) );

		$validator = $this->make_validator( $api, array( 'wp_registration' => true ) );

		$errors = new WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'good@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	public function test_wp_registration_failopen_on_api_error() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn(
			new WP_Error( 'http_request_failed', 'Connection timed out' )
		);

		$validator = $this->make_validator( $api, array( 'wp_registration' => true ) );

		$errors = new WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'user@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	// =========================================================================
	// WooCommerce checkout hook (if WC integration enabled)
	// =========================================================================

	public function test_woo_checkout_hooks_register_when_enabled() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );

		Filters\expectAdded( 'woocommerce_registration_errors' )->once();
		Actions\expectAdded( 'woocommerce_after_checkout_validation' )->once();

		$this->make_validator( $api, array( 'woocommerce' => true ) );
	}

	public function test_woo_checkout_hooks_not_registered_when_disabled() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );

		Filters\expectAdded( 'woocommerce_registration_errors' )->never();
		Actions\expectAdded( 'woocommerce_after_checkout_validation' )->never();

		$this->make_validator( $api, array( 'woocommerce' => false ) );
	}

	public function test_woo_checkout_reject_blocks_order() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn( array(
			'status' => 'invalid',
			'action' => 'reject',
		) );

		$validator = $this->make_validator( $api, array( 'woocommerce' => true ) );

		$errors = new WP_Error();
		$validator->validate_woo_checkout( array( 'billing_email' => 'fake@example.com' ), $errors );

		$this->assertSame( 'validation', $errors->get_error_code() );
	}

	public function test_woo_checkout_accept_allows_order() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn( array(
			'status' => 'valid',
			'action' => 'accept',
		) );

		$validator = $this->make_validator( $api, array( 'woocommerce' => true ) );

		$errors = new WP_Error();
		$validator->validate_woo_checkout( array( 'billing_email' => 'buyer@example.com' ), $errors );

		$this->assertEmpty( $errors->get_error_code() );
	}

	public function test_woo_checkout_missing_email_skips_validation() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldNotReceive( 'validate' );

		$validator = $this->make_validator( $api, array( 'woocommerce' => true ) );

		$errors = new WP_Error();
		$validator->validate_woo_checkout( array(), $errors );

		$this->assertEmpty( $errors->get_error_code() );
	}

	public function test_woo_checkout_failopen_on_api_error() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn(
			new WP_Error( 'mailodds_api_error', 'Internal Server Error' )
		);

		$validator = $this->make_validator( $api, array( 'woocommerce' => true ) );

		$errors = new WP_Error();
		$validator->validate_woo_checkout( array( 'billing_email' => 'buyer@example.com' ), $errors );

		$this->assertEmpty( $errors->get_error_code() );
	}

	// =========================================================================
	// Validate-and-block flow: threshold variations
	// =========================================================================

	public function test_caution_threshold_blocks_risky_email() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn( array(
			'status' => 'catch_all',
			'action' => 'accept_with_caution',
		) );

		$validator = $this->make_validator( $api, array( 'wp_registration' => true ), 'caution' );

		$errors = new WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'risky@example.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertStringContainsString( 'risky', $result->get_error_message() );
	}

	public function test_reject_threshold_allows_risky_email() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn( array(
			'status' => 'catch_all',
			'action' => 'accept_with_caution',
		) );

		$validator = $this->make_validator( $api, array( 'wp_registration' => true ), 'reject' );

		$errors = new WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'maybe@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	public function test_do_not_mail_status_has_custom_rejection_message() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn( array(
			'status' => 'do_not_mail',
			'action' => 'reject',
		) );

		$validator = $this->make_validator( $api, array( 'wp_registration' => true ) );

		$errors = new WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'disposable@temp.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertStringContainsString( 'not accepted', $result->get_error_message() );
	}

	// =========================================================================
	// No API key: all hooks skipped
	// =========================================================================

	public function test_no_api_key_skips_all_hooks() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( false );

		Filters\expectAdded( 'registration_errors' )->never();
		Filters\expectAdded( 'woocommerce_registration_errors' )->never();
		Actions\expectAdded( 'woocommerce_after_checkout_validation' )->never();

		$this->make_validator( $api, array(
			'wp_registration' => true,
			'woocommerce'     => true,
		) );
	}

	// =========================================================================
	// WooCommerce registration (My Account page)
	// =========================================================================

	public function test_woo_registration_reject_blocks_signup() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn( array(
			'status' => 'invalid',
			'action' => 'reject',
		) );

		$validator = $this->make_validator( $api, array( 'woocommerce' => true ) );

		$errors = new WP_Error();
		$result = $validator->validate_woo_registration( $errors, 'newbuyer', 'bad@example.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertSame( 'mailodds_invalid_email', $result->get_error_code() );
	}

	public function test_woo_registration_accept_allows_signup() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->once()->andReturn( array(
			'status' => 'valid',
			'action' => 'accept',
		) );

		$validator = $this->make_validator( $api, array( 'woocommerce' => true ) );

		$errors = new WP_Error();
		$result = $validator->validate_woo_registration( $errors, 'newbuyer', 'good@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}
}
