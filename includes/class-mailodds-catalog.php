<?php
/**
 * WooCommerce product catalog sync hooks.
 *
 * Listens for product changes and notifies MailOdds for incremental sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Catalog {

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
	 * Connected store ID.
	 *
	 * @var string
	 */
	private $store_id;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api_key  = get_option( 'mailodds_api_key', '' );
		$this->api_base = get_option( 'mailodds_api_base', 'https://api.mailodds.com' );
		$this->store_id = get_option( 'mailodds_store_id', '' );
	}

	/**
	 * Register WooCommerce hooks.
	 */
	public function register_hooks() {
		if ( ! $this->is_connected() ) {
			return;
		}

		add_action( 'woocommerce_new_product', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_delete_product', array( $this, 'on_product_delete' ), 10, 1 );
		add_action( 'woocommerce_trash_product', array( $this, 'on_product_delete' ), 10, 1 );
	}

	/**
	 * Check if store is connected.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return ! empty( $this->store_id )
			&& ! empty( $this->api_key )
			&& get_option( 'mailodds_store_connected', false );
	}

	/**
	 * Handle product created or updated.
	 *
	 * @param int $product_id WooCommerce product ID.
	 */
	public function on_product_change( $product_id ) {
		$this->notify_sync( 'product_changed', $product_id );
	}

	/**
	 * Handle product deleted.
	 *
	 * @param int $product_id WooCommerce product ID.
	 */
	public function on_product_delete( $product_id ) {
		$this->notify_sync( 'product_deleted', $product_id );
	}

	/**
	 * Notify MailOdds to trigger incremental sync.
	 *
	 * Uses a non-blocking approach to avoid slowing down WC admin.
	 *
	 * @param string $event      Event type (product_changed, product_deleted).
	 * @param int    $product_id WooCommerce product ID.
	 */
	private function notify_sync( $event, $product_id ) {
		if ( empty( $this->store_id ) ) {
			return;
		}

		// Debounce: don't fire for bulk operations within the same request
		static $notified = array();
		$key = $event . '_' . $product_id;
		if ( isset( $notified[ $key ] ) ) {
			return;
		}
		$notified[ $key ] = true;

		wp_remote_post(
			$this->api_base . '/api/v1/stores/' . $this->store_id . '/sync',
			array(
				'headers'  => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'     => wp_json_encode( array(
					'sync_type'  => 'incremental',
					'trigger'    => $event,
					'product_id' => $product_id,
				) ),
				'timeout'  => 5,
				'blocking' => false,
			)
		);
	}
}
