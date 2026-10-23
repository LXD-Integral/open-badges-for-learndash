<?php
/**
 * Award-record storage.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Issuance;

use OBLD\Provider;
use OBLD\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see the class docblock below.

/**
 * Reads and writes the obld_awards table.
 *
 * Like the other repositories: thin, logic-free, non-final so tests can fake
 * the $wpdb-bound parts, and every interpolated value is a Schema::table()
 * name (built from $wpdb->prefix and a literal). Caller values are bound
 * through $wpdb->prepare().
 *
 * The idempotency guarantee lives here, in the database rather than in PHP:
 * obld_awards carries UNIQUE KEY user_trigger (user_id, trigger_id), so a
 * duplicate INSERT fails at the index instead of racing a read-then-write.
 */
class AwardRepository {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_ISSUED    = 'issued';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_ABANDONED = 'abandoned';

	/**
	 * Claim the right to award a badge to a learner for a trigger.
	 *
	 * Returns the new award id, or 0 when this learner already has an award
	 * row for this trigger — which is exactly the "never issue the same badge
	 * twice for the same trigger and learner" guarantee. Relies on the unique
	 * index rejecting the second insert; no SELECT-then-INSERT window exists.
	 *
	 * @param int    $user_id    Learner.
	 * @param int    $badge_id   Local badge id.
	 * @param int    $trigger_id Trigger that caused this.
	 * @param string $email      Recipient email captured at queue time.
	 */
	public function claim( int $user_id, int $badge_id, int $trigger_id, string $email ): int {
		global $wpdb;

		// Suppress the duplicate-key warning: a rejected insert is the normal,
		// expected outcome for an already-awarded learner, not an error.
		$suppress = $wpdb->suppress_errors( true );

		$inserted = $wpdb->insert(
			Schema::table( 'awards' ),
			array(
				'user_id'         => $user_id,
				'badge_id'        => $badge_id,
				'trigger_id'      => $trigger_id,
				'recipient_email' => $email,
				'status'          => self::STATUS_PENDING,
				'attempts'        => 0,
				'created_at'      => current_time( 'mysql', true ),
			)
		);

		$wpdb->suppress_errors( $suppress );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Claim the right to award a badge to a learner *without* a trigger.
	 *
	 * Manual and backfill-without-trigger awards store a NULL trigger_id, and
	 * MySQL permits many NULLs in a unique index — so the (user_id, trigger_id)
	 * index does NOT deduplicate these. The check is therefore explicit and at
	 * badge level: "does this person already have this badge, however it was
	 * awarded?". Returns 0 when they do.
	 *
	 * @param int    $user_id  Learner.
	 * @param int    $badge_id Local badge id.
	 * @param string $email    Recipient email captured at queue time.
	 */
	public function claim_manual( int $user_id, int $badge_id, string $email ): int {
		global $wpdb;

		if ( $this->has_badge( $user_id, $badge_id ) ) {
			return 0;
		}

		$inserted = $wpdb->insert(
			Schema::table( 'awards' ),
			array(
				'user_id'         => $user_id,
				'badge_id'        => $badge_id,
				'trigger_id'      => null,
				'recipient_email' => $email,
				'status'          => self::STATUS_PENDING,
				'attempts'        => 0,
				'created_at'      => current_time( 'mysql', true ),
			)
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * A single award joined to the badge it issues.
	 *
	 * @param int $id Award id.
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		$awards = Schema::table( 'awards' );
		$badges = Schema::table( 'badges' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.*,
					b.provider AS badge_provider,
					b.provider_badge_id AS badge_provider_badge_id,
					b.name AS badge_name
				FROM {$awards} a
				LEFT JOIN {$badges} b ON b.id = a.badge_id
				WHERE a.id = %d",
				$id
			)
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Award ids still worth attempting: pending, or failed below the attempt
	 * cap. Ordered oldest first so a backlog drains in the order it arrived.
	 *
	 * @param int $max_attempts Attempt cap.
	 * @param int $limit        Maximum ids to return.
	 *
	 * @return array<int, int>
	 */
	public function retryable( int $max_attempts, int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::table( 'awards' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE status IN ( %s, %s )
				AND attempts < %d
				ORDER BY id ASC
				LIMIT %d",
				self::STATUS_PENDING,
				self::STATUS_FAILED,
				$max_attempts,
				$limit
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Record a successful issuance.
	 *
	 * @param int    $id       Award id.
	 * @param string $event_id Provider issuing-event id.
	 */
	public function mark_issued( int $id, string $event_id ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'awards' ),
			array(
				'status'            => self::STATUS_ISSUED,
				'provider_event_id' => $event_id,
				'error_message'     => null,
				'issued_at'         => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Record the per-recipient verifiable-export links for an issued award.
	 *
	 * Empty strings are meaningful: enrichment stores '' when the provider
	 * answered but had no recipient row (event deleted or recipient removed),
	 * so the sweep stops retrying. NULL means "not looked up yet".
	 *
	 * @param int    $id                 Award id.
	 * @param string $assertion_id       Provider assertion id.
	 * @param string $receive_url        Recipient badge-page URL (claim link).
	 * @param string $assertion_json_url Hosted OB 3.0 assertion JSON URL.
	 */
	public function record_assertion( int $id, string $assertion_id, string $receive_url, string $assertion_json_url ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'awards' ),
			array(
				'assertion_id'       => $assertion_id,
				'receive_url'        => $receive_url,
				'assertion_json_url' => $assertion_json_url,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Issued awards whose export links have never been fetched.
	 *
	 * A receive_url of NULL is the "not looked up yet" state — a stored '' means
	 * the provider was asked and had nothing, and is deliberately excluded so
	 * a dead event is not re-queried every sweep. Rows predating milestone 7
	 * are NULL, which is what makes the sweep a self-running backfill.
	 *
	 * @param int $limit Maximum rows returned.
	 *
	 * @return array<int, object> Rows with id, provider_event_id, recipient_email, badge_provider.
	 */
	public function unenriched( int $limit = 25 ): array {
		global $wpdb;

		$awards = Schema::table( 'awards' );
		$badges = Schema::table( 'badges' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.provider_event_id, a.recipient_email,
					b.provider AS badge_provider
				FROM {$awards} a
				INNER JOIN {$badges} b ON b.id = a.badge_id
				WHERE a.status = %s
				AND a.provider_event_id IS NOT NULL AND a.provider_event_id <> ''
				AND a.receive_url IS NULL
				ORDER BY a.id ASC
				LIMIT %d",
				self::STATUS_ISSUED,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Record a failed attempt.
	 *
	 * @param int    $id        Award id.
	 * @param string $message   Failure detail.
	 * @param int    $attempts  New attempt count.
	 * @param bool   $abandoned Whether the attempt cap is now exhausted.
	 */
	public function mark_failed( int $id, string $message, int $attempts, bool $abandoned ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'awards' ),
			array(
				'status'        => $abandoned ? self::STATUS_ABANDONED : self::STATUS_FAILED,
				'attempts'      => $attempts,
				'error_message' => $message,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Whether a learner already has a non-failed award of a badge.
	 *
	 * Trigger-driven issuance is deduplicated by the unique index; this covers
	 * the badge-level question milestone 5's manual and backfill runs ask
	 * ("has this person already got this badge, however it was awarded?").
	 *
	 * @param int $user_id  Learner.
	 * @param int $badge_id Local badge id.
	 */
	public function has_badge( int $user_id, int $badge_id ): bool {
		global $wpdb;

		$table = Schema::table( 'awards' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE user_id = %d AND badge_id = %d AND status IN ( %s, %s )
				LIMIT 1",
				$user_id,
				$badge_id,
				self::STATUS_ISSUED,
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * Badges a learner has actually been issued, newest first.
	 *
	 * Only `issued` rows: a badge that is queued, failing, or abandoned has
	 * not been earned and must never appear on the front end. Revoked rows
	 * (milestone 9) are excluded here too — a revoked credential must not
	 * render on the owner's list or on anyone's public showcase; the row is
	 * kept so the award history and the trigger's idempotency survive.
	 *
	 * @param int $user_id Learner.
	 * @param int $limit   Maximum badges returned.
	 *
	 * @return array<int, object>
	 */
	public function issued_for_user( int $user_id, int $limit = 100 ): array {
		global $wpdb;

		$awards = Schema::table( 'awards' );
		$badges = Schema::table( 'badges' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.issued_at, a.provider_event_id,
					a.receive_url, a.assertion_json_url,
					a.expires_on, a.received, a.in_passport,
					b.name AS badge_name,
					b.description AS badge_description,
					b.image_url AS badge_image_url,
					b.provider AS badge_provider
				FROM {$awards} a
				INNER JOIN {$badges} b ON b.id = a.badge_id
				WHERE a.user_id = %d AND a.status = %s AND a.revoked = 0
				ORDER BY a.issued_at DESC, a.id DESC
				LIMIT %d",
				$user_id,
				self::STATUS_ISSUED,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every issued award of one provider, with the identifiers a status
	 * refresh matches on and the flags it compares against (milestone 9).
	 *
	 * @param Provider $provider Provider whose awards to load.
	 *
	 * @return array<int, object> Rows with id, assertion_id, provider_event_id,
	 *                            recipient_email, revoked, expires_on, received, in_passport.
	 */
	public function issued_for_provider( Provider $provider ): array {
		global $wpdb;

		$awards = Schema::table( 'awards' );
		$badges = Schema::table( 'badges' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.assertion_id, a.provider_event_id, a.recipient_email,
					a.revoked, a.expires_on, a.received, a.in_passport
				FROM {$awards} a
				INNER JOIN {$badges} b ON b.id = a.badge_id
				WHERE a.status = %s AND b.provider = %s
				ORDER BY a.id ASC",
				self::STATUS_ISSUED,
				$provider->value
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Store the provider-reported status of one award (milestone 9).
	 *
	 * The provider is authoritative: whatever it reports is written, including
	 * a flag flipping back. Callers only invoke this for rows that changed.
	 *
	 * @param int         $id          Award id.
	 * @param bool        $revoked     Credential revoked at the provider.
	 * @param string|null $expires_on  Expiry as a UTC MySQL datetime, or null for "never".
	 * @param bool        $received    Recipient has opened the claim page.
	 * @param bool        $in_passport Recipient saved the badge to a Passport.
	 */
	public function record_status( int $id, bool $revoked, ?string $expires_on, bool $received, bool $in_passport ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'awards' ),
			array(
				'revoked'           => $revoked ? 1 : 0,
				'expires_on'        => $expires_on,
				'received'          => $received ? 1 : 0,
				'in_passport'       => $in_passport ? 1 : 0,
				'status_checked_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Stamp every issued award of a provider as status-checked now (milestone 9).
	 *
	 * One statement instead of one per row: a refresh that finds nothing
	 * changed should still leave evidence that it ran.
	 *
	 * @param Provider $provider Provider whose awards were checked.
	 */
	public function mark_status_checked( Provider $provider ): void {
		global $wpdb;

		$awards = Schema::table( 'awards' );
		$badges = Schema::table( 'badges' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$awards} a
				INNER JOIN {$badges} b ON b.id = a.badge_id
				SET a.status_checked_at = %s
				WHERE a.status = %s AND b.provider = %s",
				current_time( 'mysql', true ),
				self::STATUS_ISSUED,
				$provider->value
			)
		);
	}

	/**
	 * Number of awards that exhausted their retries.
	 *
	 * These are the badges that were never delivered and never will be without
	 * intervention, so a health notice surfaces them: the Activity Log alone
	 * would mean the admin had to go looking.
	 */
	public function count_abandoned(): int {
		global $wpdb;

		$table = Schema::table( 'awards' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s",
				self::STATUS_ABANDONED
			)
		);
	}

	/**
	 * Number of award rows.
	 */
	public function count(): int {
		global $wpdb;

		$table = Schema::table( 'awards' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
