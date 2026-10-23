<?php
/**
 * PHPUnit bootstrap: WordPress function shims backed by in-memory stores.
 *
 * These tests are pure unit tests — no WordPress install, no database, no
 * network. The shims below cover exactly the WP surface the classes under
 * test touch.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', sys_get_temp_dir() . '/wp/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// In-memory state + reset helper.
// ---------------------------------------------------------------------------

$GLOBALS['obld_test_options']    = array();
$GLOBALS['obld_test_transients'] = array();
$GLOBALS['obld_test_salts']      = array(
	'auth'        => 'test-auth-salt',
	'secure_auth' => 'test-secure-auth-salt',
);
$GLOBALS['obld_test_posts']      = array();
$GLOBALS['obld_test_can']        = true;
$GLOBALS['obld_test_users']      = array();
$GLOBALS['obld_test_cron']       = array();
$GLOBALS['obld_test_actions']    = array();
$GLOBALS['obld_test_fired']      = array();

/**
 * Reset all shim state between tests.
 */
function obld_test_reset(): void {
	$GLOBALS['obld_test_options']    = array();
	$GLOBALS['obld_test_transients'] = array();
	$GLOBALS['obld_test_salts']      = array(
		'auth'        => 'test-auth-salt',
		'secure_auth' => 'test-secure-auth-salt',
	);
	$GLOBALS['obld_test_posts']      = array();
	$GLOBALS['obld_test_can']        = true;
	$GLOBALS['obld_test_users']      = array();
	$GLOBALS['obld_test_cron']       = array();
	$GLOBALS['obld_test_actions']    = array();
	$GLOBALS['obld_test_fired']      = array();
	$GLOBALS['obld_test_filters']    = array();
	unset( $GLOBALS['obld_test_current_user'] );
	\OBLD\PageCache::reset();
	if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof OBLD_Test_Wpdb ) {
		$GLOBALS['wpdb']->inserts = array();
		$GLOBALS['wpdb']->queries = array();
		$GLOBALS['wpdb']->updates = array();
	}
}

/**
 * Register a fake user for the get_userdata() shim.
 *
 * @param int    $id    User ID.
 * @param string $email Email address.
 * @param string $name  Display name.
 */
function obld_test_add_user( int $id, string $email, string $name = 'Test Learner' ): void {
	$GLOBALS['obld_test_users'][ $id ] = (object) array(
		'ID'           => $id,
		'user_email'   => $email,
		'display_name' => $name,
	);
}

/**
 * Register a fake post for the get_posts()/get_post() shims.
 *
 * @param int    $id     Post ID.
 * @param string $type   Post type.
 * @param string $title  Post title.
 * @param string $status Post status.
 */
function obld_test_add_post( int $id, string $type, string $title, string $status = 'publish' ): void {
	$GLOBALS['obld_test_posts'][ $id ] = new WP_Post( $id, $type, $title, $status );
}

// ---------------------------------------------------------------------------
// Options / transients.
// ---------------------------------------------------------------------------

function get_option( string $option, mixed $default_value = false ): mixed {
	return $GLOBALS['obld_test_options'][ $option ] ?? $default_value;
}

function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['obld_test_options'][ $option ] = $value;
	return true;
}

function delete_option( string $option ): bool {
	unset( $GLOBALS['obld_test_options'][ $option ] );
	return true;
}

function get_transient( string $key ): mixed {
	return $GLOBALS['obld_test_transients'][ $key ] ?? false;
}

function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['obld_test_transients'][ $key ] = $value;
	return true;
}

function delete_transient( string $key ): bool {
	unset( $GLOBALS['obld_test_transients'][ $key ] );
	return true;
}

// ---------------------------------------------------------------------------
// Misc WP shims.
// ---------------------------------------------------------------------------

function wp_salt( string $scheme = 'auth' ): string {
	return $GLOBALS['obld_test_salts'][ $scheme ] ?? 'fallback-salt';
}

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	foreach ( $GLOBALS['obld_test_filters'][ $hook_name ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['obld_test_filters'][ $hook_name ][] = $callback;
	return true;
}

function current_time( string $type, bool|int $gmt = 0 ): string {
	return gmdate( 'Y-m-d H:i:s' );
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return $text;
}

function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function esc_url( string $url ): string {
	return $url;
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function get_current_user_id(): int {
	return (int) ( $GLOBALS['obld_test_current_user'] ?? 0 );
}

function date_i18n( string $format, int $timestamp ): string {
	return gmdate( $format, $timestamp );
}

function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ?? '' );
}

