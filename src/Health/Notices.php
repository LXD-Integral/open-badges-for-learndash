<?php
/**
 * Health notices.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Health;

use OBLD\Admin\ActivityLogPage;
use OBLD\Admin\BadgesPage;
use OBLD\Admin\SettingsPage;
use OBLD\Admin\TriggersPage;
use OBLD\Badges\BadgeRepository;
use OBLD\Issuance\AwardRepository;
use OBLD\Badges\SyncService;
use OBLD\Credentials\ConnectionStatus;
use OBLD\Credentials\CredentialStore;
use OBLD\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Computes configuration/health notices live from current state — nothing
 * here performs network I/O (the old plugin probed both providers on every
 * admin page load; this build never does).
 *
 * Dismissals are per-user and carry a hash of the notice's content, so a
 * dismissed notice re-appears if the underlying problem changes.
 */
final class Notices {

	public const DISMISS_ACTION = 'obld_dismiss_notice';
	private const USER_META     = 'obld_dismissed_notices';

	/**
	 * Constructor.
	 *
	 * @param CredentialStore $store  Credential storage.
	 * @param BadgeRepository $badges Badge storage.
	 * @param AwardRepository $awards Award storage.
	 */
	public function __construct(
		private readonly CredentialStore $store,
		private readonly BadgeRepository $badges,
		private readonly AwardRepository $awards,
	) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Current notices as key => [type, message, dismissible].
	 *
	 * @return array<string, array{type: string, message: string, dismissible: bool}>
	 */
	public function current_notices(): array {
		$notices = array();

		if ( ! defined( 'LEARNDASH_VERSION' ) ) {
			$notices['learndash_missing'] = array(
				'type'        => 'warning',
				'message'     => __( 'Open Badges for LearnDash: LearnDash is not active, so completion events cannot award badges. Settings can still be configured.', 'open-badges-for-learndash' ),
				'dismissible' => true,
			);
		}

		$any_configured = false;
		foreach ( Provider::cases() as $provider ) {
			if ( $this->store->is_configured( $provider ) ) {
				$any_configured = true;
			}

			if ( $this->store->needs_reentry( $provider ) ) {
				$notices[ 'reentry_' . $provider->value ] = array(
					'type'        => 'error',
					'message'     => sprintf(
						/* translators: 1: provider name, 2: settings page URL. */
						__( 'Open Badges for LearnDash: the saved %1$s credentials can no longer be decrypted (the site’s security keys changed). <a href="%2$s">Re-enter the Client Secret</a>.', 'open-badges-for-learndash' ),
						$provider->label(),
						esc_url( SettingsPage::url() )
					),
					'dismissible' => false,
				);
				continue;
			}

			$last_sync = get_option( SyncService::LAST_SYNC_OPTION, array() );
			$sync_row  = is_array( $last_sync ) ? ( $last_sync[ $provider->value ] ?? null ) : null;
			if ( is_array( $sync_row )
				&& SyncService::STATUS_FAILED === ( $sync_row['status'] ?? '' )
				&& $this->store->is_configured( $provider ) ) {
				$notices[ 'sync_failed_' . $provider->value ] = array(
					'type'        => 'error',
					'message'     => sprintf(
						/* translators: 1: provider name, 2: failure detail, 3: badges page URL. */
						__( 'Open Badges for LearnDash: the last %1$s badge sync failed — %2$s <a href="%3$s">Open the Badges screen to re-sync</a>.', 'open-badges-for-learndash' ),
						$provider->label(),
						esc_html( (string) ( $sync_row['message'] ?? '' ) ),
						esc_url( BadgesPage::url() )
					),
					'dismissible' => false,
				);
			}

			$status = ConnectionStatus::get( $provider );
			if ( ConnectionStatus::FAILED === $status['status'] ) {
				$notices[ 'failed_' . $provider->value ] = array(
					'type'        => 'error',
					'message'     => sprintf(
						/* translators: 1: provider name, 2: failure detail, 3: settings page URL. */
						__( 'Open Badges for LearnDash: the %1$s connection is failing — %2$s <a href="%3$s">Review settings and re-test</a>.', 'open-badges-for-learndash' ),
						$provider->label(),
						esc_html( $status['message'] ),
						esc_url( SettingsPage::url() )
					),
					'dismissible' => false,
				);
			}
		}

		// A badge that vanished at the provider is kept (not deleted) whenever a
		// trigger still points at it, precisely so this notice can be raised
		// instead of the rule disappearing silently.
		$missing = $this->badges->missing_at_provider();
		if ( array() !== $missing ) {
			$names = array();
			foreach ( $missing as $badge ) {
				$names[] = (string) $badge->name;
			}

			$notices['badges_missing'] = array(
				'type'        => 'error',
				'message'     => sprintf(
					/* translators: 1: comma-separated badge names, 2: triggers page URL. */
					__( 'Open Badges for LearnDash: %1$s is used by a trigger but is no longer available at its provider, so that trigger cannot issue. <a href="%2$s">Review your triggers</a>.', 'open-badges-for-learndash' ),
					esc_html( implode( ', ', $names ) ),
					esc_url( TriggersPage::url() )
				),
				'dismissible' => false,
			);
		}

		// Badges that exhausted their retries are the one failure the plugin can
		// no longer fix by itself, so it must be said out loud rather than left
		// for someone to find in the log.
		$abandoned = $this->awards->count_abandoned();
		if ( $abandoned > 0 ) {
			$notices['awards_abandoned'] = array(
				'type'        => 'error',
				'message'     => sprintf(
					/* translators: 1: number of undelivered badges, 2: activity log URL. */
					_n(
						'Open Badges for LearnDash: %1$d badge could not be issued after repeated attempts and has been given up on. <a href="%2$s">Review the activity log</a>, fix the cause, then re-award it from Issue Badges.',
						'Open Badges for LearnDash: %1$d badges could not be issued after repeated attempts and have been given up on. <a href="%2$s">Review the activity log</a>, fix the cause, then re-award them from Issue Badges.',
						$abandoned,
						'open-badges-for-learndash'
					),
					$abandoned,
					esc_url( ActivityLogPage::url( 'error' ) )
				),
				'dismissible' => true,
			);
		}

		if ( ! $any_configured ) {
			$notices['not_configured'] = array(
				'type'        => 'info',
				'message'     => sprintf(
					/* translators: %s: settings page URL. */
					__( 'Open Badges for LearnDash is active but no badge provider is connected yet. <a href="%s">Add your Open Badge Factory or CanCred credentials</a>.', 'open-badges-for-learndash' ),
					esc_url( SettingsPage::url() )
				),
				'dismissible' => true,
			);
		}

		return $notices;
	}

