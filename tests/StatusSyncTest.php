<?php
/**
 * StatusSync tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Credentials\CredentialStore;
use OBLD\Issuance\AwardRepository;
use OBLD\Issuance\IssuanceService;
use OBLD\Issuance\StatusSync;
use OBLD\Log\Logger;
use OBLD\Provider;
use PHPUnit\Framework\TestCase;

/**
 * Award repository double for the status refresh: canned per-provider rows,
 * recorded status writes.
 */
final class FakeStatusAwardRepository extends AwardRepository {

	/**
	 * Issued rows per provider value.
	 *
	 * @var array<string, array<int, object>>
	 */
	public array $issued = array();

	/**
	 * record_status() calls, in order.
	 *
	 * @var array<int, array{id: int, revoked: bool, expires_on: string|null, received: bool, in_passport: bool}>
	 */
	public array $recorded = array();

	/**
	 * Providers stamped via mark_status_checked(), in order.
	 *
	 * @var array<int, string>
	 */
	public array $stamped = array();

	/**
	 * Canned rows.
	 *
	 * @param Provider $provider Provider.
	 *
	 * @return array<int, object>
	 */
	public function issued_for_provider( Provider $provider ): array {
		return $this->issued[ $provider->value ] ?? array();
	}

	/**
	 * Record a status write.
	 *
	 * @param int         $id          Award id.
	 * @param bool        $revoked     Revoked.
	 * @param string|null $expires_on  Expiry.
	 * @param bool        $received    Received.
	 * @param bool        $in_passport In Passport.
	 */
	public function record_status( int $id, bool $revoked, ?string $expires_on, bool $received, bool $in_passport ): void {
		$this->recorded[] = array(
			'id'          => $id,
			'revoked'     => $revoked,
			'expires_on'  => $expires_on,
			'received'    => $received,
			'in_passport' => $in_passport,
		);
	}

	/**
	 * Record a provider stamp.
	 *
	 * @param Provider $provider Provider.
	 */
	public function mark_status_checked( Provider $provider ): void {
		$this->stamped[] = $provider->value;
	}
}

/**
 * The read-back half of issuance: one listing per provider, compared against
 * local awards, writing only what changed.
 */
final class StatusSyncTest extends TestCase {

	private FakeStatusAwardRepository $awards;
	private CredentialStore $store;

	protected function setUp(): void {
		obld_test_reset();

		$this->awards = new FakeStatusAwardRepository();
		$this->store  = new CredentialStore();
		$this->store->save( Provider::CanCred, 'client-id', 'client-secret' );
	}

	private function sync( MockTransport $transport ): StatusSync {
		return new StatusSync( $this->store, $this->awards, new Logger(), $transport );
	}

	/**
	 * A local issued award row as issued_for_provider() returns it.
	 *
	 * @param int                  $id        Award id.
	 * @param array<string, mixed> $overrides Column overrides.
	 */
	private static function award( int $id, array $overrides = array() ): object {
		return (object) array_merge(
			array(
				'id'                => $id,
				'assertion_id'      => 'ASSERT-' . $id,
				'provider_event_id' => 'EVENT-' . $id,
				'recipient_email'   => 'learner' . $id . '@example.test',
				'revoked'           => '0',
				'expires_on'        => null,
				'received'          => '0',
				'in_passport'       => '0',
			),
			$overrides
		);
	}

