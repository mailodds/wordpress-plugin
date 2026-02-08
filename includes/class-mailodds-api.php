<?php
/**
 * MailOdds API client.
 *
 * Lightweight wrapper around the MailOdds REST API using WordPress HTTP functions.
 * Includes transient-based caching to minimize API calls and credit usage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_API {

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 10;

	/**
	 * Cache TTL in seconds (24 hours).
	 *
	 * @var int
	 */
	private $cache_ttl = DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Check if an API key is configured.
	 *
	 * @return bool
	 */
	public function has_key() {
		return ! empty( $this->api_key );
	}

	/**
	 * Check if running in test mode.
	 *
	 * @return bool
	 */
	public function is_test_mode() {
		return 0 === strpos( $this->api_key, 'mo_test_' );
	}

	/**
	 * Validate a single email address.
	 *
	 * Uses transient cache to avoid re-validating the same email within 24 hours.
	 *
	 * @param string $email   Email address.
	 * @param array  $options {
	 *     Optional settings.
	 *
	 *     @type string $depth      Validation depth: 'standard' or 'enhanced'. Default 'enhanced'.
	 *     @type int    $policy_id  Policy ID to apply. Default none.
	 *     @type bool   $skip_cache Skip transient cache. Default false.
	 * }
	 * @return array|WP_Error Validation result or error.
	 */
	public function validate( $email, $options = array() ) {
		$email = sanitize_email( $email );
		if ( empty( $email ) ) {
			return new WP_Error( 'mailodds_invalid_email', __( 'Invalid email address.', 'mailodds' ) );
		}

		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds' ) );
		}

		$depth      = isset( $options['depth'] ) ? $options['depth'] : get_option( 'mailodds_depth', 'enhanced' );
		$policy_id  = isset( $options['policy_id'] ) ? absint( $options['policy_id'] ) : absint( get_option( 'mailodds_policy_id', 0 ) );
		$skip_cache = ! empty( $options['skip_cache'] );

		// Check transient cache
		if ( ! $skip_cache ) {
			$cache_key = $this->cache_key( $email, $depth );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				$cached['_cached'] = true;
				return $cached;
			}
		}

		// Build request body
		$body = array( 'email' => $email );
		if ( 'standard' === $depth ) {
			$body['depth'] = 'standard';
		}
		if ( $policy_id > 0 ) {
			$body['policy_id'] = $policy_id;
		}

		$result = $this->post( '/v1/validate', $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = $result;

		// Cache successful results
		if ( ! $skip_cache && isset( $data['status'] ) ) {
			$cache_key = $this->cache_key( $email, $depth );
			set_transient( $cache_key, $data, $this->cache_ttl );
		}

		// Track local stats
		$this->track_validation( $data );

		return $data;
	}

	/**
	 * Validate multiple email addresses.
	 *
	 * @param array $emails  List of email addresses.
	 * @param array $options {
	 *     Optional settings.
	 *
	 *     @type string $depth     Validation depth. Default 'enhanced'.
	 *     @type int    $policy_id Policy ID. Default none.
	 * }
	 * @return array|WP_Error Array of results or error.
	 */
	public function validate_batch( $emails, $options = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds' ) );
		}

		$emails = array_map( 'sanitize_email', $emails );
		$emails = array_filter( $emails );

		if ( empty( $emails ) ) {
			return new WP_Error( 'mailodds_no_emails', __( 'No valid emails provided.', 'mailodds' ) );
		}

		$depth     = isset( $options['depth'] ) ? $options['depth'] : get_option( 'mailodds_depth', 'enhanced' );
		$policy_id = isset( $options['policy_id'] ) ? absint( $options['policy_id'] ) : absint( get_option( 'mailodds_policy_id', 0 ) );

		$body = array( 'emails' => array_values( $emails ) );
		if ( 'standard' === $depth ) {
			$body['depth'] = 'standard';
		}
		if ( $policy_id > 0 ) {
			$body['policy_id'] = $policy_id;
		}

		$result = $this->post( '/v1/validate/batch', $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$results = isset( $result['results'] ) ? $result['results'] : array();

		// Cache each result individually
		foreach ( $results as $item ) {
			if ( isset( $item['email'], $item['status'] ) ) {
				$cache_key = $this->cache_key( $item['email'], $depth );
				set_transient( $cache_key, $item, $this->cache_ttl );
				$this->track_validation( $item );
			}
		}

		return $results;
	}

	/**
	 * Make a POST request to the MailOdds API.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Request body.
	 * @return array|WP_Error Decoded response or error.
	 */
	private function post( $endpoint, $body ) {
		$url = MAILODDS_API_BASE . $endpoint;

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'MailOdds-WordPress/' . MAILODDS_VERSION,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => $this->timeout,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error'] ) ? $body['error'] : __( 'API request failed.', 'mailodds' );
			return new WP_Error( 'mailodds_api_error', $message, array( 'status' => $code ) );
		}

		return $body;
	}

	/**
	 * Generate a cache key for an email/depth pair.
	 *
	 * @param string $email Email address.
	 * @param string $depth Validation depth.
	 * @return string Transient key.
	 */
	private function cache_key( $email, $depth ) {
		return 'mailodds_' . substr( hash( 'sha256', strtolower( $email ) . ':' . $depth ), 0, 16 );
	}

	/**
	 * Track validation result in local stats.
	 *
	 * Stores daily counters in a WordPress option for the dashboard widget.
	 *
	 * @param array $result Validation result.
	 */
	private function track_validation( $result ) {
		$today = current_time( 'Y-m-d' );
		$stats = get_option( 'mailodds_daily_stats', array() );

		if ( ! isset( $stats[ $today ] ) ) {
			$stats[ $today ] = array(
				'total'       => 0,
				'valid'       => 0,
				'invalid'     => 0,
				'catch_all'   => 0,
				'unknown'     => 0,
				'do_not_mail' => 0,
			);
		}

		$stats[ $today ]['total']++;
		$status = isset( $result['status'] ) ? $result['status'] : 'unknown';
		if ( isset( $stats[ $today ][ $status ] ) ) {
			$stats[ $today ][ $status ]++;
		}

		// Keep only last 30 days
		$cutoff = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		foreach ( array_keys( $stats ) as $date ) {
			if ( $date < $cutoff ) {
				unset( $stats[ $date ] );
			}
		}

		update_option( 'mailodds_daily_stats', $stats, false );
	}
}
