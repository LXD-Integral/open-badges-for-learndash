<?php
/**
 * Settings screen.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

use OBLD\Credentials\ConnectionStatus;
use OBLD\Credentials\CredentialStore;
use OBLD\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The Open Badges → Settings screen: provider credentials, connection status,
 * and the uninstall data-removal toggle.
 *
 * Secrets are write-only: the saved value is never rendered back into the
 * page. A blank secret field on save means "keep the saved secret".
 */
final class SettingsPage {

	public const SLUG        = 'obld-settings';
	public const SAVE_ACTION = 'obld_save_settings';

	/**
	 * Constructor.
	 *
	 * @param CredentialStore $store Credential storage.
	 */
	public function __construct( private readonly CredentialStore $store ) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * URL of the settings screen.
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

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Open Badges for LearnDash — Settings', 'open-badges-for-learndash' ) . '</h1>';

		$this->render_feedback();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';

		foreach ( Provider::cases() as $provider ) {
			$this->render_provider_section( $provider );
		}

		echo '<h2>' . esc_html__( 'Advanced', 'open-badges-for-learndash' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Uninstall', 'open-badges-for-learndash' ) . '</th>';
		echo '<td><label><input type="checkbox" name="delete_data_on_uninstall" value="1" ' . checked( (bool) get_option( 'obld_delete_data_on_uninstall' ), true, false ) . ' /> ';
		echo esc_html__( 'Delete all plugin data (badges, triggers, awards, logs, settings) when the plugin is uninstalled.', 'open-badges-for-learndash' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default: uninstalling preserves your award history.', 'open-badges-for-learndash' ) . '</p></td>';
		echo '</tr></tbody></table>';

		submit_button( __( 'Save Settings', 'open-badges-for-learndash' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * One provider's credentials section, with connection indicator and
	 * Test Connection action (a nonce link — it lives outside the main form).
	 *
	 * @param Provider $provider Provider.
	 */
	private function render_provider_section( Provider $provider ): void {
		$status = ConnectionStatus::get( $provider );

		echo '<h2>' . esc_html( $provider->label() ) . '</h2>';
		echo '<p>' . wp_kses_post( $this->status_html( $provider, $status ) ) . '</p>';

		$field = 'credentials_' . $provider->value;

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="' . esc_attr( $field . '_id' ) . '">' . esc_html__( 'Client ID', 'open-badges-for-learndash' ) . '</label></th>';
		echo '<td><input type="text" class="regular-text code" id="' . esc_attr( $field . '_id' ) . '" name="' . esc_attr( $field . '[client_id]' ) . '" value="' . esc_attr( $this->store->client_id( $provider ) ) . '" autocomplete="off" /></td></tr>';

		$placeholder = $this->store->has_secret( $provider )
			? __( 'Saved — enter a new value to replace', 'open-badges-for-learndash' )
			: '';

		echo '<tr><th scope="row"><label for="' . esc_attr( $field . '_secret' ) . '">' . esc_html__( 'Client Secret', 'open-badges-for-learndash' ) . '</label></th>';
		echo '<td><input type="password" class="regular-text code" id="' . esc_attr( $field . '_secret' ) . '" name="' . esc_attr( $field . '[client_secret]' ) . '" value="" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="new-password" />';
		echo '<p class="description">' . esc_html__( 'Stored encrypted and never displayed again. Leave blank to keep the saved secret.', 'open-badges-for-learndash' ) . '</p></td></tr>';

		echo '</tbody></table>';

		$test_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . ConnectionTest::ACTION . '&provider=' . $provider->value ),
			ConnectionTest::ACTION . '_' . $provider->value
		);
		echo '<p><a href="' . esc_url( $test_url ) . '" class="button button-secondary">';
		/* translators: %s: provider name. */
		echo esc_html( sprintf( __( 'Test %s Connection', 'open-badges-for-learndash' ), $provider->label() ) );
		echo '</a></p>';
	}

	/**
	 * Connection indicator markup for a provider.
	 *
	 * @param Provider                                                $provider Provider.
	 * @param array{status: string, message: string, checked_at: int} $status Recorded status.
	 */
	private function status_html( Provider $provider, array $status ): string {
		if ( $this->store->needs_reentry( $provider ) ) {
			return '<span style="color:#b32d2e;font-weight:600;">●</span> '
				. esc_html__( 'Credentials cannot be decrypted (the site’s security keys changed). Please re-enter the Client Secret.', 'open-badges-for-learndash' );
		}

		if ( ! $this->store->is_configured( $provider ) ) {
			return '<span style="color:#787c82;font-weight:600;">●</span> '
				. esc_html__( 'Not configured.', 'open-badges-for-learndash' );
		}

		return match ( $status['status'] ) {
			ConnectionStatus::CONNECTED => '<span style="color:#00a32a;font-weight:600;">●</span> '
				. sprintf(
					/* translators: %s: human-readable time difference, e.g. "2 mins". */
					esc_html__( 'Connected — verified %s ago.', 'open-badges-for-learndash' ),
					esc_html( human_time_diff( $status['checked_at'] ) )
				),
			ConnectionStatus::FAILED    => '<span style="color:#b32d2e;font-weight:600;">●</span> '
				. sprintf(
					/* translators: 1: human-readable time difference, 2: failure detail. */
					esc_html__( 'Connection failing (checked %1$s ago): %2$s', 'open-badges-for-learndash' ),
					esc_html( human_time_diff( $status['checked_at'] ) ),
					esc_html( $status['message'] )
				),
			default                     => '<span style="color:#dba617;font-weight:600;">●</span> '
				. esc_html__( 'Credentials saved but not yet tested — use Test Connection below.', 'open-badges-for-learndash' ),
		};
	}

	/**
	 * Post-redirect feedback notices (settings saved / test result).
	 */
	private function render_feedback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only feedback flags on a redirect.
		if ( isset( $_GET['obld_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'open-badges-for-learndash' ) . '</p></div>';
		}

		if ( isset( $_GET['obld_tested'] ) ) {
			$provider = Provider::tryFrom( sanitize_key( wp_unslash( $_GET['obld_tested'] ) ) );
			if ( $provider instanceof Provider ) {
				$status = ConnectionStatus::get( $provider );
				if ( ConnectionStatus::CONNECTED === $status['status'] ) {
					echo '<div class="notice notice-success is-dismissible"><p>';
					/* translators: %s: provider name. */
					echo esc_html( sprintf( __( '%s connection verified.', 'open-badges-for-learndash' ), $provider->label() ) );
					echo '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>';
					/* translators: 1: provider name, 2: failure detail. */
					echo esc_html( sprintf( __( '%1$s connection failed: %2$s', 'open-badges-for-learndash' ), $provider->label(), $status['message'] ) );
					echo '</p></div>';
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Save handler (admin-post).
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'open-badges-for-learndash' ) );
		}
		check_admin_referer( self::SAVE_ACTION );

		foreach ( Provider::cases() as $provider ) {
			$field = 'credentials_' . $provider->value;
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- fields sanitized individually below.
			$raw = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : array();

			$client_id = sanitize_text_field( is_scalar( $raw['client_id'] ?? null ) ? (string) $raw['client_id'] : '' );
			// The secret is a credential, not display data: trim only, never mangle.
			$secret = trim( is_scalar( $raw['client_secret'] ?? null ) ? (string) $raw['client_secret'] : '' );

			$this->store->save( $provider, $client_id, '' === $secret ? null : $secret );
		}

		update_option( 'obld_delete_data_on_uninstall', isset( $_POST['delete_data_on_uninstall'] ), false );

		wp_safe_redirect( add_query_arg( 'obld_saved', '1', self::url() ) );
		exit;
	}
}
