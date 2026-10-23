<?php
/**
 * Triggers screen.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

use OBLD\Badges\BadgeRepository;
use OBLD\LearnDash\ItemRepository;
use OBLD\Provider;
use OBLD\Triggers\EventType;
use OBLD\Triggers\TriggerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The Open Badges → Triggers screen: the rule list plus the add-a-rule form.
 *
 * Triggers are only *defined* here. Nothing on this screen fires a trigger or
 * touches a provider — matching completions to rules is milestone 4, and this
 * screen performs no network I/O at all.
 */
final class TriggersPage {

	public const SLUG          = 'obld-triggers';
	public const ADD_ACTION    = 'obld_add_trigger';
	public const DELETE_ACTION = 'obld_delete_trigger';
	public const TOGGLE_ACTION = 'obld_toggle_trigger';

	/**
	 * Query arg carrying post-action feedback.
	 */
	private const FEEDBACK_ARG = 'obld_trigger';

	/**
	 * Constructor.
	 *
	 * @param TriggerRepository $triggers Trigger storage.
	 * @param BadgeRepository   $badges   Badge storage.
	 * @param ItemRepository    $items    LearnDash item lookup.
	 */
	public function __construct(
		private readonly TriggerRepository $triggers,
		private readonly BadgeRepository $badges,
		private readonly ItemRepository $items,
	) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ADD_ACTION, array( $this, 'handle_add' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
		add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle' ) );
	}

