<?php
/**
 * Synced-badge storage.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Badges;

use OBLD\Provider;
use OBLD\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see the class docblock below.

/**
 * Reads and writes the obld_badges table. Deliberately logic-free: mapping
 * and orchestration live in SyncService so this stays a thin, obvious layer.
 *
 * Not final: tests substitute a stored-state fake for the $wpdb-bound parts.
 *
 * Every query below interpolates exactly one kind of value — a table name
 * returned by Schema::table(), which is built from $wpdb->prefix and a literal
 * and can never contain user input. All caller-supplied values go through
 * $wpdb->prepare(). InterpolatedNotPrepared is therefore suppressed for the
 * whole class rather than repeated on a dozen lines; keep that invariant true.
 */
class BadgeRepository {

	/**
	 * Replace a provider's badges with the freshly-fetched set: upsert every
	 * fetched badge, then reconcile the rows the provider no longer has.
	 *
	 * Removal semantics (settled in milestone 3, which introduced triggers):
	 * a vanished badge that **no trigger references** is deleted, but one that
	 * **is referenced** is kept and flagged `missing_at_provider`. Deleting it
	 * would silently destroy an admin's trigger configuration and throw away
	 * the provider id that issuance needs; flagging keeps the rule visible and
	 * repairable, and a health notice surfaces it. The flag clears by itself
	 * if the badge reappears at the provider.
	 *
	 * @param Provider                         $provider Provider synced.
	 * @param array<int, array<string, mixed>> $badges   Raw badge arrays from ApiClient::list_badges().
	 *
	 * @return array{added: int, updated: int, removed: int, missing: int}
	 */
	public function replace_provider( Provider $provider, array $badges ): array {
		global $wpdb;

		$table   = Schema::table( 'badges' );
		$now     = current_time( 'mysql', true );
		$added   = 0;
		$updated = 0;
		$kept    = array();

		foreach ( $badges as $badge ) {
			$provider_badge_id = is_scalar( $badge['id'] ?? null ) ? (string) $badge['id'] : '';
			if ( '' === $provider_badge_id ) {
				continue;
			}
			$kept[] = $provider_badge_id;

			$row = array(
				'provider'            => $provider->value,
				'provider_badge_id'   => $provider_badge_id,
				'name'                => is_scalar( $badge['name'] ?? null ) ? (string) $badge['name'] : '',
				'description'         => is_scalar( $badge['description'] ?? null ) ? (string) $badge['description'] : '',
				'image_url'           => is_scalar( $badge['image'] ?? null ) ? (string) $badge['image'] : '',
				'is_draft'            => empty( $badge['draft'] ) ? 0 : 1,
				// Present in this fetch, so any earlier missing flag is stale.
				'missing_at_provider' => 0,
				'synced_at'           => $now,
			);

			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE provider = %s AND provider_badge_id = %s",
					$provider->value,
					$provider_badge_id
				)
			);

			if ( $existing_id ) {
				$wpdb->update( $table, $row, array( 'id' => (int) $existing_id ) );
				++$updated;
			} else {
				$wpdb->insert( $table, $row );
				++$added;
			}
		}

		$vanished = $this->reconcile_vanished( $provider, $kept );

		return array(
			'added'   => $added,
			'updated' => $updated,
			'removed' => $vanished['removed'],
			'missing' => $vanished['missing'],
		);
	}

	/**
	 * Reconcile the rows this provider no longer returns.
	 *
	 * Referenced by a trigger → flagged `missing_at_provider` and kept.
	 * Unreferenced → deleted. See replace_provider()'s docblock for why.
	 *
	 * @param Provider      $provider Provider synced.
	 * @param array<string> $kept     provider_badge_id values seen in this fetch.
	 *
	 * @return array{removed: int, missing: int}
	 */
	private function reconcile_vanished( Provider $provider, array $kept ): array {
		global $wpdb;

		$table    = Schema::table( 'badges' );
		$triggers = Schema::table( 'triggers' );

		// "Not in this fetch" — as a SQL fragment reused by both statements
		// below, with its bound values kept alongside it.
		if ( array() !== $kept ) {
			$placeholders = implode( ',', array_fill( 0, count( $kept ), '%s' ) );
			$scope        = "provider = %s AND provider_badge_id NOT IN ({$placeholders})";
			$values       = array_merge( array( $provider->value ), $kept );
		} else {
			$scope  = 'provider = %s';
			$values = array( $provider->value );
		}

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count spread placeholders.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders live in $scope, which the sniff does not follow.
		$missing = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET missing_at_provider = 1
				WHERE {$scope}
				AND id IN ( SELECT badge_id FROM {$triggers} )",
				...$values
			)
		);

		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE {$scope}
				AND id NOT IN ( SELECT badge_id FROM {$triggers} )",
				...$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array(
			'removed' => $removed,
			'missing' => $missing,
		);
	}

	/**
	 * Badges flagged as no longer present at their provider.
	 *
	 * @return array<int, object>
	 */
	public function missing_at_provider(): array {
		global $wpdb;

		$table = Schema::table( 'badges' );

		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE missing_at_provider = 1 ORDER BY provider, name" );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Badges selectable in a trigger: published (non-draft) and still present
	 * at the provider. Drafts cannot be issued, so they are never offered.
	 *
	 * @return array<int, object>
	 */
	public function selectable(): array {
		global $wpdb;

		$table = Schema::table( 'badges' );

		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_draft = 0 AND missing_at_provider = 0 ORDER BY provider, name" );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All synced badges, ordered by provider then name.
	 *
	 * @param Provider|null $provider Optional provider filter.
	 *
	 * @return array<int, object>
	 */
	public function all( ?Provider $provider = null ): array {
		global $wpdb;

		$table = Schema::table( 'badges' );

		if ( null !== $provider ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE provider = %s ORDER BY name",
					$provider->value
				)
			);
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY provider, name" );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of synced badges.
	 */
	public function count(): int {
		global $wpdb;

		$table = Schema::table( 'badges' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
