<?php
/**
 * Test Connection action.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

use OBLD\Api\ApiClient;
use OBLD\Api\WpHttpTransport;
use OBLD\Credentials\ConnectionStatus;
use OBLD\Credentials\CredentialStore;
use OBLD\Log\Logger;
use OBLD\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the per-provider Test Connection action: obtains a token, calls the
 * authenticated ping, records the outcome, logs it, and redirects back to the
 * settings screen. This is the ONLY place a connection check performs network
 * I/O — indicators elsewhere read the recorded result.
 */
final class ConnectionTest {

	public const ACTION = 'obld_test_connection';

	/**
	 * Constructor.
	 *
	 * @param CredentialStore $store  Credential storage.
	 * @param Logger          $logger Activity log.
	 */
	public function __construct(
		private readonly CredentialStore $store,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Run the test and redirect back to settings.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'open-badges-for-learndash' ) );
		}

		$provider = isset( $_GET['provider'] )
			? Provider::tryFrom( sanitize_key( wp_unslash( $_GET['provider'] ) ) )
			: null;

		if ( ! $provider instanceof Provider ) {
			wp_die( esc_html__( 'Unknown provider.', 'open-badges-for-learndash' ) );
		}

		check_admin_referer( self::ACTION . '_' . $provider->value );

		$this->run_test( $provider );

		wp_safe_redirect( add_query_arg( 'obld_tested', $provider->value, SettingsPage::url() ) );
		exit;
	}

	/**
	 * Perform the actual connection test and record + log the outcome.
	 *
	 * @param Provider $provider Provider to test.
	 */
	public function run_test( Provider $provider ): void {
		if ( $this->store->needs_reentry( $provider ) ) {
			$message = __( 'The saved Client Secret can no longer be decrypted (the site’s security keys changed). Re-enter the credentials.', 'open-badges-for-learndash' );
			ConnectionStatus::record( $provider, ConnectionStatus::FAILED, $message );
			$this->logger->error( $provider->label() . ': ' . $message );
			return;
		}

		if ( ! $this->store->is_configured( $provider ) ) {
			$message = __( 'Client ID and Client Secret are not both set.', 'open-badges-for-learndash' );
			ConnectionStatus::record( $provider, ConnectionStatus::NOT_CONFIGURED, $message );
			return;
		}

		$client = new ApiClient(
			$provider,
			$this->store->client_id( $provider ),
			(string) $this->store->secret( $provider ),
			new WpHttpTransport()
		);

		$result = $client->ping();

		if ( true === $result ) {
			ConnectionStatus::record( $provider, ConnectionStatus::CONNECTED );
			$this->logger->success(
				sprintf(
					/* translators: %s: provider name. */
					__( 'Connection test succeeded for %s.', 'open-badges-for-learndash' ),
					$provider->label()
				)
			);
			return;
		}

		ConnectionStatus::record( $provider, ConnectionStatus::FAILED, $result->get_error_message() );
		$this->logger->error(
			sprintf(
				/* translators: 1: provider name, 2: failure detail. */
				__( 'Connection test failed for %1$s: %2$s', 'open-badges-for-learndash' ),
				$provider->label(),
				$result->get_error_message()
			)
		);
	}
}
