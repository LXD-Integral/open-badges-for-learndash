<?php
/**
 * Activity Log screen.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

use OBLD\Log\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The Open Badges → Activity Log screen: every issuance attempt and every
 * connection problem, filterable by outcome, exportable to CSV, clearable.
 *
 * "Never fail silently" is this plugin's founding principle, so this screen is
 * the place a failure always ends up. It performs no network I/O.
 */
final class ActivityLogPage {

	public const SLUG          = 'obld-activity-log';
	public const CLEAR_ACTION  = 'obld_clear_log';
	public const EXPORT_ACTION = 'obld_export_log';

	private const PER_PAGE = 50;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Activity log.
	 */
	public function __construct( private readonly Logger $logger ) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( $this, 'handle_clear' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
	}

	/**
	 * URL of the Activity Log screen.
	 *
	 * @param string $outcome Optional outcome filter to preserve.
	 */
	public static function url( string $outcome = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		return '' === $outcome ? $url : add_query_arg( 'outcome', $outcome, $url );
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'open-badges-for-learndash' ) );
		}

		$outcome = $this->current_outcome();
		$total   = $this->logger->count( $outcome );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$page    = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		$page    = max( 1, $page );
		$entries = $this->logger->entries( $outcome, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Activity Log', 'open-badges-for-learndash' ) . '</h1>';

		if ( $this->logger->count() > 0 ) {
			echo ' <a href="' . esc_url( $this->action_url( self::EXPORT_ACTION ) ) . '" class="page-title-action">'
				. esc_html__( 'Export CSV', 'open-badges-for-learndash' ) . '</a>';
			printf(
				' <a href="%s" class="page-title-action" data-obld-confirm="%s">%s</a>',
				esc_url( $this->action_url( self::CLEAR_ACTION ) ),
				esc_attr( __( 'Delete every entry in the activity log? Issued badges and award records are not affected.', 'open-badges-for-learndash' ) ),
				esc_html__( 'Clear Log', 'open-badges-for-learndash' )
			);
		}

		echo '<hr class="wp-header-end" />';

		$this->render_feedback();
		$this->render_filter( $outcome );

		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'Nothing logged yet.', 'open-badges-for-learndash' ) . '</p></div>';
			return;
		}

		$table = new LogListTable( $entries, $total, $page, self::PER_PAGE );
		$table->prepare_items();
		$table->display();

		echo '</div>';
	}

	/**
	 * Outcome filter links.
	 *
	 * @param string $current Active filter.
	 */
	private function render_filter( string $current ): void {
		$options = array(
			''              => __( 'All', 'open-badges-for-learndash' ),
			Logger::SUCCESS => __( 'Successes', 'open-badges-for-learndash' ),
			Logger::ERROR   => __( 'Errors', 'open-badges-for-learndash' ),
		);

		$links = array();
		foreach ( $options as $value => $label ) {
			$count = $this->logger->count( (string) $value );
			$text  = sprintf( '%s (%s)', $label, number_format_i18n( $count ) );

			$links[] = (string) $value === $current
				? '<strong>' . esc_html( $text ) . '</strong>'
				: '<a href="' . esc_url( self::url( (string) $value ) ) . '">' . esc_html( $text ) . '</a>';
		}

		echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $links ) . '</li></ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link is escaped as it is built above.
	}

	/**
	 * Post-action feedback.
	 */
	private function render_feedback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feedback flag on a redirect.
		if ( ! isset( $_GET['obld_cleared'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feedback flag on a redirect.
		$count = (int) $_GET['obld_cleared'];

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			sprintf(
				/* translators: %s: number of deleted log entries. */
				_n( 'Cleared %s log entry.', 'Cleared %s log entries.', $count, 'open-badges-for-learndash' ),
				number_format_i18n( $count )
			)
		) . '</p></div>';
	}

	/**
	 * Nonce-protected URL for a screen action.
	 *
	 * @param string $action Action name.
	 */
	private function action_url( string $action ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
	}

	/**
	 * Active outcome filter.
	 */
	private function current_outcome(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter.
		$outcome = isset( $_GET['outcome'] ) ? sanitize_key( wp_unslash( $_GET['outcome'] ) ) : '';

		return in_array( $outcome, array( Logger::SUCCESS, Logger::ERROR ), true ) ? $outcome : '';
	}

	/**
	 * Clear the log (admin-post).
	 */
	public function handle_clear(): void {
		$this->authorize( self::CLEAR_ACTION );

		$cleared = $this->logger->clear();

		wp_safe_redirect( add_query_arg( 'obld_cleared', (string) $cleared, self::url() ) );
		exit;
	}

	/**
	 * Stream the log as CSV (admin-post).
	 */
	public function handle_export(): void {
		$this->authorize( self::EXPORT_ACTION );

		$filename = 'open-badges-activity-log-' . gmdate( 'Y-m-d' ) . '.csv';

		// Stray output from another plugin would corrupt the file.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				__( 'Date (UTC)', 'open-badges-for-learndash' ),
				__( 'Outcome', 'open-badges-for-learndash' ),
				__( 'Learner', 'open-badges-for-learndash' ),
				__( 'Email', 'open-badges-for-learndash' ),
				__( 'Badge', 'open-badges-for-learndash' ),
				__( 'Trigger', 'open-badges-for-learndash' ),
				__( 'Message', 'open-badges-for-learndash' ),
			)
		);

		// Page through rather than loading the whole table: an export must not
		// exhaust memory on a site with a long history.
		$offset  = 0;
		$outcome = $this->current_outcome();
		$batch   = 500;

		do {
			$rows  = $this->logger->entries( $outcome, $batch, $offset );
			$found = count( $rows );

			foreach ( $rows as $row ) {
				fputcsv(
					$out,
					array_map(
						array( $this, 'csv_cell' ),
						array(
							(string) $row->created_at,
							(string) $row->outcome,
							(string) ( $row->user_name ?? '' ),
							(string) ( $row->user_email ?? '' ),
							(string) ( $row->badge_name ?? '' ),
							(string) ( $row->trigger_id ?? '' ),
							(string) $row->message,
						)
					)
				);
			}

			$offset += $batch;
		} while ( $found === $batch );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output is a stream, not a file; WP_Filesystem cannot write it.
		fclose( $out );
		exit;
	}

	/**
	 * Neutralise spreadsheet formula injection.
	 *
	 * Badge names come from the provider and learner names from user input;
	 * a cell beginning with =, +, -, @ or a tab/CR would be executed as a
	 * formula by Excel and LibreOffice when the export is opened. Prefixing
	 * such cells with an apostrophe makes them inert text.
	 *
	 * @param string $value Cell value.
	 */
	public static function csv_cell( string $value ): string {
		if ( '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Capability + nonce check.
	 *
	 * @param string $action Action name.
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'open-badges-for-learndash' ) );
		}

		check_admin_referer( $action );
	}
}
