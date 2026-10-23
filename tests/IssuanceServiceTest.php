<?php
/**
 * IssuanceService tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Credentials\CredentialStore;
use OBLD\Issuance\AwardRepository;
use OBLD\Issuance\IssuanceService;
use OBLD\Log\Logger;
use OBLD\Provider;
use OBLD\Triggers\TriggerRepository;
use PHPUnit\Framework\TestCase;

/**
 * In-memory award repository double.
 */
final class FakeAwardRepository extends AwardRepository {

	/**
	 * Award rows by id.
	 *
	 * @var array<int, object>
	 */
	public array $rows = array();

	/**
	 * Claims that were rejected as duplicates, as "user|trigger" keys.
	 *
	 * @var array<int, string>
	 */
	public array $taken = array();

	/**
	 * Next id handed out by claim().
	 *
	 * @var int
	 */
	public int $next_id = 1;

	/**
	 * Claim an award slot.
	 *
	 * @param int    $user_id    Learner.
	 * @param int    $badge_id   Badge id.
	 * @param int    $trigger_id Trigger id.
	 * @param string $email      Recipient email.
	 */
	public function claim( int $user_id, int $badge_id, int $trigger_id, string $email ): int {
		$key = $user_id . '|' . $trigger_id;

		if ( in_array( $key, $this->taken, true ) ) {
			return 0;
		}

		$this->taken[] = $key;
		$id            = $this->next_id++;

		$this->rows[ $id ] = (object) array(
			'id'              => $id,
			'user_id'         => $user_id,
			'badge_id'        => $badge_id,
			'trigger_id'      => $trigger_id,
			'recipient_email' => $email,
			'status'          => self::STATUS_PENDING,
			'attempts'        => 0,
		);

		return $id;
	}

	/**
	 * Find an award.
	 *
	 * @param int $id Award id.
	 */
	public function find( int $id ): ?object {
		return $this->rows[ $id ] ?? null;
	}

	/**
	 * Mark issued.
	 *
	 * @param int    $id       Award id.
	 * @param string $event_id Provider event id.
	 */
	public function mark_issued( int $id, string $event_id ): void {
		$this->rows[ $id ]->status            = self::STATUS_ISSUED;
		$this->rows[ $id ]->provider_event_id = $event_id;
	}

	/**
	 * Mark failed.
	 *
	 * @param int    $id        Award id.
	 * @param string $message   Failure detail.
	 * @param int    $attempts  Attempt count.
	 * @param bool   $abandoned Whether retries are exhausted.
	 */
	public function mark_failed( int $id, string $message, int $attempts, bool $abandoned ): void {
		$this->rows[ $id ]->status        = $abandoned ? self::STATUS_ABANDONED : self::STATUS_FAILED;
		$this->rows[ $id ]->attempts      = $attempts;
		$this->rows[ $id ]->error_message = $message;
	}

	/**
	 * Rows returned by unenriched().
	 *
	 * @var array<int, object>
	 */
	public array $unenriched_rows = array();

