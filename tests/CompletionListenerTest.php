<?php
/**
 * CompletionListener tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Badges\BadgeRepository;
use OBLD\Credentials\CredentialStore;
use OBLD\Issuance\IssuanceService;
use OBLD\LearnDash\CompletionListener;
use OBLD\Log\Logger;
use OBLD\Triggers\EventType;
use OBLD\Triggers\TriggerRepository;
use PHPUnit\Framework\TestCase;

/**
 * Trigger repository double returning scripted matches.
 */
final class FakeMatchingTriggerRepository extends TriggerRepository {

	/**
	 * Rows to return, keyed "event|target".
	 *
	 * @var array<string, array<int, object>>
	 */
	public array $matches = array();

	/**
	 * Lookups performed, as "event|target" keys.
	 *
	 * @var array<int, string>
	 */
	public array $queried = array();

	/**
	 * Return the scripted matches.
	 *
	 * @param EventType $event_type Event type.
	 * @param int       $target_id  Target item.
	 *
	 * @return array<int, object>
	 */
	public function matching( EventType $event_type, int $target_id ): array {
		$key             = $event_type->value . '|' . $target_id;
		$this->queried[] = $key;

		return $this->matches[ $key ] ?? array();
	}
}

/**
 * Issuance double recording what was queued.
 */
final class FakeIssuanceService extends IssuanceService {

	/**
	 * Queued awards.
	 *
	 * @var array<int, array{user_id: int, badge_id: int, trigger_id: int, email: string}>
	 */
	public array $queued = array();

	/**
	 * Record a queued award.
	 *
	 * @param int    $user_id    Learner.
	 * @param int    $badge_id   Badge id.
	 * @param int    $trigger_id Trigger id.
	 * @param string $email      Recipient email.
	 */
	public function queue( int $user_id, int $badge_id, int $trigger_id, string $email ): int {
		$this->queued[] = array(
			'user_id'    => $user_id,
			'badge_id'   => $badge_id,
			'trigger_id' => $trigger_id,
			'email'      => $email,
		);

		return count( $this->queued );
	}
}

/**
 * Matching LearnDash completions to trigger rules.
 *
 * The payload shapes asserted here were read from LearnDash 5.1.8 source:
 * course/lesson/topic pass a single array containing WP_User and WP_Post
 * objects; quiz passes ($quizdata, WP_User) with the score in
 * $quizdata['percentage'].
 */
final class CompletionListenerTest extends TestCase {

	private FakeMatchingTriggerRepository $triggers;
	private FakeIssuanceService $issuance;
	private CompletionListener $listener;

	protected function setUp(): void {
		obld_test_reset();

		$this->triggers = new FakeMatchingTriggerRepository();
		$this->issuance = new FakeIssuanceService(
			new CredentialStore(),
			new \OBLD\Issuance\AwardRepository(),
			$this->triggers,
			new Logger()
		);
		$this->listener = new CompletionListener(
			$this->triggers,
			new BadgeRepository(),
			$this->issuance,
			new Logger()
		);

		obld_test_add_user( 7, 'learner@example.test', 'Ada Learner' );
	}

	/**
	 * Build a trigger row.
	 *
	 * @param int        $id        Trigger id.
	 * @param int        $badge_id  Badge id.
	 * @param float|null $min_score Minimum score.
	 * @param bool       $missing   Whether the badge is missing at the provider.
	 */
	private function trigger( int $id, int $badge_id = 3, ?float $min_score = null, bool $missing = false ): object {
		return (object) array(
			'id'                        => $id,
			'badge_id'                  => $badge_id,
			'min_score'                 => $min_score,
			'badge_name'                => 'Test Badge',
			'badge_missing_at_provider' => $missing ? 1 : 0,
		);
	}

	/**
	 * A course-completion payload in LearnDash 5.1.8's shape.
	 *
	 * @param int $course_id Course post id.
	 * @param int $user_id   Learner id.
	 *
	 * @return array<string, mixed>
	 */
	private function course_payload( int $course_id, int $user_id = 7 ): array {
		return array(
			'user'   => new \WP_User( $user_id ),
			'course' => new \WP_Post( $course_id, 'sfwd-courses', 'A Course' ),
		);
	}

	public function test_course_completion_queues_the_mapped_badge(): void {
		$this->triggers->matches['course_completed|10'] = array( $this->trigger( 1 ) );

		$this->listener->on_course_completed( $this->course_payload( 10 ) );

		$this->assertSame(
			array(
				array(
					'user_id'    => 7,
					'badge_id'   => 3,
					'trigger_id' => 1,
					'email'      => 'learner@example.test',
				),
			),
			$this->issuance->queued
		);
	}

