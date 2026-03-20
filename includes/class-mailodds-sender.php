<?php
/**
 * MailOdds email sender - wp_mail replacement.
 *
 * Hooks into WordPress email sending to route transactional emails through
 * the MailOdds API for better deliverability and tracking.
 *
 * Requires a verified sending domain in the MailOdds dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Sender {

	/**
	 * API client.
	 *
	 * @var MailOdds_API
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param MailOdds_API $api API client.
	 */
	public function __construct( MailOdds_API $api ) {
		$this->api = $api;

		// Only hook if sending is enabled
		if ( get_option( 'mailodds_sending_enabled', false ) ) {
			add_filter( 'pre_wp_mail', array( $this, 'intercept_wp_mail' ), 10, 2 );
		}
	}

	/**
	 * Intercept wp_mail and route through MailOdds API.
	 *
	 * @param null|bool $return Short-circuit return value.
	 * @param array     $atts   wp_mail attributes (to, subject, message, headers, attachments).
	 * @return bool|null True on success, null to fall back to default.
	 */
	public function intercept_wp_mail( $return, $atts ) {
		if ( ! $this->api->has_key() ) {
			return null; // Fall back to default wp_mail
		}

		$to      = isset( $atts['to'] ) ? $atts['to'] : '';
		$subject = isset( $atts['subject'] ) ? $atts['subject'] : '';
		$message = isset( $atts['message'] ) ? $atts['message'] : '';
		$headers = isset( $atts['headers'] ) ? $atts['headers'] : '';

		// Parse recipients
		$recipients = $this->parse_recipients( $to );
		if ( empty( $recipients ) ) {
			return null;
		}

		// Parse headers for from, content-type, cc, bcc, reply-to
		$parsed = $this->parse_headers( $headers );

		// Determine from address
		$from_email = ! empty( $parsed['from_email'] ) ? $parsed['from_email'] : get_option( 'mailodds_sending_from', get_option( 'admin_email' ) );
		$from_name  = ! empty( $parsed['from_name'] ) ? $parsed['from_name'] : get_option( 'mailodds_sending_from_name', get_option( 'blogname' ) );

		// Determine content type
		$content_type = ! empty( $parsed['content_type'] ) ? $parsed['content_type'] : 'text/plain';
		$is_html      = ( false !== strpos( $content_type, 'html' ) );

		// Build delivery payload
		$delivery = array(
			'from'    => $from_name ? sprintf( '%s <%s>', $from_name, $from_email ) : $from_email,
			'to'      => $recipients,
			'subject' => $subject,
		);

		if ( $is_html ) {
			$delivery['html'] = $message;
		} else {
			$delivery['text'] = $message;
		}

		// Add CC/BCC/Reply-To if present
		if ( ! empty( $parsed['cc'] ) ) {
			$delivery['cc'] = $parsed['cc'];
		}
		if ( ! empty( $parsed['bcc'] ) ) {
			$delivery['bcc'] = $parsed['bcc'];
		}
		if ( ! empty( $parsed['reply_to'] ) ) {
			$delivery['reply_to'] = $parsed['reply_to'];
		}

		// Custom headers
		if ( ! empty( $parsed['custom_headers'] ) ) {
			$delivery['headers'] = $parsed['custom_headers'];
		}

		$result = $this->api->deliver( $delivery );

		if ( is_wp_error( $result ) ) {
			// Log the failure but allow WordPress to handle it
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'MailOdds deliver failed: ' . $result->get_error_message() );
			}

			// Fall back to default wp_mail if failover is enabled
			if ( get_option( 'mailodds_sending_failover', true ) ) {
				return null;
			}
			return false;
		}

		return true;
	}

	/**
	 * Parse recipients from wp_mail $to parameter.
	 *
	 * @param string|array $to Recipients.
	 * @return array Parsed recipients [{email, name}].
	 */
	private function parse_recipients( $to ) {
		if ( ! is_array( $to ) ) {
			$to = explode( ',', $to );
		}

		$recipients = array();
		foreach ( $to as $recipient ) {
			$recipient = trim( $recipient );
			if ( empty( $recipient ) ) {
				continue;
			}

			// Handle "Name <email>" format
			if ( preg_match( '/(.*)<(.+)>/', $recipient, $matches ) ) {
				$recipients[] = array(
					'email' => sanitize_email( trim( $matches[2] ) ),
					'name'  => sanitize_text_field( trim( $matches[1] ) ),
				);
			} else {
				$email = sanitize_email( $recipient );
				if ( ! empty( $email ) ) {
					$recipients[] = array( 'email' => $email );
				}
			}
		}

		return $recipients;
	}

	/**
	 * Parse wp_mail headers string/array.
	 *
	 * @param string|array $headers Raw headers.
	 * @return array Parsed header components.
	 */
	private function parse_headers( $headers ) {
		$parsed = array(
			'from_email'     => '',
			'from_name'      => '',
			'content_type'   => '',
			'cc'             => array(),
			'bcc'            => array(),
			'reply_to'       => '',
			'custom_headers' => array(),
		);

		if ( empty( $headers ) ) {
			return $parsed;
		}

		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}

		foreach ( $headers as $header ) {
			$header = trim( $header );
			if ( empty( $header ) ) {
				continue;
			}

			if ( false === strpos( $header, ':' ) ) {
				continue;
			}

			list( $name, $value ) = explode( ':', $header, 2 );
			$name  = strtolower( trim( $name ) );
			$value = trim( $value );

			switch ( $name ) {
				case 'from':
					if ( preg_match( '/(.*)<(.+)>/', $value, $matches ) ) {
						$parsed['from_name']  = sanitize_text_field( trim( $matches[1] ) );
						$parsed['from_email'] = sanitize_email( trim( $matches[2] ) );
					} else {
						$parsed['from_email'] = sanitize_email( $value );
					}
					break;

				case 'content-type':
					$parsed['content_type'] = $value;
					break;

				case 'cc':
					$parsed['cc'] = array_merge( $parsed['cc'], array_map( 'trim', explode( ',', $value ) ) );
					break;

				case 'bcc':
					$parsed['bcc'] = array_merge( $parsed['bcc'], array_map( 'trim', explode( ',', $value ) ) );
					break;

				case 'reply-to':
					$parsed['reply_to'] = sanitize_email( $value );
					break;

				default:
					$parsed['custom_headers'][ $name ] = sanitize_text_field( $value );
					break;
			}
		}

		return $parsed;
	}
}
