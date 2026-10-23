<?php
/**
 * Badge synchronisation.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Badges;

use OBLD\Api\ApiClient;
use OBLD\Api\HttpTransportInterface;
use OBLD\Api\WpHttpTransport;
use OBLD\Credentials\CredentialStore;
use OBLD\Log\Logger;
use OBLD\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls the badge list from every configured provider into local storage.
 *
 * One provider failing never prevents the other from syncing; every outcome
 * (including failures) is logged and recorded in the obld_last_sync option
 * that the Badges screen and health notices read.
 */
final class SyncService {

	public const LAST_SYNC_OPTION = 'obld_last_sync';

	public const STATUS_SYNCED  = 'synced';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * Constructor.
	 *
	 * @param CredentialStore             $store     Credential storage.
	 * @param BadgeRepository             $repo      Badge storage.
	 * @param Logger                      $logger    Activity log.
	 * @param HttpTransportInterface|null $transport Optional transport override (tests).
	 */
	public function __construct(
		private readonly CredentialStore $store,
		private readonly BadgeRepository $repo,
		private readonly Logger $logger,
		private readonly ?HttpTransportInterface $transport = null,
	) {
	}

	/**
	 * Sync every configured provider. Returns (and persists) a per-provider summary.
	 *
	 * @return array<string, array{status: string, message: string, count: int, added: int, updated: int, removed: int, synced_at: int}>
	 */
	public function sync_all(): array {
		$summary = array();

		foreach ( Provider::cases() as $provider ) {
			$summary[ $provider->value ] = $this->sync_provider( $provider );
		}

		update_option( self::LAST_SYNC_OPTION, $summary, false );

		return $summary;
	}

	/**
	 * Sync a single provider.
	 *
	 * @param Provider $provider Provider to sync.
	 *
	 * @return array{status: string, message: string, count: int, added: int, updated: int, removed: int, synced_at: int}
	 */
	private function sync_provider( Provider $provider ): array {
		if ( ! $this->store->is_configured( $provider ) || $this->store->needs_reentry( $provider ) ) {
			return $this->result( self::STATUS_SKIPPED, __( 'Not configured.', 'open-badges-for-learndash' ) );
		}

		$client = new ApiClient(
			$provider,
			$this->store->client_id( $provider ),
			(string) $this->store->secret( $provider ),
			$this->transport ?? new WpHttpTransport()
		);

		$published = $client->list_badges( false );
		if ( is_wp_error( $published ) ) {
			return $this->failure( $provider, $published->get_error_message() );
		}

		$drafts = $client->list_badges( true );
		if ( is_wp_error( $drafts ) ) {
			return $this->failure( $provider, $drafts->get_error_message() );
		}

		// Make the draft flag explicit per source call — don't trust the
		// payload to always carry it.
		foreach ( $published as &$badge ) {
			$badge['draft'] = ! empty( $badge['draft'] );
		}
		unset( $badge );
		foreach ( $drafts as &$badge ) {
			$badge['draft'] = true;
		}
		unset( $badge );

		$stats = $this->repo->replace_provider( $provider, array_merge( $published, $drafts ) );
		$count = count( $published ) + count( $drafts );

		$this->logger->success(
			sprintf(
				/* translators: 1: badge count, 2: provider name, 3: added count, 4: updated count, 5: removed count. */
				__( 'Synced %1$d badges from %2$s (%3$d added, %4$d updated, %5$d removed).', 'open-badges-for-learndash' ),
				$count,
				$provider->label(),
				$stats['added'],
				$stats['updated'],
				$stats['removed']
			)
		);

		// A badge that vanished at the provider but is still referenced by a
		// trigger is kept and flagged rather than deleted; say so explicitly,
		// because the admin now has a rule that cannot issue.
		if ( ( $stats['missing'] ?? 0 ) > 0 ) {
			$this->logger->error(
				sprintf(
					/* translators: 1: badge count, 2: provider name. */
					__( '%1$d badge(s) used by triggers are no longer available at %2$s; the triggers were kept but cannot issue until the badge returns or the rule is changed.', 'open-badges-for-learndash' ),
					(int) $stats['missing'],
					$provider->label()
				)
			);
		}

		return $this->result( self::STATUS_SYNCED, '', $count, $stats );
	}

	/**
	 * Record and shape a failed provider sync.
	 *
	 * @param Provider $provider Provider that failed.
	 * @param string   $message  Failure detail.
	 *
	 * @return array{status: string, message: string, count: int, added: int, updated: int, removed: int, synced_at: int}
	 */
	private function failure( Provider $provider, string $message ): array {
		$this->logger->error(
			sprintf(
				/* translators: 1: provider name, 2: failure detail. */
				__( 'Badge sync failed for %1$s: %2$s', 'open-badges-for-learndash' ),
				$provider->label(),
				$message
			)
		);

		return $this->result( self::STATUS_FAILED, $message );
	}

	/**
	 * Result-row shape.
	 *
	 * @param string                                                           $status  Status constant.
	 * @param string                                                           $message Detail (failures/skips).
	 * @param int                                                              $count   Badges fetched.
	 * @param array{added: int, updated: int, removed: int, missing: int}|null $stats   Repository stats.
	 *
	 * @return array{status: string, message: string, count: int, added: int, updated: int, removed: int, missing: int, synced_at: int}
	 */
	private function result( string $status, string $message, int $count = 0, ?array $stats = null ): array {
		return array(
			'status'    => $status,
			'message'   => $message,
			'count'     => $count,
			'added'     => $stats['added'] ?? 0,
			'updated'   => $stats['updated'] ?? 0,
			'removed'   => $stats['removed'] ?? 0,
			'missing'   => $stats['missing'] ?? 0,
			'synced_at' => time(),
		);
	}
}
