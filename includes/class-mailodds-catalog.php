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

		// Product sync hooks
		add_action( 'woocommerce_new_product', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_delete_product', array( $this, 'on_product_delete' ), 10, 1 );
		add_action( 'woocommerce_trash_product', array( $this, 'on_product_delete' ), 10, 1 );

		// Order sync hooks
		add_action( 'woocommerce_new_order', array( $this, 'on_order_change' ), 10, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'on_order_change' ), 10, 1 );

		// Ensure WooCommerce webhooks are registered after WC initializes
		add_action( 'woocommerce_init', array( $this, 'ensure_webhooks_registered' ) );
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
	 * Handle order created or updated.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function on_order_change( $order_id ) {
		$this->notify_sync( 'order_changed', $order_id );
	}

	/**
	 * Ensure WooCommerce webhooks are registered for MailOdds sync topics.
	 *
	 * Creates webhooks for product and order events if they do not already
	 * exist. Uses the MailOdds REST webhook endpoint as the delivery URL.
	 * Idempotent: skips creation when matching webhooks are already active.
	 */
	public function ensure_webhooks_registered() {
		if ( ! function_exists( 'wc_get_webhooks' ) ) {
			return;
		}

		// Only run once per request
		static $ran = false;
		if ( $ran ) {
			return;
		}
		$ran = true;

		// Debounce: only check once per hour via transient
		if ( false !== get_transient( 'mailodds_webhooks_checked' ) ) {
			return;
		}

		$webhook_secret = get_option( 'mailodds_webhook_secret', '' );
		if ( empty( $webhook_secret ) ) {
			return;
		}

		$delivery_url = rest_url( 'mailodds/v1/webhook' );

		$topics = array(
			'product.created',
			'product.updated',
			'order.created',
			'order.updated',
		);

		// Get existing MailOdds webhooks
		$existing_topics = array();
		$data_store = \WC_Data_Store::load( 'webhook' );
		$webhook_ids = $data_store->search_webhooks( array(
			'status' => 'active',
			'limit'  => 50,
		) );

		foreach ( $webhook_ids as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( $webhook && false !== strpos( $webhook->get_delivery_url(), 'mailodds/v1/webhook' ) ) {
				$existing_topics[] = $webhook->get_topic();
			}
		}

		// Register missing topics
		foreach ( $topics as $topic ) {
			if ( in_array( $topic, $existing_topics, true ) ) {
				continue;
			}

			$webhook = new \WC_Webhook();
			$webhook->set_name( 'MailOdds - ' . $topic );
			$webhook->set_user_id( get_current_user_id() ? get_current_user_id() : 1 );
			$webhook->set_topic( $topic );
			$webhook->set_secret( $webhook_secret );
			$webhook->set_delivery_url( $delivery_url );
			$webhook->set_status( 'active' );
			$webhook->save();
		}

		set_transient( 'mailodds_webhooks_checked', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Get all MailOdds webhooks and their statuses.
	 *
	 * @return array List of webhook info arrays with keys: id, topic, status, delivery_url, failure_count.
	 */
	public function get_mailodds_webhooks() {
		$webhooks = array();

		if ( ! function_exists( 'wc_get_webhook' ) ) {
			return $webhooks;
		}

		$data_store  = \WC_Data_Store::load( 'webhook' );
		$webhook_ids = $data_store->search_webhooks( array( 'limit' => 100 ) );

		foreach ( $webhook_ids as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( ! $webhook ) {
				continue;
			}

			if ( false === strpos( $webhook->get_delivery_url(), 'mailodds/v1/webhook' ) ) {
				continue;
			}

			$webhooks[] = array(
				'id'            => $webhook->get_id(),
				'topic'         => $webhook->get_topic(),
				'status'        => $webhook->get_status(),
				'delivery_url'  => $webhook->get_delivery_url(),
				'failure_count' => $webhook->get_failure_count(),
			);
		}

		return $webhooks;
	}

	/**
	 * Notify MailOdds to trigger incremental sync.
	 *
	 * Uses a non-blocking approach to avoid slowing down WC admin.
	 *
	 * @param string $event       Event type (product_changed, product_deleted, order_changed).
	 * @param int    $resource_id WooCommerce product or order ID.
	 */
	private function notify_sync( $event, $resource_id ) {
		if ( empty( $this->store_id ) ) {
			return;
		}

		// Debounce: don't fire for bulk operations within the same request
		static $notified = array();
		$key = $event . '_' . $resource_id;
		if ( isset( $notified[ $key ] ) ) {
			return;
		}
		$notified[ $key ] = true;

		$body = array(
			'sync_type' => 'incremental',
			'trigger'   => $event,
		);

		// Use the appropriate resource key based on event type
		if ( 0 === strpos( $event, 'order_' ) ) {
			$body['order_id'] = $resource_id;
		} else {
			$body['product_id'] = $resource_id;
		}

		wp_remote_post(
			$this->api_base . '/api/v1/stores/' . $this->store_id . '/sync',
			array(
				'headers'  => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'     => wp_json_encode( $body ),
				'timeout'  => 5,
				'blocking' => false,
			)
		);
	}
}
