<?php
/**
 * Personal-data export and erasure.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only Schema::table() names are interpolated; every value is bound through prepare().

/**
 * Hooks the plugin's award and log records into WordPress's personal-data
 * tools (Tools → Export / Erase Personal Data).
 *
 * The plugin stores a learner's email address on each award (the provider
 * delivers the credential to it) and inside activity-log messages. Export
 * lists the awards; erasure blanks the stored email on the learner's awards
 * and deletes their log rows. The award rows themselves are kept, with the
 * email removed: they are the record that the badge was issued (the
 * credential lives at the provider, where its own privacy policy applies)
 * and the guard against the same trigger issuing the badge twice.
 */
final class Privacy {

	private const GROUP = 'obld_awards';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Add the exporter (wp_privacy_personal_data_exporters).
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['open-badges-for-learndash'] = array(
			'exporter_friendly_name' => __( 'Open Badges for LearnDash', 'open-badges-for-learndash' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the eraser (wp_privacy_personal_data_erasers).
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['open-badges-for-learndash'] = array(
			'eraser_friendly_name' => __( 'Open Badges for LearnDash', 'open-badges-for-learndash' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export the badges awarded to an email address.
	 *
	 * @param string $email Email address being exported.
	 * @param int    $page  Page (unused: the result fits one page).
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress exporter signature.
		global $wpdb;

		$awards = Schema::table( 'awards' );
		$badges = Schema::table( 'badges' );
		$user   = get_user_by( 'email', $email );
		$id     = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.recipient_email, a.status, a.created_at, a.issued_at, a.revoked, a.expires_on, b.name AS badge_name, b.provider
				FROM {$awards} a
				LEFT JOIN {$badges} b ON b.id = a.badge_id
				WHERE a.user_id = %d OR a.recipient_email = %s
				ORDER BY a.id ASC",
				$id,
				$email
			)
		);

		$data = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$provider = Provider::tryFrom( (string) $row->provider );

			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Open Badges', 'open-badges-for-learndash' ),
				'item_id'     => 'obld-award-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Badge', 'open-badges-for-learndash' ),
						'value' => (string) ( $row->badge_name ?? '' ),
					),
					array(
						'name'  => __( 'Provider', 'open-badges-for-learndash' ),
						'value' => $provider instanceof Provider ? $provider->label() : (string) $row->provider,
					),
					array(
						'name'  => __( 'Recipient email', 'open-badges-for-learndash' ),
						'value' => (string) $row->recipient_email,
					),
					array(
						'name'  => __( 'Status', 'open-badges-for-learndash' ),
						'value' => ! empty( $row->revoked ) ? __( 'Revoked', 'open-badges-for-learndash' ) : (string) $row->status,
					),
					array(
						'name'  => __( 'Issued (UTC)', 'open-badges-for-learndash' ),
						'value' => (string) ( $row->issued_at ?? '' ),
					),
					array(
						'name'  => __( 'Expires (UTC)', 'open-badges-for-learndash' ),
						'value' => (string) ( $row->expires_on ?? '' ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase the email from a learner's awards and delete their log rows.
	 *
	 * @param string $email Email address being erased.
	 * @param int    $page  Page (unused).
	 *
	 * @return array{items_removed: int, items_retained: int, messages: array<int, string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress eraser signature.
		global $wpdb;

		$awards = Schema::table( 'awards' );
		$logs   = Schema::table( 'logs' );
		$user   = get_user_by( 'email', $email );
		$id     = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;

		$blanked = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$awards} SET recipient_email = '' WHERE ( user_id = %d OR recipient_email = %s ) AND recipient_email <> ''",
				$id,
				$email
			)
		);

		$deleted = 0;
		if ( $id > 0 ) {
			$deleted = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$logs} WHERE user_id = %d", $id )
			);
		}

		$messages = array();
		if ( $blanked > 0 ) {
			$messages[] = __( 'The email address was removed from the badge award records; the records themselves are kept so the badge is not issued twice. The credential itself is held by the badge provider.', 'open-badges-for-learndash' );
		}

		return array(
			'items_removed'  => $blanked + $deleted,
			'items_retained' => 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
