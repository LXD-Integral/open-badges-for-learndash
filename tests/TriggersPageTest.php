<?php
/**
 * TriggersPage validation tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Admin\TriggersPage;
use OBLD\Badges\BadgeRepository;
use OBLD\LearnDash\ItemRepository;
use OBLD\Triggers\EventType;
use OBLD\Triggers\TriggerRepository;
use OBLD_Test_Redirected;
use PHPUnit\Framework\TestCase;

/**
 * In-memory trigger repository double: no $wpdb.
 */
final class FakeTriggerRepository extends TriggerRepository {

	/**
	 * Rows created, in order.
	 *
	 * @var array<int, array{event_type: string, target_id: int, badge_id: int, min_score: float|null}>
	 */
	public array $created = array();

	/**
	 * Rules reported as already existing, as "event|target|badge" keys.
	 *
	 * @var array<int, string>
	 */
	public array $existing = array();

	/**
	 * Next id handed out by create().
	 *
	 * @var int
	 */
	public int $next_id = 1;

	/**
	 * Record a creation.
	 *
	 * @param EventType  $event_type Event type.
	 * @param int        $target_id  LearnDash item id.
	 * @param int        $badge_id   Badge id.
	 * @param float|null $min_score  Minimum score.
	 */
	public function create( EventType $event_type, int $target_id, int $badge_id, ?float $min_score ): int {
		$this->created[] = array(
			'event_type' => $event_type->value,
			'target_id'  => $target_id,
			'badge_id'   => $badge_id,
			'min_score'  => $min_score,
		);

		return $this->next_id++;
	}

	/**
	 * Duplicate check against the seeded set.
	 *
	 * @param EventType $event_type Event type.
	 * @param int       $target_id  LearnDash item id.
	 * @param int       $badge_id   Badge id.
	 */
	public function exists( EventType $event_type, int $target_id, int $badge_id ): bool {
		return in_array( $event_type->value . '|' . $target_id . '|' . $badge_id, $this->existing, true );
	}
}

/**
 * Badge repository double exposing a fixed selectable set.
 */
final class FakeSelectableBadgeRepository extends BadgeRepository {

	/**
	 * Selectable badge rows.
	 *
	 * @var array<int, object>
	 */
	public array $rows = array();

	/**
	 * Selectable badges.
	 *
	 * @return array<int, object>
	 */
	public function selectable(): array {
		return $this->rows;
	}
}

/**
 * The add-trigger handler is the plugin's only user-supplied write path in
 * milestone 3, so its server-side re-validation is tested directly: a stale or
 * tampered form must not be able to attach a draft, missing, or unknown badge
 * to a rule, nor a target of the wrong post type.
 */
final class TriggersPageTest extends TestCase {

	private FakeTriggerRepository $triggers;
	private FakeSelectableBadgeRepository $badges;
	private TriggersPage $page;

