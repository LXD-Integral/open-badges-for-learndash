<?php
/**
 * CredentialStore tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Credentials\CredentialStore;
use OBLD\Provider;
use PHPUnit\Framework\TestCase;

/**
 * Encryption round-trips, write-only semantics, and the rotated-salts path.
 */
final class CredentialStoreTest extends TestCase {

	private CredentialStore $store;

	protected function setUp(): void {
		obld_test_reset();
		$this->store = new CredentialStore();
	}

	public function test_round_trip(): void {
		$this->store->save( Provider::OBF, 'MYCLIENT', 'my-very-secret' );

		$this->assertSame( 'MYCLIENT', $this->store->client_id( Provider::OBF ) );
		$this->assertSame( 'my-very-secret', $this->store->secret( Provider::OBF ) );
		$this->assertTrue( $this->store->is_configured( Provider::OBF ) );
		$this->assertFalse( $this->store->needs_reentry( Provider::OBF ) );
	}

	public function test_secret_is_not_stored_in_plaintext(): void {
		$this->store->save( Provider::OBF, 'MYCLIENT', 'my-very-secret' );

		$raw = serialize( get_option( CredentialStore::option_key( Provider::OBF ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$this->assertStringNotContainsString( 'my-very-secret', $raw );
	}

	public function test_blank_secret_keeps_existing_secret(): void {
		$this->store->save( Provider::OBF, 'MYCLIENT', 'original-secret' );
		$this->store->save( Provider::OBF, 'NEWCLIENT', null );

		$this->assertSame( 'NEWCLIENT', $this->store->client_id( Provider::OBF ) );
		$this->assertSame( 'original-secret', $this->store->secret( Provider::OBF ) );
	}

	public function test_providers_are_isolated(): void {
		$this->store->save( Provider::OBF, 'OBF-ID', 'obf-secret' );
		$this->store->save( Provider::CanCred, 'CC-ID', 'cancred-secret' );

		$this->assertSame( 'obf-secret', $this->store->secret( Provider::OBF ) );
		$this->assertSame( 'cancred-secret', $this->store->secret( Provider::CanCred ) );
	}

	public function test_rotated_salts_flag_reentry_instead_of_failing(): void {
		$this->store->save( Provider::OBF, 'MYCLIENT', 'my-very-secret' );

		$GLOBALS['obld_test_salts']['auth'] = 'rotated-salt';

		$this->assertNull( $this->store->secret( Provider::OBF ) );
		$this->assertTrue( $this->store->needs_reentry( Provider::OBF ) );
		$this->assertTrue( $this->store->has_secret( Provider::OBF ) );
	}

	public function test_saving_invalidates_cached_token_and_status(): void {
		set_transient( 'obld_token_obf', 'cached-token', 18000 );
		update_option( 'obld_connection_status', array( 'obf' => array( 'status' => 'connected', 'message' => '', 'checked_at' => 123 ) ) );

		$this->store->save( Provider::OBF, 'MYCLIENT', 'new-secret' );

		$this->assertFalse( get_transient( 'obld_token_obf' ) );
		$status = get_option( 'obld_connection_status' );
		$this->assertArrayNotHasKey( 'obf', $status );
	}

	public function test_unconfigured_provider_reports_cleanly(): void {
		$this->assertSame( '', $this->store->client_id( Provider::CanCred ) );
		$this->assertNull( $this->store->secret( Provider::CanCred ) );
		$this->assertFalse( $this->store->is_configured( Provider::CanCred ) );
		$this->assertFalse( $this->store->needs_reentry( Provider::CanCred ) );
	}
}
