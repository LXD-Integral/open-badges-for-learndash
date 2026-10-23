<?php
/**
 * Earned-badges renderer.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Display;

use OBLD\Issuance\AwardRepository;
use OBLD\PageCache;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a learner's earned badges.
 *
 * The single renderer behind both the `[user_badges]` shortcode and the
 * Gutenberg block. The block is *dynamic* — its front-end output comes from
 * this same method — which is precisely how the editor and the front end are
 * guaranteed to agree. The predecessor plugin's block silently dropped the
 * image-size attribute because it rendered twice, in two places, from two
 * code paths; there is only one code path here.
 *
 * No network I/O: everything shown comes from local award and badge rows.
 */
final class BadgeList {

	/**
	 * Default and permitted layout values.
	 */
	public const DEFAULT_COLUMNS = 3;
	public const DEFAULT_SIZE    = 96;
	public const MIN_SIZE        = 32;
	public const MAX_SIZE        = 256;
	public const MAX_COLUMNS     = 6;

	/**
	 * Maximum badges rendered in one list.
	 */
	private const MAX_BADGES = 100;

	/**
	 * Constructor.
	 *
	 * @param AwardRepository $awards Award storage.
	 */
	public function __construct( private readonly AwardRepository $awards ) {
	}

	/**
	 * Normalise caller-supplied attributes into safe layout values.
	 *
	 * Shared by the shortcode and the block so a hand-typed shortcode and an
	 * editor-set attribute are constrained identically.
	 *
	 * @param array<string, mixed> $atts Raw attributes.
	 *
	 * @return array{user_id: int, columns: int, size: int, show_name: bool, link: bool, export: bool, verify: bool}
	 */
	public function normalise( array $atts ): array {
		$user_id = isset( $atts['user'] ) ? (int) $atts['user'] : 0;

		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		$columns = isset( $atts['columns'] ) ? (int) $atts['columns'] : self::DEFAULT_COLUMNS;
		$size    = isset( $atts['size'] ) ? (int) $atts['size'] : self::DEFAULT_SIZE;

		return array(
			'user_id'   => $user_id,
			'columns'   => max( 1, min( self::MAX_COLUMNS, $columns ) ),
			'size'      => max( self::MIN_SIZE, min( self::MAX_SIZE, $size ) ),
			'show_name' => ! isset( $atts['show_name'] ) || $this->truthy( $atts['show_name'] ),
			'link'      => isset( $atts['link'] ) && $this->truthy( $atts['link'] ),
			'export'    => ! isset( $atts['export'] ) || $this->truthy( $atts['export'] ),
			'verify'    => ! isset( $atts['verify'] ) || $this->truthy( $atts['verify'] ),
		);
	}

	/**
	 * Render the badge list as HTML.
	 *
	 * @param array<string, mixed> $atts    Raw attributes.
	 * @param string               $wrapper Optional extra attributes for the wrapper element.
	 */
	public function render( array $atts, string $wrapper = '' ): string {
		$config = $this->normalise( $atts );

		// With no explicit user the list follows whoever is looking (including
		// the signed-out prompt), and an owner viewing their own list gets
		// claim links no one else may see — either way the response must not
		// be served to a different viewer from a page cache. Only an explicit
		// user attribute seen by a non-owner is the same for everyone.
		$explicit_user = isset( $atts['user'] ) && (int) $atts['user'] > 0;
		$own_view      = ! $explicit_user
			|| ( $config['user_id'] > 0 && get_current_user_id() === $config['user_id'] );

		if ( $own_view ) {
			PageCache::nocache( 'open-badges-for-learndash: viewer-specific badge list' );
		}

		if ( $config['user_id'] <= 0 ) {
			return $this->message(
				__( 'Sign in to see your badges.', 'open-badges-for-learndash' ),
				$wrapper
			);
		}

		$badges = $this->awards->issued_for_user( $config['user_id'], self::MAX_BADGES );

		if ( array() === $badges ) {
			return $this->message(
				$own_view
					? __( 'No badges earned yet. Badges are awarded automatically as you complete courses, lessons, topics, or quizzes — a new badge can take a few minutes to appear here after you finish.', 'open-badges-for-learndash' )
					: __( 'No badges earned yet.', 'open-badges-for-learndash' ),
				$wrapper
			);
		}

		$style = sprintf(
			'display:grid;grid-template-columns:repeat(%d,minmax(0,1fr));gap:1.5rem;align-items:start;',
			$config['columns']
		);

		$html  = '<div class="obld-earned-badges"' . ( '' === $wrapper ? '' : ' ' . $wrapper ) . '>';
		$html .= '<ul class="obld-earned-badges__list" style="' . esc_attr( $style ) . 'list-style:none;margin:0;padding:0;">';

		foreach ( $badges as $badge ) {
			$html .= $this->badge_item( $badge, $config );
		}

		return $html . '</ul></div>';
	}

