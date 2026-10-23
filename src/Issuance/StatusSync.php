<?php
/**
 * Provider-side credential status refresh.
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

defined( 'ABSPATH' ) || exit;

/**
 * Reconciles local award rows with what the provider now says about each
 * credential: revoked, expiry, opened ("received"), saved to a Passport.
 *
 * Issuance is a one-way write; this is the read-back. One paged listing per
 * provider (not one request per award) is compared against every issued
 * award of that provider, and only rows whose facts changed are written.
 * The provider is authoritative — flags are set to whatever it reports.
 *
 * Runs on the hourly sweep and from the Badges screen's "Refresh Award
 * Status" action. A refresh can never affect issuance: it touches only the
 * status columns.
 */
final class StatusSync {

	public const LAST_SYNC_OPTION = 'obld_last_status_sync';

	public const STATUS_CHECKED = 'checked';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * Constructor.
	 *
	 * @param CredentialStore             $store     Credential storage.
	 * @param AwardRepository             $awards    Award storage.
	 * @param Logger                      $logger    Activity log.
	 * @param HttpTransportInterface|null $transport Optional transport override (tests).
	 */
	public function __construct(
		private readonly CredentialStore $store,
		private readonly AwardRepository $awards,
		private readonly Logger $logger,
		private readonly ?HttpTransportInterface $transport = null,
	) {
	}

	/**
	 * Hook registration: piggy-back on the existing hourly sweep event.
	 */
	public function register(): void {
		add_action( IssuanceService::SWEEP_HOOK, array( $this, 'sweep' ) );
	}

	/**
	 * Cron entry point.
	 */
	public function sweep(): void {
		$this->sync_all( false );
	}

	/**
	 * Refresh every configured provider. Returns (and persists) a per-provider summary.
	 *
	 * @param bool $manual True for an admin-initiated run: failures are then
	 *                     logged to the Activity Log. The hourly sweep records
	 *                     failures in the summary only, so a provider outage
	 *                     cannot flood the log (same rule as milestone 7's
	 *                     enrichment retries).
	 *
	 * @return array<string, array{status: string, message: string, checked: int, changed: int, revoked: int, saved: int, expiry: int, checked_at: int}>
	 */
	public function sync_all( bool $manual = false ): array {
		$summary = array();

		foreach ( Provider::cases() as $provider ) {
			$summary[ $provider->value ] = $this->sync_provider( $provider, $manual );
		}

		update_option( self::LAST_SYNC_OPTION, $summary, false );

		return $summary;
	}

	/**
	 * Refresh one provider.
	 *
	 * @param Provider $provider Provider.
	 * @param bool     $manual   Admin-initiated run.
	 *
	 * @return array{status: string, message: string, checked: int, changed: int, revoked: int, saved: int, expiry: int, checked_at: int}
	 */
	private function sync_provider( Provider $provider, bool $manual ): array {
		if ( ! $this->store->is_configured( $provider ) || $this->store->needs_reentry( $provider ) ) {
			return $this->result( self::STATUS_SKIPPED, __( 'Not configured.', 'open-badges-for-learndash' ) );
		}

		$awards = $this->awards->issued_for_provider( $provider );

		if ( array() === $awards ) {
			// Nothing to compare — and no reason to spend a request.
			return $this->result( self::STATUS_CHECKED );
		}

		$client = new ApiClient(
			$provider,
			$this->store->client_id( $provider ),
			(string) $this->store->secret( $provider ),
			$this->transport ?? new WpHttpTransport()
		);

		$recipients = $client->list_recipients();

		if ( is_wp_error( $recipients ) ) {
			if ( $manual ) {
				$this->logger->error(
					sprintf(
						/* translators: 1: provider name, 2: failure detail. */
						__( 'Award status check failed for %1$s: %2$s', 'open-badges-for-learndash' ),
						$provider->label(),
						$recipients->get_error_message()
					)
				);
			}

			return $this->result( self::STATUS_FAILED, $recipients->get_error_message() );
		}

		$by_assertion = array();
		$by_event     = array();

		foreach ( $recipients as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$assertion_id = (string) ( $row['assertion_id'] ?? '' );
			$event_id     = (string) ( $row['event_id'] ?? '' );
			$email        = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );

			if ( '' !== $assertion_id ) {
				$by_assertion[ $assertion_id ] = $row;
			}

			if ( '' !== $event_id && '' !== $email ) {
				$by_event[ $event_id . '|' . $email ] = $row;
			}
		}

