<?php
/**
 * LearnDash completion listener.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\LearnDash;

use OBLD\Badges\BadgeRepository;
use OBLD\Issuance\IssuanceService;
use OBLD\Log\Logger;
use OBLD\Triggers\EventType;
use OBLD\Triggers\TriggerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Turns LearnDash completion events into queued awards.
 *
 * Hook signatures verified against **LearnDash 5.1.8** source (the PRD names
 * 4.x; the slugs are unchanged but the payloads must not be assumed):
 *
 *  - `learndash_course_completed`  → one array: user, course, progress, course_completed
 *  - `learndash_lesson_completed`  → one array: user, course, lesson, progress
 *  - `learndash_topic_completed`   → one array: user, course, lesson, topic, progress
 *  - `learndash_quiz_completed`    → TWO args: $quizdata array, WP_User
 *                                    ($quizdata: quiz, percentage, pass, …)
 *
 * Nothing here talks to a provider. Each handler resolves the learner and the
 * completed item, then hands off to IssuanceService::queue(), which only
 * writes a row and schedules cron — a provider outage can never slow down or
 * break a learner's completion.
 */
final class CompletionListener {

	/**
	 * Constructor.
	 *
	 * @param TriggerRepository $triggers Trigger storage.
	 * @param BadgeRepository   $badges   Badge storage.
	 * @param IssuanceService   $issuance Issuance queue.
	 * @param Logger            $logger   Activity log.
	 */
	public function __construct(
		private readonly TriggerRepository $triggers,
		private readonly BadgeRepository $badges,
		private readonly IssuanceService $issuance,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'learndash_course_completed', array( $this, 'on_course_completed' ), 10, 1 );
		add_action( 'learndash_lesson_completed', array( $this, 'on_lesson_completed' ), 10, 1 );
		add_action( 'learndash_topic_completed', array( $this, 'on_topic_completed' ), 10, 1 );
		add_action( 'learndash_quiz_completed', array( $this, 'on_quiz_completed' ), 10, 2 );
	}

	/**
	 * Course completed.
	 *
	 * @param array<string, mixed> $data LearnDash payload.
	 */
	public function on_course_completed( $data ): void {
		$this->handle( EventType::CourseCompleted, $this->user_id( $data ), $this->post_id( $data, 'course' ) );
	}

	/**
	 * Lesson completed.
	 *
	 * @param array<string, mixed> $data LearnDash payload.
	 */
	public function on_lesson_completed( $data ): void {
		$this->handle( EventType::LessonCompleted, $this->user_id( $data ), $this->post_id( $data, 'lesson' ) );
	}

	/**
	 * Topic completed.
	 *
	 * @param array<string, mixed> $data LearnDash payload.
	 */
	public function on_topic_completed( $data ): void {
		$this->handle( EventType::TopicCompleted, $this->user_id( $data ), $this->post_id( $data, 'topic' ) );
	}

	/**
	 * Quiz completed. Unlike the other three, this hook passes the user
	 * separately and carries the score.
	 *
	 * @param array<string, mixed> $quizdata Quiz result data.
	 * @param mixed                $user     WP_User for the learner.
	 */
	public function on_quiz_completed( $quizdata, $user = null ): void {
		if ( ! is_array( $quizdata ) ) {
			return;
		}

		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
		$quiz_id = isset( $quizdata['quiz'] ) ? (int) $quizdata['quiz'] : 0;
		$score   = isset( $quizdata['percentage'] ) && is_numeric( $quizdata['percentage'] )
			? (float) $quizdata['percentage']
			: null;

		$this->handle( EventType::QuizCompleted, $user_id, $quiz_id, $score );
	}

	/**
	 * Match a completion to enabled triggers and queue each award.
	 *
	 * @param EventType  $event_type Event that occurred.
	 * @param int        $user_id    Learner.
	 * @param int        $target_id  LearnDash item completed.
	 * @param float|null $score      Quiz score percentage, when applicable.
	 */
	private function handle( EventType $event_type, int $user_id, int $target_id, ?float $score = null ): void {
		if ( $user_id <= 0 || $target_id <= 0 ) {
			return;
		}

		$matches = $this->triggers->matching( $event_type, $target_id );

		if ( array() === $matches ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user || '' === (string) $user->user_email ) {
			$this->logger->error(
				sprintf(
					/* translators: %d: user ID. */
					__( 'Cannot issue a badge to user %d: the account has no email address.', 'open-badges-for-learndash' ),
					$user_id
				),
				array( 'user_id' => $user_id )
			);
			return;
		}

		foreach ( $matches as $trigger ) {
			$this->queue_one( $trigger, $user_id, (string) $user->user_email, $score );
		}
	}

	/**
	 * Apply one trigger's conditions and queue its award.
	 *
	 * @param object     $trigger Trigger row joined to its badge.
	 * @param int        $user_id Learner.
	 * @param string     $email   Recipient email.
	 * @param float|null $score   Quiz score percentage, when applicable.
	 */
	private function queue_one( object $trigger, int $user_id, string $email, ?float $score ): void {
		// A badge that has vanished at the provider cannot be issued. Say so
		// rather than queueing an award that is certain to fail.
		if ( ! empty( $trigger->badge_missing_at_provider ) ) {
			$this->logger->error(
				sprintf(
					/* translators: %s: badge name. */
					__( 'Skipped issuing “%s”: the badge is no longer available at its provider.', 'open-badges-for-learndash' ),
					(string) $trigger->badge_name
				),
				array(
					'user_id'    => $user_id,
					'badge_id'   => (int) $trigger->badge_id,
					'trigger_id' => (int) $trigger->id,
				)
			);
			return;
		}

		if ( null !== $trigger->min_score ) {
			// A score condition with no score is not satisfied — never award
			// on the assumption that a missing score means a pass.
			if ( null === $score || $score < (float) $trigger->min_score ) {
				return;
			}
		}

		$this->issuance->queue( $user_id, (int) $trigger->badge_id, (int) $trigger->id, $email );
	}

	/**
	 * Learner id from a LearnDash payload.
	 *
	 * @param mixed $data LearnDash payload.
	 */
	private function user_id( $data ): int {
		if ( ! is_array( $data ) ) {
			return 0;
		}

		$user = $data['user'] ?? null;

		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}

	/**
	 * Post id for one key of a LearnDash payload.
	 *
	 * @param mixed  $data LearnDash payload.
	 * @param string $key  Payload key holding a WP_Post.
	 */
	private function post_id( $data, string $key ): int {
		if ( ! is_array( $data ) ) {
			return 0;
		}

		$post = $data[ $key ] ?? null;

		return $post instanceof \WP_Post ? (int) $post->ID : 0;
	}
}
