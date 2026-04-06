<?php
/**
 * One-click connect flow for MailOdds.
 *
 * Implements PKCE (RFC 7636) S256 authorization code flow where MailOdds
 * acts as the Authorization Server. Users click "Connect to MailOdds" in
 * the plugin admin, authorize on mailodds.com, and the plugin receives
 * an API key automatically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Connect {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private $api_base;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api_base = get_option( 'mailodds_api_base', 'https://api.mailodds.com' );
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_mailodds_initiate_connect', array( $this, 'initiate_connect' ) );
		add_action( 'wp_ajax_mailodds_connect_callback', array( $this, 'handle_callback' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_callback_redirect' ) );
		add_action( 'wp_ajax_mailodds_disconnect_oneclick', array( $this, 'ajax_disconnect' ) );
	}

	// -------------------------------------------------------------------------
	// PKCE helpers (RFC 7636)
	// -------------------------------------------------------------------------

	/**
	 * Generate a cryptographically random PKCE code verifier.
	 *
	 * @return string Base64url-encoded 32-byte random value.
	 */
	public static function generate_code_verifier() {
		$bytes = random_bytes( 32 );
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Compute PKCE S256 code challenge from a verifier.
	 *
	 * @param string $verifier The code verifier.
	 * @return string Base64url-encoded SHA-256 hash.
	 */
	public static function compute_code_challenge( $verifier ) {
		$hash = hash( 'sha256', $verifier, true );
		return rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );
	}

	// -------------------------------------------------------------------------
	// Connection health check
	// -------------------------------------------------------------------------

	/**
	 * Check the health of the current API key connection.
	 *
	 * Caches the result in a transient for 5 minutes.
	 *
	 * @return string One of: 'connected', 'degraded', 'disconnected', 'not_configured'.
	 */
	public function check_connection_health() {
		$api_key = get_option( 'mailodds_api_key', '' );
		if ( empty( $api_key ) ) {
			return 'not_configured';
		}

		$cached = get_transient( 'mailodds_connection_health' );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			$this->api_base . '/v1/health',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = 'degraded';
		} else {
			$status = wp_remote_retrieve_response_code( $response );
			if ( 200 === $status ) {
				$result = 'connected';
			} elseif ( 401 === $status ) {
				$result = 'disconnected';
			} else {
				$result = 'degraded';
			}
		}

		set_transient( 'mailodds_connection_health', $result, 300 );
		return $result;
	}

	// -------------------------------------------------------------------------
	// Step 1: Initiate connect (AJAX handler)
	// -------------------------------------------------------------------------

	/**
	 * Handle the "Connect to MailOdds" button click via AJAX.
	 *
	 * Registers the store with MailOdds, stores PKCE verifier + state
	 * in transients, and returns the authorization URL.
	 */
	public function initiate_connect() {
		check_ajax_referer( 'mailodds_connect_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
			return;
		}

		$store_url = home_url();

		// Require HTTPS
		if ( 0 !== strpos( $store_url, 'https://' ) ) {
			wp_send_json_error( array(
				'message' => 'One-click connect requires HTTPS. Please enable SSL on your site, or use the manual API key setup below.',
			) );
			return;
		}

		// Generate PKCE pair
		$verifier  = self::generate_code_verifier();
		$challenge = self::compute_code_challenge( $verifier );

		// Generate CSRF state
		$state = bin2hex( random_bytes( 16 ) );

		// Detect platform
		$platform     = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'wordpress';
		$redirect_uri = admin_url( 'admin-ajax.php?action=mailodds_connect_callback' );

		// Register with MailOdds backend
		$response = wp_remote_post(
			$this->api_base . '/v1/connect/setup',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'platform'     => $platform,
					'store_url'    => $store_url,
					'redirect_uri' => $redirect_uri,
				) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Could not reach MailOdds. ' . $response->get_error_message() ) );
			return;
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 !== $status_code ) {
			$msg = isset( $response_body['message'] ) ? $response_body['message'] : 'Setup failed (HTTP ' . $status_code . ')';
			wp_send_json_error( array( 'message' => $msg ) );
			return;
		}

		$setup_id = isset( $response_body['setup_id'] ) ? $response_body['setup_id'] : '';

		// Validate setup_id format (UUID or alphanumeric token)
		if ( empty( $setup_id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $setup_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid setup response from MailOdds.' ) );
			return;
		}

		// Store verifier and state keyed by setup_id to avoid tab collisions
		set_transient( 'mailodds_cv_' . $setup_id, $verifier, 600 );
		set_transient( 'mailodds_st_' . $setup_id, $state, 600 );
		set_transient( 'mailodds_setup_id', $setup_id, 600 );

		// Build authorize URL with PKCE params
		$authorize_url = $response_body['authorize_url']
			. '&code_challenge=' . rawurlencode( $challenge )
			. '&code_challenge_method=S256'
			. '&state=' . rawurlencode( $state );

		wp_send_json_success( array( 'redirect_url' => $authorize_url ) );
	}

	// -------------------------------------------------------------------------
	// Step 2: Handle callback redirect
	// -------------------------------------------------------------------------

	/**
	 * Check if the current admin page load is a callback redirect from MailOdds.
	 *
	 * This handles the case where admin-ajax.php receives the callback but
	 * the user's WP session may have expired and been re-authenticated.
	 */
	public function maybe_handle_callback_redirect() {
		if ( ! is_admin() ) {
			return;
		}

		// Check if this is a callback redirect (via admin URL with code param)
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- callback from external redirect, state param serves as CSRF
		if ( isset( $_GET['action'] ) && 'mailodds_connect_callback' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) && isset( $_GET['code'] ) ) {
		// phpcs:enable
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Permission denied.', 'MailOdds Connect', array( 'response' => 403 ) );
			}
			$this->handle_callback();
			exit;
		}
	}

	/**
	 * Handle the OAuth callback from MailOdds with the authorization code.
	 *
	 * Verifies state (CSRF), exchanges the code + PKCE verifier for API
	 * credentials, generates platform credentials, and saves everything.
	 */
	public function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.', 'MailOdds Connect', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- callback from external redirect, state param serves as CSRF
		$code     = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable

		if ( empty( $code ) || empty( $state ) ) {
			wp_die( 'Missing authorization parameters.', 'MailOdds Connect', array( 'response' => 400 ) );
		}

		$setup_id = get_transient( 'mailodds_setup_id' );
		if ( empty( $setup_id ) ) {
			wp_die(
				'Session expired. Please return to Settings &gt; MailOdds and click Connect again.',
				'MailOdds Connect',
				array( 'response' => 400 )
			);
		}

		// Verify state (constant-time comparison for CSRF protection)
		$stored_state = get_transient( 'mailodds_st_' . $setup_id );
		if ( empty( $stored_state ) || ! hash_equals( $stored_state, $state ) ) {
			wp_die( 'Invalid state parameter. Please try connecting again.', 'MailOdds Connect', array( 'response' => 400 ) );
		}

		// Retrieve PKCE verifier
		$verifier = get_transient( 'mailodds_cv_' . $setup_id );
		if ( empty( $verifier ) ) {
			wp_die(
				'Session expired. Please return to Settings &gt; MailOdds and click Connect again.',
				'MailOdds Connect',
				array( 'response' => 400 )
			);
		}

		// Generate platform credentials
		$platform    = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'wordpress';
		$credentials = array( 'store_url' => home_url() );

		if ( 'woocommerce' === $platform ) {
			$keys = self::generate_wc_keys_for_connect();
			if ( ! is_wp_error( $keys ) ) {
				$credentials['consumer_key']    = $keys['consumer_key'];
				$credentials['consumer_secret'] = $keys['consumer_secret'];
			}
		}

		// Exchange code + verifier for API key and store credentials
		$response = wp_remote_post(
			$this->api_base . '/v1/connect/token',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'setup_id'      => $setup_id,
					'code'          => $code,
					'code_verifier' => $verifier,
					'platform'      => $platform,
					'credentials'   => $credentials,
				) ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die(
				'Could not complete connection: ' . esc_html( $response->get_error_message() ),
				'MailOdds Connect',
				array( 'response' => 500 )
			);
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			$msg = isset( $response_body['message'] ) ? $response_body['message'] : 'Token exchange failed (HTTP ' . $status_code . ')';
			wp_die( 'Connection failed: ' . esc_html( $msg ), 'MailOdds Connect', array( 'response' => 400 ) );
		}

		// Save all returned config
		update_option( 'mailodds_api_key', $response_body['api_key'] );
		update_option( 'mailodds_store_id', $response_body['store_id'] );
		update_option( 'mailodds_webhook_secret', $response_body['webhook_secret'] );
		update_option( 'mailodds_pixel_uuid', $response_body['pixel_uuid'] );
		update_option( 'mailodds_store_connected', true );
		update_option( 'mailodds_connected_via', 'one_click_connect' );

		// Clean up transients
		delete_transient( 'mailodds_cv_' . $setup_id );
		delete_transient( 'mailodds_st_' . $setup_id );
		delete_transient( 'mailodds_setup_id' );
		delete_transient( 'mailodds_connection_health' );

		// Post-connect verification: test API call
		$api      = new MailOdds_API( $response_body['api_key'] );
		$test     = $api->validate( 'test@example.com' );
		$verified = ! is_wp_error( $test );

		// Redirect to settings with success message
		wp_safe_redirect( admin_url( 'options-general.php?page=mailodds&connected=1&verified=' . ( $verified ? '1' : '0' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Disconnect
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler for one-click disconnect.
	 *
	 * Calls /v1/connect/disconnect to revoke the auto-provisioned API key
	 * on the MailOdds side, then clears local options.
	 */
	public function ajax_disconnect() {
		check_ajax_referer( 'mailodds_connect_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
		}

		$api_key  = get_option( 'mailodds_api_key', '' );
		$store_id = get_option( 'mailodds_store_id', '' );

		// Notify backend if we have credentials
		if ( ! empty( $api_key ) && ! empty( $store_id ) ) {
			wp_remote_post(
				$this->api_base . '/v1/connect/disconnect',
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					),
					'body'    => wp_json_encode( array( 'store_id' => $store_id ) ),
					'timeout' => 15,
				)
			);
		}

		// Use existing handshake disconnect to clean up WC keys + local options
		$handshake = new MailOdds_Handshake();
		$handshake->disconnect();

		// Clean up one-click-specific options
		delete_option( 'mailodds_webhook_secret' );
		delete_option( 'mailodds_pixel_uuid' );
		delete_option( 'mailodds_connected_via' );
		delete_transient( 'mailodds_connection_health' );

		wp_send_json_success( array( 'message' => 'Disconnected successfully' ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Generate WC REST API keys for the one-click connect flow.
	 *
	 * Similar to MailOdds_Handshake::generate_wc_keys() but as a static
	 * method so it can be called without a configured API key.
	 *
	 * @return array|WP_Error Consumer key and secret, or error.
	 */
	private static function generate_wc_keys_for_connect() {
		global $wpdb;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'wc_not_active', 'WooCommerce is not active' );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'no_user', 'No user logged in' );
		}

		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();

		$data = array(
			'user_id'         => $user_id,
			'description'     => 'MailOdds One-Click Connect',
			'permissions'     => 'read',
			'consumer_key'    => wc_api_hash( $consumer_key ),
			'consumer_secret' => $consumer_secret,
			'truncated_key'   => substr( $consumer_key, -7 ),
		);

		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( 0 === $wpdb->insert_id ) {
			return new WP_Error( 'key_creation_failed', 'Failed to create WC API keys' );
		}

		update_option( 'mailodds_wc_key_id', $wpdb->insert_id );

		return array(
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		);
	}
}
