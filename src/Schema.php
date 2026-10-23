<?php
/**
 * Database schema: creation and upgrades for the plugin's custom tables.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables idempotently via dbDelta().
 *
 * No DB-level foreign keys on purpose: dbDelta() cannot manage them, and the
 * predecessor plugin's ALTER TABLE ... ADD CONSTRAINT calls were a persistent
 * source of activation errors. Referential cleanup is enforced in PHP instead.
 */
final class Schema {

	/**
	 * Bump when any table definition below changes.
	 *
	 * 2 — milestone 3 adds badges.missing_at_provider.
	 * 3 — milestone 7 adds awards.assertion_id / receive_url /
	 *     assertion_json_url (per-recipient verifiable-export links).
	 * 4 — milestone 9 adds awards.revoked / expires_on / received /
	 *     in_passport / status_checked_at (provider-side credential status).
	 */
	public const VERSION = '4';

	private const VERSION_OPTION = 'obld_schema_version';

	/**
	 * Activation hook: create/upgrade tables and record the schema version.
	 */
	public static function activate(): void {
		self::create_tables();
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Upgrade check for updates that arrive without re-activation.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) !== self::VERSION ) {
			self::activate();
		}
	}

	/**
	 * Run dbDelta() over every table definition. Safe to call repeatedly.
	 */
	public static function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::table_definitions() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Fully-qualified table name.
	 *
	 * @param string $table Bare table name: badges|triggers|awards|logs.
	 */
	public static function table( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . 'obld_' . $table;
	}

	/**
	 * CREATE TABLE statements in dbDelta()-compatible form.
	 *
	 * @return string[]
	 */
	public static function table_definitions(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$badges          = self::table( 'badges' );
		$triggers        = self::table( 'triggers' );
		$awards          = self::table( 'awards' );
		$logs            = self::table( 'logs' );

		return array(
			"CREATE TABLE {$badges} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				provider varchar(20) NOT NULL,
				provider_badge_id varchar(100) NOT NULL,
				name text NOT NULL,
				description longtext NULL,
				image_url text NULL,
				is_draft tinyint(1) NOT NULL DEFAULT 0,
				missing_at_provider tinyint(1) NOT NULL DEFAULT 0,
				synced_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY provider_badge (provider,provider_badge_id)
			) {$charset_collate};",

			"CREATE TABLE {$triggers} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				badge_id bigint(20) unsigned NOT NULL,
				event_type varchar(20) NOT NULL,
				target_id bigint(20) unsigned NOT NULL,
				min_score decimal(5,2) NULL,
				enabled tinyint(1) NOT NULL DEFAULT 1,
				fired_count bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event_target (event_type,target_id),
				KEY badge_id (badge_id)
			) {$charset_collate};",

			"CREATE TABLE {$awards} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				badge_id bigint(20) unsigned NOT NULL,
				trigger_id bigint(20) unsigned NULL,
				recipient_email varchar(255) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				provider_event_id varchar(100) NULL,
				assertion_id varchar(100) NULL,
				receive_url text NULL,
				assertion_json_url text NULL,
				revoked tinyint(1) NOT NULL DEFAULT 0,
				expires_on datetime NULL,
				received tinyint(1) NOT NULL DEFAULT 0,
				in_passport tinyint(1) NOT NULL DEFAULT 0,
				status_checked_at datetime NULL,
				error_message text NULL,
				created_at datetime NOT NULL,
				issued_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_trigger (user_id,trigger_id),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset_collate};",

			"CREATE TABLE {$logs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				outcome varchar(10) NOT NULL,
				user_id bigint(20) unsigned NULL,
				badge_id bigint(20) unsigned NULL,
				trigger_id bigint(20) unsigned NULL,
				message text NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY outcome (outcome)
			) {$charset_collate};",
		);
	}
}