	/**
	 * One badge list item.
	 *
	 * @param object                                                                                                $badge  Award row joined to its badge.
	 * @param array{user_id: int, columns: int, size: int, show_name: bool, link: bool, export: bool, verify: bool} $config Layout.
	 */
	private function badge_item( object $badge, array $config ): string {
		$name  = (string) $badge->badge_name;
		$image = (string) $badge->badge_image_url;

		if ( '' === $image ) {
			$media = '<span class="obld-earned-badges__placeholder" aria-hidden="true" style="display:inline-block;width:'
				. (int) $config['size'] . 'px;height:' . (int) $config['size']
				. 'px;background:#f0f0f1;border-radius:50%;"></span>';
		} else {
			$media = sprintf(
				'<img class="obld-earned-badges__image" src="%s" alt="%s" width="%d" height="%d" loading="lazy" decoding="async" style="width:%dpx;height:%dpx;object-fit:contain;" />',
				esc_url( $image ),
				esc_attr( $name ),
				(int) $config['size'],
				(int) $config['size'],
				(int) $config['size'],
				(int) $config['size']
			);

			if ( $config['link'] ) {
				// The provider's credential page (image, description, issuer,
				// criteria, OpenGraph tags) beats the bare image file whenever
				// the award has been enriched.
				$href = $this->credential_page_url( (string) ( $badge->assertion_json_url ?? '' ) );

				$media = sprintf(
					'<a class="obld-earned-badges__link" href="%s" rel="noopener noreferrer" target="_blank">%s</a>',
					esc_url( '' === $href ? $image : $href ),
					$media
				);
			}
		}

		$item = '<li class="obld-earned-badges__item" style="text-align:center;">' . $media;

		if ( $config['show_name'] ) {
			$item .= '<span class="obld-earned-badges__name" style="display:block;margin-top:.5rem;font-weight:600;">'
				. esc_html( $name ) . '</span>';
		}

		if ( ! empty( $badge->issued_at ) ) {
			$timestamp = strtotime( (string) $badge->issued_at . ' UTC' );

			if ( false !== $timestamp ) {
				$item .= '<span class="obld-earned-badges__date" style="display:block;font-size:.85em;opacity:.7;">'
					. esc_html( date_i18n( (string) get_option( 'date_format' ), $timestamp ) ) . '</span>';
			}
		}

		$item .= $this->expiry_line( $badge );

		return $item . $this->badge_actions( $badge, $config ) . '</li>';
	}

	/**
	 * "Expires <date>" / "Expired <date>" under the issue date (milestone 9).
	 *
	 * Expired badges stay listed: the achievement happened, and the Verify
	 * link tells a verifier the credential has lapsed. Nothing renders for a
	 * badge with no expiry.
	 *
	 * @param object $badge Award row joined to its badge.
	 */
	private function expiry_line( object $badge ): string {
		$expires = $this->expiry_timestamp( $badge );

		if ( null === $expires ) {
			return '';
		}

		$expired = $expires <= time();
		$date    = date_i18n( (string) get_option( 'date_format' ), $expires );

		return sprintf(
			'<span class="obld-earned-badges__expiry%s" style="display:block;font-size:.85em;opacity:.7;">%s</span>',
			$expired ? ' obld-earned-badges__expiry--expired' : '',
			esc_html(
				$expired
					/* translators: %s: expiry date. */
					? sprintf( __( 'Expired %s', 'open-badges-for-learndash' ), $date )
					/* translators: %s: expiry date. */
					: sprintf( __( 'Expires %s', 'open-badges-for-learndash' ), $date )
			)
		);
	}

