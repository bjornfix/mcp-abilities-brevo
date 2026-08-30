<?php
/**
 * Runtime contract for managed WonderPush subscription-bell settings.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

function add_action(): void {}
function add_filter(): void {}
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

require dirname( __DIR__ ) . '/mcp-abilities-brevo.php';

function mcp_brevo_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

mcp_register_brevo_abilities();
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

echo "PASS: WonderPush subscription-bell settings are sanitized and merged through the existing pre-init seam.\n";