	protected function setUp(): void {
		obld_test_reset();

		$this->triggers = new FakeTriggerRepository();
		$this->badges   = new FakeSelectableBadgeRepository();
		$this->page     = new TriggersPage( $this->triggers, $this->badges, new ItemRepository() );

		$this->badges->rows = array(
			(object) array(
				'id'       => 5,
				'name'     => 'Selectable Badge',
				'provider' => 'cancred',
			),
		);

		obld_test_add_post( 10, 'sfwd-courses', 'A Course' );
		obld_test_add_post( 11, 'sfwd-quiz', 'A Quiz' );

		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	/**
	 * Run handle_add() and return the feedback code it redirected with.
	 *
	 * @param array<string, string> $post Submitted fields.
	 */
	private function submit( array $post ): string {
		$_POST = $post;

		try {
			$this->page->handle_add();
		} catch ( OBLD_Test_Redirected $redirect ) {
			parse_str( (string) wp_parse_url( $redirect->url, PHP_URL_QUERY ), $query );

			return isset( $query['obld_trigger'] ) ? (string) $query['obld_trigger'] : '';
		}

		$this->fail( 'handle_add() should always redirect.' );
	}

	public function test_saves_a_valid_course_trigger(): void {
		$code = $this->submit(
			array(
				'event_type' => 'course_completed',
				'target_id'  => '10',
				'badge_id'   => '5',
			)
		);

		$this->assertSame( 'added', $code );
		$this->assertCount( 1, $this->triggers->created );
		$this->assertSame(
			array(
				'event_type' => 'course_completed',
				'target_id'  => 10,
				'badge_id'   => 5,
				'min_score'  => null,
			),
			$this->triggers->created[0]
		);
	}

	public function test_saves_a_quiz_trigger_with_a_minimum_score(): void {
		$code = $this->submit(
			array(
				'event_type' => 'quiz_completed',
				'target_id'  => '11',
				'badge_id'   => '5',
				'min_score'  => '80',
			)
		);

		$this->assertSame( 'added', $code );
		$this->assertSame( 80.0, $this->triggers->created[0]['min_score'] );
	}

	public function test_rejects_a_badge_that_is_not_selectable(): void {
		// Badge 6 is a draft or missing-at-provider badge: never offered by the
		// form, and must be refused even when posted directly.
		$code = $this->submit(
			array(
				'event_type' => 'course_completed',
				'target_id'  => '10',
				'badge_id'   => '6',
			)
		);

		$this->assertSame( 'err_badge', $code );
		$this->assertSame( array(), $this->triggers->created );
	}

	public function test_rejects_a_target_of_the_wrong_post_type(): void {
		$code = $this->submit(
			array(
				'event_type' => 'quiz_completed',
				'target_id'  => '10',
				'badge_id'   => '5',
			)
		);

		$this->assertSame( 'err_item', $code );
		$this->assertSame( array(), $this->triggers->created );
	}

	public function test_rejects_an_unknown_event_type(): void {
		$code = $this->submit(
			array(
				'event_type' => 'user_registered',
				'target_id'  => '10',
				'badge_id'   => '5',
			)
		);

		$this->assertSame( 'err_item', $code );
	}

	/**
	 * @dataProvider out_of_range_scores
	 *
	 * @param string $score Submitted score.
	 */
	public function test_rejects_out_of_range_scores( string $score ): void {
		$code = $this->submit(
			array(
				'event_type' => 'quiz_completed',
				'target_id'  => '11',
				'badge_id'   => '5',
				'min_score'  => $score,
			)
		);

		$this->assertSame( 'err_score', $code );
		$this->assertSame( array(), $this->triggers->created );
	}

	/**
	 * Scores that must be refused.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function out_of_range_scores(): array {
		return array(
			'negative'    => array( '-1' ),
			'over 100'    => array( '101' ),
			'not a number' => array( 'eighty' ),
		);
	}

	public function test_ignores_a_score_on_a_non_quiz_event(): void {
		// The field is hidden for non-quiz events; a stale post must not
		// silently create a course rule that appears to be score-gated.
		$code = $this->submit(
			array(
				'event_type' => 'course_completed',
				'target_id'  => '10',
				'badge_id'   => '5',
				'min_score'  => '80',
			)
		);

		$this->assertSame( 'added', $code );
		$this->assertNull( $this->triggers->created[0]['min_score'] );
	}

	public function test_rejects_an_exact_duplicate_rule(): void {
		$this->triggers->existing = array( 'course_completed|10|5' );

		$code = $this->submit(
			array(
				'event_type' => 'course_completed',
				'target_id'  => '10',
				'badge_id'   => '5',
			)
		);

		$this->assertSame( 'err_duplicate', $code );
		$this->assertSame( array(), $this->triggers->created );
	}

	public function test_allows_a_second_badge_on_the_same_item(): void {
		// More than one trigger per LearnDash item is a PRD requirement.
		$this->badges->rows[] = (object) array(
			'id'       => 9,
			'name'     => 'Second Badge',
			'provider' => 'obf',
		);
		$this->triggers->existing = array( 'course_completed|10|5' );

		$code = $this->submit(
			array(
				'event_type' => 'course_completed',
				'target_id'  => '10',
				'badge_id'   => '9',
			)
		);

		$this->assertSame( 'added', $code );
		$this->assertSame( 9, $this->triggers->created[0]['badge_id'] );
	}

	public function test_refuses_to_run_without_the_capability(): void {
		$GLOBALS['obld_test_can'] = false;

		$this->expectException( \OBLD_Test_Died::class );

		$_POST = array(
			'event_type' => 'course_completed',
			'target_id'  => '10',
			'badge_id'   => '5',
		);
		$this->page->handle_add();
	}
}
