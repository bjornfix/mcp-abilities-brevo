<?php
/**
 * Runtime contract for managed WonderPush subscription-bell settings.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

final class SIB_Manager {}

function add_action(): void {}
function add_filter( string $hook_name, callable|string $callback ): void {
	$GLOBALS['mcp_brevo_test_filters'][ $hook_name ][] = $callback;
}
function plugins_url( string $path = '' ): string {
	return 'http://wordpress.example/wp-content/plugins/' . ltrim( $path, '/' );
}
function home_url( string $path = '' ): string {
	return 'https://wordpress.example/' . ltrim( $path, '/' );
}
function wp_register_ability( string $name, array $args ): void {
	$GLOBALS['mcp_brevo_test_abilities'][ $name ] = $args;
}
function sanitize_key( string $value ): string {
	return trim( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ) );
}
function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}
function wp_unslash( string $value ): string {
	return stripslashes( $value );
}
function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['mcp_brevo_test_options'][ $name ] ?? $default;
}
function wp_parse_args( array $args, array $defaults = array() ): array {
	return array_merge( $defaults, $args );
}
function get_locale(): string {
	return 'en_US';
}
function apply_filters( string $hook_name, mixed $value ): mixed {
	return $value;
}
function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component );
}
function is_admin(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function wp_is_json_request(): bool { return false; }
function wp_enqueue_style( string $handle, string $src, array $deps, string $ver, string $media ): void {
	$GLOBALS['mcp_brevo_test_styles'][ $handle ] = array( 'media' => $media, 'version' => $ver );
}
function wp_register_script(): void {}
function wp_enqueue_script(): void {}
function wp_add_inline_script(): void {}
function wp_json_encode( mixed $data, int $flags = 0 ): string {
	return json_encode( $data, $flags | JSON_THROW_ON_ERROR );
}

require dirname( __DIR__ ) . '/mcp-abilities-brevo.php';

function mcp_brevo_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

mcp_register_brevo_abilities();
$runtime_asset_filter = $GLOBALS['mcp_brevo_test_filters']['static_publication_initial_asset_urls'][0] ?? null;
mcp_brevo_test_assert( is_callable( $runtime_asset_filter ), 'The Brevo Adapter did not register its Static Publication Interface.' );
$runtime_asset_urls = $runtime_asset_filter( array( 'https://wordpress.example/existing.js' ) );
mcp_brevo_test_assert(
	array(
		'https://wordpress.example/existing.js',
		'https://wordpress.example/wp-content/plugins/mailin/wonderpush-worker-loader.min.js',
		'https://wordpress.example/wp-content/plugins/mailin/wonderpush.min.html',
		'https://wordpress.example/wp-content/plugins/mailin/brevo-frame.html',
	) === $runtime_asset_urls,
	'The Brevo Adapter did not declare the exact Mailin worker and frame files.'
);
mcp_brevo_test_assert(
	isset( $GLOBALS['mcp_brevo_test_abilities']['brevo/wonderpush-get-subscription-bell'] ),
	'The read-only subscription-bell ability should be registered.'
);
mcp_brevo_test_assert(
	isset( $GLOBALS['mcp_brevo_test_abilities']['brevo/wonderpush-update-subscription-bell'] ),
	'The update subscription-bell ability should be registered.'
);
mcp_brevo_test_assert( 50 === count( $GLOBALS['mcp_brevo_test_abilities'] ), 'The documented ability inventory should match runtime registration.' );

$sanitized = mcp_brevo_wonderpush_sanitize_subscription_bell_settings(
	array(
		'position'           => 'RIGHT',
		'hideWhenSubscribed' => true,
		'unknown'            => 'discarded',
	)
);
mcp_brevo_test_assert(
	array(
		'position'           => 'right',
		'hideWhenSubscribed' => true,
	) === $sanitized,
	'Only supported native subscription-bell settings should survive sanitization.'
);
mcp_brevo_test_assert(
	array() === mcp_brevo_wonderpush_sanitize_subscription_bell_settings( array( 'position' => 'center' ) ),
	'An unsupported WonderPush position should be rejected.'
);

$GLOBALS['mcp_brevo_test_options'][ MCP_BREVO_WONDERPUSH_LOCALIZATION_OPTION ] = array(
	'enabled'           => true,
	'subscription_bell' => $sanitized,
	'languages'         => array(
		'en' => array(
			'locale' => 'en-GB',
			'texts'  => array(
				'subscriptionBell' => array(
					'dialogTitle' => 'Manage notifications',
				),
			),
		),
	),
);

$_SERVER['REQUEST_URI'] = '/';
$options                = mcp_brevo_wonderpush_get_current_init_options();
mcp_brevo_test_assert( 'en-GB' === $options['locale'], 'The localized WonderPush locale should remain intact.' );
mcp_brevo_test_assert( 'Manage notifications' === $options['subscriptionBell']['dialogTitle'], 'Localized bell text should remain intact.' );
mcp_brevo_test_assert( 'right' === $options['subscriptionBell']['position'], 'The global native position should merge into subscriptionBell.' );
mcp_brevo_test_assert( true === $options['subscriptionBell']['hideWhenSubscribed'], 'The subscribed-user visibility setting should merge into subscriptionBell.' );

$GLOBALS['mcp_brevo_test_options'][ MCP_BREVO_WONDERPUSH_LOCALIZATION_OPTION ]['languages'] = array();
$options = mcp_brevo_wonderpush_get_current_init_options();
mcp_brevo_test_assert( 'right' === $options['subscriptionBell']['position'], 'Global bell settings should apply without a language-specific text record.' );
mcp_brevo_test_assert( ! isset( $options['locale'] ), 'No locale should be invented without a language-specific record.' );

$style = 'mcp-brevo-wonderpush-inline-bell';
mcp_brevo_wonderpush_enqueue_preinit_script();
mcp_brevo_test_assert( ! isset( $GLOBALS['mcp_brevo_test_styles'][ $style ] ), 'An existing configuration should keep the bell floating.' );
$query = '(width < 48rem)';
$GLOBALS['mcp_brevo_test_options'][ MCP_BREVO_WONDERPUSH_LOCALIZATION_OPTION ]['subscription_bell']['inlineMediaQuery'] = $query;
mcp_brevo_test_assert( $options === mcp_brevo_wonderpush_get_current_init_options(), 'A local placement must not modify the original WonderPush init options.' );
mcp_brevo_wonderpush_enqueue_preinit_script();
$registered = $GLOBALS['mcp_brevo_test_styles'][ $style ] ?? array();
mcp_brevo_test_assert( $query === ( $registered['media'] ?? '' ), 'The asset should use exactly the supplied media query.' );
$stylesheet_contents = file_get_contents( dirname( __DIR__ ) . '/includes/wonderpush-inline-bell.css' );
mcp_brevo_test_assert( hash( 'sha256', $stylesheet_contents ) === ( $registered['version'] ?? '' ), 'The cache version should match the stylesheet contents.' );

unset( $GLOBALS['mcp_brevo_test_styles'][ $style ] );
$GLOBALS['mcp_brevo_test_options'][ MCP_BREVO_WONDERPUSH_LOCALIZATION_OPTION ]['subscription_bell']['inlineMediaQuery'] = '';
mcp_brevo_wonderpush_enqueue_preinit_script();
mcp_brevo_test_assert( ! isset( $GLOBALS['mcp_brevo_test_styles'][ $style ] ), 'An empty query should restore floating placement.' );

echo "PASS: WonderPush subscription-bell settings are sanitized and merged through the existing pre-init seam.\n";
