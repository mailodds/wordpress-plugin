<?php
/**
 * Tests for MailOdds_Validator.
 *
 * Exercises the private check_email() decision engine indirectly through
 * public hook methods. Uses Mockery to mock MailOdds_API and Brain Monkey
 * for WordPress function stubs.
 */

use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Brain\Monkey\Actions;


class ValidatorTest extends MailOdds_TestCase {

	/**
	 * Build a MailOdds_Validator with common option and function stubs.
	 *
	 * @param object $api          Mockery mock of MailOdds_API.
	 * @param array  $integrations Enabled integrations map.
	 * @param string $threshold    Action threshold (reject|caution).
	 * @return MailOdds_Validator
	 */
	private function make_validator( $api, $integrations = [], $threshold = 'reject' ) {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) use ( $integrations, $threshold ) {
			if ( 'mailodds_integrations' === $name ) {
				return $integrations;
			}
			if ( 'mailodds_action_threshold' === $name ) {
				return $threshold;
			}
			return $default;
		} );

		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );

		return new MailOdds_Validator( $api );
	}

	// =========================================================================
	// Fail-open tests (1-4)
	// =========================================================================

	public function test_api_timeout_allows_registration() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn(
			new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
		);

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ] );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'slow@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	public function test_api_500_allows_registration() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn(
			new \WP_Error( 'mailodds_api_error', 'Internal Server Error' )
		);

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ] );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'user@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	public function test_api_network_error_allows_checkout() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn(
			new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' )
		);

		$validator = $this->make_validator( $api, [ 'woocommerce' => true ] );

		$errors = new \WP_Error();
		$validator->validate_woo_checkout( [ 'billing_email' => 'buyer@example.com' ], $errors );

		$this->assertEmpty( $errors->get_error_code() );
	}

	public function test_empty_email_allows_through() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldNotReceive( 'validate' );

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ] );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', '' );

		$this->assertEmpty( $result->get_error_code() );
	}

	// =========================================================================
	// Blocking tests with threshold=reject (5-9)
	// =========================================================================

	public function test_reject_action_blocks_email() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status'     => 'invalid',
			'action'     => 'reject',
			'sub_status' => 'smtp_rejected',
		] );

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ], 'reject' );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'bad@example.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertStringContainsString( 'could not be verified', $result->get_error_message() );
	}

	public function test_reject_do_not_mail_custom_message() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status'     => 'do_not_mail',
			'action'     => 'reject',
			'sub_status' => 'role_based',
		] );

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ], 'reject' );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'noreply@example.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertStringContainsString( 'not accepted', $result->get_error_message() );
	}

	public function test_accept_allows_email() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'valid',
			'action' => 'accept',
		] );

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ], 'reject' );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'good@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	public function test_accept_with_caution_allows_on_reject_threshold() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'catch_all',
			'action' => 'accept_with_caution',
		] );

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ], 'reject' );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'maybe@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	public function test_retry_later_allows_on_reject_threshold() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'unknown',
			'action' => 'retry_later',
		] );

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ], 'reject' );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'unknown@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	// =========================================================================
	// Blocking tests with threshold=caution (10-12)
	// =========================================================================

	public function test_accept_with_caution_blocks_on_caution_threshold() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'catch_all',
			'action' => 'accept_with_caution',
		] );

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ], 'caution' );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'risky@example.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertStringContainsString( 'appears risky', $result->get_error_message() );
	}

	public function test_retry_later_blocks_on_caution_threshold() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'unknown',
			'action' => 'retry_later',
		] );

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ], 'caution' );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'mystery@example.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertStringContainsString( 'could not verify', $result->get_error_message() );
	}

	public function test_accept_still_allows_on_caution_threshold() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'valid',
			'action' => 'accept',
		] );

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ], 'caution' );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'testuser', 'good@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	// =========================================================================
	// WooCommerce checkout tests (13-16)
	// =========================================================================

	public function test_woo_checkout_validates_billing_email() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status'     => 'invalid',
			'action'     => 'reject',
			'sub_status' => 'smtp_rejected',
		] );

		$validator = $this->make_validator( $api, [ 'woocommerce' => true ] );

		$errors = new \WP_Error();
		$validator->validate_woo_checkout( [ 'billing_email' => 'test@invalid.com' ], $errors );

		$this->assertNotEmpty( $errors->get_error_code() );
	}

	public function test_woo_checkout_empty_email_skips() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldNotReceive( 'validate' );

		$validator = $this->make_validator( $api, [ 'woocommerce' => true ] );

		$errors = new \WP_Error();
		$validator->validate_woo_checkout( [], $errors );

		$this->assertEmpty( $errors->get_error_code() );
	}

	public function test_woo_checkout_api_down_allows_checkout() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn(
			new \WP_Error( 'http_request_failed', 'Connection refused' )
		);

		$validator = $this->make_validator( $api, [ 'woocommerce' => true ] );

		$errors = new \WP_Error();
		$validator->validate_woo_checkout( [ 'billing_email' => 'buyer@example.com' ], $errors );

		$this->assertEmpty( $errors->get_error_code() );
	}

	public function test_woo_checkout_reject_adds_validation_error() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'invalid',
			'action' => 'reject',
		] );

		$validator = $this->make_validator( $api, [ 'woocommerce' => true ] );

		$errors = new \WP_Error();
		$validator->validate_woo_checkout( [ 'billing_email' => 'fake@example.com' ], $errors );

		$this->assertSame( 'validation', $errors->get_error_code() );
	}

	// =========================================================================
	// WooCommerce registration tests (17-18)
	// =========================================================================

	public function test_woo_registration_validates_email() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'invalid',
			'action' => 'reject',
		] );

		$validator = $this->make_validator( $api, [ 'woocommerce' => true ] );

		$errors = new \WP_Error();
		$result = $validator->validate_woo_registration( $errors, 'buyer', 'bad@example.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertSame( 'mailodds_invalid_email', $result->get_error_code() );
	}

	public function test_woo_registration_api_down_allows() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn(
			new \WP_Error( 'http_request_failed', 'Service unavailable' )
		);

		$validator = $this->make_validator( $api, [ 'woocommerce' => true ] );

		$errors = new \WP_Error();
		$result = $validator->validate_woo_registration( $errors, 'buyer', 'user@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	// =========================================================================
	// WordPress registration tests (19-20)
	// =========================================================================

	public function test_wp_registration_validates_email() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status'     => 'invalid',
			'action'     => 'reject',
			'sub_status' => 'mailbox_not_found',
		] );

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ] );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'newuser', 'bad@example.com' );

		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertSame( 'mailodds_invalid_email', $result->get_error_code() );
	}

	public function test_wp_registration_api_down_allows() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn(
			new \WP_Error( 'mailodds_api_error', 'Gateway Timeout' )
		);

		$validator = $this->make_validator( $api, [ 'wp_registration' => true ] );

		$errors = new \WP_Error();
		$result = $validator->validate_wp_registration( $errors, 'newuser', 'user@example.com' );

		$this->assertEmpty( $result->get_error_code() );
	}

	// =========================================================================
	// Form plugin tests (21-25)
	// =========================================================================

	public function test_wpforms_validates_email_fields() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'invalid',
			'action' => 'reject',
		] );

		$process         = new \stdClass();
		$process->errors = [];

		$wpforms_obj          = new \stdClass();
		$wpforms_obj->process = $process;

		Functions\when( 'wpforms' )->justReturn( $wpforms_obj );

		$validator = $this->make_validator( $api, [ 'wpforms' => true ] );

		$form_data = [
			'id'     => 42,
			'fields' => [
				7 => [ 'type' => 'email' ],
			],
		];
		$entry = [
			'fields' => [
				7 => 'bad@example.com',
			],
		];

		$validator->validate_wpforms( $entry, $form_data );

		$this->assertNotEmpty( $process->errors[42][7] );
	}

	public function test_wpforms_skips_non_email_fields() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldNotReceive( 'validate' );

		$process         = new \stdClass();
		$process->errors = [];

		$wpforms_obj          = new \stdClass();
		$wpforms_obj->process = $process;

		Functions\when( 'wpforms' )->justReturn( $wpforms_obj );

		$validator = $this->make_validator( $api, [ 'wpforms' => true ] );

		$form_data = [
			'id'     => 42,
			'fields' => [
				7 => [ 'type' => 'text' ],
			],
		];
		$entry = [
			'fields' => [
				7 => 'just some text',
			],
		];

		$validator->validate_wpforms( $entry, $form_data );

		$this->assertEmpty( $process->errors );
	}

	public function test_gravity_forms_validates_email() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'invalid',
			'action' => 'reject',
		] );

		$validator = $this->make_validator( $api, [ 'gravity_forms' => true ] );

		$field       = new \stdClass();
		$field->type = 'email';

		$result = [
			'is_valid' => true,
			'message'  => '',
		];

		$returned = $validator->validate_gravity_forms( $result, 'bad@example.com', [ 'id' => 1 ], $field );

		$this->assertFalse( $returned['is_valid'] );
		$this->assertNotEmpty( $returned['message'] );
	}

	public function test_gravity_forms_skips_failed_validation() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldNotReceive( 'validate' );

		$validator = $this->make_validator( $api, [ 'gravity_forms' => true ] );

		$field       = new \stdClass();
		$field->type = 'email';

		$result = [
			'is_valid' => false,
			'message'  => 'Email is required.',
		];

		$returned = $validator->validate_gravity_forms( $result, 'bad@example.com', [ 'id' => 1 ], $field );

		$this->assertFalse( $returned['is_valid'] );
		$this->assertSame( 'Email is required.', $returned['message'] );
	}

	public function test_cf7_validates_email() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'validate' )->andReturn( [
			'status' => 'invalid',
			'action' => 'reject',
		] );

		$validator = $this->make_validator( $api, [ 'cf7' => true ] );

		$tag       = new \stdClass();
		$tag->name = 'your-email';

		$cf7_result = Mockery::mock();
		$cf7_result->shouldReceive( 'invalidate' )
			->once()
			->with( $tag, Mockery::type( 'string' ) );

		$_POST['your-email'] = 'bad@example.com';

		$returned = $validator->validate_cf7( $cf7_result, $tag );

		unset( $_POST['your-email'] );

		$this->assertSame( $cf7_result, $returned );
	}

	// =========================================================================
	// Integration toggle tests (26-28)
	// =========================================================================

	public function test_disabled_integration_no_hooks() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );

		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_integrations' === $name ) {
				return [];
			}
			return $default;
		} );

		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );

		Filters\expectAdded( 'registration_errors' )->never();
		Filters\expectAdded( 'woocommerce_registration_errors' )->never();
		Actions\expectAdded( 'woocommerce_after_checkout_validation' )->never();
		Filters\expectAdded( 'wpforms_process_before_form' )->never();
		Filters\expectAdded( 'gform_field_validation' )->never();
		Filters\expectAdded( 'wpcf7_validate_email' )->never();
		Filters\expectAdded( 'wpcf7_validate_email*' )->never();

		new MailOdds_Validator( $api );
	}

	public function test_no_api_key_no_hooks() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( false );

		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_integrations' === $name ) {
				return [
					'wp_registration' => true,
					'woocommerce'     => true,
					'wpforms'         => true,
					'gravity_forms'   => true,
					'cf7'             => true,
				];
			}
			return $default;
		} );

		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );

		Filters\expectAdded( 'registration_errors' )->never();
		Filters\expectAdded( 'woocommerce_registration_errors' )->never();
		Actions\expectAdded( 'woocommerce_after_checkout_validation' )->never();
		Filters\expectAdded( 'wpforms_process_before_form' )->never();
		Filters\expectAdded( 'gform_field_validation' )->never();
		Filters\expectAdded( 'wpcf7_validate_email' )->never();
		Filters\expectAdded( 'wpcf7_validate_email*' )->never();

		new MailOdds_Validator( $api );
	}

	public function test_enabled_integration_registers_hooks() {
		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );

		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_integrations' === $name ) {
				return [ 'wp_registration' => true ];
			}
			return $default;
		} );

		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );

		Filters\expectAdded( 'registration_errors' )->once();

		new MailOdds_Validator( $api );
	}
}
