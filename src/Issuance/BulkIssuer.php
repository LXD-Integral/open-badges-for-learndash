<?php
/**
 * Manual and bulk issuance runs.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Issuance;

use OBLD\LearnDash\CompletionRepository;
use OBLD\Log\Logger;
use OBLD\Triggers\EventType;
use OBLD\Triggers\TriggerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Awards badges outside the automatic flow: by hand to chosen users, and by
 * backfilling a trigger over learners who finished before it existed.
 *
 * Deliberately thin. It decides *who* should get a badge and counts the
 * outcome; the issuing itself is IssuanceService's job, so a manual award goes
 * through exactly the same queue, retry, idempotency and logging as an
 * automatic one. Nothing here talks to a provider.
 */
final class BulkIssuer {

	/**
	 * Hard ceiling on learners processed in one backfill run.
	 *
	 * A run only *queues* awards, so this is generous — but it is bounded, and
	 * the caller is told when the ceiling was hit so a second run can finish
	 * the job rather than the shortfall passing unnoticed.
	 */
	public const MAX_PER_RUN = 2000;

	/**
	 * Constructor.
	 *
	 * @param IssuanceService      $issuance    Issuance queue.
	 * @param TriggerRepository    $triggers    Trigger storage.
	 * @param CompletionRepository $completions LearnDash completion history.
	 * @param Logger               $logger      Activity log.
	 */
	public function __construct(
		private readonly IssuanceService $issuance,
		private readonly TriggerRepository $triggers,
		private readonly CompletionRepository $completions,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Award one badge to chosen learners.
	 *
	 * @param int             $badge_id   Local badge id.
	 * @param array<int, int> $user_ids   Learners to award.
	 * @param string          $badge_name Badge name, for the log message.
	 *
	 * @return array{issued: int, skipped: int, failed: int, truncated: bool}
	 */
	public function issue_manually( int $badge_id, array $user_ids, string $badge_name = '' ): array {
		$result = $this->run( $badge_id, $user_ids );

		$this->logger->success(
			sprintf(
				/* translators: 1: badge name, 2: queued count, 3: skipped count, 4: failed count. */
				__( 'Manual issuance of “%1$s”: %2$d queued, %3$d already had it, %4$d could not be issued.', 'open-badges-for-learndash' ),
				$badge_name,
				$result['issued'],
				$result['skipped'],
				$result['failed']
			),
			array( 'badge_id' => $badge_id )
		);

		return $result;
	}

	/**
	 * Award a trigger's badge to everyone who already completed its item.
	 *
	 * @param int $trigger_id Trigger to backfill.
	 *
	 * @return array{issued: int, skipped: int, failed: int, truncated: bool}|\WP_Error
	 */
	public function backfill( int $trigger_id ): array|\WP_Error {
		$trigger = $this->triggers->find( $trigger_id );

		if ( null === $trigger ) {
			return new \WP_Error( 'obld_no_trigger', __( 'That trigger no longer exists.', 'open-badges-for-learndash' ) );
		}

		$event_type = EventType::tryFrom( (string) $trigger->event_type );

		if ( ! $event_type instanceof EventType ) {
			return new \WP_Error( 'obld_bad_trigger', __( 'That trigger has an unrecognised event type.', 'open-badges-for-learndash' ) );
		}

		if ( ! $this->completions->available() ) {
			return new \WP_Error(
				'obld_no_activity_table',
				__( 'LearnDash’s activity table is not available, so past completions cannot be read.', 'open-badges-for-learndash' )
			);
		}

		$min_score = null === $trigger->min_score ? null : (float) $trigger->min_score;

		$totals = array(
			'issued'    => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'truncated' => false,
		);

		// Page through the completers rather than loading every learner at once.
		$offset = 0;
		while ( $offset < self::MAX_PER_RUN ) {
			$user_ids = $this->completions->completers(
				$event_type,
				(int) $trigger->target_id,
				$min_score,
				CompletionRepository::PAGE_SIZE,
				$offset
			);

			if ( array() === $user_ids ) {
				break;
			}

			$page = $this->run( (int) $trigger->badge_id, $user_ids );

			$totals['issued']  += $page['issued'];
			$totals['skipped'] += $page['skipped'];
			$totals['failed']  += $page['failed'];

			if ( count( $user_ids ) < CompletionRepository::PAGE_SIZE ) {
				break;
			}

			$offset += CompletionRepository::PAGE_SIZE;
		}

		$totals['truncated'] = $offset >= self::MAX_PER_RUN;

		$this->logger->success(
			sprintf(
				/* translators: 1: badge name, 2: queued count, 3: skipped count, 4: failed count. */
				__( 'Backfill of “%1$s”: %2$d queued, %3$d already had it, %4$d could not be issued.', 'open-badges-for-learndash' ),
				(string) ( $trigger->badge_name ?? '' ),
				$totals['issued'],
				$totals['skipped'],
				$totals['failed']
			),
			array(
				'badge_id'   => (int) $trigger->badge_id,
				'trigger_id' => $trigger_id,
			)
		);

		return $totals;
	}

	/**
	 * Queue one badge for a set of learners and tally the outcome.
	 *
	 * @param int             $badge_id Local badge id.
	 * @param array<int, int> $user_ids Learners.
	 *
	 * @return array{issued: int, skipped: int, failed: int, truncated: bool}
	 */
	private function run( int $badge_id, array $user_ids ): array {
		$issued  = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user || '' === (string) $user->user_email ) {
				++$failed;

				$this->logger->error(
					sprintf(
						/* translators: %d: user ID. */
						__( 'Cannot issue a badge to user %d: the account has no email address.', 'open-badges-for-learndash' ),
						$user_id
					),
					array(
						'user_id'  => $user_id,
						'badge_id' => $badge_id,
					)
				);
				continue;
			}

			// 0 means "already has this badge" — the skip the PRD asks for.
			if ( 0 === $this->issuance->queue_manual( $user_id, $badge_id, (string) $user->user_email ) ) {
				++$skipped;
				continue;
			}

			++$issued;
		}

		return array(
			'issued'    => $issued,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'truncated' => false,
		);
	}
}
