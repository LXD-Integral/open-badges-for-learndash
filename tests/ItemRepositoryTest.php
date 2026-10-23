<?php
/**
 * ItemRepository tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\LearnDash\ItemRepository;
use OBLD\Triggers\EventType;
use PHPUnit\Framework\TestCase;

/**
 * Bounded LearnDash item enumeration — the predecessor plugin's unbounded
 * `numberposts => -1` query is the specific defect these tests guard against.
 */
final class ItemRepositoryTest extends TestCase {

	private ItemRepository $repo;

	protected function setUp(): void {
		obld_test_reset();
		$this->repo = new ItemRepository();
	}

	public function test_lists_only_the_matching_post_type(): void {
		obld_test_add_post( 1, 'sfwd-courses', 'Alpha Course' );
		obld_test_add_post( 2, 'sfwd-lessons', 'A Lesson' );
		obld_test_add_post( 3, 'sfwd-courses', 'Beta Course' );

		$result = $this->repo->items( EventType::CourseCompleted );

		$this->assertSame(
			array( 'Alpha Course', 'Beta Course' ),
			array_column( $result['items'], 'title' )
		);
		$this->assertFalse( $result['truncated'] );
	}

	public function test_excludes_unpublished_items(): void {
		obld_test_add_post( 1, 'sfwd-courses', 'Published Course' );
		obld_test_add_post( 2, 'sfwd-courses', 'Draft Course', 'draft' );

		$result = $this->repo->items( EventType::CourseCompleted );

		$this->assertSame( array( 'Published Course' ), array_column( $result['items'], 'title' ) );
	}

	public function test_caps_the_list_and_reports_truncation(): void {
		for ( $i = 1; $i <= ItemRepository::MAX_ITEMS + 25; $i++ ) {
			obld_test_add_post( $i, 'sfwd-courses', sprintf( 'Course %04d', $i ) );
		}

		$result = $this->repo->items( EventType::CourseCompleted );

		$this->assertCount( ItemRepository::MAX_ITEMS, $result['items'] );
		$this->assertTrue( $result['truncated'], 'A capped list must say so rather than silently truncating.' );
	}

	public function test_reports_no_truncation_at_exactly_the_cap(): void {
		for ( $i = 1; $i <= ItemRepository::MAX_ITEMS; $i++ ) {
			obld_test_add_post( $i, 'sfwd-courses', sprintf( 'Course %04d', $i ) );
		}

		$result = $this->repo->items( EventType::CourseCompleted );

		$this->assertCount( ItemRepository::MAX_ITEMS, $result['items'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_untitled_items_get_an_identifiable_label(): void {
		obld_test_add_post( 42, 'sfwd-quiz', '' );

		$result = $this->repo->items( EventType::QuizCompleted );

		$this->assertStringContainsString( '42', $result['items'][0]['title'] );
	}

	public function test_title_returns_null_for_a_deleted_item(): void {
		$this->assertNull( $this->repo->title( 999 ) );
	}

	public function test_target_must_match_the_event_type(): void {
		obld_test_add_post( 7, 'sfwd-courses', 'A Course' );

		$this->assertTrue( $this->repo->is_valid_target( EventType::CourseCompleted, 7 ) );
		// A course id submitted against a quiz event must be rejected.
		$this->assertFalse( $this->repo->is_valid_target( EventType::QuizCompleted, 7 ) );
		$this->assertFalse( $this->repo->is_valid_target( EventType::CourseCompleted, 999 ) );
	}
}
