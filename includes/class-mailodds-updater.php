<?php
/**
 * MailOdds GitHub auto-updater.
 *
 * Checks the mailodds/wordpress-plugin GitHub repository for new releases
 * and provides update information to the WordPress plugin update system.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Updater {

	/**
	 * GitHub repository.
	 */
	private $repo = 'mailodds/wordpress-plugin';

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->basename = plugin_basename( MAILODDS_PLUGIN_FILE );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
	}

	/**
	 * Check GitHub for a newer release.
	 *
	 * @param object $transient Update transient data.
	 * @return object
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = ltrim( $release['tag_name'], 'v' );

		if ( version_compare( MAILODDS_VERSION, $remote_version, '<' ) ) {
			$item = (object) array(
				'slug'        => 'mailodds',
				'plugin'      => $this->basename,
				'new_version' => $remote_version,
				'url'         => 'https://github.com/' . $this->repo,
				'package'     => isset( $release['zipball_url'] ) ? $release['zipball_url'] : '',
			);
			$transient->response[ $this->basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the WordPress plugin details modal.
	 *
	 * @param false|object|array $result Plugin info result.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || 'mailodds' !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$info = (object) array(
			'name'          => 'MailOdds Email Validation',
			'slug'          => 'mailodds',
			'version'       => ltrim( $release['tag_name'], 'v' ),
			'author'        => '<a href="https://mailodds.com">MailOdds</a>',
			'homepage'      => 'https://mailodds.com/integrations/wordpress',
			'download_link' => isset( $release['zipball_url'] ) ? $release['zipball_url'] : '',
			'sections'      => array(
				'description' => 'Email validation for WordPress forms, WooCommerce, and user registration.',
				'changelog'   => isset( $release['body'] ) ? $release['body'] : '',
			),
		);

		return $info;
	}

	/**
	 * Fetch the latest release from GitHub.
	 *
	 * Cached in a transient for 12 hours.
	 *
	 * @return array|false Release data or false on error.
	 */
	private function get_latest_release() {
		$cache_key = 'mailodds_github_release';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
		$response = wp_remote_get( $url, array(
			'timeout'    => 10,
			'headers'    => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'MailOdds-WordPress/' . MAILODDS_VERSION,
			),
			'sslverify'  => true,
		) );

		if ( is_wp_error( $response ) ) {
			// Graceful SSL failure on shared hosts with old CA bundles
			$msg = $response->get_error_message();
			if ( false !== strpos( $msg, 'cURL error 60' ) || false !== strpos( $msg, 'SSL' ) ) {
				error_log( 'MailOdds: GitHub update check skipped due to SSL error: ' . $msg );
			}
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['tag_name'] ) ) {
			return false;
		}

		set_transient( $cache_key, $body, 12 * HOUR_IN_SECONDS );

		return $body;
	}
}
