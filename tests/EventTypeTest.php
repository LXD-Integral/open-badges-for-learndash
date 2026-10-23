<?php
/**
 * EventType tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Triggers\EventType;
use PHPUnit\Framework\TestCase;

/**
 * The enum is a storage contract (values live in obld_triggers.event_type) and
 * the single source of the LearnDash post-type mapping, so both are pinned.
 */
final class EventTypeTest extends TestCase {

	public function test_stored_values_are_stable(): void {
		// Changing any of these silently orphans every existing trigger row.
		$this->assertSame( 'course_completed', EventType::CourseCompleted->value );
		$this->assertSame( 'lesson_completed', EventType::LessonCompleted->value );
		$this->assertSame( 'topic_completed', EventType::TopicCompleted->value );
		$this->assertSame( 'quiz_completed', EventType::QuizCompleted->value );
	}

	public function test_maps_to_learndash_post_types(): void {
		$this->assertSame( 'sfwd-courses', EventType::CourseCompleted->post_type() );
		$this->assertSame( 'sfwd-lessons', EventType::LessonCompleted->post_type() );
		$this->assertSame( 'sfwd-topic', EventType::TopicCompleted->post_type() );
		$this->assertSame( 'sfwd-quiz', EventType::QuizCompleted->post_type() );
	}

	public function test_only_quizzes_support_a_minimum_score(): void {
		$this->assertTrue( EventType::QuizCompleted->supports_min_score() );

		foreach ( array( EventType::CourseCompleted, EventType::LessonCompleted, EventType::TopicCompleted ) as $event_type ) {
			$this->assertFalse( $event_type->supports_min_score(), $event_type->value );
		}
	}

	public function test_every_case_has_labels(): void {
		foreach ( EventType::cases() as $event_type ) {
			$this->assertNotSame( '', $event_type->label() );
			$this->assertNotSame( '', $event_type->item_label() );
		}
	}
}