		$tally = array(
			'changed' => 0,
			'revoked' => 0,
			'saved'   => 0,
			'expiry'  => 0,
		);

		foreach ( $awards as $award ) {
			// Enriched awards match on the assertion id the provider minted;
			// awards whose enrichment never ran fall back to the issuing
			// event plus the recipient address. An award matching neither
			// (event deleted at the provider) is left exactly as it was —
			// absence is not evidence of revocation.
			$assertion_id = (string) ( $award->assertion_id ?? '' );
			$event_key    = (string) ( $award->provider_event_id ?? '' ) . '|' . strtolower( trim( (string) ( $award->recipient_email ?? '' ) ) );

			$row = $by_assertion[ $assertion_id ] ?? $by_event[ $event_key ] ?? null;

			if ( null === $row ) {
				continue;
			}

			$revoked     = ! empty( $row['revoked'] );
			$expires_on  = self::expiry( $row['expires_on'] ?? null );
			$received    = ! empty( $row['received'] );
			$in_passport = ! empty( $row['in_passport'] );

			$was_revoked     = ! empty( $award->revoked );
			$was_expires_on  = self::normalise_datetime( $award->expires_on ?? null );
			$was_received    = ! empty( $award->received );
			$was_in_passport = ! empty( $award->in_passport );

			if (
				$revoked === $was_revoked
				&& $expires_on === $was_expires_on
				&& $received === $was_received
				&& $in_passport === $was_in_passport
			) {
				continue;
			}

			$this->awards->record_status( (int) $award->id, $revoked, $expires_on, $received, $in_passport );

			++$tally['changed'];

			if ( $revoked && ! $was_revoked ) {
				++$tally['revoked'];
			}

			if ( $in_passport && ! $was_in_passport ) {
				++$tally['saved'];
			}

			if ( $expires_on !== $was_expires_on ) {
				++$tally['expiry'];
			}
		}

		$this->awards->mark_status_checked( $provider );

		if ( $tally['changed'] > 0 ) {
			// A hidden (revoked) badge or a changed label is already sitting
			// in someone's cached page.
			PageCache::purge();

			$this->logger->success(
				sprintf(
					/* translators: 1: provider name, 2: awards compared, 3: awards changed, 4: newly revoked, 5: newly saved to Passport, 6: expiry dates set or changed. */
					__( 'Award status check (%1$s): %2$d awards compared, %3$d changed — %4$d newly revoked, %5$d newly saved to Passport, %6$d expiry updates.', 'open-badges-for-learndash' ),
					$provider->label(),
					count( $awards ),
					$tally['changed'],
					$tally['revoked'],
					$tally['saved'],
					$tally['expiry']
				)
			);
		}

		return $this->result( self::STATUS_CHECKED, '', count( $awards ), $tally );
	}

	/**
	 * Provider expiry (Unix epoch, or null/0 for "never") → UTC MySQL datetime or null.
	 *
	 * @param mixed $epoch Raw value from the recipient row.
	 */
	private static function expiry( mixed $epoch ): ?string {
		if ( ! is_numeric( $epoch ) || (int) $epoch <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', (int) $epoch );
	}

	/**
	 * Stored datetime → comparable form ('' and '0000-00-00 00:00:00' count as null).
	 *
	 * @param mixed $stored Raw column value.
	 */
	private static function normalise_datetime( mixed $stored ): ?string {
		if ( ! is_string( $stored ) || '' === $stored || str_starts_with( $stored, '0000-00-00' ) ) {
			return null;
		}

		return $stored;
	}

	/**
	 * Result-row shape.
	 *
	 * @param string                                                          $status  Status constant.
	 * @param string                                                          $message Detail (failures/skips).
	 * @param int                                                             $checked Awards compared.
	 * @param array{changed: int, revoked: int, saved: int, expiry: int}|null $tally   Change counts.
	 *
	 * @return array{status: string, message: string, checked: int, changed: int, revoked: int, saved: int, expiry: int, checked_at: int}
	 */
	private function result( string $status, string $message = '', int $checked = 0, ?array $tally = null ): array {
		return array(
			'status'     => $status,
			'message'    => $message,
			'checked'    => $checked,
			'changed'    => $tally['changed'] ?? 0,
			'revoked'    => $tally['revoked'] ?? 0,
			'saved'      => $tally['saved'] ?? 0,
			'expiry'     => $tally['expiry'] ?? 0,
			'checked_at' => time(),
		);
	}
}