	/**
	 * Render notices (admin_notices).
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// The plugin's own screens show connection/sync state inline; don't
		// stack duplicate banners on top of them.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display context check only.
		$page        = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$on_settings = in_array( $page, array( SettingsPage::SLUG, BadgesPage::SLUG, TriggersPage::SLUG ), true );

		$dismissed = get_user_meta( get_current_user_id(), self::USER_META, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();

		foreach ( $this->current_notices() as $key => $notice ) {
			if ( $on_settings && 'learndash_missing' !== $key ) {
				continue;
			}

			$hash = self::hash( $notice['message'] );
			if ( $notice['dismissible'] && ( $dismissed[ $key ] ?? '' ) === $hash ) {
				continue;
			}

			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . '"><p>';
			echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array() ) ) );
			if ( $notice['dismissible'] ) {
				$dismiss_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION . '&notice=' . $key . '&hash=' . $hash ),
					self::DISMISS_ACTION . '_' . $key
				);
				echo ' <a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'open-badges-for-learndash' ) . '</a>';
			}
			echo '</p></div>';
		}
	}

	/**
	 * Persist a per-user dismissal (admin-post).
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'open-badges-for-learndash' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line, which needs this key.
		$key = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		check_admin_referer( self::DISMISS_ACTION . '_' . $key );

		$hash = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';

		// Only a notice that currently exists, and only its content hash, is
		// recorded — the usermeta cannot be filled with arbitrary keys.
		$notices = $this->current_notices();
		if ( isset( $notices[ $key ] ) && $notices[ $key ]['dismissible'] && self::hash( $notices[ $key ]['message'] ) === $hash ) {
			$dismissed         = get_user_meta( get_current_user_id(), self::USER_META, true );
			$dismissed         = is_array( $dismissed ) ? $dismissed : array();
			$dismissed[ $key ] = $hash;
			update_user_meta( get_current_user_id(), self::USER_META, $dismissed );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Content hash used to re-arm dismissed notices when their substance changes.
	 *
	 * @param string $message Notice message.
	 */
	private static function hash( string $message ): string {
		return substr( md5( $message ), 0, 12 );
	}
}