	/**
	 * The award's expiry as a Unix timestamp, or null when it never expires.
	 *
	 * @param object $badge Award row joined to its badge.
	 */
	private function expiry_timestamp( object $badge ): ?int {
		$stored = (string) ( $badge->expires_on ?? '' );

		if ( '' === $stored || str_starts_with( $stored, '0000-00-00' ) ) {
			return null;
		}

		$timestamp = strtotime( $stored . ' UTC' );

		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Per-badge action links: "Save & share" and "Verify" (milestone 7).
	 *
	 * The receive URL is the recipient's claim link — the same URL the provider
	 * emails — so it renders ONLY when the viewer is the badge's owner. Anyone
	 * holding it could save the badge to their own Passport, which is why it
	 * must never appear on a public showcase. Verify targets the provider's
	 * hosted validator — a human-readable page confirming the credential is
	 * valid, unrevoked, and unexpired — derived from the assertion URL, and
	 * public by design, so it may render for every visitor.
	 *
	 * Older awards may not have been enriched yet ('' or absent): each link
	 * simply renders nothing until the hourly sweep fills the URLs in.
	 *
	 * Milestone 9: once the provider reports the badge saved to a Passport,
	 * the claim nudge has done its job and a "Saved to Passport" mark takes
	 * its place; an expired badge gets no claim nudge at all.
	 *
	 * @param object                                                                                                $badge  Award row joined to its badge.
	 * @param array{user_id: int, columns: int, size: int, show_name: bool, link: bool, export: bool, verify: bool} $config Layout.
	 */
	private function badge_actions( object $badge, array $config ): string {
		$actions = array();

		$receive_url   = (string) ( $badge->receive_url ?? '' );
		$assertion_url = (string) ( $badge->assertion_json_url ?? '' );
		$is_owner      = $config['user_id'] > 0 && get_current_user_id() === $config['user_id'];
		$in_passport   = ! empty( $badge->in_passport );
		$expiry        = $this->expiry_timestamp( $badge );
		$expired       = null !== $expiry && $expiry <= time();

		if ( $config['export'] && $is_owner ) {
			if ( $in_passport ) {
				$actions[] = '<span class="obld-earned-badges__action obld-earned-badges__action--saved">'
					. esc_html__( 'Saved to Passport', 'open-badges-for-learndash' )
					. ' <span aria-hidden="true">&#10003;</span></span>';
			} elseif ( ! $expired && '' !== $receive_url ) {
				$actions[] = sprintf(
					'<a class="obld-earned-badges__action obld-earned-badges__action--save" href="%s" rel="noopener noreferrer" target="_blank">%s</a>',
					esc_url( $receive_url ),
					esc_html__( 'Save & share', 'open-badges-for-learndash' )
				);
			}
		}

		if ( $config['verify'] && '' !== $assertion_url ) {
			$validator = $this->validator_url( $assertion_url );

			$actions[] = sprintf(
				'<a class="obld-earned-badges__action obld-earned-badges__action--verify" href="%s" rel="noopener noreferrer" target="_blank">%s</a>',
				esc_url( '' === $validator ? $assertion_url : $validator ),
				esc_html__( 'Verify', 'open-badges-for-learndash' )
			);
		}

		if ( array() === $actions ) {
			return '';
		}

		return '<span class="obld-earned-badges__actions" style="display:block;margin-top:.35rem;font-size:.85em;">'
			. implode( '<span aria-hidden="true"> · </span>', $actions ) . '</span>';
	}

	/**
	 * The provider's human-readable page for a hosted credential.
	 *
	 * OBF and CanCred serve the assertion JSON's URL minus its `.json`
	 * extension as an HTML badge page (image, description, issuer, criteria);
	 * the extensionless form is the page's own canonical og:url. The OpenAPI
	 * spec carries no field for it — verified live against both providers on
	 * 2026-08-22 — so a URL not ending in `.json` yields '' and callers fall
	 * back to what the API actually returned.
	 *
	 * @param string $assertion_json_url Stored assertion URL.
	 */
	private function credential_page_url( string $assertion_json_url ): string {
		return str_ends_with( $assertion_json_url, '.json' )
			? substr( $assertion_json_url, 0, -5 )
			: '';
	}

	/**
	 * The provider's hosted validator for a credential.
	 *
	 * Both providers link every credential page to
	 * `https://<host>/validator?id=<hash>`, where the hash is the assertion
	 * JSON's basename. Same convention-not-contract caveat as
	 * credential_page_url(): '' on any unexpected shape.
	 *
	 * @param string $assertion_json_url Stored assertion URL.
	 */
	private function validator_url( string $assertion_json_url ): string {
		if ( ! str_ends_with( $assertion_json_url, '.json' ) ) {
			return '';
		}

		$scheme = wp_parse_url( $assertion_json_url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $assertion_json_url, PHP_URL_HOST );
		$hash   = basename( (string) wp_parse_url( $assertion_json_url, PHP_URL_PATH ), '.json' );

		if ( ! is_string( $scheme ) || '' === $scheme || ! is_string( $host ) || '' === $host || '' === $hash ) {
			return '';
		}

		return $scheme . '://' . $host . '/validator?id=' . rawurlencode( $hash );
	}

	/**
	 * An empty/instructional state, styled like the list wrapper.
	 *
	 * @param string $text    Message.
	 * @param string $wrapper Wrapper attributes.
	 */
	private function message( string $text, string $wrapper ): string {
		return '<div class="obld-earned-badges obld-earned-badges--empty"' . ( '' === $wrapper ? '' : ' ' . $wrapper ) . '>'
			. '<p class="obld-earned-badges__empty">' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Loose truthiness for shortcode attributes, which arrive as strings.
	 *
	 * @param mixed $value Raw value.
	 */
	private function truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
