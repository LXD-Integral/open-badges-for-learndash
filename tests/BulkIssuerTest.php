<?php
/**
 * BulkIssuer tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Credentials\CredentialStore;
use OBLD\Issuance\AwardRepository;
use OBLD\Issuance\BulkIssuer;
use OBLD\Issuance\IssuanceService;
use OBLD\LearnDash\CompletionRepository;
use OBLD\Log\Logger;
use OBLD\Triggers\EventType;
use OBLD\Triggers\TriggerRepository;
use PHPUnit\Framework\TestCase;

/**
 * Issuance double that reports some learners as already holding the badge.
 */
final class FakeManualIssuanceService extends IssuanceService {

	/**
	 * Learner ids that already hold the badge (queue_manual returns 0).
	 *
	 * @var array<int, int>
	 */
	public array $already_hold = array();

	/**
	 * Queued awards.
	 *
	 * @var array<int, array{user_id: int, badge_id: int, email: string}>
	 */
	public array $queued = array();

	/**
	 * Record a manual queue attempt.
	 *
	 * @param int    $user_id  Learner.
	 * @param int    $badge_id Badge id.
	 * @param string $email    Recipient email.
	 */
	public function queue_manual( int $user_id, int $badge_id, string $email ): int {
		if ( in_array( $user_id, $this->already_hold, true ) ) {
			return 0;
		}

		$this->queued[] = array(
			'user_id'  => $user_id,
			'badge_id' => $badge_id,
			'email'    => $email,
		);

		return count( $this->queued );
	}
}

/**
 * Completion history double.
 */
final class FakeCompletionRepository extends CompletionRepository {

	/**
	 * Learner ids to return, in page order.
	 *
	 * @var array<int, int>
	 */
	public array $users = array();

	/**
	 * Whether LearnDash's activity table is reported as present.
	 *
	 * @var bool
	 */
	public bool $available = true;

	/**
	 * Score filters seen, for assertions.
	 *
	 * @var array<int, float|null>
	 */
	public array $score_filters = array();

	/**
	 * Availability.
	 */
	public function available(): bool {
		return $this->available;
	}

	/**
	 * Return a page of completers.
	 *
	 * @param EventType  $event_type Event type.
	 * @param int        $target_id  Item id.
	 * @param float|null $min_score  Minimum score.
	 * @param int        $limit      Page size.
	 * @param int        $offset     Offset.
	 *
	 * @return array<int, int>
	 */
	public function completers(
		EventType $event_type,
		int $target_id,
		?float $min_score = null,
		int $limit = self::PAGE_SIZE,
		int $offset = 0
	): array {
		$this->score_filters[] = $min_score;

		return array_slice( $this->users, $offset, $limit );
	}
}

/**
 * Trigger double returning one scripted trigger.
 */
final class FakeSingleTriggerRepository extends TriggerRepository {

	/**
	 * The trigger to return from find(), or null.
	 *
	 * @var object|null
	 */
	public ?object $row = null;

	/**
	 * Find the scripted trigger.
	 *
	 * @param int $id Trigger id.
	 */
	public function find( int $id ): ?object {
		return $this->row;
	}
}

/**
 * Manual and backfill runs: who gets queued, who is skipped, who fails, and
 * the summary the admin is shown.
 */
final class BulkIssuerTest extends TestCase {

	private FakeManualIssuanceService $issuance;
	private FakeSingleTriggerRepository $triggers;
	private FakeCompletionRepository $completions;
	private BulkIssuer $issuer;

	protected function setUp(): void {
		obld_test_reset();

		$this->triggers    = new FakeSingleTriggerRepository();
		$this->issuance    = new FakeManualIssuanceService(
			new CredentialStore(),
			new AwardRepository(),
			$this->triggers,
			new Logger()
		);
		$this->completions = new FakeCompletionRepository();
		$this->issuer      = new BulkIssuer( $this->issuance, $this->triggers, $this->completions, new Logger() );

		obld_test_add_user( 2, 'ada@example.test', 'Ada' );
		obld_test_add_user( 3, 'bo@example.test', 'Bo' );
		obld_test_add_user( 4, 'cy@example.test', 'Cy' );
	}