	/**
	 * URL of the Triggers screen.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Nonce-protected URL for a per-row action.
	 *
	 * @param string $action     One of the *_ACTION constants.
	 * @param int    $trigger_id Trigger id.
	 */
	public static function action_url( string $action, int $trigger_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . $action . '&trigger=' . $trigger_id ),
			$action . '_' . $trigger_id
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'open-badges-for-learndash' ) );
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Triggers', 'open-badges-for-learndash' ) . '</h1>';
		echo '<hr class="wp-header-end" />';

		$this->render_feedback();

		echo '<p class="description">' . esc_html__(
			'A trigger awards a badge when a learner completes a specific LearnDash item.',
			'open-badges-for-learndash'
		) . '</p>';

		$table = new TriggerListTable( $this->triggers->all(), $this->items );
		$table->prepare_items();
		$table->display();

		$this->render_form();

		echo '</div>';
	}

	/**
	 * The add-a-trigger form, or an explanation of what is missing.
	 */
	private function render_form(): void {
		echo '<h2>' . esc_html__( 'Add a trigger', 'open-badges-for-learndash' ) . '</h2>';

		$badges = $this->badges->selectable();

		if ( array() === $badges ) {
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: %s: badges page URL. */
					__( 'No badges are available to award yet. <a href="%s">Sync your badges</a> first — draft badges cannot be issued, so they are not offered here.', 'open-badges-for-learndash' ),
					esc_url( BadgesPage::url() )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p>';
			return;
		}

		if ( ! $this->items->learndash_active() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__(
				'LearnDash is not active, so there are no courses, lessons, topics, or quizzes to choose from.',
				'open-badges-for-learndash'
			) . '</p></div>';
			return;
		}

		$sets      = $this->item_sets();
		$has_items = false;
		foreach ( $sets as $set ) {
			if ( array() !== $set['items'] ) {
				$has_items = true;
			}
		}

		if ( ! $has_items ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__(
				'No published LearnDash courses, lessons, topics, or quizzes were found. Publish one, then add a trigger for it.',
				'open-badges-for-learndash'
			) . '</p></div>';
			return;
		}

		$this->render_truncation_note( $sets );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ADD_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADD_ACTION ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		// Event type.
		echo '<tr><th scope="row"><label for="obld-event-type">' . esc_html__( 'When', 'open-badges-for-learndash' ) . '</label></th><td>';
		echo '<select name="event_type" id="obld-event-type">';
		foreach ( EventType::cases() as $event_type ) {
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $event_type->value ),
				esc_html( $event_type->label() )
			);
		}
		echo '</select></td></tr>';

		// Target item — options are swapped client-side to match the event type.
		echo '<tr><th scope="row"><label for="obld-target-id">' . esc_html__( 'Item', 'open-badges-for-learndash' ) . '</label></th><td>';
		echo '<select name="target_id" id="obld-target-id"></select>';
		echo '<p class="description">' . esc_html__( 'Only published items are listed.', 'open-badges-for-learndash' ) . '</p>';
		echo '</td></tr>';

		// Badge.
		echo '<tr><th scope="row"><label for="obld-badge-id">' . esc_html__( 'Award badge', 'open-badges-for-learndash' ) . '</label></th><td>';
		echo '<select name="badge_id" id="obld-badge-id">';
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

		// Minimum score — quiz events only; shown/hidden client-side.
		echo '<tr id="obld-min-score-row"><th scope="row"><label for="obld-min-score">' . esc_html__( 'Minimum score', 'open-badges-for-learndash' ) . '</label></th><td>';
		echo '<input type="number" name="min_score" id="obld-min-score" min="0" max="100" step="0.01" class="small-text" /> %';
		echo '<p class="description">' . esc_html__( 'Optional. Leave empty to award the badge for any passing completion.', 'open-badges-for-learndash' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Add trigger', 'open-badges-for-learndash' ) );
		echo '</form>';

		$this->render_form_script( $sets );
	}

	/**
	 * Note listing any event type whose item list hit the cap.
	 *
	 * @param array<string, array{items: array<int, array{id: int, title: string}>, truncated: bool}> $sets Item sets.
	 */
	private function render_truncation_note( array $sets ): void {
		$capped = array();

		foreach ( EventType::cases() as $event_type ) {
			if ( ! empty( $sets[ $event_type->value ]['truncated'] ) ) {
				$capped[] = $event_type->item_label();
			}
		}

		if ( array() === $capped ) {
			return;
		}

		echo '<div class="notice notice-info inline"><p>' . esc_html(
			sprintf(
				/* translators: 1: comma-separated item type names, 2: maximum number listed. */
				__( 'This site has more %1$s than the form lists; only the first %2$d (alphabetical) are selectable.', 'open-badges-for-learndash' ),
				implode( ', ', $capped ),
				ItemRepository::MAX_ITEMS
			)
		) . '</p></div>';
	}

	/**
	 * Data for the form's light vanilla JS (in Assets): the item list per
	 * event type, and which event types take a minimum score. No build step,
	 * no framework — the old plugin's Vue SPA is exactly what this build avoids.
	 *
	 * @param array<string, array{items: array<int, array{id: int, title: string}>, truncated: bool}> $sets Item sets.
	 */
	private function render_form_script( array $sets ): void {
		$data = array(
			'items'          => array(),
			'scoreEvents'    => array(),
			'noItemsMessage' => __( 'No published items of this type', 'open-badges-for-learndash' ),
		);

		foreach ( EventType::cases() as $event_type ) {
			$data['items'][ $event_type->value ] = $sets[ $event_type->value ]['items'] ?? array();

			if ( $event_type->supports_min_score() ) {
				$data['scoreEvents'][] = $event_type->value;
			}
		}

		// Attached before the admin script (registered by Assets), which reads
		// window.obldTriggerFormData. JSON_HEX_TAG escapes < and >, so a badge
		// or item title containing "</script>" cannot break out of the block.
		wp_add_inline_script(
			Assets::SCRIPT,
			'window.obldTriggerFormData = ' . (string) wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
	}

	/**
	 * Item sets for all four event types, keyed by event value.
	 *
	 * @return array<string, array{items: array<int, array{id: int, title: string}>, truncated: bool}>
	 */
	private function item_sets(): array {
		$sets = array();

		foreach ( EventType::cases() as $event_type ) {
			$sets[ $event_type->value ] = $this->items->items( $event_type );
		}

		return $sets;
	}

	/**
	 * Add-trigger handler (admin-post).
	 */
	public function handle_add(): void {
		// authorize() runs check_admin_referer() before any $_POST is read; the
		// sniff cannot see through the helper, hence the suppressions below.
		$this->authorize( self::ADD_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize() on the line above.
		$event_type = EventType::tryFrom(
			isset( $_POST['event_type'] ) ? sanitize_key( wp_unslash( $_POST['event_type'] ) ) : ''
		);
		$target_id  = isset( $_POST['target_id'] ) ? (int) $_POST['target_id'] : 0;
		$badge_id   = isset( $_POST['badge_id'] ) ? (int) $_POST['badge_id'] : 0;
		$raw_score  = isset( $_POST['min_score'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['min_score'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $event_type instanceof EventType || ! $this->items->is_valid_target( $event_type, $target_id ) ) {
			$this->redirect( 'err_item' );
		}

		if ( ! $this->is_selectable_badge( $badge_id ) ) {
			$this->redirect( 'err_badge' );
		}

		$min_score = null;
		if ( '' !== $raw_score && $event_type->supports_min_score() ) {
			if ( ! is_numeric( $raw_score ) ) {
				$this->redirect( 'err_score' );
			}

			$min_score = (float) $raw_score;
			if ( $min_score < 0 || $min_score > 100 ) {
				$this->redirect( 'err_score' );
			}
		}

		if ( $this->triggers->exists( $event_type, $target_id, $badge_id ) ) {
			$this->redirect( 'err_duplicate' );
		}

		$this->redirect( $this->triggers->create( $event_type, $target_id, $badge_id, $min_score ) > 0 ? 'added' : 'err_save' );
	}

	/**
	 * Delete-trigger handler (admin-post).
	 */
	public function handle_delete(): void {
		$trigger_id = $this->authorize_row( self::DELETE_ACTION );

		$this->redirect( $this->triggers->delete( $trigger_id ) ? 'deleted' : 'err_notfound' );
	}

	/**
	 * Enable/disable handler (admin-post).
	 */
	public function handle_toggle(): void {
		$trigger_id = $this->authorize_row( self::TOGGLE_ACTION );

		$trigger = $this->triggers->find( $trigger_id );
		if ( null === $trigger ) {
			$this->redirect( 'err_notfound' );
		}

		$enable = empty( $trigger->enabled );
		$this->triggers->set_enabled( $trigger_id, $enable );

		$this->redirect( $enable ? 'enabled' : 'disabled' );
	}

	/**
	 * Whether a badge id is one the form legitimately offers.
	 *
	 * Re-checked server-side so a stale or tampered form cannot attach a
	 * draft or missing badge to a rule.
	 *
	 * @param int $badge_id Local badge id.
	 */
	private function is_selectable_badge( int $badge_id ): bool {
		foreach ( $this->badges->selectable() as $badge ) {
			if ( (int) $badge->id === $badge_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Capability + nonce check for a form post.
	 *
	 * @param string $action Action name (also the nonce action).
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'open-badges-for-learndash' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Capability + per-row nonce check, returning the row id.
	 *
	 * @param string $action Action name.
	 */
	private function authorize_row( string $action ): int {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'open-badges-for-learndash' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line, which needs this id.
		$trigger_id = isset( $_GET['trigger'] ) ? (int) $_GET['trigger'] : 0;

		check_admin_referer( $action . '_' . $trigger_id );

		return $trigger_id;
	}

	/**
	 * Redirect back to the screen with a feedback code. Never returns.
	 *
	 * @param string $code Feedback code.
	 */
	private function redirect( string $code ): void {
		wp_safe_redirect( add_query_arg( self::FEEDBACK_ARG, $code, self::url() ) );
		exit;
	}

	/**
	 * Render the feedback notice for the current request, if any.
	 */
	private function render_feedback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feedback flag on a redirect.
		$code = isset( $_GET[ self::FEEDBACK_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::FEEDBACK_ARG ] ) ) : '';

		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'added'         => array( 'success', __( 'Trigger added.', 'open-badges-for-learndash' ) ),
			'deleted'       => array( 'success', __( 'Trigger deleted.', 'open-badges-for-learndash' ) ),
			'enabled'       => array( 'success', __( 'Trigger enabled.', 'open-badges-for-learndash' ) ),
			'disabled'      => array( 'success', __( 'Trigger disabled.', 'open-badges-for-learndash' ) ),
			'err_item'      => array( 'error', __( 'Choose a published LearnDash item that matches the selected event.', 'open-badges-for-learndash' ) ),
			'err_badge'     => array( 'error', __( 'Choose a synced, published badge. Draft badges and badges missing at the provider cannot be awarded.', 'open-badges-for-learndash' ) ),
			'err_score'     => array( 'error', __( 'The minimum score must be a number between 0 and 100.', 'open-badges-for-learndash' ) ),
			'err_duplicate' => array( 'error', __( 'That badge is already awarded by an existing trigger on this item.', 'open-badges-for-learndash' ) ),
			'err_save'      => array( 'error', __( 'The trigger could not be saved. Please try again.', 'open-badges-for-learndash' ) ),
			'err_notfound'  => array( 'error', __( 'That trigger no longer exists.', 'open-badges-for-learndash' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		[ $type, $message ] = $messages[ $code ];

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
