<?php
/**
 * Badges screen.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

use OBLD\Badges\BadgeRepository;
use OBLD\Badges\SyncService;
use OBLD\Credentials\CredentialStore;
use OBLD\Issuance\StatusSync;
use OBLD\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The Open Badges → Badges screen: the synced badge list, the Sync Now
 * action, and (milestone 9) the Refresh Award Status action. Those two
 * admin-post actions are the ONLY places this screen performs network I/O.
 */
final class BadgesPage {

	public const SLUG           = 'obld-badges';
	public const SYNC_ACTION    = 'obld_sync_badges';
	public const REFRESH_ACTION = 'obld_refresh_status';

	/**
	 * Constructor.
	 *
	 * @param CredentialStore $store  Credential storage.
	 * @param BadgeRepository $repo   Badge storage.
	 * @param SyncService     $sync   Badge-class sync orchestration.
	 * @param StatusSync      $status Award status refresh.
	 */
	public function __construct(
		private readonly CredentialStore $store,
		private readonly BadgeRepository $repo,
		private readonly SyncService $sync,
		private readonly StatusSync $status,
	) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SYNC_ACTION, array( $this, 'handle_sync' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );
	}

	/**
	 * URL of the Badges screen.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'open-badges-for-learndash' ) );
		}

		$any_configured = false;
		foreach ( Provider::cases() as $provider ) {
			if ( $this->store->is_configured( $provider ) ) {
				$any_configured = true;
			}
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Badges', 'open-badges-for-learndash' ) . '</h1>';

		if ( $any_configured ) {
			$sync_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::SYNC_ACTION ),
				self::SYNC_ACTION
			);
			echo ' <a href="' . esc_url( $sync_url ) . '" class="page-title-action">' . esc_html__( 'Sync Now', 'open-badges-for-learndash' ) . '</a>';

			$refresh_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::REFRESH_ACTION ),
				self::REFRESH_ACTION
			);
			echo ' <a href="' . esc_url( $refresh_url ) . '" class="page-title-action">' . esc_html__( 'Refresh Award Status', 'open-badges-for-learndash' ) . '</a>';
		}

		echo '<hr class="wp-header-end" />';

		$this->render_feedback();

		if ( ! $any_configured ) {
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: %s: settings page URL. */
					__( 'No badge provider is connected yet. <a href="%s">Add your Open Badge Factory or CanCred credentials</a> first, then sync your badges here.', 'open-badges-for-learndash' ),
					esc_url( SettingsPage::url() )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p></div>';
			return;
		}

		$this->render_sync_summary();
		$this->render_status_summary();

		$badges = $this->repo->all();

		if ( array() === $badges ) {
			echo '<p>' . esc_html__( 'No badges yet — click Sync Now to fetch the badges from your connected providers.', 'open-badges-for-learndash' ) . '</p></div>';
			return;
		}

		$table = new BadgeListTable( $badges );
		$table->prepare_items();
		$table->display();

		echo '</div>';
	}

	/**
	 * Post-action feedback notices, one per provider outcome.
	 */
	private function render_feedback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feedback flag on a redirect.
		if ( isset( $_GET['obld_status_refreshed'] ) ) {
			$this->render_status_feedback();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feedback flag on a redirect.
		if ( ! isset( $_GET['obld_synced'] ) ) {
			return;
		}

		foreach ( $this->last_sync() as $provider_value => $result ) {
			$provider = Provider::tryFrom( (string) $provider_value );
			if ( ! $provider instanceof Provider || SyncService::STATUS_SKIPPED === $result['status'] ) {
				continue;
			}

			if ( SyncService::STATUS_SYNCED === $result['status'] ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html(
					sprintf(
						/* translators: 1: provider name, 2: badge count, 3: added, 4: updated, 5: removed. */
						__( '%1$s: synced %2$d badges (%3$d added, %4$d updated, %5$d removed).', 'open-badges-for-learndash' ),
						$provider->label(),
						$result['count'],
						$result['added'],
						$result['updated'],
						$result['removed']
					)
				);
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>';
				echo esc_html(
					sprintf(
						/* translators: 1: provider name, 2: failure detail. */
						__( '%1$s sync failed: %2$s', 'open-badges-for-learndash' ),
						$provider->label(),
						$result['message']
					)
				);
				echo '</p></div>';
			}
		}
	}

	/**
	 * The per-provider "last synced" summary line under the heading.
	 */
	private function render_sync_summary(): void {
		$parts = array();

		foreach ( $this->last_sync() as $provider_value => $result ) {
			$provider = Provider::tryFrom( (string) $provider_value );
			if ( ! $provider instanceof Provider || SyncService::STATUS_SKIPPED === $result['status'] ) {
				continue;
			}

			if ( SyncService::STATUS_SYNCED === $result['status'] ) {
				$parts[] = sprintf(
					/* translators: 1: provider name, 2: badge count, 3: human-readable time difference. */
					__( '%1$s: %2$d badges, synced %3$s ago', 'open-badges-for-learndash' ),
					$provider->label(),
					$result['count'],
					human_time_diff( (int) $result['synced_at'] )
				);
			} else {
				$parts[] = sprintf(
					/* translators: 1: provider name, 2: failure detail. */
					__( '%1$s: last sync failed — %2$s', 'open-badges-for-learndash' ),
					$provider->label(),
					$result['message']
				);
			}
		}

		if ( array() !== $parts ) {
			echo '<p class="description">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
		}
	}

	/**
	 * Post-refresh notices for the Refresh Award Status action (milestone 9).
	 */
	private function render_status_feedback(): void {
		foreach ( $this->last_status() as $provider_value => $result ) {
			$provider = Provider::tryFrom( (string) $provider_value );
			if ( ! $provider instanceof Provider || StatusSync::STATUS_SKIPPED === $result['status'] ) {
				continue;
			}

			if ( StatusSync::STATUS_CHECKED === $result['status'] ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html(
					sprintf(
						/* translators: 1: provider name, 2: awards compared, 3: awards changed, 4: newly revoked, 5: newly saved to Passport. */
						__( '%1$s: checked %2$d awards, %3$d changed (%4$d revoked, %5$d saved to Passport).', 'open-badges-for-learndash' ),
						$provider->label(),
						$result['checked'],
						$result['changed'],
						$result['revoked'],
						$result['saved']
					)
				);
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>';
				echo esc_html(
					sprintf(
						/* translators: 1: provider name, 2: failure detail. */
						__( '%1$s award status check failed: %2$s', 'open-badges-for-learndash' ),
						$provider->label(),
						$result['message']
					)
				);
				echo '</p></div>';
			}
		}
	}

	/**
	 * The per-provider "award status last checked" line (milestone 9).
	 */
	private function render_status_summary(): void {
		$parts = array();

		foreach ( $this->last_status() as $provider_value => $result ) {
			$provider = Provider::tryFrom( (string) $provider_value );
			if ( ! $provider instanceof Provider || StatusSync::STATUS_SKIPPED === $result['status'] ) {
				continue;
			}

			if ( StatusSync::STATUS_CHECKED === $result['status'] ) {
				$parts[] = sprintf(
					/* translators: 1: provider name, 2: awards compared, 3: human-readable time difference. */
					__( '%1$s: %2$d awards, status checked %3$s ago', 'open-badges-for-learndash' ),
					$provider->label(),
					$result['checked'],
					human_time_diff( (int) $result['checked_at'] )
				);
			} else {
				$parts[] = sprintf(
					/* translators: 1: provider name, 2: failure detail. */
					__( '%1$s: last status check failed — %2$s', 'open-badges-for-learndash' ),
					$provider->label(),
					$result['message']
				);
			}
		}

		if ( array() !== $parts ) {
			echo '<p class="description">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
		}
	}

	/**
	 * Refresh Award Status handler (admin-post).
	 */
	public function handle_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'open-badges-for-learndash' ) );
		}
		check_admin_referer( self::REFRESH_ACTION );

		$this->status->sync_all( true );

		wp_safe_redirect( add_query_arg( 'obld_status_refreshed', '1', self::url() ) );
		exit;
	}

	/**
	 * Sync Now handler (admin-post).
	 */
	public function handle_sync(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'open-badges-for-learndash' ) );
		}
		check_admin_referer( self::SYNC_ACTION );

		$this->sync->sync_all();

		wp_safe_redirect( add_query_arg( 'obld_synced', '1', self::url() ) );
		exit;
	}

	/**
	 * Stored last award-status-check summary.
	 *
	 * @return array<string, array{status: string, message: string, checked: int, changed: int, revoked: int, saved: int, expiry: int, checked_at: int}>
	 */
	private function last_status(): array {
		$summary = get_option( StatusSync::LAST_SYNC_OPTION, array() );

		return is_array( $summary ) ? $summary : array();
	}

	/**
	 * Stored last-sync summary.
	 *
	 * @return array<string, array{status: string, message: string, count: int, added: int, updated: int, removed: int, synced_at: int}>
	 */
	private function last_sync(): array {
		$summary = get_option( SyncService::LAST_SYNC_OPTION, array() );

		return is_array( $summary ) ? $summary : array();
	}
}
