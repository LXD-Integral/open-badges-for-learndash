<?php
/**
 * Background badge issuance.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Issuance;

use OBLD\Api\ApiClient;
use OBLD\Api\HttpTransportInterface;
use OBLD\Api\WpHttpTransport;
use OBLD\Credentials\CredentialStore;
use OBLD\Log\Logger;
use OBLD\PageCache;
use OBLD\Provider;
use OBLD\Triggers\TriggerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queues and performs badge issuance.
 *
 * This class exists to avoid, by construction, the four failures that defeated
 * the predecessor plugin:
 *
 *  1. Cross-provider token collision — the client is built per provider and
 *     ApiClient caches tokens per provider (milestone 1).
 *  2. Synchronous issuance inside the completion hook — queue() only writes a
 *     row and schedules cron, so a learner's completion is never slowed by, or
 *     exposed to, a provider outage.
 *  3. No retry — failures are re-scheduled with backoff, and a recurring sweep
 *     catches anything whose single event was lost.
 *  4. No idempotency — enforced by a unique index in AwardRepository::claim().
 *
 * Not final: tests substitute a queue-recording fake, and milestone 5's manual
 * and backfill runs reuse queue() rather than reimplementing issuance.
 */
class IssuanceService {

	/**
	 * Cron hook for one queued award.
	 */
	public const PROCESS_HOOK = 'obld_process_award';

	/**
	 * Recurring safety-net sweep.
	 */
	public const SWEEP_HOOK = 'obld_retry_awards';

	/**
	 * Attempts before an award is abandoned.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Backoff before each retry, in seconds, indexed by attempts already made.
	 * Past the end of the list the award is abandoned, so no value is reused.
	 *
	 * @var array<int, int>
	 */
	private const BACKOFF = array( 300, 900, 3600, 21600 );