function sanitize_text_field( string $text ): string {
	return trim( strip_tags( $text ) );
}

function wp_unslash( mixed $value ): mixed {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function nocache_headers(): void {}

function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
	$GLOBALS['obld_test_inline_scripts'][] = array( $handle, $data, $position );
	return true;
}

function get_user_by( string $field, $value ) {
	foreach ( $GLOBALS['obld_test_users'] as $user ) {
		if ( 'email' === $field && isset( $user->user_email ) && $user->user_email === $value ) {
			return $user;
		}
	}
	return false;
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component );
}

function wp_json_encode( mixed $data, int $options = 0 ): string|false {
	return json_encode( $data, $options );
}

function number_format_i18n( float $number, int $decimals = 0 ): string {
	return number_format( $number, $decimals );
}

// ---------------------------------------------------------------------------
// Users, hooks, cron.
// ---------------------------------------------------------------------------

function get_userdata( int $user_id ): object|false {
	return $GLOBALS['obld_test_users'][ $user_id ] ?? false;
}

function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['obld_test_actions'][ $hook ][] = $callback;
	return true;
}

/**
 * Record a fired hook. Nothing listens — tests assert on the recording.
 *
 * @param string $hook Hook name.
 * @param mixed  ...$args Hook args.
 */
function do_action( string $hook, mixed ...$args ): void {
	$GLOBALS['obld_test_fired'][] = array(
		'hook' => $hook,
		'args' => $args,
	);
}

/**
 * How many times a hook has fired.
 *
 * @param string $hook Hook name.
 */
function obld_test_fired_count( string $hook ): int {
	return count(
		array_filter(
			$GLOBALS['obld_test_fired'],
			static fn ( array $f ): bool => $f['hook'] === $hook
		)
	);
}

/**
 * Record a scheduled cron event.
 *
 * @param int                $timestamp When to run.
 * @param string             $hook      Hook name.
 * @param array<int, mixed>  $args      Hook args.
 */
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
	$GLOBALS['obld_test_cron'][] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
	);
	return true;
}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
	$GLOBALS['obld_test_cron'][] = array(
		'timestamp'  => $timestamp,
		'hook'       => $hook,
		'args'       => $args,
		'recurrence' => $recurrence,
	);
	return true;
}

function wp_next_scheduled( string $hook, array $args = array() ): int|false {
	foreach ( $GLOBALS['obld_test_cron'] as $event ) {
		if ( $event['hook'] === $hook ) {
			return $event['timestamp'];
		}
	}
	return false;
}

function wp_unschedule_event( int $timestamp, string $hook, array $args = array() ): bool {
	return true;
}

/**
 * Cron events scheduled for one hook.
 *
 * @param string $hook Hook name.
 *
 * @return array<int, array<string, mixed>>
 */
function obld_test_cron_for( string $hook ): array {
	return array_values(
		array_filter(
			$GLOBALS['obld_test_cron'],
			static fn ( array $e ): bool => $e['hook'] === $hook
		)
	);
}

