<?php
/**
 * Tests for MailOdds_Sender class.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

class SenderTest extends MailOdds_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof WP_Error;
		} );
	}

	public function test_hooks_pre_wp_mail_when_enabled() {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_sending_enabled' === $name ) {
				return true;
			}
			return $default;
		} );
		Filters\expectAdded( 'pre_wp_mail' )->once();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Sender( $api );
	}

	public function test_does_not_hook_when_disabled() {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			return $default;
		} );
		Filters\expectAdded( 'pre_wp_mail' )->never();

		$api = Mockery::mock( 'MailOdds_API' );
		new MailOdds_Sender( $api );
	}

	public function test_falls_back_when_no_api_key() {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_sending_enabled' === $name ) {
				return true;
			}
			return $default;
		} );

		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( false );

		$sender = new MailOdds_Sender( $api );
		$result = $sender->intercept_wp_mail( null, array(
			'to'      => 'user@example.com',
			'subject' => 'Test',
			'message' => 'Hello',
		) );

		$this->assertNull( $result );
	}

	public function test_delivers_via_api_on_success() {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_sending_enabled' === $name ) {
				return true;
			}
			if ( 'mailodds_sending_from' === $name ) {
				return 'noreply@example.com';
			}
			if ( 'mailodds_sending_from_name' === $name ) {
				return 'Example';
			}
			if ( 'admin_email' === $name ) {
				return 'admin@example.com';
			}
			if ( 'blogname' === $name ) {
				return 'TestSite';
			}
			return $default;
		} );

		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'deliver' )->once()->andReturn( array( 'delivery' => array( 'id' => '123' ) ) );

		$sender = new MailOdds_Sender( $api );
		$result = $sender->intercept_wp_mail( null, array(
			'to'      => 'user@example.com',
			'subject' => 'Test',
			'message' => 'Hello',
		) );

		$this->assertTrue( $result );
	}

	public function test_falls_back_on_api_error_with_failover() {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_sending_enabled' === $name ) {
				return true;
			}
			if ( 'mailodds_sending_failover' === $name ) {
				return true;
			}
			if ( 'admin_email' === $name ) {
				return 'admin@example.com';
			}
			if ( 'blogname' === $name ) {
				return 'TestSite';
			}
			return $default;
		} );

		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'deliver' )->once()->andReturn( new WP_Error( 'fail', 'Error' ) );

		$sender = new MailOdds_Sender( $api );
		$result = $sender->intercept_wp_mail( null, array(
			'to'      => 'user@example.com',
			'subject' => 'Test',
			'message' => 'Hello',
		) );

		$this->assertNull( $result );
	}

	public function test_parses_html_content_type_header() {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			if ( 'mailodds_sending_enabled' === $name ) {
				return true;
			}
			if ( 'admin_email' === $name ) {
				return 'admin@example.com';
			}
			if ( 'blogname' === $name ) {
				return 'TestSite';
			}
			return $default;
		} );

		$api = Mockery::mock( 'MailOdds_API' );
		$api->shouldReceive( 'has_key' )->andReturn( true );
		$api->shouldReceive( 'deliver' )->once()->with( Mockery::on( function ( $data ) {
			return isset( $data['html'] ) && ! isset( $data['text'] );
		} ) )->andReturn( array( 'delivery' => array( 'id' => '123' ) ) );

		$sender = new MailOdds_Sender( $api );
		$result = $sender->intercept_wp_mail( null, array(
			'to'      => 'user@example.com',
			'subject' => 'Test',
			'message' => '<h1>Hello</h1>',
			'headers' => 'Content-Type: text/html',
		) );

		$this->assertTrue( $result );
	}
}
