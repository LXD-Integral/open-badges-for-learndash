<?php
/**
 * Manual & bulk issuance screen.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

use OBLD\Badges\BadgeRepository;
use OBLD\Issuance\BulkIssuer;
use OBLD\LearnDash\ItemRepository;
use OBLD\Provider;
use OBLD\Triggers\EventType;
use OBLD\Triggers\TriggerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The Open Badges → Issue Badges screen: award a badge to chosen users, or
 * backfill a trigger over everyone who already completed its item.
 *
 * Both actions only *queue* awards — the provider calls happen on cron, so a
 * bulk run cannot time out mid-flight and leave a partial result. This screen
 * performs no network I/O.
 */
final class IssuePage {

	public const SLUG            = 'obld-issue';
	public const MANUAL_ACTION   = 'obld_issue_manually';
	public const BACKFILL_ACTION = 'obld_backfill_trigger';

	/**
	 * Users listed in the picker at once.
	 */
	private const USER_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param BulkIssuer        $issuer   Manual/bulk orchestration.
	 * @param BadgeRepository   $badges   Badge storage.
	 * @param TriggerRepository $triggers Trigger storage.
	 * @param ItemRepository    $items    LearnDash item lookup.
	 */
	public function __construct(
		private readonly BulkIssuer $issuer,
		private readonly BadgeRepository $badges,
		private readonly TriggerRepository $triggers,
		private readonly ItemRepository $items,
	) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::MANUAL_ACTION, array( $this, 'handle_manual' ) );
		add_action( 'admin_post_' . self::BACKFILL_ACTION, array( $this, 'handle_backfill' ) );
	}

	/**
	 * URL of the Issue Badges screen.
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
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Issue Badges', 'open-badges-for-learndash' ) . '</h1>';
		echo '<hr class="wp-header-end" />';

		$this->render_feedback();

		$badges = $this->badges->selectable();

		if ( array() === $badges ) {
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: %s: badges page URL. */
					__( 'No badges are available to award yet. <a href="%s">Sync your badges</a> first.', 'open-badges-for-learndash' ),
					esc_url( BadgesPage::url() )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p></div>';
			return;
		}

		$this->render_manual_form( $badges );
		$this->render_backfill_form();

		echo '</div>';
	}

	/**
	 * The award-to-chosen-users form.
	 *
	 * @param array<int, object> $badges Selectable badges.
	 */
	private function render_manual_form( array $badges ): void {
		$search = $this->current_search();
		$users  = $this->users( $search );

		echo '<h2>' . esc_html__( 'Award a badge by hand', 'open-badges-for-learndash' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Choose a badge and one or more people. Anyone who already has the badge is skipped.',
			'open-badges-for-learndash'
		) . '</p>';

		// Plain GET form: a server-side search keeps the picker usable on a
		// site with thousands of users without adding an AJAX endpoint.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<p><label for="obld-user-search">' . esc_html__( 'Find people:', 'open-badges-for-learndash' ) . '</label> ';
		echo '<input type="search" id="obld-user-search" name="user_search" value="' . esc_attr( $search ) . '" class="regular-text" placeholder="' . esc_attr__( 'name or email', 'open-badges-for-learndash' ) . '" /> ';
		submit_button( __( 'Search', 'open-badges-for-learndash' ), 'secondary', '', false );
		if ( '' !== $search ) {
			echo ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Clear', 'open-badges-for-learndash' ) . '</a>';
		}
		echo '</p></form>';

		if ( array() === $users ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__(
				'No matching users found.',
				'open-badges-for-learndash'
			) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::MANUAL_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::MANUAL_ACTION ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="obld-manual-badge">' . esc_html__( 'Badge', 'open-badges-for-learndash' ) . '</label></th><td>';
		echo '<select name="badge_id" id="obld-manual-badge">';
		foreach ( $badges as $badge ) {
			$provider = Provider::tryFrom( (string) $badge->provider );
			printf(
				'<option value="%d">%s</option>',
				(int) $badge->id,
				esc_html(
					$provider instanceof Provider
						? sprintf( '%s (%s)', (string) $badge->name, $provider->label() )
						: (string) $badge->name
				)
			);
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="obld-manual-users">' . esc_html__( 'People', 'open-badges-for-learndash' ) . '</label></th><td>';
		echo '<select name="user_ids[]" id="obld-manual-users" multiple size="10" style="min-width:24rem;">';
		foreach ( $users as $user ) {
			printf(
				'<option value="%d">%s</option>',
				(int) $user->ID,
				esc_html( sprintf( '%s (%s)', $user->display_name, $user->user_email ) )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Hold Ctrl (or Cmd) to select more than one.', 'open-badges-for-learndash' ) . '</p>';

		if ( count( $users ) >= self::USER_LIMIT ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: maximum number of users listed. */
					__( 'Only the first %d people are listed — use the search above to narrow it down.', 'open-badges-for-learndash' ),
					self::USER_LIMIT
				)
			) . '</p>';
		}

		echo '</td></tr></tbody></table>';
		submit_button( __( 'Award badge', 'open-badges-for-learndash' ) );
		echo '</form>';
	}

	/**
	 * The backfill-a-trigger form.
	 */
	private function render_backfill_form(): void {
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Backfill a trigger', 'open-badges-for-learndash' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Award a trigger’s badge to everyone who already completed its item before the trigger existed. Anyone who already has the badge is skipped.',
			'open-badges-for-learndash'
		) . '</p>';

		$triggers = $this->triggers->all();

		if ( array() === $triggers ) {
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: %s: triggers page URL. */
					__( 'No triggers exist yet. <a href="%s">Create one</a> first.', 'open-badges-for-learndash' ),
					esc_url( TriggersPage::url() )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::BACKFILL_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::BACKFILL_ACTION ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="obld-backfill-trigger">' . esc_html__( 'Trigger', 'open-badges-for-learndash' ) . '</label></th><td>';
		echo '<select name="trigger_id" id="obld-backfill-trigger">';

		foreach ( $triggers as $trigger ) {
			// A trigger whose badge is gone or missing at the provider cannot
			// issue, so it is not offered for a backfill either.
			if ( null === $trigger->badge_name || ! empty( $trigger->badge_missing_at_provider ) ) {
				continue;
			}

			$event = EventType::tryFrom( (string) $trigger->event_type );
			$item  = $this->items->title( (int) $trigger->target_id );

			printf(
				'<option value="%d">%s</option>',
				(int) $trigger->id,
				esc_html(
					sprintf(
						/* translators: 1: badge name, 2: event label, 3: LearnDash item title. */
						__( '%1$s — %2$s: %3$s', 'open-badges-for-learndash' ),
						(string) $trigger->badge_name,
						$event instanceof EventType ? $event->label() : (string) $trigger->event_type,
						null === $item ? __( '(deleted item)', 'open-badges-for-learndash' ) : $item
					)
				)
			);
		}

		echo '</select></td></tr></tbody></table>';
		submit_button( __( 'Run backfill', 'open-badges-for-learndash' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Users for the picker, filtered by the current search.
	 *
	 * @param string $search Search term.
	 *
	 * @return array<int, \WP_User>
	 */
	private function users( string $search ): array {
		$args = array(
			'number'  => self::USER_LIMIT,
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'fields'  => array( 'ID', 'display_name', 'user_email' ),
		);

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}

		$users = get_users( $args );

		return is_array( $users ) ? $users : array();
	}

	/**
	 * Current user search term.
	 */
	private function current_search(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter on a GET form.
		return isset( $_GET['user_search'] ) ? sanitize_text_field( wp_unslash( $_GET['user_search'] ) ) : '';
	}

	/**
	 * Manual issuance handler (admin-post).
	 */
	public function handle_manual(): void {
		$this->authorize( self::MANUAL_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize() above.
		$badge_id = isset( $_POST['badge_id'] ) ? (int) $_POST['badge_id'] : 0;
		$user_ids = isset( $_POST['user_ids'] ) && is_array( $_POST['user_ids'] )
			? array_map( 'intval', wp_unslash( $_POST['user_ids'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$badge = $this->selectable_badge( $badge_id );

		if ( null === $badge ) {
			$this->redirect( array( 'obld_issue' => 'err_badge' ) );
		}

		if ( array() === $user_ids ) {
			$this->redirect( array( 'obld_issue' => 'err_users' ) );
		}

		$result = $this->issuer->issue_manually( $badge_id, $user_ids, (string) $badge->name );

		$this->redirect(
			array(
				'obld_issue' => 'done',
				'issued'     => (string) $result['issued'],
				'skipped'    => (string) $result['skipped'],
				'failed'     => (string) $result['failed'],
			)
		);
	}

	/**
	 * Backfill handler (admin-post).
	 */
	public function handle_backfill(): void {
		$this->authorize( self::BACKFILL_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize() above.
		$trigger_id = isset( $_POST['trigger_id'] ) ? (int) $_POST['trigger_id'] : 0;

		$result = $this->issuer->backfill( $trigger_id );

		if ( is_wp_error( $result ) ) {
			// The message is read back once by render_feedback(); it never
			// travels in the URL, where anyone could forge it.
			set_transient( self::error_transient_key(), $result->get_error_message(), MINUTE_IN_SECONDS );
			$this->redirect( array( 'obld_issue' => 'err_backfill' ) );
		}

		$this->redirect(
			array(
				'obld_issue' => 'done',
				'issued'     => (string) $result['issued'],
				'skipped'    => (string) $result['skipped'],
				'failed'     => (string) $result['failed'],
				'truncated'  => $result['truncated'] ? '1' : '0',
			)
		);
	}

	/**
	 * A badge id that the form legitimately offers, or null.
	 *
	 * @param int $badge_id Local badge id.
	 */
	private function selectable_badge( int $badge_id ): ?object {
		foreach ( $this->badges->selectable() as $badge ) {
			if ( (int) $badge->id === $badge_id ) {
				return $badge;
			}
		}

		return null;
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

	/**
	 * Redirect back with feedback args. Never returns.
	 *
	 * @param array<string, string> $args Query args.
	 */
	private function redirect( array $args ): void {
		$url = self::url();

		foreach ( $args as $key => $value ) {
			$url = add_query_arg( $key, $value, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Post-run summary notice.
	 */
	private function render_feedback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only feedback flags on a redirect.
		$code = isset( $_GET['obld_issue'] ) ? sanitize_key( wp_unslash( $_GET['obld_issue'] ) ) : '';

		if ( 'err_badge' === $code ) {
			$this->notice( 'error', __( 'Choose a synced, published badge to award.', 'open-badges-for-learndash' ) );
			return;
		}

		if ( 'err_users' === $code ) {
			$this->notice( 'error', __( 'Choose at least one person to award the badge to.', 'open-badges-for-learndash' ) );
			return;
		}

		if ( 'err_backfill' === $code ) {
			$detail = get_transient( self::error_transient_key() );
			$detail = is_string( $detail ) ? $detail : '';
			delete_transient( self::error_transient_key() );
			$this->notice(
				'error',
				sprintf(
					/* translators: %s: failure detail. */
					__( 'The backfill could not run: %s', 'open-badges-for-learndash' ),
					$detail
				)
			);
			return;
		}

		if ( 'done' !== $code ) {
			return;
		}

		$issued    = isset( $_GET['issued'] ) ? absint( wp_unslash( $_GET['issued'] ) ) : 0;
		$skipped   = isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0;
		$failed    = isset( $_GET['failed'] ) ? absint( wp_unslash( $_GET['failed'] ) ) : 0;
		$truncated = isset( $_GET['truncated'] ) && '1' === $_GET['truncated'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->notice(
			$failed > 0 ? 'warning' : 'success',
			sprintf(
				/* translators: 1: queued count, 2: skipped count, 3: failed count. */
				__( '%1$d queued for issuing, %2$d skipped (already had the badge), %3$d could not be issued. Badges are sent in the background — check the Activity Log for the outcome of each.', 'open-badges-for-learndash' ),
				$issued,
				$skipped,
				$failed
			)
		);

		if ( $truncated ) {
			$this->notice(
				'warning',
				sprintf(
					/* translators: %d: per-run ceiling. */
					__( 'This run stopped at the %d-learner ceiling. Run the backfill again to continue where it left off.', 'open-badges-for-learndash' ),
					BulkIssuer::MAX_PER_RUN
				)
			);
		}
	}

	/**
	 * Per-user transient carrying the last backfill error message.
	 */
	private static function error_transient_key(): string {
		return 'obld_backfill_error_' . get_current_user_id();
	}

	/**
	 * Render one notice.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Message.
	 */
	private function notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