	public function test_every_matching_trigger_fires_not_just_the_first(): void {
		$this->triggers->matches['course_completed|10'] = array(
			$this->trigger( 1, 3 ),
			$this->trigger( 2, 4 ),
		);

		$this->listener->on_course_completed( $this->course_payload( 10 ) );

		$this->assertCount( 2, $this->issuance->queued );
		$this->assertSame( array( 3, 4 ), array_column( $this->issuance->queued, 'badge_id' ) );
	}

	public function test_a_completion_with_no_matching_trigger_does_nothing(): void {
		$this->listener->on_course_completed( $this->course_payload( 10 ) );

		$this->assertSame( array(), $this->issuance->queued );
	}

	public function test_lesson_and_topic_payloads_resolve_their_own_item(): void {
		$this->triggers->matches['lesson_completed|21'] = array( $this->trigger( 1 ) );
		$this->triggers->matches['topic_completed|31']  = array( $this->trigger( 2 ) );

		$this->listener->on_lesson_completed(
			array(
				'user'   => new \WP_User( 7 ),
				'course' => new \WP_Post( 10, 'sfwd-courses', 'A Course' ),
				'lesson' => new \WP_Post( 21, 'sfwd-lessons', 'A Lesson' ),
			)
		);
		$this->listener->on_topic_completed(
			array(
				'user'   => new \WP_User( 7 ),
				'course' => new \WP_Post( 10, 'sfwd-courses', 'A Course' ),
				'lesson' => new \WP_Post( 21, 'sfwd-lessons', 'A Lesson' ),
				'topic'  => new \WP_Post( 31, 'sfwd-topic', 'A Topic' ),
			)
		);

		// The lesson handler must key off the lesson, and the topic handler off
		// the topic — not off the course they share.
		$this->assertSame( array( 'lesson_completed|21', 'topic_completed|31' ), $this->triggers->queried );
		$this->assertCount( 2, $this->issuance->queued );
	}

	public function test_quiz_at_or_above_the_minimum_score_is_awarded(): void {
		$this->triggers->matches['quiz_completed|11'] = array( $this->trigger( 1, 3, 80.0 ) );

		$this->listener->on_quiz_completed(
			array( 'quiz' => 11, 'percentage' => 80 ),
			new \WP_User( 7 )
		);

		$this->assertCount( 1, $this->issuance->queued );
	}

	public function test_quiz_below_the_minimum_score_is_not_awarded(): void {
		$this->triggers->matches['quiz_completed|11'] = array( $this->trigger( 1, 3, 80.0 ) );

		$this->listener->on_quiz_completed(
			array( 'quiz' => 11, 'percentage' => 79.99 ),
			new \WP_User( 7 )
		);

		$this->assertSame( array(), $this->issuance->queued );
	}

	public function test_a_missing_score_never_satisfies_a_score_condition(): void {
		$this->triggers->matches['quiz_completed|11'] = array( $this->trigger( 1, 3, 50.0 ) );

		$this->listener->on_quiz_completed( array( 'quiz' => 11 ), new \WP_User( 7 ) );

		$this->assertSame( array(), $this->issuance->queued, 'An absent score must not be treated as a pass.' );
	}

	public function test_a_quiz_trigger_without_a_score_condition_awards_on_completion(): void {
		$this->triggers->matches['quiz_completed|11'] = array( $this->trigger( 1 ) );

		$this->listener->on_quiz_completed( array( 'quiz' => 11, 'percentage' => 12 ), new \WP_User( 7 ) );

		$this->assertCount( 1, $this->issuance->queued );
	}

	public function test_a_badge_missing_at_the_provider_is_skipped_not_queued(): void {
		$this->triggers->matches['course_completed|10'] = array( $this->trigger( 1, 3, null, true ) );

		$this->listener->on_course_completed( $this->course_payload( 10 ) );

		$this->assertSame( array(), $this->issuance->queued );
	}

	public function test_a_learner_without_an_email_is_not_queued(): void {
		$this->triggers->matches['course_completed|10'] = array( $this->trigger( 1 ) );
		obld_test_add_user( 8, '', 'No Email' );

		$this->listener->on_course_completed( $this->course_payload( 10, 8 ) );

		$this->assertSame( array(), $this->issuance->queued );
	}

	public function test_a_malformed_payload_is_ignored(): void {
		$this->triggers->matches['course_completed|10'] = array( $this->trigger( 1 ) );

		$this->listener->on_course_completed( 'not an array' );
		$this->listener->on_course_completed( array() );
		$this->listener->on_quiz_completed( 'nope', null );

		$this->assertSame( array(), $this->issuance->queued );
	}
}