	/**
	 * Record export links.
	 *
	 * @param int    $id                 Award id.
	 * @param string $assertion_id       Assertion id.
	 * @param string $receive_url        Recipient page URL.
	 * @param string $assertion_json_url Assertion JSON URL.
	 */
	public function record_assertion( int $id, string $assertion_id, string $receive_url, string $assertion_json_url ): void {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ]->assertion_id       = $assertion_id;
			$this->rows[ $id ]->receive_url        = $receive_url;
			$this->rows[ $id ]->assertion_json_url = $assertion_json_url;
		}
	}

	/**
	 * Canned unenriched rows.
	 *
	 * @param int $limit Maximum rows.
	 *
	 * @return array<int, object>
	 */
	public function unenriched( int $limit = 25 ): array {
		return $this->unenriched_rows;
	}

	/**
	 * In-memory retryable(): pending/failed rows under the attempt cap.
	 *
	 * @param int $max_attempts Attempt cap.
	 * @param int $limit        Maximum ids.
	 *
	 * @return array<int, int>
	 */
	public function retryable( int $max_attempts, int $limit = 50 ): array {
		$ids = array();

		foreach ( $this->rows as $id => $row ) {
			if ( in_array( $row->status, array( self::STATUS_PENDING, self::STATUS_FAILED ), true ) && $row->attempts < $max_attempts ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}

/**
 * Trigger repository double recording fired-count increments.
 */
final class FakeFiringTriggerRepository extends TriggerRepository {

	/**
	 * Trigger ids incremented, in order.
	 *
	 * @var array<int, int>
	 */
	public array $fired = array();

	/**
	 * Record an increment.
	 *
	 * @param int $id Trigger id.
	 */
	public function increment_fired( int $id ): void {
		$this->fired[] = $id;
	}
}

/**
 * The reliability core: background issuance, retry with backoff, abandonment,
 * and the guarantee that a badge is never issued twice for the same trigger.
 */
final class IssuanceServiceTest extends TestCase {

	private FakeAwardRepository $awards;
	private FakeFiringTriggerRepository $triggers;
	private CredentialStore $store;

	protected function setUp(): void {
		obld_test_reset();

		$this->awards   = new FakeAwardRepository();
		$this->triggers = new FakeFiringTriggerRepository();
		$this->store    = new CredentialStore();

		$this->store->save( Provider::CanCred, 'client-id', 'client-secret' );
		obld_test_add_user( 7, 'learner@example.test', 'Ada Learner' );
	}

	private function service( MockTransport $transport ): IssuanceService {
		return new IssuanceService( $this->store, $this->awards, $this->triggers, new Logger(), $transport );
	}

	/**
	 * Seed an award row ready to process.
	 *
	 * @param int $attempts Attempts already made.
	 */
	private function seed_award( int $attempts = 0 ): int {
		$id = $this->awards->claim( 7, 3, 11, 'learner@example.test' );

		$this->awards->rows[ $id ]->attempts                = $attempts;
		$this->awards->rows[ $id ]->badge_provider          = 'cancred';
		$this->awards->rows[ $id ]->badge_provider_badge_id = 'BADGE123';
		$this->awards->rows[ $id ]->badge_name              = 'Test Badge';

		return $id;
	}

	public function test_queue_schedules_a_background_job_rather_than_issuing_inline(): void {
		$transport = new MockTransport();
		$service   = $this->service( $transport );

		$award_id = $service->queue( 7, 3, 11, 'learner@example.test' );

		$this->assertGreaterThan( 0, $award_id );
		// The whole point: no provider call happens on the learner's request.
		$this->assertSame( array(), $transport->requests );
		$this->assertCount( 1, obld_test_cron_for( IssuanceService::PROCESS_HOOK ) );
	}

	public function test_queue_never_issues_the_same_badge_twice_for_one_trigger(): void {
		$service = $this->service( new MockTransport() );

		$first  = $service->queue( 7, 3, 11, 'learner@example.test' );
		$second = $service->queue( 7, 3, 11, 'learner@example.test' );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, $second, 'A repeat completion must not create a second award.' );
		$this->assertCount( 1, obld_test_cron_for( IssuanceService::PROCESS_HOOK ) );
	}

	/**
	 * A recipient row as the provider returns it.
	 *
	 * @param string $suffix Distinguishes URLs between rows.
	 */
	private static function recipient_row( string $suffix = 'abc' ): array {
		return array(
			'event_id'           => 'EVENT99',
			'assertion_id'       => 'ASSERT-' . $suffix,
			'email'              => 'learner@example.test',
			'receive_url'        => 'https://factory.cancred.ca/badge/receive/' . $suffix,
			'assertion_json_url' => 'https://factory.cancred.ca/assertion/' . $suffix . '.json',
		);
	}

	public function test_successful_issuance_records_the_event_and_fires_the_trigger(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 200, array( 'success' => true, 'resource_id' => 'EVENT99' ) );
		$transport->queue_json( 200, array( 'result' => array( self::recipient_row() ), 'total' => 1 ) );

		$award_id = $this->seed_award();
		$this->service( $transport )->process( $award_id );

		$award = $this->awards->find( $award_id );
		$this->assertSame( AwardRepository::STATUS_ISSUED, $award->status );
		$this->assertSame( 'EVENT99', $award->provider_event_id );
		$this->assertSame( array( 11 ), $this->triggers->fired );

		$issue = $transport->requests[1];
		$this->assertSame( 'POST', $issue['method'] );
		$this->assertStringContainsString( '/v2/event/', $issue['url'] );
		$this->assertStringContainsString( 'BADGE123/issue', $issue['url'] );

		$body = json_decode( $issue['args']['body'], true );
		$this->assertSame( 'learner@example.test', $body['recipient'][0]['email'] );
		$this->assertSame( 'Ada Learner', $body['recipient'][0]['name'] );
		$this->assertSame( 'open-badges-for-learndash', $body['api_consumer_id'] );
	}

	public function test_successful_issuance_fetches_and_stores_the_export_links(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 200, array( 'success' => true, 'resource_id' => 'EVENT99' ) );
		$transport->queue_json( 200, array( 'result' => array( self::recipient_row() ), 'total' => 1 ) );

		$award_id = $this->seed_award();
		$this->service( $transport )->process( $award_id );

		$award = $this->awards->find( $award_id );
		$this->assertSame( 'ASSERT-abc', $award->assertion_id );
		$this->assertSame( 'https://factory.cancred.ca/badge/receive/abc', $award->receive_url );
		$this->assertSame( 'https://factory.cancred.ca/assertion/abc.json', $award->assertion_json_url );

		// The lookup is scoped to this event and this recipient.
		$lookup = $transport->requests[2];
		$this->assertSame( 'GET', $lookup['method'] );
		$this->assertStringContainsString( '/recipient?', $lookup['url'] );
		$this->assertStringContainsString( 'event_id=EVENT99', $lookup['url'] );
		$this->assertStringContainsString( 'email=learner%40example.test', $lookup['url'] );
	}

	public function test_successful_issuance_purges_page_caches_once(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 200, array( 'success' => true, 'resource_id' => 'EVENT99' ) );
		$transport->queue_json( 200, array( 'result' => array( self::recipient_row() ), 'total' => 1 ) );

		$this->service( $transport )->process( $this->seed_award() );

		// Issuance and enrichment both request a purge; one per request is
		// enough — a sweep processing many awards must not purge repeatedly.
		$this->assertSame( 1, obld_test_fired_count( 'litespeed_purge_all' ) );
	}

	public function test_a_failed_attempt_purges_nothing(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 500, array( 'message' => 'upstream exploded' ) );

		$this->service( $transport )->process( $this->seed_award() );

		$this->assertSame( 0, obld_test_fired_count( 'litespeed_purge_all' ), 'No badge appeared, so no cached page went stale.' );
	}

	public function test_a_failed_link_lookup_never_fails_the_issued_award(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 200, array( 'success' => true, 'resource_id' => 'EVENT99' ) );
		$transport->queue_json( 500, array( 'message' => 'recipient lookup exploded' ) );

		$award_id = $this->seed_award();
		$this->service( $transport )->process( $award_id );

		$award = $this->awards->find( $award_id );
		$this->assertSame( AwardRepository::STATUS_ISSUED, $award->status, 'The badge WAS issued; a lookup failure must not undo that.' );
		$this->assertSame( array( 11 ), $this->triggers->fired );
		$this->assertFalse( property_exists( $award, 'receive_url' ), 'Links stay unset (NULL) so the sweep retries.' );
		$this->assertSame( array(), obld_test_cron_for( IssuanceService::PROCESS_HOOK ), 'No re-issue may be scheduled for an issued award.' );
	}

	public function test_an_empty_recipient_answer_stores_the_checked_sentinel(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 200, array( 'success' => true, 'resource_id' => 'EVENT99' ) );
		$transport->queue_json( 200, array( 'result' => array(), 'total' => 0 ) );

		$award_id = $this->seed_award();
		$this->service( $transport )->process( $award_id );

		$award = $this->awards->find( $award_id );
		// '' (not NULL): the provider was asked and had nothing — the sweep
		// must not re-query a dead event every hour forever.
		$this->assertSame( '', $award->receive_url );
		$this->assertSame( '', $award->assertion_json_url );
	}

	public function test_success_without_an_event_id_skips_the_lookup(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 200, array( 'success' => true ) ); // No resource_id.

		$award_id = $this->seed_award();
		$this->service( $transport )->process( $award_id );

		$award = $this->awards->find( $award_id );
		$this->assertSame( AwardRepository::STATUS_ISSUED, $award->status );
		$this->assertCount( 2, $transport->requests, 'With no event id there is nothing to look up.' );
	}

	public function test_the_sweep_backfills_export_links_for_old_awards(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 200, array( 'result' => array( self::recipient_row() ), 'total' => 1 ) );

		// An award issued before milestone 7: event stored, links never fetched.
		$award_id = $this->seed_award();
		$this->awards->rows[ $award_id ]->status            = AwardRepository::STATUS_ISSUED;
		$this->awards->rows[ $award_id ]->provider_event_id = 'EVENT99';

		$this->awards->unenriched_rows = array(
			(object) array(
				'id'                => $award_id,
				'provider_event_id' => 'EVENT99',
				'recipient_email'   => 'learner@example.test',
				'badge_provider'    => 'cancred',
			),
		);

		$this->service( $transport )->sweep();

		$award = $this->awards->find( $award_id );
		$this->assertSame( 'https://factory.cancred.ca/badge/receive/abc', $award->receive_url );
		$this->assertSame( 'https://factory.cancred.ca/assertion/abc.json', $award->assertion_json_url );

		// The sweep looked the links up; it did not re-issue anything.
		$this->assertCount( 2, $transport->requests );
		$this->assertSame( 'GET', $transport->requests[1]['method'] );
	}

	public function test_the_sweep_skips_backfill_for_an_unconfigured_provider(): void {
		$transport = new MockTransport();

		$this->awards->unenriched_rows = array(
			(object) array(
				'id'                => 42,
				'provider_event_id' => 'EVENT1',
				'recipient_email'   => 'learner@example.test',
				'badge_provider'    => 'obf', // Only CanCred is configured in setUp().
			),
		);

		$this->service( $transport )->sweep();

		$this->assertSame( array(), $transport->requests );
	}

	public function test_a_failure_is_recorded_and_retried_rather_than_lost(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 500, array( 'message' => 'upstream exploded' ) );

		$award_id = $this->seed_award();
		$this->service( $transport )->process( $award_id );

		$award = $this->awards->find( $award_id );
		$this->assertSame( AwardRepository::STATUS_FAILED, $award->status );
		$this->assertSame( 1, $award->attempts );
		$this->assertStringContainsString( 'upstream exploded', $award->error_message );
		$this->assertSame( array(), $this->triggers->fired, 'A failed attempt must not count as a firing.' );

		// Retry scheduled in the future, not dropped.
		$scheduled = obld_test_cron_for( IssuanceService::PROCESS_HOOK );
		$this->assertCount( 1, $scheduled );
		$this->assertGreaterThan( time(), $scheduled[0]['timestamp'] );
		$this->assertSame( array( $award_id ), $scheduled[0]['args'] );
	}

	public function test_the_provider_reporting_failure_on_a_200_is_still_a_failure(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 200, array( 'success' => false, 'message' => 'quota exceeded' ) );

		$award_id = $this->seed_award();
		$this->service( $transport )->process( $award_id );

		$award = $this->awards->find( $award_id );
		$this->assertSame( AwardRepository::STATUS_FAILED, $award->status );
		$this->assertStringContainsString( 'quota exceeded', $award->error_message );
	}

	public function test_retries_stop_after_the_attempt_cap(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 500, array( 'message' => 'still broken' ) );

		$award_id = $this->seed_award( IssuanceService::MAX_ATTEMPTS - 1 );
		$this->service( $transport )->process( $award_id );

		$award = $this->awards->find( $award_id );
		$this->assertSame( AwardRepository::STATUS_ABANDONED, $award->status );
		$this->assertSame( IssuanceService::MAX_ATTEMPTS, $award->attempts );
		$this->assertSame( array(), obld_test_cron_for( IssuanceService::PROCESS_HOOK ), 'An abandoned award must not reschedule forever.' );
	}

	public function test_an_already_issued_award_is_never_issued_again(): void {
		$transport = new MockTransport();

		$award_id                            = $this->seed_award();
		$this->awards->rows[ $award_id ]->status = AwardRepository::STATUS_ISSUED;

		$this->service( $transport )->process( $award_id );

		// No token fetch, no issue call — re-entry (from the sweep, or a
		// duplicated cron event) must be harmless.
		$this->assertSame( array(), $transport->requests );
	}

	public function test_an_unconfigured_provider_fails_without_calling_out(): void {
		$transport = new MockTransport();

		$award_id                                    = $this->seed_award();
		$this->awards->rows[ $award_id ]->badge_provider = 'obf';

		$this->service( $transport )->process( $award_id );

		$award = $this->awards->find( $award_id );
		$this->assertSame( AwardRepository::STATUS_FAILED, $award->status );
		$this->assertSame( array(), $transport->requests );
	}
}