	/**
	 * Build a trigger row.
	 *
	 * @param string     $event_type Event type value.
	 * @param float|null $min_score  Minimum score.
	 */
	private function trigger( string $event_type = 'course_completed', ?float $min_score = null ): object {
		return (object) array(
			'id'         => 1,
			'badge_id'   => 5,
			'event_type' => $event_type,
			'target_id'  => 10,
			'min_score'  => $min_score,
			'badge_name' => 'Test Badge',
		);
	}

	public function test_manual_issuance_queues_each_chosen_learner(): void {
		$result = $this->issuer->issue_manually( 5, array( 2, 3, 4 ), 'Test Badge' );

		$this->assertSame( 3, $result['issued'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( array( 2, 3, 4 ), array_column( $this->issuance->queued, 'user_id' ) );
	}

	public function test_manual_issuance_skips_learners_who_already_hold_the_badge(): void {
		$this->issuance->already_hold = array( 3 );

		$result = $this->issuer->issue_manually( 5, array( 2, 3, 4 ), 'Test Badge' );

		$this->assertSame( 2, $result['issued'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array( 2, 4 ), array_column( $this->issuance->queued, 'user_id' ) );
	}

	public function test_a_learner_selected_twice_is_only_queued_once(): void {
		$result = $this->issuer->issue_manually( 5, array( 2, 2, 3 ), 'Test Badge' );

		$this->assertSame( 2, $result['issued'] );
		$this->assertCount( 2, $this->issuance->queued );
	}

	public function test_a_learner_without_an_email_is_counted_as_failed(): void {
		obld_test_add_user( 9, '', 'No Email' );

		$result = $this->issuer->issue_manually( 5, array( 2, 9 ), 'Test Badge' );

		$this->assertSame( 1, $result['issued'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 0, $result['skipped'], 'A missing email is a failure, not a skip.' );
	}

	public function test_an_unknown_user_is_counted_as_failed(): void {
		$result = $this->issuer->issue_manually( 5, array( 2, 999 ), 'Test Badge' );

		$this->assertSame( 1, $result['issued'] );
		$this->assertSame( 1, $result['failed'] );
	}

	public function test_backfill_queues_prior_completers(): void {
		$this->triggers->row     = $this->trigger();
		$this->completions->users = array( 2, 3, 4 );

		$result = $this->issuer->backfill( 1 );

		$this->assertSame( 3, $result['issued'] );
		$this->assertSame( array( 2, 3, 4 ), array_column( $this->issuance->queued, 'user_id' ) );
		$this->assertSame( 5, $this->issuance->queued[0]['badge_id'] );
	}

	public function test_backfill_skips_anyone_who_already_has_the_badge(): void {
		$this->triggers->row          = $this->trigger();
		$this->completions->users     = array( 2, 3, 4 );
		$this->issuance->already_hold = array( 2, 4 );

		$result = $this->issuer->backfill( 1 );

		$this->assertSame( 1, $result['issued'] );
		$this->assertSame( 2, $result['skipped'] );
		$this->assertSame( array( 3 ), array_column( $this->issuance->queued, 'user_id' ) );
	}

	public function test_backfill_passes_a_quiz_trigger_minimum_score_through(): void {
		$this->triggers->row      = $this->trigger( 'quiz_completed', 80.0 );
		$this->completions->users = array( 2 );

		$this->issuer->backfill( 1 );

		$this->assertSame( array( 80.0 ), $this->completions->score_filters );
	}

	public function test_backfill_of_a_scoreless_trigger_passes_no_filter(): void {
		$this->triggers->row      = $this->trigger();
		$this->completions->users = array( 2 );

		$this->issuer->backfill( 1 );

		$this->assertSame( array( null ), $this->completions->score_filters );
	}

	public function test_backfill_of_a_missing_trigger_is_an_error(): void {
		$this->triggers->row = null;

		$result = $this->issuer->backfill( 99 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->issuance->queued );
	}

	public function test_backfill_says_so_when_learndash_activity_is_unavailable(): void {
		$this->triggers->row          = $this->trigger();
		$this->completions->available = false;

		$result = $this->issuer->backfill( 1 );

		// Reporting an error beats reporting "0 completers", which would read
		// as "nobody qualified".
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'obld_no_activity_table', $result->get_error_code() );
	}

	public function test_backfill_of_an_unrecognised_event_type_is_an_error(): void {
		$this->triggers->row = $this->trigger( 'user_registered' );

		$result = $this->issuer->backfill( 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
