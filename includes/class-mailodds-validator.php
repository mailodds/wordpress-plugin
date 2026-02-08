<?php
/**
 * MailOdds form validation hooks.
 *
 * Integrates with:
 * - WordPress registration
 * - WooCommerce registration + checkout
 * - WPForms
 * - Gravity Forms
 * - Contact Form 7
 *
 * Each integration is toggled via Settings > MailOdds > Form Integrations.
 * Graceful degradation: if the API is unreachable, registration proceeds (fail-open).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Validator {

	/**
	 * API client.
	 *
	 * @var MailOdds_API
	 */
	private $api;

	/**
	 * Active integrations.
	 *
	 * @var array
	 */
	private $integrations;

	/**
	 * Constructor.
	 *
	 * @param MailOdds_API $api API client.
	 */
	public function __construct( MailOdds_API $api ) {
		$this->api          = $api;
		$this->integrations = get_option( 'mailodds_integrations', array() );

		if ( ! $this->api->has_key() ) {
			return;
		}

		// WordPress registration
		if ( ! empty( $this->integrations['wp_registration'] ) ) {
			add_filter( 'registration_errors', array( $this, 'validate_wp_registration' ), 10, 3 );
		}

		// WooCommerce
		if ( ! empty( $this->integrations['woocommerce'] ) ) {
			add_filter( 'woocommerce_registration_errors', array( $this, 'validate_woo_registration' ), 10, 3 );
			add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_woo_checkout' ), 10, 2 );
		}

		// WPForms
		if ( ! empty( $this->integrations['wpforms'] ) ) {
			add_filter( 'wpforms_process_before_form', array( $this, 'validate_wpforms' ), 10, 2 );
		}

		// Gravity Forms
		if ( ! empty( $this->integrations['gravity_forms'] ) ) {
			add_filter( 'gform_field_validation', array( $this, 'validate_gravity_forms' ), 10, 4 );
		}

		// Contact Form 7
		if ( ! empty( $this->integrations['cf7'] ) ) {
			add_filter( 'wpcf7_validate_email', array( $this, 'validate_cf7' ), 20, 2 );
			add_filter( 'wpcf7_validate_email*', array( $this, 'validate_cf7' ), 20, 2 );
		}
	}

	// =========================================================================
	// WordPress Registration
	// =========================================================================

	/**
	 * Validate email on WordPress registration.
	 *
	 * @param WP_Error $errors              Registration errors.
	 * @param string   $sanitized_user_login User login.
	 * @param string   $user_email           User email.
	 * @return WP_Error
	 */
	public function validate_wp_registration( $errors, $sanitized_user_login, $user_email ) {
		$rejection = $this->check_email( $user_email );
		if ( $rejection ) {
			$errors->add( 'mailodds_invalid_email', $rejection );
		}
		return $errors;
	}

	// =========================================================================
	// WooCommerce
	// =========================================================================

	/**
	 * Validate email on WooCommerce My Account registration.
	 *
	 * @param WP_Error $errors   Registration errors.
	 * @param string   $username Username.
	 * @param string   $email    Email address.
	 * @return WP_Error
	 */
	public function validate_woo_registration( $errors, $username, $email ) {
		$rejection = $this->check_email( $email );
		if ( $rejection ) {
			$errors->add( 'mailodds_invalid_email', $rejection );
		}
		return $errors;
	}

	/**
	 * Validate email on WooCommerce checkout.
	 *
	 * @param array    $data   Checkout data.
	 * @param WP_Error $errors Checkout errors.
	 */
	public function validate_woo_checkout( $data, $errors ) {
		$email = isset( $data['billing_email'] ) ? $data['billing_email'] : '';
		if ( empty( $email ) ) {
			return;
		}

		$rejection = $this->check_email( $email );
		if ( $rejection ) {
			$errors->add( 'validation', $rejection );
		}
	}

	// =========================================================================
	// WPForms
	// =========================================================================

	/**
	 * Validate email fields in WPForms submissions.
	 *
	 * Finds all email-type fields and validates them.
	 *
	 * @param array $entry   Form entry data.
	 * @param array $form_data Form configuration.
	 */
	public function validate_wpforms( $entry, $form_data ) {
		if ( empty( $entry['fields'] ) || empty( $form_data['fields'] ) ) {
			return;
		}

		foreach ( $form_data['fields'] as $field_id => $field ) {
			if ( 'email' !== $field['type'] ) {
				continue;
			}

			$email = isset( $entry['fields'][ $field_id ] ) ? $entry['fields'][ $field_id ] : '';
			if ( empty( $email ) ) {
				continue;
			}

			$rejection = $this->check_email( $email );
			if ( $rejection ) {
				wpforms()->process->errors[ $form_data['id'] ][ $field_id ] = wp_strip_all_tags( $rejection );
			}
		}
	}

	// =========================================================================
	// Gravity Forms
	// =========================================================================

	/**
	 * Validate email fields in Gravity Forms.
	 *
	 * @param array    $result     Validation result.
	 * @param string   $value      Field value.
	 * @param array    $form       Form data.
	 * @param GF_Field $field      Field object.
	 * @return array
	 */
	public function validate_gravity_forms( $result, $value, $form, $field ) {
		if ( 'email' !== $field->type ) {
			return $result;
		}

		// Only validate if the field passed Gravity Forms' own validation
		if ( ! $result['is_valid'] ) {
			return $result;
		}

		$email = is_array( $value ) ? ( isset( $value[0] ) ? $value[0] : '' ) : $value;
		if ( empty( $email ) ) {
			return $result;
		}

		$rejection = $this->check_email( $email );
		if ( $rejection ) {
			$result['is_valid'] = false;
			$result['message']  = wp_strip_all_tags( $rejection );
		}

		return $result;
	}

	// =========================================================================
	// Contact Form 7
	// =========================================================================

	/**
	 * Validate email fields in Contact Form 7.
	 *
	 * @param WPCF7_Validation $result Validation result.
	 * @param WPCF7_FormTag    $tag    Form tag.
	 * @return WPCF7_Validation
	 */
	public function validate_cf7( $result, $tag ) {
		$name  = $tag->name;
		$email = isset( $_POST[ $name ] ) ? sanitize_email( wp_unslash( $_POST[ $name ] ) ) : '';

		if ( empty( $email ) ) {
			return $result;
		}

		$rejection = $this->check_email( $email );
		if ( $rejection ) {
			$result->invalidate( $tag, wp_strip_all_tags( $rejection ) );
		}

		return $result;
	}

	// =========================================================================
	// Core Validation Logic
	// =========================================================================

	/**
	 * Check an email against the MailOdds API.
	 *
	 * Returns an error message string if the email should be rejected,
	 * or null if the email is acceptable.
	 *
	 * Fail-open: if the API is unreachable, returns null (allows registration).
	 *
	 * @param string $email Email to validate.
	 * @return string|null Error message or null.
	 */
	private function check_email( $email ) {
		$email = sanitize_email( $email );
		if ( empty( $email ) ) {
			return null;
		}

		$result = $this->api->validate( $email );

		// Fail-open: API error means we allow the email through
		if ( is_wp_error( $result ) ) {
			return null;
		}

		$action    = isset( $result['action'] ) ? $result['action'] : '';
		$status    = isset( $result['status'] ) ? $result['status'] : '';
		$threshold = get_option( 'mailodds_action_threshold', 'reject' );

		// Always block rejected emails
		if ( 'reject' === $action ) {
			if ( 'do_not_mail' === $status ) {
				return __( '<strong>Error:</strong> This email address is not accepted. Please use a different email.', 'mailodds-email-validation' );
			}
			return __( '<strong>Error:</strong> This email address could not be verified. Please check and try again.', 'mailodds-email-validation' );
		}

		// Optionally block risky emails (accept_with_caution)
		if ( 'caution' === $threshold && 'accept_with_caution' === $action ) {
			return __( '<strong>Error:</strong> This email address appears risky. Please use a different email.', 'mailodds-email-validation' );
		}

		// Also block retry_later as unknown (can't verify = risky for registration)
		if ( 'retry_later' === $action && 'caution' === $threshold ) {
			return __( '<strong>Error:</strong> We could not verify this email at this time. Please try again later.', 'mailodds-email-validation' );
		}

		return null;
	}
}