	/**
	 * Constructor.
	 *
	 * @param CredentialStore             $store     Credential storage.
	 * @param AwardRepository             $awards    Award storage.
	 * @param TriggerRepository           $triggers  Trigger storage.
	 * @param Logger                      $logger    Activity log.
	 * @param HttpTransportInterface|null $transport Optional transport override (tests).
	 */
	public function __construct(
		private readonly CredentialStore $store,
		private readonly AwardRepository $awards,
		private readonly TriggerRepository $triggers,
		private readonly Logger $logger,
		private readonly ?HttpTransportInterface $transport = null,
	) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( self::PROCESS_HOOK, array( $this, 'process' ) );
		add_action( self::SWEEP_HOOK, array( $this, 'sweep' ) );

		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::SWEEP_HOOK );
		}
	}

	/**
	 * Queue an award for a learner and schedule it.
	 *
	 * Returns the award id, or 0 when this learner already has an award for
	 * this trigger (the duplicate is the expected case, not an error, so it is
	 * not logged as one).
	 *
	 * @param int    $user_id    Learner.
	 * @param int    $badge_id   Local badge id.
	 * @param int    $trigger_id Trigger id.
	 * @param string $email      Recipient email.
	 */
	public function queue( int $user_id, int $badge_id, int $trigger_id, string $email ): int {
		$award_id = $this->awards->claim( $user_id, $badge_id, $trigger_id, $email );

		if ( 0 === $award_id ) {
			return 0;
		}

		// Fire on the next request rather than inline: the learner's completion
		// must never wait on, or fail because of, the provider.
		wp_schedule_single_event( time(), self::PROCESS_HOOK, array( $award_id ) );

		return $award_id;
	}

	/**
	 * Queue an award with no trigger behind it (manual issuance, or a backfill
	 * the admin ran by hand). Returns the award id, or 0 when the learner
	 * already holds this badge.
	 *
	 * Same background path as queue(): the API call, retry, and logging are
	 * shared, so a manual run behaves exactly like an automatic one.
	 *
	 * @param int    $user_id  Learner.
	 * @param int    $badge_id Local badge id.
	 * @param string $email    Recipient email.
	 */
	public function queue_manual( int $user_id, int $badge_id, string $email ): int {
		$award_id = $this->awards->claim_manual( $user_id, $badge_id, $email );

		if ( 0 === $award_id ) {
			return 0;
		}

		wp_schedule_single_event( time(), self::PROCESS_HOOK, array( $award_id ) );

		return $award_id;
	}

	/**
	 * Process one queued award (cron).
	 *
	 * @param int $award_id Award id.
	 */
	public function process( int $award_id ): void {
		$award = $this->awards->find( $award_id );

		if ( null === $award || AwardRepository::STATUS_ISSUED === $award->status ) {
			return;
		}

		$attempts = (int) $award->attempts + 1;

		$failure = $this->attempt( $award );

		if ( null === $failure ) {
			return;
		}

		$abandoned = $attempts >= self::MAX_ATTEMPTS;
		$this->awards->mark_failed( $award_id, $failure, $attempts, $abandoned );

		$this->logger->error(
			$abandoned
				? sprintf(
					/* translators: 1: badge name, 2: attempt count, 3: failure detail. */
					__( 'Gave up issuing “%1$s” after %2$d attempts: %3$s', 'open-badges-for-learndash' ),
					(string) $award->badge_name,
					$attempts,
					$failure
				)
				: sprintf(
					/* translators: 1: badge name, 2: attempt number, 3: failure detail. */
					__( 'Attempt %2$d to issue “%1$s” failed, will retry: %3$s', 'open-badges-for-learndash' ),
					(string) $award->badge_name,
					$attempts,
					$failure
				),
			array(
				'user_id'    => (int) $award->user_id,
				'badge_id'   => (int) $award->badge_id,
				'trigger_id' => (int) $award->trigger_id,
			)
		);

		if ( ! $abandoned ) {
			// $attempts < MAX_ATTEMPTS here, so the index always exists; the
			// fallback only guards a future change to either constant.
			$backoff = self::BACKOFF;
			$delay   = $backoff[ $attempts - 1 ] ?? $backoff[ count( $backoff ) - 1 ];
			wp_schedule_single_event( time() + $delay, self::PROCESS_HOOK, array( $award_id ) );
		}
	}

	/**
	 * One issuance attempt. Returns null on success, or a failure message.
	 *
	 * @param object $award Award row joined to its badge.
	 */
	private function attempt( object $award ): ?string {
		$provider = Provider::tryFrom( (string) $award->badge_provider );

		if ( ! $provider instanceof Provider || '' === (string) $award->badge_provider_badge_id ) {
			// The badge row is gone or was never resolvable — retrying cannot
			// help, so burn the attempts immediately rather than looping.
			return __( 'the badge is no longer available locally.', 'open-badges-for-learndash' );
		}

		if ( ! $this->store->is_configured( $provider ) || $this->store->needs_reentry( $provider ) ) {
			return sprintf(
				/* translators: %s: provider name. */
				__( '%s is not configured, so the badge could not be issued.', 'open-badges-for-learndash' ),
				$provider->label()
			);
		}

		$user = get_userdata( (int) $award->user_id );
		$name = $user ? (string) $user->display_name : '';

		$client = new ApiClient(
			$provider,
			$this->store->client_id( $provider ),
			(string) $this->store->secret( $provider ),
			$this->transport ?? new WpHttpTransport()
		);

		$event_id = $client->issue_badge(
			(string) $award->badge_provider_badge_id,
			(string) $award->recipient_email,
			$name
		);

		if ( is_wp_error( $event_id ) ) {
			return $event_id->get_error_message();
		}

		$this->awards->mark_issued( (int) $award->id, $event_id );

		// Any cached page holding this learner's badge list just went stale.
		PageCache::purge();

		if ( $award->trigger_id ) {
			$this->triggers->increment_fired( (int) $award->trigger_id );
		}

		$this->logger->success(
			sprintf(
				/* translators: 1: badge name, 2: recipient email. */
				__( 'Issued “%1$s” to %2$s.', 'open-badges-for-learndash' ),
				(string) $award->badge_name,
				(string) $award->recipient_email
			),
			array(
				'user_id'    => (int) $award->user_id,
				'badge_id'   => (int) $award->badge_id,
				'trigger_id' => (int) $award->trigger_id,
			)
		);

		$this->enrich( $client, (int) $award->id, $event_id, (string) $award->recipient_email, true );

		return null;
	}

	/**
	 * Fetch and store an issued award's export links (milestone 7).
	 *
	 * The badge IS issued by the time this runs, so nothing here may fail the
	 * award: a lookup problem leaves the columns NULL and the hourly sweep
	 * retries. When the provider answers but has no recipient row (event
	 * deleted, recipient removed), '' is stored so the sweep stops asking.
	 *
	 * @param ApiClient $client   Provider client, already built by the caller.
	 * @param int       $award_id Award id.
	 * @param string    $event_id Provider issuing-event id.
	 * @param string    $email    Recipient email.
	 * @param bool      $log_failure Whether to log a lookup failure. The inline
	 *                               call logs (the admin should see the links are
	 *                               missing); hourly sweep retries stay silent so
	 *                               a provider outage cannot flood the log.
	 */
	private function enrich( ApiClient $client, int $award_id, string $event_id, string $email, bool $log_failure = false ): void {
		if ( '' === $event_id ) {
			// The provider reported success without an event id; there is
			// nothing to look up, now or ever.
			return;
		}

		$recipients = $client->event_recipients( $event_id, $email );

		if ( is_wp_error( $recipients ) ) {
			if ( $log_failure ) {
				$this->logger->error(
					sprintf(
						/* translators: 1: recipient email, 2: failure detail. */
						__( 'The badge was issued, but its share links could not be fetched for %1$s (will retry hourly): %2$s', 'open-badges-for-learndash' ),
						$email,
						$recipients->get_error_message()
					)
				);
			}

			return;
		}

		$row = $recipients[0] ?? array();

		$this->awards->record_assertion(
			$award_id,
			is_array( $row ) ? (string) ( $row['assertion_id'] ?? '' ) : '',
			is_array( $row ) ? (string) ( $row['receive_url'] ?? '' ) : '',
			is_array( $row ) ? (string) ( $row['assertion_json_url'] ?? '' ) : ''
		);

		// Cached lists rendered between issuance and (sweep) enrichment show
		// the badge without its links.
		PageCache::purge();
	}

	/**
	 * Enrich one already-issued award from its stored provider event (sweep).
	 *
	 * @param object $row Row from AwardRepository::unenriched().
	 */
	private function enrich_row( object $row ): void {
		$provider = Provider::tryFrom( (string) $row->badge_provider );

		if ( ! $provider instanceof Provider || ! $this->store->is_configured( $provider ) || $this->store->needs_reentry( $provider ) ) {
			return;
		}

		$client = new ApiClient(
			$provider,
			$this->store->client_id( $provider ),
			(string) $this->store->secret( $provider ),
			$this->transport ?? new WpHttpTransport()
		);

		$this->enrich( $client, (int) $row->id, (string) $row->provider_event_id, (string) $row->recipient_email );
	}

	/**
	 * Safety-net sweep (hourly cron): re-process anything still outstanding.
	 *
	 * A single scheduled event can be lost — WP-Cron only runs on traffic, and
	 * a crash between claiming and scheduling would strand a row forever. This
	 * catches those. process() is idempotent, so re-entry is harmless.
	 *
	 * The sweep also fetches export links for issued awards that lack them:
	 * inline enrichment can fail, and every award issued before milestone 7
	 * has never been enriched at all — this is their backfill.
	 */
	public function sweep(): void {
		foreach ( $this->awards->retryable( self::MAX_ATTEMPTS ) as $award_id ) {
			$this->process( $award_id );
		}

		foreach ( $this->awards->unenriched() as $row ) {
			$this->enrich_row( $row );
		}
	}

	/**
	 * Clear the recurring sweep (deactivation).
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::SWEEP_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::SWEEP_HOOK );
		}
	}
}
