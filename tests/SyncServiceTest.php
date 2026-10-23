<?php
/**
 * SyncService tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Badges\BadgeRepository;
use OBLD\Badges\SyncService;
use OBLD\Credentials\CredentialStore;
use OBLD\Log\Logger;
use OBLD\Provider;
use PHPUnit\Framework\TestCase;

/**
 * In-memory repository double: records what was stored, no $wpdb.
 */
final class FakeBadgeRepository extends BadgeRepository {

	/**
	 * Stored badges per provider value.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $stored = array();

	/**
	 * Record and report all badges as added.
	 *
	 * @param Provider                         $provider Provider.
	 * @param array<int, array<string, mixed>> $badges   Badges.
	 *
	 * @return array{added: int, updated: int, removed: int, missing: int}
	 */
	public function replace_provider( Provider $provider, array $badges ): array {
		$this->stored[ $provider->value ] = $badges;

		return array(
			'added'   => count( $badges ),
			'updated' => 0,
			'removed' => 0,
			'missing' => 0,
		);
	}
}

/**
 * Provider isolation, skip semantics, and failure recording.
 */
final class SyncServiceTest extends TestCase {

	private CredentialStore $store;
	private FakeBadgeRepository $repo;

	protected function setUp(): void {
		obld_test_reset();
		$this->store = new CredentialStore();
		$this->repo  = new FakeBadgeRepository();
	}

	private function sync( MockTransport $transport ): array {
		$service = new SyncService( $this->store, $this->repo, new Logger(), $transport );

		return $service->sync_all();
	}

	private function badge_page( array $names, bool $draft = false ): array {
		return array(
			'result' => array_map(
				static fn( string $name ) => array(
					'id'    => strtoupper( $name ),
					'name'  => $name,
					'draft' => $draft,
				),
				$names
			),
			'total'  => count( $names ),
		);
	}

	public function test_unconfigured_providers_are_skipped_without_requests(): void {
		$transport = new MockTransport();

		$summary = $this->sync( $transport );

		$this->assertSame( SyncService::STATUS_SKIPPED, $summary['obf']['status'] );
		$this->assertSame( SyncService::STATUS_SKIPPED, $summary['cancred']['status'] );
		$this->assertCount( 0, $transport->requests );
		$this->assertSame( $summary, get_option( SyncService::LAST_SYNC_OPTION ) );
	}

	public function test_syncs_published_and_draft_badges_for_configured_provider(): void {
		$this->store->save( Provider::OBF, 'OBF-ID', 'obf-secret' );

		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 18000 ) );
		$transport->queue_json( 200, $this->badge_page( array( 'Course Champ' ) ) );
		$transport->queue_json( 200, $this->badge_page( array( 'Draft Star' ), true ) );

		$summary = $this->sync( $transport );

		$this->assertSame( SyncService::STATUS_SYNCED, $summary['obf']['status'] );
		$this->assertSame( 2, $summary['obf']['count'] );
		$this->assertSame( SyncService::STATUS_SKIPPED, $summary['cancred']['status'] );

		$stored = $this->repo->stored['obf'];
		$this->assertCount( 2, $stored );
		$this->assertFalse( $stored[0]['draft'] );
		$this->assertTrue( $stored[1]['draft'] );

		// Published call sends draft=0, drafts call draft=1.
		$this->assertStringContainsString( 'draft=0', $transport->requests[1]['url'] );
		$this->assertStringContainsString( 'draft=1', $transport->requests[2]['url'] );
	}

	public function test_one_provider_failing_does_not_stop_the_other(): void {
		$this->store->save( Provider::OBF, 'OBF-ID', 'obf-secret' );
		$this->store->save( Provider::CanCred, 'CC-ID', 'cancred-secret' );

		$transport = new MockTransport();
		// OBF (first case): token rejected.
		$transport->queue_json( 400, array( 'error' => 'invalid_client' ) );
		// CanCred: token + published + drafts.
		$transport->queue_json( 200, array( 'access_token' => 'cc-tok', 'expires_in' => 18000 ) );
		$transport->queue_json( 200, $this->badge_page( array( 'CanCred LearnDash Integration' ) ) );
		$transport->queue_json( 200, $this->badge_page( array() ) );

		$summary = $this->sync( $transport );

		$this->assertSame( SyncService::STATUS_FAILED, $summary['obf']['status'] );
		$this->assertStringContainsString( 'rejected the credentials', $summary['obf']['message'] );
		$this->assertSame( SyncService::STATUS_SYNCED, $summary['cancred']['status'] );
		$this->assertSame( 1, $summary['cancred']['count'] );
		$this->assertArrayNotHasKey( 'obf', $this->repo->stored );
	}
}