function add_query_arg( string $key, string $value, string $url ): string {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

function current_user_can( string $capability ): bool {
	return (bool) $GLOBALS['obld_test_can'];
}

function check_admin_referer( string $action = '', string $query_arg = '_wpnonce' ): bool {
	return true;
}

function wp_die( string $message = '' ): never {
	throw new OBLD_Test_Died( $message );
}

function wp_safe_redirect( string $location, int $status = 302 ): never {
	throw new OBLD_Test_Redirected( $location );
}

// ---------------------------------------------------------------------------
// Posts.
// ---------------------------------------------------------------------------

/**
 * Minimal WP_Post stand-in.
 */
class WP_Post {

	/**
	 * Constructor.
	 *
	 * @param int    $ID          Post ID.
	 * @param string $post_type   Post type.
	 * @param string $post_title  Post title.
	 * @param string $post_status Post status.
	 */
	public function __construct(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- mirrors WP_Post's real property name.
		public readonly int $ID,
		public readonly string $post_type,
		public readonly string $post_title,
		public readonly string $post_status = 'publish',
	) {
	}
}

/**
 * Minimal WP_User stand-in.
 */
class WP_User {

	/**
	 * Constructor.
	 *
	 * @param int $ID User ID.
	 */
	public function __construct(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- mirrors WP_User's real property name.
		public readonly int $ID
	) {
	}
}

/**
 * get_posts() shim honouring post_type, post_status, numberposts and title order.
 *
 * @param array<string, mixed> $args Query args.
 *
 * @return array<int, WP_Post>
 */
function get_posts( array $args = array() ): array {
	$type   = (string) ( $args['post_type'] ?? 'post' );
	$status = (string) ( $args['post_status'] ?? 'publish' );
	$limit  = (int) ( $args['numberposts'] ?? 5 );

	$matches = array_values(
		array_filter(
			$GLOBALS['obld_test_posts'],
			static fn ( WP_Post $post ): bool => $post->post_type === $type && $post->post_status === $status
		)
	);

	usort( $matches, static fn ( WP_Post $a, WP_Post $b ): int => strcmp( $a->post_title, $b->post_title ) );

	return $limit < 0 ? $matches : array_slice( $matches, 0, $limit );
}

/**
 * get_post() shim.
 *
 * @param int $id Post ID.
 */
function get_post( int $id = 0 ): ?WP_Post {
	return $GLOBALS['obld_test_posts'][ $id ] ?? null;
}

/**
 * Thrown in place of wp_die().
 */
class OBLD_Test_Died extends \RuntimeException {
}

/**
 * Thrown in place of wp_safe_redirect(), so the exit() after it is never hit.
 */
class OBLD_Test_Redirected extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $url Redirect target.
	 */
	public function __construct( public readonly string $url ) {
		parent::__construct( $url );
	}
}

/**
 * Minimal $wpdb stand-in for schema-definition tests.
 */
class OBLD_Test_Wpdb {

	public string $prefix   = 'wp_';
	public string $options  = 'wp_options';
	public string $usermeta = 'wp_usermeta';

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	/**
	 * Recorded insert() calls: [table, data] pairs (Logger writes in tests land here).
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $inserts = array();

	public function insert( string $table, array $data ): int {
		$this->inserts[] = array( $table, $data );
		return 1;
	}

	/**
	 * Every SQL string handed to a read/query method (after prepare()), in order.
	 *
	 * @var array<int, string>
	 */
	public array $queries = array();

	/**
	 * Recorded update() calls: [table, data, where] triples.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	public array $updates = array();

	/**
	 * Placeholder substitution good enough to assert on query shape: %d/%s/%f
	 * are replaced in order, strings single-quoted.
	 *
	 * @param string $query SQL with placeholders.
	 * @param mixed  ...$args Values.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$i = 0;
		return (string) preg_replace_callback(
			'/%[dsf]/',
			static function ( array $m ) use ( &$i, $args ): string {
				$value = $args[ $i++ ] ?? '';
				return 's' === $m[0][1] ? "'" . addslashes( (string) $value ) . "'" : (string) $value;
			},
			$query
		);
	}

	public function get_results( string $query ): array {
		$this->queries[] = $query;
		return array();
	}

	public function get_row( string $query ): ?object {
		$this->queries[] = $query;
		return null;
	}

	public function get_col( string $query ): array {
		$this->queries[] = $query;
		return array();
	}

	public function get_var( string $query ): ?string {
		$this->queries[] = $query;
		return null;
	}

	public function query( string $query ): int {
		$this->queries[] = $query;
		return 0;
	}

	public function update( string $table, array $data, array $where ): int {
		$this->updates[] = array( $table, $data, $where );
		return 1;
	}
}

$GLOBALS['wpdb'] = new OBLD_Test_Wpdb();

/**
 * Minimal WP_Error stand-in matching the surface the plugin uses.
 */
class WP_Error {

	/**
	 * Constructor.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	public function __construct(
		public readonly string $code = '',
		public readonly string $message = '',
	) {
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}
