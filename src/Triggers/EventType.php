<?php
/**
 * Trigger event types.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Triggers;

defined( 'ABSPATH' ) || exit;

/**
 * The four LearnDash completion events a trigger can listen for.
 *
 * The string values are persisted in obld_triggers.event_type, so they are a
 * storage contract: rename a case and existing triggers stop matching.
 */
enum EventType: string {

	case CourseCompleted = 'course_completed';
	case LessonCompleted = 'lesson_completed';
	case TopicCompleted  = 'topic_completed';
	case QuizCompleted   = 'quiz_completed';

	/**
	 * Human-readable event name.
	 */
	public function label(): string {
		return match ( $this ) {
			self::CourseCompleted => __( 'Course completed', 'open-badges-for-learndash' ),
			self::LessonCompleted => __( 'Lesson completed', 'open-badges-for-learndash' ),
			self::TopicCompleted  => __( 'Topic completed', 'open-badges-for-learndash' ),
			self::QuizCompleted   => __( 'Quiz completed', 'open-badges-for-learndash' ),
		};
	}

	/**
	 * Singular noun for the target item ("Course", "Lesson"…).
	 */
	public function item_label(): string {
		return match ( $this ) {
			self::CourseCompleted => __( 'Course', 'open-badges-for-learndash' ),
			self::LessonCompleted => __( 'Lesson', 'open-badges-for-learndash' ),
			self::TopicCompleted  => __( 'Topic', 'open-badges-for-learndash' ),
			self::QuizCompleted   => __( 'Quiz', 'open-badges-for-learndash' ),
		};
	}

	/**
	 * LearnDash post type holding this event's target items.
	 *
	 * Verified against LearnDash 5.1.8 on 2026-08-03; these slugs have been
	 * stable across LearnDash 4.x and 5.x.
	 */
	public function post_type(): string {
		return match ( $this ) {
			self::CourseCompleted => 'sfwd-courses',
			self::LessonCompleted => 'sfwd-lessons',
			self::TopicCompleted  => 'sfwd-topic',
			self::QuizCompleted   => 'sfwd-quiz',
		};
	}

	/**
	 * Whether a minimum-score condition is meaningful for this event.
	 *
	 * Only quizzes produce a score, so only quiz triggers accept one.
	 */
	public function supports_min_score(): bool {
		return self::QuizCompleted === $this;
	}

	/**
	 * LearnDash's own `activity_type` for this event.
	 *
	 * Rows in wp_learndash_user_activity are how LearnDash records (and reports
	 * on) completions, so they are what milestone 5's backfill reads to find
	 * learners who finished an item before its trigger existed. Verified
	 * against LearnDash 5.1.8: a real quiz submission writes an activity row
	 * with activity_type 'quiz' and the full result set as activity_meta.
	 */
	public function activity_type(): string {
		return match ( $this ) {
			self::CourseCompleted => 'course',
			self::LessonCompleted => 'lesson',
			self::TopicCompleted  => 'topic',
			self::QuizCompleted   => 'quiz',
		};
	}
}