	/**
	 * A recipient row as the provider returns it.
	 *
	 * @param int                  $id        Matching award id.
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	private static function recipient( int $id, array $overrides = array() ): array {
		return array_merge(
			array(
				'event_id'           => 'EVENT-' . $id,
				'assertion_id'       => 'ASSERT-' . $id,
				'email'              => 'learner' . $id . '@example.test',
				'receive_url'        => 'https://factory.cancred.ca/badge/receive/' . $id,
				'assertion_json_url' => 'https://factory.cancred.ca/assertion/' . $id . '.json',
				'received'           => false,
				'in_passport'        => false,
				'issued_on'          => 1_700_000_000,
				'expires_on'         => null,
				'revoked'            => false,
			),
			$overrides
		);
	}

	private function transport_with( array $recipients ): MockTransport {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 200, array( 'result' => $recipients, 'total' => count( $recipients ) ) );

		return $transport;
	}

	public function test_a_revocation_is_written_and_logged_and_purges_caches(): void {
		$this->awards->issued['cancred'] = array( self::award( 1 ), self::award( 2 ) );
		$transport                       = $this->transport_with(
			array(
				self::recipient( 1, array( 'revoked' => true ) ),
				self::recipient( 2 ),
			)
		);

		$summary = $this->sync( $transport )->sync_all();

		$this->assertCount( 1, $this->awards->recorded, 'Only the changed row is written.' );
		$this->assertSame( 1, $this->awards->recorded[0]['id'] );
		$this->assertTrue( $this->awards->recorded[0]['revoked'] );

		$this->assertSame( array( 'cancred' ), $this->awards->stamped );
		$this->assertSame( 1, obld_test_fired_count( 'litespeed_purge_all' ), 'A hidden badge is sitting in a cached page.' );

		$result = $summary['cancred'];
		$this->assertSame( StatusSync::STATUS_CHECKED, $result['status'] );
		$this->assertSame( 2, $result['checked'] );
		$this->assertSame( 1, $result['changed'] );
		$this->assertSame( 1, $result['revoked'] );

		$logs = $GLOBALS['wpdb']->inserts;
		$this->assertCount( 1, $logs );
		$this->assertSame( 'success', $logs[0][1]['outcome'] );
		$this->assertStringContainsString( '1 newly revoked', $logs[0][1]['message'] );
	}

	public function test_nothing_changed_means_no_writes_no_purge_no_log_entry(): void {
		$this->awards->issued['cancred'] = array( self::award( 1, array( 'in_passport' => '1', 'received' => '1' ) ) );
		$transport                       = $this->transport_with(
			array( self::recipient( 1, array( 'in_passport' => true, 'received' => true ) ) )
		);

		$summary = $this->sync( $transport )->sync_all();

		$this->assertSame( array(), $this->awards->recorded );
		$this->assertSame( array( 'cancred' ), $this->awards->stamped, 'The check still leaves evidence it ran.' );
		$this->assertSame( 0, obld_test_fired_count( 'litespeed_purge_all' ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->inserts, 'An hourly "nothing changed" must not fill the Activity Log.' );
		$this->assertSame( 0, $summary['cancred']['changed'] );
	}

	public function test_saved_to_passport_and_expiry_are_recorded(): void {
		$this->awards->issued['cancred'] = array( self::award( 1 ) );
		$transport                       = $this->transport_with(
			array(
				self::recipient(
					1,
					array(
						'received'    => true,
						'in_passport' => true,
						'expires_on'  => 1_893_456_000, // 2030-01-01 00:00:00 UTC.
					)
				),
			)
		);

		$summary = $this->sync( $transport )->sync_all();

		$written = $this->awards->recorded[0];
		$this->assertTrue( $written['received'] );
		$this->assertTrue( $written['in_passport'] );
		$this->assertSame( '2030-01-01 00:00:00', $written['expires_on'], 'Epoch becomes a UTC MySQL datetime.' );
		$this->assertFalse( $written['revoked'] );

		$this->assertSame( 1, $summary['cancred']['saved'] );
		$this->assertSame( 1, $summary['cancred']['expiry'] );
		$this->assertSame( 0, $summary['cancred']['revoked'] );
	}

	public function test_an_unenriched_award_matches_by_event_and_email(): void {
		// Enrichment never ran for this award (no assertion id stored), so the
		// refresh falls back to the issuing event plus the recipient address —
		// case-insensitively, since the provider may normalise the email.
		$this->awards->issued['cancred'] = array(
			self::award( 1, array( 'assertion_id' => null, 'recipient_email' => 'Learner1@Example.test' ) ),
		);
		$transport                       = $this->transport_with(
			array( self::recipient( 1, array( 'revoked' => true ) ) )
		);

		$this->sync( $transport )->sync_all();

		$this->assertCount( 1, $this->awards->recorded );
		$this->assertTrue( $this->awards->recorded[0]['revoked'] );
	}

	public function test_an_award_the_provider_no_longer_lists_is_left_alone(): void {
		// Absence is not revocation: an event deleted at the provider (or a
		// filter quirk) must not flip anything locally.
		$this->awards->issued['cancred'] = array( self::award( 1 ), self::award( 2, array( 'in_passport' => '1' ) ) );
		$transport                       = $this->transport_with( array( self::recipient( 1 ) ) );

		$this->sync( $transport )->sync_all();

		$this->assertSame( array(), $this->awards->recorded );
	}

	public function test_the_provider_is_authoritative_in_both_directions(): void {
		// A flag flipping back (however unlikely) is mirrored rather than
		// treated as sticky.
		$this->awards->issued['cancred'] = array( self::award( 1, array( 'revoked' => '1', 'expires_on' => '2030-01-01 00:00:00' ) ) );
		$transport                       = $this->transport_with( array( self::recipient( 1 ) ) );

		$summary = $this->sync( $transport )->sync_all();

		$this->assertCount( 1, $this->awards->recorded );
		$this->assertFalse( $this->awards->recorded[0]['revoked'] );
		$this->assertNull( $this->awards->recorded[0]['expires_on'] );
		$this->assertSame( 0, $summary['cancred']['revoked'], 'Un-revoking is not a new revocation.' );
		$this->assertSame( 1, $summary['cancred']['expiry'] );
	}

	public function test_a_provider_with_no_issued_awards_is_not_asked(): void {
		$transport = new MockTransport(); // Any request would throw.

		$summary = $this->sync( $transport )->sync_all();

		$this->assertSame( array(), $transport->requests );
		$this->assertSame( StatusSync::STATUS_CHECKED, $summary['cancred']['status'] );
		$this->assertSame( 0, $summary['cancred']['checked'] );
		$this->assertSame( array(), $this->awards->stamped );
	}

	public function test_an_unconfigured_provider_is_skipped(): void {
		$this->awards->issued['obf'] = array( self::award( 1 ) ); // Only CanCred is configured.
		$transport                   = new MockTransport();

		$summary = $this->sync( $transport )->sync_all();

		$this->assertSame( array(), $transport->requests );
		$this->assertSame( StatusSync::STATUS_SKIPPED, $summary['obf']['status'] );
	}

	public function test_a_sweep_failure_is_recorded_but_not_logged(): void {
		$this->awards->issued['cancred'] = array( self::award( 1 ) );
		$transport                       = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 500, array( 'message' => 'upstream exploded' ) );

		$summary = $this->sync( $transport )->sync_all( false );

		$this->assertSame( StatusSync::STATUS_FAILED, $summary['cancred']['status'] );
		$this->assertNotSame( '', $summary['cancred']['message'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->inserts, 'Hourly retries during an outage must not flood the log.' );
		$this->assertSame( array(), $this->awards->recorded );
		$this->assertSame( array(), $this->awards->stamped, 'A failed check is not a check.' );
		$this->assertSame( $summary, get_option( StatusSync::LAST_SYNC_OPTION ), 'The Badges screen reads the failure from the option.' );
	}

	public function test_a_manual_failure_is_logged(): void {
		$this->awards->issued['cancred'] = array( self::award( 1 ) );
		$transport                       = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 500, array( 'message' => 'upstream exploded' ) );

		$this->sync( $transport )->sync_all( true );

		$logs = $GLOBALS['wpdb']->inserts;
		$this->assertCount( 1, $logs );
		$this->assertSame( 'error', $logs[0][1]['outcome'] );
		$this->assertStringContainsString( 'CanCred', $logs[0][1]['message'] );
	}

	public function test_registers_on_the_existing_hourly_sweep(): void {
		$this->sync( new MockTransport() )->register();

		$this->assertArrayHasKey( IssuanceService::SWEEP_HOOK, $GLOBALS['obld_test_actions'] );
	}
}
