<?php
/**
 * WooCommerce store connection handshake.
 *
 * Generates WC REST API consumer keys and sends them to MailOdds
 * backend via the /internal/store-handshake endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Handshake {

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private $api_base;

	/**
	 * Handshake secret.
	 *
	 * @var string
	 */
	private $handshake_secret;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api_key          = get_option( 'mailodds_api_key', '' );
		$this->api_base         = get_option( 'mailodds_api_base', 'https://api.mailodds.com' );
		$this->handshake_secret = get_option( 'mailodds_handshake_secret', '' );
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Perform the store handshake.
	 *
	 * Creates WC API consumer keys and sends them to MailOdds.
	 *
	 * @return array|WP_Error Store connection data or error.
	 */
	public function connect() {
		if ( ! $this->is_woocommerce_active() ) {
			return new WP_Error( 'wc_not_active', 'WooCommerce is not active' );
		}

		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', 'MailOdds API key not configured' );
		}

		// Generate WC consumer keys
		$keys = $this->generate_wc_keys();
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		// Send to MailOdds
		$result = $this->send_handshake( $keys );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_option( 'mailodds_store_connected', true );
		update_option( 'mailodds_store_id', $result['store_id'] );

		return $result;
	}

	/**
	 * Generate WooCommerce REST API keys.
	 *
	 * @return array|WP_Error Consumer key and secret, or error.
	 */
	private function generate_wc_keys() {
		global $wpdb;

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'no_user', 'No user logged in' );
		}

		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();

		$data = array(
			'user_id'         => $user_id,
			'description'     => 'MailOdds Product Sync',
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

		// Track key ID for revocation on disconnect/uninstall
		update_option( 'mailodds_wc_key_id', $wpdb->insert_id );

		return array(
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		);
	}

	/**
	 * Send the handshake to MailOdds backend.
	 *
	 * @param array $keys Consumer key and secret.
	 * @return array|WP_Error Response data or error.
	 */
	private function send_handshake( $keys ) {
		$store_url  = home_url();
		$store_name = get_bloginfo( 'name' );

		$body = array(
			'platform'        => 'woocommerce',
			'store_url'       => $store_url,
			'store_name'      => $store_name,
			'consumer_key'    => $keys['consumer_key'],
			'consumer_secret' => $keys['consumer_secret'],
			'api_key'         => $this->api_key,
		);

		$response = wp_remote_post(
			$this->api_base . '/internal/store-handshake',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->handshake_secret,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code && 201 !== $status_code ) {
			$error_msg = isset( $response_body['error'] ) ? $response_body['error'] : 'Handshake failed';
			return new WP_Error( 'handshake_failed', $error_msg );
		}

		return $response_body;
	}

	/**
	 * Check if the store is currently connected.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return (bool) get_option( 'mailodds_store_connected', false );
	}

	/**
	 * Get the connected store ID.
	 *
	 * @return string
	 */
	public function get_store_id() {
		return get_option( 'mailodds_store_id', '' );
	}

	/**
	 * Disconnect the store.
	 */
	public function disconnect() {
		$store_id = get_option( 'mailodds_store_id', '' );

		if ( ! empty( $store_id ) && ! empty( $this->api_key ) ) {
			$response = wp_remote_request(
				$this->api_base . '/api/v1/stores/' . $store_id,
				array(
					'method'  => 'DELETE',
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->api_key,
					),
					'timeout' => 15,
				)
			);

			// Only clear local state if backend confirmed or store already gone
			if ( ! is_wp_error( $response ) ) {
				$status = wp_remote_retrieve_response_code( $response );
				if ( $status !== 200 && $status !== 204 && $status !== 404 ) {
					return new WP_Error(
						'disconnect_failed',
						'Failed to disconnect store on backend (HTTP ' . $status . ')'
					);
				}
			} else {
				return $response;
			}
		}

		// Revoke WC API key
		$this->revoke_wc_key();

		delete_option( 'mailodds_store_connected' );
		delete_option( 'mailodds_store_id' );
		delete_option( 'mailodds_handshake_secret' );
		delete_option( 'mailodds_api_base' );

		return true;
	}

	/**
	 * Revoke the WC REST API key created during handshake.
	 */
	private function revoke_wc_key() {
		global $wpdb;

		$key_id = get_option( 'mailodds_wc_key_id', 0 );
		if ( $key_id ) {
			$wpdb->delete(
				$wpdb->prefix . 'woocommerce_api_keys',
				array( 'key_id' => $key_id ),
				array( '%d' )
			);
			delete_option( 'mailodds_wc_key_id' );
		}
	}
}
