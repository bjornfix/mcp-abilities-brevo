<?php
/**
 * Plugin Name: MCP Abilities - Brevo
 * Plugin URI: https://devenia.com/plugins/mcp-abilities-brevo/
 * Description: Brevo (Sendinblue) abilities for MCP. Manage contacts, lists, WonderPush localization, and send emails via Brevo API.
 * Version: 1.0.10
 * Author: basicus
 * Author URI: https://profiles.wordpress.org/basicus/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package MCP_Abilities_Brevo
 */

declare( strict_types=1 );

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MCP_BREVO_WONDERPUSH_LOCALIZATION_OPTION = 'mcp_brevo_wonderpush_localization';

/**
 * Check if Abilities API is available.
 */
function mcp_brevo_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>MCP Abilities - Brevo</strong> requires the WordPress Abilities API.</p></div>';
		} );
		return false;
	}
	return true;
}

/**
 * Permission callback for Brevo abilities.
 */
function mcp_brevo_permission_callback(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Add default MCP annotations for Brevo abilities when omitted.
 *
 * @param array  $args         Ability registration args.
 * @param string $ability_name Ability slug.
 * @return array
 */
function mcp_brevo_add_default_annotations( array $args, string $ability_name ): array {
	if ( ! str_starts_with( $ability_name, 'brevo/' ) || isset( $args['meta']['annotations'] ) ) {
		return $args;
	}

	$readonly_abilities = array(
		'brevo/list-contacts',
		'brevo/get-contact',
		'brevo/list-lists',
		'brevo/list-language-audiences',
		'brevo/get-list',
		'brevo/list-wordpress-forms',
		'brevo/get-wordpress-form',
		'brevo/list-attributes',
		'brevo/list-sender-domains',
		'brevo/get-account',
		'brevo/list-folders',
		'brevo/get-folder',
		'brevo/list-webhooks',
		'brevo/get-webhook',
		'brevo/list-campaigns',
	);

	$destructive_abilities = array(
		'brevo/delete-contact',
		'brevo/delete-list',
		'brevo/delete-wordpress-form',
		'brevo/delete-folder',
		'brevo/delete-webhook',
		'brevo/delete-attribute',
	);

	$readonly = in_array( $ability_name, $readonly_abilities, true );
	$destructive = in_array( $ability_name, $destructive_abilities, true );
	$idempotent  = $readonly || str_contains( $ability_name, '/update-' );

	$args['meta']['annotations'] = array(
		'readonly'    => $readonly,
		'destructive' => $destructive,
		'idempotent'  => $idempotent,
	);

	return $args;
}

/**
 * Sanitize a Brevo contact attribute name.
 *
 * @param string $name Attribute name.
 * @return string
 */
function mcp_brevo_sanitize_attribute_name( string $name ): string {
	$name = strtoupper( trim( $name ) );
	$name = preg_replace( '/[^A-Z0-9_]/', '_', $name );
	$name = trim( (string) $name, '_' );

	return '' === $name ? 'LANGUAGE' : substr( $name, 0, 50 );
}

/**
 * Sanitize scalar contact attribute values.
 *
 * @param array $attributes Raw attributes.
 * @return array<string, scalar>
 */
function mcp_brevo_sanitize_contact_attributes( array $attributes ): array {
	$output = array();

	foreach ( $attributes as $key => $value ) {
		$attribute_name = mcp_brevo_sanitize_attribute_name( (string) $key );
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			$output[ $attribute_name ] = $value;
			continue;
		}

		if ( is_scalar( $value ) ) {
			$output[ $attribute_name ] = sanitize_text_field( (string) $value );
		}
	}

	return $output;
}

/**
 * Build the default Brevo list name for a language.
 *
 * @param string $language   Language code.
 * @param string $list_prefix List name prefix.
 * @return string
 */
function mcp_brevo_language_list_name( string $language, string $list_prefix ): string {
	$list_prefix = sanitize_text_field( trim( $list_prefix ) );
	if ( '' === $list_prefix ) {
		$list_prefix = mcp_brevo_default_list_prefix();
	}

	return $list_prefix . ' ' . strtoupper( mcp_brevo_wonderpush_normalize_language( $language ) );
}

/**
 * Return a vendor-neutral default prefix based on the current site title.
 *
 * @return string
 */
function mcp_brevo_default_list_prefix(): string {
	$site_title = sanitize_text_field( trim( (string) get_bloginfo( 'name' ) ) );

	return '' !== $site_title ? $site_title : 'WordPress';
}

/**
 * Return configured languages, optionally filtered by input.
 *
 * @param array $requested_languages Requested language codes.
 * @return array<string, array<string, string>>
 */
function mcp_brevo_get_requested_languages( array $requested_languages = array() ): array {
	$site_languages = mcp_brevo_wonderpush_get_site_languages();
	if ( empty( $requested_languages ) ) {
		return $site_languages;
	}

	$output = array();
	foreach ( $requested_languages as $language ) {
		$language = mcp_brevo_wonderpush_normalize_language( (string) $language );
		if ( '' === $language ) {
			continue;
		}

		$output[ $language ] = $site_languages[ $language ] ?? array(
			'language' => $language,
			'locale'   => '',
			'source'   => 'input',
		);
	}

	return $output;
}

/**
 * List Brevo attributes and detect whether the language attribute exists.
 *
 * @param string $attribute_name Attribute name.
 * @return array
 */
function mcp_brevo_get_language_attribute_status( string $attribute_name ): array {
	$attribute_name = mcp_brevo_sanitize_attribute_name( $attribute_name );
	$result         = mcp_brevo_api_request( 'GET', 'contacts/attributes' );
	if ( empty( $result['success'] ) ) {
		return array(
			'success' => false,
			'message' => $result['message'] ?? 'Could not list Brevo attributes.',
		);
	}

	$attributes = $result['data']['attributes'] ?? $result['data'] ?? array();
	$exists     = false;
	foreach ( (array) $attributes as $attribute ) {
		if ( ! is_array( $attribute ) ) {
			continue;
		}

		$name = strtoupper( (string) ( $attribute['name'] ?? '' ) );
		if ( $attribute_name === $name ) {
			$exists = true;
			break;
		}
	}

	return array(
		'success'        => true,
		'attribute_name' => $attribute_name,
		'exists'         => $exists,
		'attributes'     => $attributes,
	);
}

/**
 * Ensure the Brevo language attribute exists.
 *
 * @param string $attribute_name Attribute name.
 * @param bool   $dry_run        Whether to only report the action.
 * @return array
 */
function mcp_brevo_ensure_language_attribute( string $attribute_name, bool $dry_run = false ): array {
	$status = mcp_brevo_get_language_attribute_status( $attribute_name );
	if ( empty( $status['success'] ) ) {
		return $status;
	}

	if ( ! empty( $status['exists'] ) ) {
		return array(
			'success'        => true,
			'attribute_name' => $status['attribute_name'],
			'exists'         => true,
			'created'        => false,
			'dry_run'        => $dry_run,
			'message'        => 'Language attribute already exists.',
		);
	}

	if ( $dry_run ) {
		return array(
			'success'        => true,
			'attribute_name' => $status['attribute_name'],
			'exists'         => false,
			'created'        => false,
			'dry_run'        => true,
			'message'        => 'Language attribute would be created.',
		);
	}

	$result = mcp_brevo_api_request(
		'POST',
		'contacts/attributes/normal/' . rawurlencode( (string) $status['attribute_name'] ),
		array( 'type' => 'text' )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	return array(
		'success'        => true,
		'attribute_name' => $status['attribute_name'],
		'exists'         => false,
		'created'        => true,
		'dry_run'        => false,
		'message'        => 'Language attribute created.',
	);
}

/**
 * Fetch Brevo contact lists.
 *
 * @return array
 */
function mcp_brevo_get_all_lists(): array {
	$lists  = array();
	$count  = 0;
	$limit  = 50;
	$offset = 0;

	do {
		$result = mcp_brevo_api_request( 'GET', 'contacts/lists?limit=' . $limit . '&offset=' . $offset );
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$page_lists = (array) ( $result['data']['lists'] ?? array() );
		$count      = (int) ( $result['data']['count'] ?? count( $page_lists ) );
		$lists      = array_merge( $lists, $page_lists );
		$offset    += $limit;
	} while ( count( $page_lists ) === $limit && $offset < $count );

	return array(
		'success' => true,
		'lists'   => $lists,
		'count'   => $count,
	);
}

/**
 * Find a Brevo list by exact name, case-insensitive.
 *
 * @param array  $lists Lists.
 * @param string $name  List name.
 * @return array|null
 */
function mcp_brevo_find_list_by_name( array $lists, string $name ): ?array {
	foreach ( $lists as $list ) {
		if ( ! is_array( $list ) ) {
			continue;
		}

		if ( strtolower( (string) ( $list['name'] ?? '' ) ) === strtolower( $name ) ) {
			return $list;
		}
	}

	return null;
}

/**
 * Build or create a language-list mapping.
 *
 * @param array  $languages   Languages.
 * @param int    $folder_id   Brevo folder ID for new lists.
 * @param string $list_prefix List prefix.
 * @param bool   $dry_run     Whether to only report actions.
 * @return array
 */
function mcp_brevo_ensure_language_lists( array $languages, int $folder_id, string $list_prefix, bool $dry_run = false ): array {
	$lists_result = mcp_brevo_get_all_lists();
	if ( empty( $lists_result['success'] ) ) {
		return $lists_result;
	}

	$existing_lists = (array) $lists_result['lists'];
	$audiences      = array();
	foreach ( $languages as $language => $language_data ) {
		$list_name = mcp_brevo_language_list_name( (string) $language, $list_prefix );
		$existing  = mcp_brevo_find_list_by_name( $existing_lists, $list_name );
		$created   = false;

		if ( null === $existing && ! $dry_run ) {
			if ( $folder_id <= 0 ) {
				return array(
					'success' => false,
					'message' => 'folderId is required to create missing language lists.',
				);
			}

			$create_result = mcp_brevo_api_request(
				'POST',
				'contacts/lists',
				array(
					'name'     => $list_name,
					'folderId' => $folder_id,
				)
			);
			if ( empty( $create_result['success'] ) ) {
				return $create_result;
			}

			$existing = array(
				'id'       => $create_result['data']['id'] ?? null,
				'name'     => $list_name,
				'folderId' => $folder_id,
			);
			$existing_lists[] = $existing;
			$created          = true;
		}

		$audiences[ $language ] = array(
			'language'  => (string) $language,
			'locale'    => is_array( $language_data ) ? (string) ( $language_data['locale'] ?? '' ) : '',
			'list_name' => $list_name,
			'list_id'   => isset( $existing['id'] ) ? (int) $existing['id'] : 0,
			'exists'    => null !== $existing,
			'created'   => $created,
			'dry_run'   => $dry_run,
		);
	}

	return array(
		'success'   => true,
		'audiences' => $audiences,
	);
}

/**
 * Make a request to the Brevo API.
 *
 * @param string $method   HTTP method (GET, POST, PUT, DELETE).
 * @param string $endpoint API endpoint (without base URL).
 * @param array  $body     Request body for POST/PUT requests.
 * @return array Response array with success, data, and message.
 */
function mcp_brevo_api_request( string $method, string $endpoint, array $body = array() ): array {
	$api_key = get_option( 'sib_api_key_v3', '' );

	if ( empty( $api_key ) ) {
		return array(
			'success' => false,
			'message' => 'Brevo API key not configured. Install and configure the Brevo plugin first.',
		);
	}

	$args = array(
		'method'  => $method,
		'headers' => array(
			'api-key'      => $api_key,
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		),
		'timeout' => 30,
	);

	if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
		$args['body'] = wp_json_encode( $body );
	}

	$response = null;
	$attempts = 0;
	$max_attempts = 3;

	while ( $attempts < $max_attempts ) {
		$attempts++;
		$response = wp_remote_request( 'https://api.brevo.com/v3/' . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			if ( $attempts < $max_attempts ) {
				usleep( 250000 * $attempts );
				continue;
			}

			return array(
				'success' => false,
				'message' => 'API request failed: ' . $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( ( 429 === $status_code || $status_code >= 500 ) && $attempts < $max_attempts ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			if ( $retry_after > 0 && $retry_after <= 10 ) {
				sleep( $retry_after );
			} else {
				usleep( 250000 * $attempts );
			}
			continue;
		}

		break;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$status_text = wp_remote_retrieve_response_message( $response );
	$body_raw    = wp_remote_retrieve_body( $response );
	$body_data   = json_decode( $body_raw, true );
	if ( null === $body_data && '' !== $body_raw ) {
		$body_data = array( 'raw' => $body_raw );
	}

	// Success codes: 200, 201, 204.
	if ( $status_code >= 200 && $status_code < 300 ) {
		return array(
			'success' => true,
			'data'    => $body_data,
			'message' => 'Request successful',
		);
	}

	// Error response.
	$error_message = isset( $body_data['message'] ) ? $body_data['message'] : 'Unknown error';
	if ( isset( $body_data['code'] ) ) {
		$error_message = $body_data['code'] . ': ' . $error_message;
	}

	return array(
		'success' => false,
		'message' => 'API error (' . $status_code . ' ' . $status_text . '): ' . $error_message,
	);
}

/**
 * Sanitize a Brevo API endpoint for generic API calls.
 *
 * @param string $endpoint Endpoint without base URL or full Brevo v3 URL.
 * @return string|WP_Error
 */
function mcp_brevo_sanitize_endpoint( string $endpoint ) {
	$endpoint = trim( $endpoint );
	$endpoint = preg_replace( '#^https://api\.brevo\.com/v3/#', '', $endpoint );
	$endpoint = ltrim( (string) $endpoint, '/' );

	if ( '' === $endpoint ) {
		return new WP_Error( 'brevo_empty_endpoint', 'Endpoint is required.' );
	}

	if ( preg_match( '#(^|/)\.\.(/|$)#', $endpoint ) || preg_match( '#^https?://#i', $endpoint ) ) {
		return new WP_Error( 'brevo_invalid_endpoint', 'Endpoint must be a Brevo v3 relative endpoint.' );
	}

	return $endpoint;
}

/**
 * Check whether the official Brevo WordPress form model is available.
 *
 * @return array|null Error response when unavailable, null when available.
 */
function mcp_brevo_require_wordpress_forms(): ?array {
	if ( ! class_exists( 'SIB_Forms' ) ) {
		return array(
			'success' => false,
			'message' => 'The official Brevo WordPress plugin form model is not available.',
		);
	}

	return null;
}

/**
 * Build a compact Brevo WordPress form body.
 *
 * @param string $button_label Submit button label.
 * @param bool   $include_name Whether to include an optional first name field.
 * @return string
 */
function mcp_brevo_build_wordpress_form_html( string $button_label, bool $include_name ): string {
	$button_label = esc_attr( $button_label );
	$html         = '<p class="sib-email-area"><label class="sib-email-area">Email address*</label><input type="email" class="sib-email-area" name="email" required="required" autocomplete="email"></p>';

	if ( $include_name ) {
		$html .= '<p class="sib-FIRSTNAME-area"><label class="sib-FIRSTNAME-area">Name</label><input type="text" class="sib-FIRSTNAME-area" name="FIRSTNAME" autocomplete="given-name"></p>';
	}

	$html .= '<p><input type="submit" class="sib-default-btn" value="' . $button_label . '"></p>';
	return $html;
}

/**
 * Build minimal CSS for a Brevo WordPress form.
 *
 * @return string
 */
function mcp_brevo_build_wordpress_form_css(): string {
	return '[form]{display:grid;gap:14px;margin:0;}[form] p{margin:0;}[form] label{display:block;margin:0 0 6px;font-weight:600;}[form] input[type=text],[form] input[type=email]{width:100%;box-sizing:border-box;border:1px solid #c9c1bc;border-radius:6px;padding:13px 14px;min-height:48px;background:#fff;color:#111;}[form] .sib-default-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:6px;padding:13px 18px;min-height:48px;background:#0b57d0;color:#fff;font-weight:700;cursor:pointer;}[form] .sib-default-btn:hover{background:#0847ad;}';
}

/**
 * Return WonderPush localization fields that are safe to manage from MCP.
 *
 * @return array<string, array<string>>
 */
function mcp_brevo_wonderpush_allowed_text_fields(): array {
	return array(
		'subscriptionDialog' => array(
			'title',
			'message',
			'positiveButton',
			'negativeButton',
		),
		'subscriptionBell'   => array(
			'dialogTitle',
			'subscribeButtonTitle',
			'unsubscribeButtonTitle',
			'subscribeInviteText',
			'alreadySubscribedText',
			'alreadyUnsubscribedText',
			'blockedText',
			'subscribedText',
			'unsubscribedText',
			'advancedSettingsDescription',
			'advancedSettingsFineprint',
			'downloadDataButtonTitle',
			'clearDataButtonTitle',
		),
		'subscriptionSwitch' => array(
			'sentence',
			'on',
			'off',
		),
		'optInOptions'       => array(
			'externalBoxMessage',
			'externalBoxExampleTitle',
			'externalBoxExampleMessage',
			'externalBoxDisclaimer',
			'externalBoxProcessingMessage',
			'externalBoxSuccessMessage',
			'externalBoxFailureMessage',
			'externalBoxTooLongHint',
			'externalBoxCloseHint',
			'positiveButtonText',
			'negativeButtonText',
		),
	);
}

/**
 * Return text fields required for reliable localized WonderPush runtime UI.
 *
 * @return array<string, array<string>>
 */
function mcp_brevo_wonderpush_required_text_fields(): array {
	return array(
		'subscriptionBell' => array(
			'dialogTitle',
			'subscribeButtonTitle',
			'unsubscribeButtonTitle',
			'subscribeInviteText',
			'alreadySubscribedText',
			'alreadyUnsubscribedText',
			'blockedText',
			'subscribedText',
			'unsubscribedText',
			'advancedSettingsDescription',
			'advancedSettingsFineprint',
			'downloadDataButtonTitle',
			'clearDataButtonTitle',
		),
	);
}

/**
 * Get the stored WonderPush localization configuration.
 *
 * @return array<string, mixed>
 */
function mcp_brevo_wonderpush_get_localization_config(): array {
	$config = get_option( MCP_BREVO_WONDERPUSH_LOCALIZATION_OPTION, array() );
	if ( ! is_array( $config ) ) {
		$config = array();
	}

	$config = wp_parse_args(
		$config,
		array(
			'enabled'    => true,
			'languages'  => array(),
			'updated_at' => '',
		)
	);

	if ( ! is_array( $config['languages'] ) ) {
		$config['languages'] = array();
	}

	return $config;
}

/**
 * Normalize a language code used as a runtime WonderPush localization key.
 *
 * @param string $language Language code.
 * @return string
 */
function mcp_brevo_wonderpush_normalize_language( string $language ): string {
	$language = strtolower( trim( $language ) );
	$language = str_replace( '_', '-', $language );
	$language = preg_replace( '/[^a-z0-9-]/', '', $language );

	if ( ! is_string( $language ) || '' === $language ) {
		return '';
	}

	$parts = explode( '-', $language );
	return (string) $parts[0];
}

/**
 * Normalize a locale value to the form expected by WonderPush setLocale.
 *
 * @param string $locale Locale.
 * @return string
 */
function mcp_brevo_wonderpush_normalize_locale( string $locale ): string {
	$locale = trim( str_replace( '_', '-', $locale ) );
	if ( ! preg_match( '/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $locale ) ) {
		return '';
	}

	$parts = explode( '-', $locale );
	if ( 2 === count( $parts ) ) {
		return strtolower( $parts[0] ) . '-' . strtoupper( $parts[1] );
	}

	return strtolower( $parts[0] );
}

/**
 * Sanitize WonderPush text option input.
 *
 * @param array $texts Raw text groups.
 * @return array<string, array<string, string>>
 */
function mcp_brevo_wonderpush_sanitize_texts( array $texts ): array {
	$allowed = mcp_brevo_wonderpush_allowed_text_fields();
	$output  = array();

	foreach ( $allowed as $group => $keys ) {
		if ( empty( $texts[ $group ] ) || ! is_array( $texts[ $group ] ) ) {
			continue;
		}

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $texts[ $group ] ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $texts[ $group ][ $key ] );
			if ( '' !== $value ) {
				$output[ $group ][ $key ] = $value;
			}
		}
	}

	return $output;
}

/**
 * Build an MCP schema for WonderPush text option groups.
 *
 * @return array<string, mixed>
 */
function mcp_brevo_wonderpush_texts_schema(): array {
	$properties = array();

	foreach ( mcp_brevo_wonderpush_allowed_text_fields() as $group => $keys ) {
		$group_properties = array();
		foreach ( $keys as $key ) {
			$group_properties[ $key ] = array( 'type' => 'string' );
		}

		$properties[ $group ] = array(
			'type'                 => 'object',
			'properties'           => $group_properties,
			'additionalProperties' => false,
		);
	}

	return array(
		'type'                 => 'object',
		'properties'           => $properties,
		'additionalProperties' => false,
	);
}

/**
 * Return the current site language plus languages supplied through the public filter.
 *
 * @return array<string, array<string, string>>
 */
function mcp_brevo_wonderpush_get_site_languages(): array {
	$languages = array();
	$locale    = get_locale();
	$code      = mcp_brevo_wonderpush_normalize_language( $locale );
	if ( '' !== $code ) {
		$languages[ $code ] = array(
			'language' => $code,
			'locale'   => mcp_brevo_wonderpush_normalize_locale( $locale ),
			'source'   => 'site_locale',
		);
	}

	$filtered_languages = apply_filters( 'mcp_brevo_site_languages', $languages );
	if ( is_array( $filtered_languages ) ) {
		foreach ( $filtered_languages as $raw_code => $data ) {
			$lang_code = mcp_brevo_wonderpush_normalize_language( (string) $raw_code );
			if ( is_string( $data ) ) {
				$lang_code = mcp_brevo_wonderpush_normalize_language( $data );
			}
			if ( is_array( $data ) && isset( $data['language'] ) ) {
				$lang_code = mcp_brevo_wonderpush_normalize_language( (string) $data['language'] );
			}
			if ( '' === $lang_code ) {
				continue;
			}

			$lang_locale = '';
			if ( is_array( $data ) ) {
				$lang_locale = mcp_brevo_wonderpush_normalize_locale( (string) ( $data['locale'] ?? $data['wp_locale'] ?? '' ) );
			}

			$languages[ $lang_code ] = array(
				'language' => $lang_code,
				'locale'   => $lang_locale,
				'source'   => is_array( $data ) ? sanitize_key( (string) ( $data['source'] ?? 'filter' ) ) : 'filter',
			);
		}
	}

	return $languages;
}

/**
 * Detect the current frontend language from URL prefix and site language data.
 *
 * @return string
 */
function mcp_brevo_wonderpush_detect_current_language(): string {
	$known_languages = mcp_brevo_wonderpush_get_site_languages();
	$request_uri     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
	$path            = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	$first_segment   = trim( explode( '/', trim( $path, '/' ) )[0] ?? '' );
	$first_segment   = mcp_brevo_wonderpush_normalize_language( $first_segment );

	if ( '' !== $first_segment && isset( $known_languages[ $first_segment ] ) ) {
		return $first_segment;
	}

	return mcp_brevo_wonderpush_normalize_language( get_locale() ) ?: 'en';
}

/**
 * Build the WonderPush init options for the current frontend request.
 *
 * @return array<string, mixed>
 */
function mcp_brevo_wonderpush_get_current_init_options(): array {
	$config   = mcp_brevo_wonderpush_get_localization_config();
	$language = mcp_brevo_wonderpush_detect_current_language();

	if ( empty( $config['enabled'] ) || empty( $config['languages'][ $language ] ) || ! is_array( $config['languages'][ $language ] ) ) {
		return array();
	}

	$language_config = $config['languages'][ $language ];
	$texts           = isset( $language_config['texts'] ) && is_array( $language_config['texts'] ) ? mcp_brevo_wonderpush_sanitize_texts( $language_config['texts'] ) : array();
	$locale          = mcp_brevo_wonderpush_normalize_locale( (string) ( $language_config['locale'] ?? '' ) );
	$options         = array();

	foreach ( $texts as $group => $group_texts ) {
		if ( ! empty( $group_texts ) ) {
			$options[ $group ] = $group_texts;
		}
	}

	if ( '' !== $locale ) {
		$options['locale'] = $locale;
	}

	return $options;
}

/**
 * Enqueue a pre-init WonderPush patch so localized options are merged into the Brevo/WonderPush init call.
 */
function mcp_brevo_wonderpush_enqueue_preinit_script(): void {
	if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
		return;
	}

	$options = mcp_brevo_wonderpush_get_current_init_options();
	if ( empty( $options ) ) {
		return;
	}

	$locale = (string) ( $options['locale'] ?? '' );
	unset( $options['locale'] );

	$payload = array(
		'options' => $options,
		'locale'  => $locale,
	);

	$script_template = <<<'JS'
(function(config){
	if (!config || (!config.locale && !config.options)) {
		return;
	}
	function merge(target, source) {
		target = target && typeof target === 'object' ? target : {};
		source = source && typeof source === 'object' ? source : {};
		Object.keys(source).forEach(function(key) {
			if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
				target[key] = merge(target[key], source[key]);
			} else {
				target[key] = source[key];
			}
		});
		return target;
	}
	function patchQueue(queueName, initOptionsPath) {
		window[queueName] = window[queueName] || [];
		var queue = window[queueName];
		var originalPush = queue.push;
		if (queue._mcpBrevoWonderPushLocalized) {
			return;
		}
		queue._mcpBrevoWonderPushLocalized = true;
		queue.push = function() {
			for (var i = 0; i < arguments.length; i++) {
				var command = arguments[i];
				if (!Array.isArray(command) || command[0] !== 'init' || !command[1]) {
					continue;
				}
				var target = command[1];
				for (var j = 0; j < initOptionsPath.length; j++) {
					var key = initOptionsPath[j];
					target[key] = target[key] && typeof target[key] === 'object' ? target[key] : {};
					target = target[key];
				}
				merge(target, config.options || {});
			}
			return originalPush.apply(this, arguments);
		};
	}
	patchQueue('WonderPush', []);
	patchQueue('Brevo', ['push']);
	if (config.locale) {
		window.addEventListener('load', function() {
			window.WonderPush = window.WonderPush || [];
			window.WonderPush.push(function() {
				if (window.WonderPush && typeof window.WonderPush.setLocale === 'function') {
					window.WonderPush.setLocale(config.locale);
				}
			});
		});
	}
})(MCP_BREVO_WONDERPUSH_PAYLOAD);
JS;
	$script = str_replace( 'MCP_BREVO_WONDERPUSH_PAYLOAD', (string) wp_json_encode( $payload ), $script_template );

	wp_register_script( 'mcp-brevo-wonderpush-localization', false, array(), '1.0.10', false );
	wp_enqueue_script( 'mcp-brevo-wonderpush-localization' );
	wp_add_inline_script( 'mcp-brevo-wonderpush-localization', $script, 'before' );
}
add_action( 'wp_enqueue_scripts', 'mcp_brevo_wonderpush_enqueue_preinit_script', 0 );

/**
 * Sanitize Brevo form markup while preserving normal form fields.
 *
 * @param string $html Form HTML.
 * @return string
 */
function mcp_brevo_sanitize_form_html( string $html ): string {
	if ( class_exists( 'SIB_Manager' ) && method_exists( 'SIB_Manager', 'wordpress_allowed_attributes' ) ) {
		return wp_kses( $html, SIB_Manager::wordpress_allowed_attributes() );
	}

	return wp_kses_post( $html );
}

/**
 * Normalize a Brevo WordPress form array for MCP output.
 *
 * @param array $form Form row.
 * @return array
 */
function mcp_brevo_normalize_wordpress_form( array $form ): array {
	$id = (int) ( $form['id'] ?? 0 );

	return array(
		'id'             => $id,
		'title'          => (string) ( $form['title'] ?? '' ),
		'shortcode'      => $id > 0 ? '[sibwp_form id=' . $id . ']' : '',
		'list_ids'       => array_values( array_map( 'intval', (array) ( $form['listID'] ?? array() ) ) ),
		'list_names'     => (string) ( $form['listName'] ?? '' ),
		'attributes'     => array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $form['attributes'] ?? '' ) ) ) ) ),
		'is_double_optin'=> ! empty( $form['isDopt'] ),
		'is_optin'       => ! empty( $form['isOpt'] ),
		'redirect_form'  => (string) ( $form['redirectInForm'] ?? '' ),
		'redirect_email' => (string) ( $form['redirectInEmail'] ?? '' ),
		'term_accept'    => ! empty( $form['termAccept'] ),
		'terms_url'      => (string) ( $form['termsURL'] ?? '' ),
		'date'           => (string) ( $form['date'] ?? '' ),
		'is_default'     => ! empty( $form['isDefault'] ),
	);
}

/**
 * Build a form payload for the official Brevo WordPress plugin.
 *
 * @param array $input Input values.
 * @param array $base  Existing row to merge when updating.
 * @return array
 */
function mcp_brevo_build_wordpress_form_payload( array $input, array $base = array() ): array {
	$list_ids = isset( $input['listIds'] ) ? array_map( 'strval', array_map( 'intval', (array) $input['listIds'] ) ) : (array) ( $base['listID'] ?? array() );
	$list_ids = array_values( array_filter( $list_ids ) );

	$include_name = isset( $input['includeName'] ) ? (bool) $input['includeName'] : in_array( 'FIRSTNAME', explode( ',', (string) ( $base['attributes'] ?? '' ) ), true );
	$button_label = sanitize_text_field( (string) ( $input['buttonLabel'] ?? 'Join the waitlist' ) );
	$html         = isset( $input['html'] ) ? mcp_brevo_sanitize_form_html( (string) $input['html'] ) : mcp_brevo_build_wordpress_form_html( $button_label, $include_name );
	$css          = isset( $input['css'] ) ? wp_strip_all_tags( (string) $input['css'] ) : ( (string) ( $base['css'] ?? '' ) ?: mcp_brevo_build_wordpress_form_css() );
	$attributes   = isset( $input['attributes'] ) ? array_map( 'sanitize_text_field', (array) $input['attributes'] ) : array( 'email' );

	if ( $include_name && ! in_array( 'FIRSTNAME', $attributes, true ) ) {
		$attributes[] = 'FIRSTNAME';
	}

	return array(
		'title'             => sanitize_text_field( (string) ( $input['title'] ?? ( $base['title'] ?? 'Brevo signup form' ) ) ),
		'html'              => $html,
		'css'               => $css,
		'dependTheme'       => isset( $input['dependTheme'] ) ? (int) (bool) $input['dependTheme'] : (int) ( $base['dependTheme'] ?? 0 ),
		'listID'            => maybe_serialize( $list_ids ),
		'templateID'        => (int) ( $input['templateID'] ?? ( $base['templateID'] ?? -1 ) ),
		'confirmID'         => (int) ( $input['confirmID'] ?? ( $base['confirmID'] ?? -1 ) ),
		'isOpt'             => isset( $input['isOptin'] ) ? (int) (bool) $input['isOptin'] : (int) ( $base['isOpt'] ?? 0 ),
		'isDopt'            => isset( $input['isDoubleOptin'] ) ? (int) (bool) $input['isDoubleOptin'] : (int) ( $base['isDopt'] ?? 0 ),
		'redirectInEmail'   => esc_url_raw( (string) ( $input['redirectInEmail'] ?? ( $base['redirectInEmail'] ?? '' ) ) ),
		'redirectInForm'    => esc_url_raw( (string) ( $input['redirectInForm'] ?? ( $base['redirectInForm'] ?? '' ) ) ),
		'successMsg'        => sanitize_text_field( (string) ( $input['successMsg'] ?? ( $base['successMsg'] ?? 'Thanks. You are on the list.' ) ) ),
		'errorMsg'          => sanitize_text_field( (string) ( $input['errorMsg'] ?? ( $base['errorMsg'] ?? 'Something went wrong. Please try again.' ) ) ),
		'existMsg'          => sanitize_text_field( (string) ( $input['existMsg'] ?? ( $base['existMsg'] ?? 'You are already on the list.' ) ) ),
		'invalidMsg'        => sanitize_text_field( (string) ( $input['invalidMsg'] ?? ( $base['invalidMsg'] ?? 'Please enter a valid email address.' ) ) ),
		'requiredMsg'       => sanitize_text_field( (string) ( $input['requiredMsg'] ?? ( $base['requiredMsg'] ?? 'Please fill out this field.' ) ) ),
		'attributes'        => implode( ',', array_values( array_unique( array_filter( $attributes ) ) ) ),
		'gcaptcha'          => (int) ( $input['gCaptcha'] ?? ( $base['gCaptcha'] ?? 0 ) ),
		'gcaptcha_secret'   => sanitize_text_field( (string) ( $input['gCaptchaSecret'] ?? ( $base['gCaptcha_secret'] ?? '' ) ) ),
		'gcaptcha_site'     => sanitize_text_field( (string) ( $input['gCaptchaSite'] ?? ( $base['gCaptcha_site'] ?? '' ) ) ),
		'termAccept'        => isset( $input['termAccept'] ) ? (int) (bool) $input['termAccept'] : (int) ( $base['termAccept'] ?? 0 ),
		'termsURL'          => esc_url_raw( (string) ( $input['termsUrl'] ?? ( $base['termsURL'] ?? '' ) ) ),
		'selectCaptchaType' => (int) ( $input['selectCaptchaType'] ?? ( $base['selectCaptchaType'] ?? 0 ) ),
		'cCaptchaType'      => (int) ( $input['cCaptchaType'] ?? ( $base['cCaptchaType'] ?? 0 ) ),
		'ccaptcha_secret'   => sanitize_text_field( (string) ( $input['cCaptchaSecret'] ?? ( $base['cCaptcha_secret'] ?? '' ) ) ),
		'ccaptcha_site'     => sanitize_text_field( (string) ( $input['cCaptchaSite'] ?? ( $base['cCaptcha_site'] ?? '' ) ) ),
		'cCaptchaStyle'     => sanitize_text_field( (string) ( $input['cCaptchaStyle'] ?? ( $base['cCaptchaStyle'] ?? '' ) ) ),
	);
}

/**
 * Register Brevo abilities.
 */
function mcp_register_brevo_abilities(): void {
	if ( ! mcp_brevo_check_dependencies() ) {
		return;
	}

	// =========================================================================
	// CORE - Generic Brevo API request for endpoints not yet wrapped below.
	// =========================================================================
	wp_register_ability(
		'brevo/api-request',
		array(
			'label'               => 'Brevo API Request',
			'description'         => 'Call any Brevo API v3 endpoint with the configured Brevo API key. Use specific abilities when available.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'method', 'endpoint' ),
				'properties'           => array(
					'method'   => array(
						'type'        => 'string',
						'enum'        => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
						'description' => 'HTTP method.',
					),
					'endpoint' => array(
						'type'        => 'string',
						'description' => 'Brevo API v3 endpoint, for example contacts/folders or smtp/statistics/events.',
					),
					'body'     => array(
						'type'        => 'object',
						'description' => 'JSON body for POST, PUT, PATCH, or DELETE requests.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'data'    => array( 'type' => array( 'object', 'array', 'string', 'null' ) ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$method = strtoupper( sanitize_text_field( (string) ( $input['method'] ?? '' ) ) );
				if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
					return array( 'success' => false, 'message' => 'Unsupported method.' );
				}

				$endpoint = mcp_brevo_sanitize_endpoint( (string) ( $input['endpoint'] ?? '' ) );
				if ( is_wp_error( $endpoint ) ) {
					return array( 'success' => false, 'message' => $endpoint->get_error_message() );
				}

				return mcp_brevo_api_request( $method, $endpoint, (array) ( $input['body'] ?? array() ) );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/get-account',
		array(
			'label'               => 'Get Brevo Account',
			'description'         => 'Get the current Brevo account details and plan information.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'data'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function (): array {
				return mcp_brevo_api_request( 'GET', 'account' );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// WONDERPUSH - Runtime localization for official Brevo/WonderPush frontend UI.
	// =========================================================================
	wp_register_ability(
		'brevo/wonderpush-get-localization',
		array(
			'label'               => 'Get WonderPush Localization',
			'description'         => 'Read runtime WonderPush prompt/widget localization stored by this Brevo MCP add-on.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'includeAllowedFields' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Include the managed WonderPush text field allowlist in the response.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'          => array( 'type' => 'boolean' ),
					'enabled'          => array( 'type' => 'boolean' ),
					'languages'        => array( 'type' => 'object' ),
					'site_languages'   => array( 'type' => 'object' ),
					'allowed_fields'   => array( 'type' => 'object' ),
					'current_language' => array( 'type' => 'string' ),
					'current_options'  => array( 'type' => 'object' ),
					'message'          => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$config                 = mcp_brevo_wonderpush_get_localization_config();
				$include_allowed_fields = isset( $input['includeAllowedFields'] ) ? (bool) $input['includeAllowedFields'] : true;

				return array(
					'success'          => true,
					'enabled'          => (bool) $config['enabled'],
					'languages'        => $config['languages'],
					'site_languages'   => mcp_brevo_wonderpush_get_site_languages(),
					'allowed_fields'   => $include_allowed_fields ? mcp_brevo_wonderpush_allowed_text_fields() : array(),
					'current_language' => mcp_brevo_wonderpush_detect_current_language(),
					'current_options'  => mcp_brevo_wonderpush_get_current_init_options(),
					'message'          => 'WonderPush localization retrieved.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/wonderpush-update-localization',
		array(
			'label'               => 'Update WonderPush Localization',
			'description'         => 'Create or update localized WonderPush prompt/widget text for one language. This changes runtime WordPress option data, not plugin language files.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'language' ),
				'properties'           => array(
					'language' => array(
						'type'        => 'string',
						'description' => 'Language code such as nb, de, fr, es, sv, da, fi, or ar.',
					),
					'locale'   => array(
						'type'        => 'string',
						'description' => 'Optional locale for WonderPush setLocale, such as nb-NO or de-DE.',
					),
					'enabled'  => array(
						'type'        => 'boolean',
						'description' => 'Enable or disable the frontend localization injector globally.',
					),
					'texts'    => mcp_brevo_wonderpush_texts_schema(),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'language' => array( 'type' => 'string' ),
					'locale'   => array( 'type' => 'string' ),
					'texts'    => array( 'type' => 'object' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$language = mcp_brevo_wonderpush_normalize_language( (string) ( $input['language'] ?? '' ) );
				if ( '' === $language ) {
					return array( 'success' => false, 'message' => 'A valid language code is required.' );
				}

				$config = mcp_brevo_wonderpush_get_localization_config();
				if ( isset( $input['enabled'] ) ) {
					$config['enabled'] = (bool) $input['enabled'];
				}

				$existing = isset( $config['languages'][ $language ] ) && is_array( $config['languages'][ $language ] ) ? $config['languages'][ $language ] : array();
				$locale   = isset( $input['locale'] ) ? mcp_brevo_wonderpush_normalize_locale( (string) $input['locale'] ) : (string) ( $existing['locale'] ?? '' );
				if ( isset( $input['locale'] ) && '' === $locale ) {
					return array( 'success' => false, 'message' => 'Locale must look like nb, nb-NO, or de-DE.' );
				}

				$texts = isset( $existing['texts'] ) && is_array( $existing['texts'] ) ? mcp_brevo_wonderpush_sanitize_texts( $existing['texts'] ) : array();
				if ( isset( $input['texts'] ) && is_array( $input['texts'] ) ) {
					$new_texts = mcp_brevo_wonderpush_sanitize_texts( $input['texts'] );
					foreach ( $new_texts as $group => $group_texts ) {
						$texts[ $group ] = array_merge( $texts[ $group ] ?? array(), $group_texts );
					}
				}

				if ( '' === $locale && empty( $texts ) ) {
					return array( 'success' => false, 'message' => 'Provide a locale or at least one localized text value.' );
				}

				$config['languages'][ $language ] = array(
					'locale'     => $locale,
					'texts'      => $texts,
					'updated_at' => current_time( 'mysql', true ),
				);
				$config['updated_at'] = current_time( 'mysql', true );

				update_option( MCP_BREVO_WONDERPUSH_LOCALIZATION_OPTION, $config, false );

				return array(
					'success'  => true,
					'language' => $language,
					'locale'   => $locale,
					'texts'    => $texts,
					'message'  => 'WonderPush localization updated.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/wonderpush-audit-localization',
		array(
			'label'               => 'Audit WonderPush Localization',
			'description'         => 'Compare known site languages against stored WonderPush prompt/widget localization and report missing runtime text coverage.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'requireTexts' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'When true, flag languages missing required WonderPush runtime text fields.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'            => array( 'type' => 'boolean' ),
					'passed'             => array( 'type' => 'boolean' ),
					'enabled'            => array( 'type' => 'boolean' ),
					'site_languages'     => array( 'type' => 'object' ),
					'configured'         => array( 'type' => 'array' ),
					'missing_languages'  => array( 'type' => 'array' ),
					'languages_no_texts' => array( 'type' => 'array' ),
					'missing_required_texts' => array( 'type' => 'object' ),
					'message'            => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$config          = mcp_brevo_wonderpush_get_localization_config();
				$site_languages  = mcp_brevo_wonderpush_get_site_languages();
				$configured      = array_keys( $config['languages'] );
				$require_texts   = isset( $input['requireTexts'] ) ? (bool) $input['requireTexts'] : true;
				$missing         = array();
				$languages_empty = array();
				$missing_fields  = array();
				$required_fields = mcp_brevo_wonderpush_required_text_fields();

				foreach ( $site_languages as $language => $data ) {
					if ( ! isset( $config['languages'][ $language ] ) ) {
						$missing[] = $language;
						continue;
					}

					$texts = $config['languages'][ $language ]['texts'] ?? array();
					if ( $require_texts && empty( $texts ) ) {
						$languages_empty[] = $language;
					}
					if ( $require_texts && is_array( $texts ) ) {
						foreach ( $required_fields as $group => $keys ) {
							$group_texts = isset( $texts[ $group ] ) && is_array( $texts[ $group ] ) ? $texts[ $group ] : array();
							foreach ( $keys as $key ) {
								if ( '' === trim( (string) ( $group_texts[ $key ] ?? '' ) ) ) {
									$missing_fields[ $language ][] = $group . '.' . $key;
								}
							}
						}
					}
				}

				$passed = (bool) $config['enabled'] && empty( $missing ) && empty( $languages_empty ) && empty( $missing_fields );

				return array(
					'success'            => true,
					'passed'             => $passed,
					'enabled'            => (bool) $config['enabled'],
					'site_languages'     => $site_languages,
					'configured'         => $configured,
					'missing_languages'  => $missing,
					'languages_no_texts' => $languages_empty,
					'missing_required_texts' => $missing_fields,
					'message'            => $passed ? 'WonderPush localization coverage looks complete for known site languages.' : 'WonderPush localization coverage is incomplete.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CONTACTS - List Contacts
	// =========================================================================
	wp_register_ability(
		'brevo/list-contacts',
		array(
			'label'               => 'List Brevo Contacts',
			'description'         => 'Get all contacts from Brevo with pagination.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit'  => array(
						'type'        => 'integer',
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 1000,
						'description' => 'Number of contacts to return (max 1000).',
					),
					'offset' => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Pagination offset.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'contacts' => array( 'type' => 'array' ),
					'count'    => array( 'type' => 'integer' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$limit  = isset( $input['limit'] ) ? min( (int) $input['limit'], 1000 ) : 50;
				$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;

				$result = mcp_brevo_api_request( 'GET', 'contacts?limit=' . $limit . '&offset=' . $offset );

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success'  => true,
					'contacts' => $result['data']['contacts'] ?? array(),
					'count'    => $result['data']['count'] ?? 0,
					'message'  => 'Retrieved ' . count( $result['data']['contacts'] ?? array() ) . ' contacts.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CONTACTS - Get Contact
	// =========================================================================
	wp_register_ability(
		'brevo/get-contact',
		array(
			'label'               => 'Get Brevo Contact',
			'description'         => 'Get a single contact by email or ID.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'identifier' ),
				'properties'           => array(
					'identifier' => array(
						'type'        => 'string',
						'description' => 'Contact email address or ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'contact' => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['identifier'] ) ) {
					return array( 'success' => false, 'message' => 'Identifier (email or ID) is required.' );
				}

				$identifier = rawurlencode( $input['identifier'] );
				$result     = mcp_brevo_api_request( 'GET', 'contacts/' . $identifier );

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success' => true,
					'contact' => $result['data'],
					'message' => 'Contact retrieved successfully.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CONTACTS - Create Contact
	// =========================================================================
	wp_register_ability(
		'brevo/create-contact',
		array(
			'label'               => 'Create Brevo Contact',
			'description'         => 'Create a new contact in Brevo.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'email' ),
				'properties'           => array(
					'email'      => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => 'Email address of the contact.',
					),
					'attributes' => array(
						'type'        => 'object',
						'description' => 'Contact attributes (FIRSTNAME, LASTNAME, SMS, etc.).',
					),
					'listIds'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'List IDs to add the contact to.',
					),
					'updateEnabled' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Update contact if already exists.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['email'] ) ) {
					return array( 'success' => false, 'message' => 'Email is required.' );
				}

				$body = array(
					'email' => sanitize_email( $input['email'] ),
				);

				if ( ! empty( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
					$body['attributes'] = $input['attributes'];
				}

				if ( ! empty( $input['listIds'] ) && is_array( $input['listIds'] ) ) {
					$body['listIds'] = array_map( 'intval', $input['listIds'] );
				}

				if ( isset( $input['updateEnabled'] ) ) {
					$body['updateEnabled'] = (bool) $input['updateEnabled'];
				}

				$result = mcp_brevo_api_request( 'POST', 'contacts', $body );

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success' => true,
					'id'      => $result['data']['id'] ?? null,
					'message' => 'Contact created successfully.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// CONTACTS - Update Contact
	// =========================================================================
	wp_register_ability(
		'brevo/update-contact',
		array(
			'label'               => 'Update Brevo Contact',
			'description'         => 'Update an existing contact in Brevo.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'identifier' ),
				'properties'           => array(
					'identifier' => array(
						'type'        => 'string',
						'description' => 'Contact email address or ID.',
					),
					'attributes' => array(
						'type'        => 'object',
						'description' => 'Contact attributes to update.',
					),
					'listIds'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'List IDs (replaces existing lists).',
					),
					'unlinkListIds' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'List IDs to remove contact from.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['identifier'] ) ) {
					return array( 'success' => false, 'message' => 'Identifier (email or ID) is required.' );
				}

				$body = array();

				if ( ! empty( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
					$body['attributes'] = $input['attributes'];
				}

				if ( ! empty( $input['listIds'] ) && is_array( $input['listIds'] ) ) {
					$body['listIds'] = array_map( 'intval', $input['listIds'] );
				}

				if ( ! empty( $input['unlinkListIds'] ) && is_array( $input['unlinkListIds'] ) ) {
					$body['unlinkListIds'] = array_map( 'intval', $input['unlinkListIds'] );
				}

				if ( empty( $body ) ) {
					return array( 'success' => false, 'message' => 'No update data provided.' );
				}

				$identifier = rawurlencode( $input['identifier'] );
				$result     = mcp_brevo_api_request( 'PUT', 'contacts/' . $identifier, $body );

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success' => true,
					'message' => 'Contact updated successfully.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CONTACTS - Delete Contact
	// =========================================================================
	wp_register_ability(
		'brevo/delete-contact',
		array(
			'label'               => 'Delete Brevo Contact',
			'description'         => 'Delete a contact from Brevo.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'identifier' ),
				'properties'           => array(
					'identifier' => array(
						'type'        => 'string',
						'description' => 'Contact email address or ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['identifier'] ) ) {
					return array( 'success' => false, 'message' => 'Identifier (email or ID) is required.' );
				}

				$identifier = rawurlencode( $input['identifier'] );
				$result     = mcp_brevo_api_request( 'DELETE', 'contacts/' . $identifier );

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success' => true,
					'message' => 'Contact deleted successfully.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// LISTS - List Lists
	// =========================================================================
	wp_register_ability(
		'brevo/list-lists',
		array(
			'label'               => 'List Brevo Lists',
			'description'         => 'Get all contact lists from Brevo.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit'  => array(
						'type'        => 'integer',
						'default'     => 50,
						'description' => 'Number of lists to return.',
					),
					'offset' => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Pagination offset.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'lists'   => array( 'type' => 'array' ),
					'count'   => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
				'execute_callback'    => function ( array $input = array() ): array {
					$limit  = isset( $input['limit'] ) ? min( 1000, max( 1, (int) $input['limit'] ) ) : 50;
					$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;

					$result = mcp_brevo_api_request( 'GET', 'contacts/lists?limit=' . $limit . '&offset=' . $offset );

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success' => true,
					'lists'   => $result['data']['lists'] ?? array(),
					'count'   => $result['data']['count'] ?? 0,
					'message' => 'Retrieved ' . count( $result['data']['lists'] ?? array() ) . ' lists.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/get-list',
		array(
			'label'               => 'Get Contact List',
			'description'         => 'Get a contact list by ID.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'list_id' ),
				'properties'           => array(
					'list_id' => array(
						'type'        => 'integer',
						'description' => 'List ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'list'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$list_id = (int) ( $input['list_id'] ?? 0 );
				if ( $list_id <= 0 ) {
					return array( 'success' => false, 'message' => 'list_id is required.' );
				}

				$result = mcp_brevo_api_request( 'GET', 'contacts/lists/' . $list_id );
				if ( ! empty( $result['success'] ) ) {
					return $result;
				}
				return $result;
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/update-list',
		array(
			'label'               => 'Update Contact List',
			'description'         => 'Update a contact list name or folder.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'list_id' ),
				'properties'           => array(
					'list_id'  => array(
						'type'        => 'integer',
						'description' => 'List ID.',
					),
					'name'     => array(
						'type'        => 'string',
						'description' => 'List name.',
					),
					'folderId' => array(
						'type'        => 'integer',
						'description' => 'Folder ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$list_id = (int) ( $input['list_id'] ?? 0 );
				if ( $list_id <= 0 ) {
					return array( 'success' => false, 'message' => 'list_id is required.' );
				}

				$body = array();
				if ( isset( $input['name'] ) ) {
					$body['name'] = sanitize_text_field( $input['name'] );
				}
				if ( isset( $input['folderId'] ) ) {
					$body['folderId'] = (int) $input['folderId'];
				}

				if ( empty( $body ) ) {
					return array( 'success' => false, 'message' => 'No fields provided to update.' );
				}

				return mcp_brevo_api_request( 'PUT', 'contacts/lists/' . $list_id, $body );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/delete-list',
		array(
			'label'               => 'Delete Contact List',
			'description'         => 'Delete a contact list by ID.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'list_id' ),
				'properties'           => array(
					'list_id' => array(
						'type'        => 'integer',
						'description' => 'List ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$list_id = (int) ( $input['list_id'] ?? 0 );
				if ( $list_id <= 0 ) {
					return array( 'success' => false, 'message' => 'list_id is required.' );
				}

				return mcp_brevo_api_request( 'DELETE', 'contacts/lists/' . $list_id );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	// =========================================================================
	// LISTS - Create List
	// =========================================================================
	wp_register_ability(
		'brevo/create-list',
		array(
			'label'               => 'Create Brevo List',
			'description'         => 'Create a new contact list in Brevo.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name', 'folderId' ),
				'properties'           => array(
					'name'     => array(
						'type'        => 'string',
						'description' => 'Name of the list.',
					),
					'folderId' => array(
						'type'        => 'integer',
						'description' => 'Folder ID to create the list in.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['name'] ) || ! isset( $input['folderId'] ) ) {
					return array( 'success' => false, 'message' => 'Name and folderId are required.' );
				}

				$body = array(
					'name'     => sanitize_text_field( $input['name'] ),
					'folderId' => (int) $input['folderId'],
				);

				$result = mcp_brevo_api_request( 'POST', 'contacts/lists', $body );

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success' => true,
					'id'      => $result['data']['id'] ?? null,
					'message' => 'List created successfully.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// LANGUAGE AUDIENCES - Keep Brevo audience structure aligned with site languages.
	// =========================================================================
	wp_register_ability(
		'brevo/list-language-audiences',
		array(
			'label'               => 'List Brevo Language Audiences',
			'description'         => 'Audit known site languages against Brevo language attribute and language-specific contact lists.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'languages'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional language codes to inspect. Defaults to all known site languages.',
					),
					'listPrefix'    => array(
						'type'        => 'string',
						'description' => 'Prefix for language list names. Defaults to the current WordPress site title.',
					),
					'attributeName' => array(
						'type'        => 'string',
						'default'     => 'LANGUAGE',
						'description' => 'Brevo contact attribute used for the normalized language code.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'          => array( 'type' => 'boolean' ),
					'passed'           => array( 'type' => 'boolean' ),
					'attribute_name'   => array( 'type' => 'string' ),
					'attribute_exists' => array( 'type' => 'boolean' ),
					'site_languages'   => array( 'type' => 'object' ),
					'audiences'        => array( 'type' => 'object' ),
					'missing_lists'    => array( 'type' => 'array' ),
					'message'          => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$attribute_name = mcp_brevo_sanitize_attribute_name( (string) ( $input['attributeName'] ?? 'LANGUAGE' ) );
				$list_prefix    = sanitize_text_field( (string) ( $input['listPrefix'] ?? mcp_brevo_default_list_prefix() ) );
				$languages      = mcp_brevo_get_requested_languages( (array) ( $input['languages'] ?? array() ) );

				$attribute = mcp_brevo_get_language_attribute_status( $attribute_name );
				if ( empty( $attribute['success'] ) ) {
					return $attribute;
				}

				$lists = mcp_brevo_ensure_language_lists( $languages, 0, $list_prefix, true );
				if ( empty( $lists['success'] ) ) {
					return $lists;
				}

				$missing = array();
				foreach ( $lists['audiences'] as $language => $audience ) {
					if ( empty( $audience['exists'] ) ) {
						$missing[] = $language;
					}
				}

				$passed = ! empty( $attribute['exists'] ) && empty( $missing );
				return array(
					'success'          => true,
					'passed'           => $passed,
					'attribute_name'   => $attribute_name,
					'attribute_exists' => (bool) $attribute['exists'],
					'site_languages'   => $languages,
					'audiences'        => $lists['audiences'],
					'missing_lists'    => $missing,
					'message'          => $passed ? 'Brevo language audiences look complete.' : 'Brevo language audiences are incomplete.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/ensure-language-audiences',
		array(
			'label'               => 'Ensure Brevo Language Audiences',
			'description'         => 'Create the Brevo language attribute and language-specific contact lists for known site languages.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'folderId' ),
				'properties'           => array(
					'folderId'      => array(
						'type'        => 'integer',
						'description' => 'Brevo folder ID where missing language lists should be created.',
					),
					'languages'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional language codes to ensure. Defaults to all known site languages.',
					),
					'listPrefix'    => array(
						'type'        => 'string',
						'description' => 'Prefix for language list names. Defaults to the current WordPress site title.',
					),
					'attributeName' => array(
						'type'        => 'string',
						'default'     => 'LANGUAGE',
						'description' => 'Brevo contact attribute used for the normalized language code.',
					),
					'dryRun'        => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Report what would be created without changing Brevo.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'attribute' => array( 'type' => 'object' ),
					'audiences' => array( 'type' => 'object' ),
					'dry_run'   => array( 'type' => 'boolean' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$folder_id      = (int) ( $input['folderId'] ?? 0 );
				$dry_run        = ! empty( $input['dryRun'] );
				$attribute_name = mcp_brevo_sanitize_attribute_name( (string) ( $input['attributeName'] ?? 'LANGUAGE' ) );
				$list_prefix    = sanitize_text_field( (string) ( $input['listPrefix'] ?? mcp_brevo_default_list_prefix() ) );
				$languages      = mcp_brevo_get_requested_languages( (array) ( $input['languages'] ?? array() ) );

				if ( empty( $languages ) ) {
					return array( 'success' => false, 'message' => 'No site languages found or provided.' );
				}
				if ( $folder_id <= 0 && ! $dry_run ) {
					return array( 'success' => false, 'message' => 'folderId is required to create missing language lists.' );
				}

				$attribute = mcp_brevo_ensure_language_attribute( $attribute_name, $dry_run );
				if ( empty( $attribute['success'] ) ) {
					return $attribute;
				}

				$lists = mcp_brevo_ensure_language_lists( $languages, $folder_id, $list_prefix, $dry_run );
				if ( empty( $lists['success'] ) ) {
					return $lists;
				}

				return array(
					'success'   => true,
					'attribute' => $attribute,
					'audiences' => $lists['audiences'],
					'dry_run'   => $dry_run,
					'message'   => $dry_run ? 'Brevo language audiences dry-run completed.' : 'Brevo language audiences ensured.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/upsert-language-contact',
		array(
			'label'               => 'Upsert Brevo Language Contact',
			'description'         => 'Create or update one Brevo contact with a language attribute and the matching language audience list.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'email', 'language' ),
				'properties'           => array(
					'email'         => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => 'Contact email address.',
					),
					'language'      => array(
						'type'        => 'string',
						'description' => 'Language code such as en, nb, de, fr, es, sv, da, fi, or ar.',
					),
					'attributes'    => array(
						'type'        => 'object',
						'description' => 'Additional scalar Brevo contact attributes.',
					),
					'listId'        => array(
						'type'        => 'integer',
						'description' => 'Explicit language list ID. If omitted, the ability looks up listPrefix + language.',
					),
					'folderId'      => array(
						'type'        => 'integer',
						'description' => 'Folder ID for creating the list when ensureList is true.',
					),
					'ensureList'    => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Create missing language list before adding the contact.',
					),
					'listPrefix'    => array(
						'type'        => 'string',
						'description' => 'Prefix for language list names. Defaults to the current WordPress site title.',
					),
					'attributeName' => array(
						'type'        => 'string',
						'default'     => 'LANGUAGE',
						'description' => 'Brevo contact attribute used for the normalized language code.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'email'          => array( 'type' => 'string' ),
					'language'       => array( 'type' => 'string' ),
					'attribute_name' => array( 'type' => 'string' ),
					'list_id'        => array( 'type' => 'integer' ),
					'list_name'      => array( 'type' => 'string' ),
					'message'        => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
				if ( ! is_email( $email ) ) {
					return array( 'success' => false, 'message' => 'A valid email is required.' );
				}

				$language = mcp_brevo_wonderpush_normalize_language( (string) ( $input['language'] ?? '' ) );
				if ( '' === $language ) {
					return array( 'success' => false, 'message' => 'A valid language code is required.' );
				}

				$attribute_name = mcp_brevo_sanitize_attribute_name( (string) ( $input['attributeName'] ?? 'LANGUAGE' ) );
				$list_prefix    = sanitize_text_field( (string) ( $input['listPrefix'] ?? mcp_brevo_default_list_prefix() ) );
				$list_name      = mcp_brevo_language_list_name( $language, $list_prefix );
				$list_id        = (int) ( $input['listId'] ?? 0 );

				if ( $list_id <= 0 ) {
					$ensure_list = ! empty( $input['ensureList'] );
					$folder_id   = (int) ( $input['folderId'] ?? 0 );
					$lists       = $ensure_list
						? mcp_brevo_ensure_language_lists( array( $language => array( 'language' => $language, 'locale' => '', 'source' => 'input' ) ), $folder_id, $list_prefix, false )
						: mcp_brevo_ensure_language_lists( array( $language => array( 'language' => $language, 'locale' => '', 'source' => 'input' ) ), 0, $list_prefix, true );
					if ( empty( $lists['success'] ) ) {
						return $lists;
					}

					$list_id = (int) ( $lists['audiences'][ $language ]['list_id'] ?? 0 );
					if ( $list_id <= 0 ) {
						return array(
							'success' => false,
							'message' => 'Language list was not found. Provide listId or set ensureList with folderId.',
						);
					}
				}

				$attributes                    = mcp_brevo_sanitize_contact_attributes( (array) ( $input['attributes'] ?? array() ) );
				$attributes[ $attribute_name ] = $language;

				$result = mcp_brevo_api_request(
					'POST',
					'contacts',
					array(
						'email'         => $email,
						'attributes'    => $attributes,
						'listIds'       => array( $list_id ),
						'updateEnabled' => true,
					)
				);
				if ( empty( $result['success'] ) ) {
					return $result;
				}

				return array(
					'success'        => true,
					'email'          => $email,
					'language'       => $language,
					'attribute_name' => $attribute_name,
					'list_id'        => $list_id,
					'list_name'      => $list_name,
					'message'        => 'Brevo language contact upserted.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// FOLDERS - Manage contact-list folders.
	// =========================================================================
	wp_register_ability(
		'brevo/list-folders',
		array(
			'label'               => 'List Brevo Folders',
			'description'         => 'List Brevo contact-list folders.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit'  => array( 'type' => 'integer', 'default' => 50 ),
					'offset' => array( 'type' => 'integer', 'default' => 0 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'folders' => array( 'type' => 'array' ),
					'count'   => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$limit  = isset( $input['limit'] ) ? min( 50, max( 1, (int) $input['limit'] ) ) : 50;
				$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
				$result = mcp_brevo_api_request( 'GET', 'contacts/folders?limit=' . $limit . '&offset=' . $offset );
				if ( ! $result['success'] ) {
					return $result;
				}
				return array(
					'success' => true,
					'folders' => $result['data']['folders'] ?? array(),
					'count'   => $result['data']['count'] ?? 0,
					'message' => 'Retrieved ' . count( $result['data']['folders'] ?? array() ) . ' folder(s).',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/get-folder',
		array(
			'label'               => 'Get Brevo Folder',
			'description'         => 'Get a Brevo contact-list folder by ID.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'folderId' ),
				'properties'           => array(
					'folderId' => array( 'type' => 'integer' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'data'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$folder_id = (int) ( $input['folderId'] ?? 0 );
				if ( $folder_id <= 0 ) {
					return array( 'success' => false, 'message' => 'folderId is required.' );
				}
				return mcp_brevo_api_request( 'GET', 'contacts/folders/' . $folder_id );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/create-folder',
		array(
			'label'               => 'Create Brevo Folder',
			'description'         => 'Create a Brevo contact-list folder.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'data'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['name'] ) ) {
					return array( 'success' => false, 'message' => 'name is required.' );
				}
				return mcp_brevo_api_request( 'POST', 'contacts/folders', array( 'name' => sanitize_text_field( (string) $input['name'] ) ) );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/update-folder',
		array(
			'label'               => 'Update Brevo Folder',
			'description'         => 'Update a Brevo contact-list folder.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'folderId', 'name' ),
				'properties'           => array(
					'folderId' => array( 'type' => 'integer' ),
					'name'     => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$folder_id = (int) ( $input['folderId'] ?? 0 );
				if ( $folder_id <= 0 || empty( $input['name'] ) ) {
					return array( 'success' => false, 'message' => 'folderId and name are required.' );
				}
				return mcp_brevo_api_request( 'PUT', 'contacts/folders/' . $folder_id, array( 'name' => sanitize_text_field( (string) $input['name'] ) ) );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/delete-folder',
		array(
			'label'               => 'Delete Brevo Folder',
			'description'         => 'Delete a Brevo contact-list folder.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'folderId' ),
				'properties'           => array(
					'folderId' => array( 'type' => 'integer' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$folder_id = (int) ( $input['folderId'] ?? 0 );
				if ( $folder_id <= 0 ) {
					return array( 'success' => false, 'message' => 'folderId is required.' );
				}
				return mcp_brevo_api_request( 'DELETE', 'contacts/folders/' . $folder_id );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// WORDPRESS FORMS - Full CRUD for official Brevo plugin forms.
	// =========================================================================
	wp_register_ability(
		'brevo/list-wordpress-forms',
		array(
			'label'               => 'List Brevo WordPress Forms',
			'description'         => 'List sign-up forms stored by the official Brevo WordPress plugin, including shortcodes.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'forms'   => array( 'type' => 'array' ),
					'count'   => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function (): array {
				$error = mcp_brevo_require_wordpress_forms();
				if ( null !== $error ) {
					return $error;
				}

				$forms = array_map( 'mcp_brevo_normalize_wordpress_form', SIB_Forms::getForms() );
				return array(
					'success' => true,
					'forms'   => $forms,
					'count'   => count( $forms ),
					'message' => 'Retrieved ' . count( $forms ) . ' Brevo WordPress form(s).',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/get-wordpress-form',
		array(
			'label'               => 'Get Brevo WordPress Form',
			'description'         => 'Get a Brevo WordPress sign-up form by ID, including shortcode and raw HTML/CSS.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Brevo WordPress form ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'form'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$error = mcp_brevo_require_wordpress_forms();
				if ( null !== $error ) {
					return $error;
				}

				$id = (int) ( $input['id'] ?? 0 );
				if ( $id <= 0 ) {
					return array( 'success' => false, 'message' => 'id is required.' );
				}

				$form = SIB_Forms::getForm( $id );
				if ( empty( $form ) ) {
					return array( 'success' => false, 'message' => 'Brevo WordPress form not found.' );
				}

				$output = mcp_brevo_normalize_wordpress_form( array_merge( $form, array( 'id' => $id ) ) );
				$output['html'] = (string) ( $form['html'] ?? '' );
				$output['css']  = (string) ( $form['css'] ?? '' );

				return array(
					'success' => true,
					'form'    => $output,
					'message' => 'Brevo WordPress form retrieved.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/create-wordpress-form',
		array(
			'label'               => 'Create Brevo WordPress Form',
			'description'         => 'Create a sign-up form in the official Brevo WordPress plugin and return the shortcode.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title', 'listIds' ),
				'properties'           => array(
					'title'           => array( 'type' => 'string' ),
					'listIds'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					'buttonLabel'     => array( 'type' => 'string' ),
					'includeName'     => array( 'type' => 'boolean' ),
					'html'            => array( 'type' => 'string' ),
					'css'             => array( 'type' => 'string' ),
					'attributes'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'isOptin'         => array( 'type' => 'boolean' ),
					'isDoubleOptin'   => array( 'type' => 'boolean' ),
					'redirectInForm'  => array( 'type' => 'string' ),
					'redirectInEmail' => array( 'type' => 'string' ),
					'successMsg'      => array( 'type' => 'string' ),
					'errorMsg'        => array( 'type' => 'string' ),
					'existMsg'        => array( 'type' => 'string' ),
					'invalidMsg'      => array( 'type' => 'string' ),
					'requiredMsg'     => array( 'type' => 'string' ),
					'termAccept'      => array( 'type' => 'boolean' ),
					'termsUrl'        => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'id'        => array( 'type' => 'integer' ),
					'shortcode' => array( 'type' => 'string' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$error = mcp_brevo_require_wordpress_forms();
				if ( null !== $error ) {
					return $error;
				}

				if ( empty( $input['title'] ) || empty( $input['listIds'] ) ) {
					return array( 'success' => false, 'message' => 'title and listIds are required.' );
				}

				$id = (int) SIB_Forms::addForm( mcp_brevo_build_wordpress_form_payload( $input ) );
				return array(
					'success'   => $id > 0,
					'id'        => $id,
					'shortcode' => '[sibwp_form id=' . $id . ']',
					'message'   => $id > 0 ? 'Brevo WordPress form created.' : 'Brevo WordPress form could not be created.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/update-wordpress-form',
		array(
			'label'               => 'Update Brevo WordPress Form',
			'description'         => 'Update a sign-up form stored by the official Brevo WordPress plugin.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'              => array( 'type' => 'integer' ),
					'title'           => array( 'type' => 'string' ),
					'listIds'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					'buttonLabel'     => array( 'type' => 'string' ),
					'includeName'     => array( 'type' => 'boolean' ),
					'html'            => array( 'type' => 'string' ),
					'css'             => array( 'type' => 'string' ),
					'attributes'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'isOptin'         => array( 'type' => 'boolean' ),
					'isDoubleOptin'   => array( 'type' => 'boolean' ),
					'redirectInForm'  => array( 'type' => 'string' ),
					'redirectInEmail' => array( 'type' => 'string' ),
					'successMsg'      => array( 'type' => 'string' ),
					'errorMsg'        => array( 'type' => 'string' ),
					'existMsg'        => array( 'type' => 'string' ),
					'invalidMsg'      => array( 'type' => 'string' ),
					'requiredMsg'     => array( 'type' => 'string' ),
					'termAccept'      => array( 'type' => 'boolean' ),
					'termsUrl'        => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'id'        => array( 'type' => 'integer' ),
					'shortcode' => array( 'type' => 'string' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$error = mcp_brevo_require_wordpress_forms();
				if ( null !== $error ) {
					return $error;
				}

				$id = (int) ( $input['id'] ?? 0 );
				if ( $id <= 0 ) {
					return array( 'success' => false, 'message' => 'id is required.' );
				}

				$existing = SIB_Forms::getForm( $id );
				if ( empty( $existing ) ) {
					return array( 'success' => false, 'message' => 'Brevo WordPress form not found.' );
				}

				SIB_Forms::updateForm( $id, mcp_brevo_build_wordpress_form_payload( $input, $existing ) );
				return array(
					'success'   => true,
					'id'        => $id,
					'shortcode' => '[sibwp_form id=' . $id . ']',
					'message'   => 'Brevo WordPress form updated.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/delete-wordpress-form',
		array(
			'label'               => 'Delete Brevo WordPress Form',
			'description'         => 'Delete a sign-up form stored by the official Brevo WordPress plugin.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array( 'type' => 'integer' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$error = mcp_brevo_require_wordpress_forms();
				if ( null !== $error ) {
					return $error;
				}

				$id = (int) ( $input['id'] ?? 0 );
				if ( $id <= 0 ) {
					return array( 'success' => false, 'message' => 'id is required.' );
				}

				SIB_Forms::deleteForm( $id );
				return array(
					'success' => true,
					'message' => 'Brevo WordPress form deleted.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/ensure-wordpress-form',
		array(
			'label'               => 'Ensure Brevo WordPress Form',
			'description'         => 'Create or update a Brevo WordPress sign-up form by title and return its shortcode.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title', 'listIds' ),
				'properties'           => array(
					'title'           => array( 'type' => 'string' ),
					'listIds'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					'buttonLabel'     => array( 'type' => 'string' ),
					'includeName'     => array( 'type' => 'boolean' ),
					'html'            => array( 'type' => 'string' ),
					'css'             => array( 'type' => 'string' ),
					'attributes'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'isOptin'         => array( 'type' => 'boolean' ),
					'isDoubleOptin'   => array( 'type' => 'boolean' ),
					'redirectInForm'  => array( 'type' => 'string' ),
					'redirectInEmail' => array( 'type' => 'string' ),
					'successMsg'      => array( 'type' => 'string' ),
					'errorMsg'        => array( 'type' => 'string' ),
					'existMsg'        => array( 'type' => 'string' ),
					'invalidMsg'      => array( 'type' => 'string' ),
					'requiredMsg'     => array( 'type' => 'string' ),
					'termAccept'      => array( 'type' => 'boolean' ),
					'termsUrl'        => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'id'        => array( 'type' => 'integer' ),
					'shortcode' => array( 'type' => 'string' ),
					'created'   => array( 'type' => 'boolean' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$error = mcp_brevo_require_wordpress_forms();
				if ( null !== $error ) {
					return $error;
				}

				$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
				if ( '' === $title || empty( $input['listIds'] ) ) {
					return array( 'success' => false, 'message' => 'title and listIds are required.' );
				}

				foreach ( SIB_Forms::getForms() as $form ) {
					if ( isset( $form['title'], $form['id'] ) && $title === (string) $form['title'] ) {
						$id       = (int) $form['id'];
						$existing = SIB_Forms::getForm( $id );
						SIB_Forms::updateForm( $id, mcp_brevo_build_wordpress_form_payload( $input, $existing ) );
						return array(
							'success'   => true,
							'id'        => $id,
							'shortcode' => '[sibwp_form id=' . $id . ']',
							'created'   => false,
							'message'   => 'Brevo WordPress form updated.',
						);
					}
				}

				$id = (int) SIB_Forms::addForm( mcp_brevo_build_wordpress_form_payload( $input ) );
				return array(
					'success'   => $id > 0,
					'id'        => $id,
					'shortcode' => '[sibwp_form id=' . $id . ']',
					'created'   => true,
					'message'   => $id > 0 ? 'Brevo WordPress form created.' : 'Brevo WordPress form could not be created.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/list-attributes',
		array(
			'label'               => 'List Contact Attributes',
			'description'         => 'List all Brevo contact attributes.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'attributes' => array( 'type' => 'array' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function (): array {
				return mcp_brevo_api_request( 'GET', 'contacts/attributes' );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/create-attribute',
		array(
			'label'               => 'Create Contact Attribute',
			'description'         => 'Create a Brevo contact attribute.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'category', 'name', 'type' ),
				'properties'           => array(
					'category' => array(
						'type'        => 'string',
						'description' => 'Attribute category (e.g., normal, transactional).',
					),
					'name'     => array(
						'type'        => 'string',
						'description' => 'Attribute name.',
					),
					'type'     => array(
						'type'        => 'string',
						'description' => 'Attribute type (text, date, boolean, float, id, category).',
					),
					'enum'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional enum values for category type.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$category = sanitize_text_field( $input['category'] ?? '' );
				$name     = sanitize_text_field( $input['name'] ?? '' );
				$type     = sanitize_text_field( $input['type'] ?? '' );

				if ( empty( $category ) || empty( $name ) || empty( $type ) ) {
					return array( 'success' => false, 'message' => 'category, name, and type are required.' );
				}

				$body = array( 'type' => $type );
				if ( ! empty( $input['enum'] ) && is_array( $input['enum'] ) ) {
					$body['enumeration'] = array_values( array_map( 'sanitize_text_field', $input['enum'] ) );
				}

				return mcp_brevo_api_request( 'POST', 'contacts/attributes/' . $category . '/' . $name, $body );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/update-attribute',
		array(
			'label'               => 'Update Contact Attribute',
			'description'         => 'Update a Brevo contact attribute.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'category', 'name', 'type' ),
				'properties'           => array(
					'category' => array(
						'type'        => 'string',
						'description' => 'Attribute category.',
					),
					'name'     => array(
						'type'        => 'string',
						'description' => 'Attribute name.',
					),
					'type'     => array(
						'type'        => 'string',
						'description' => 'Attribute type (text, date, boolean, float, id, category).',
					),
					'enum'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional enum values for category type.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$category = sanitize_text_field( $input['category'] ?? '' );
				$name     = sanitize_text_field( $input['name'] ?? '' );
				$type     = sanitize_text_field( $input['type'] ?? '' );

				if ( empty( $category ) || empty( $name ) || empty( $type ) ) {
					return array( 'success' => false, 'message' => 'category, name, and type are required.' );
				}

				$body = array( 'type' => $type );
				if ( ! empty( $input['enum'] ) && is_array( $input['enum'] ) ) {
					$body['enumeration'] = array_values( array_map( 'sanitize_text_field', $input['enum'] ) );
				}

				return mcp_brevo_api_request( 'PUT', 'contacts/attributes/' . $category . '/' . $name, $body );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/delete-attribute',
		array(
			'label'               => 'Delete Contact Attribute',
			'description'         => 'Delete a Brevo contact attribute.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'category', 'name' ),
				'properties'           => array(
					'category' => array(
						'type'        => 'string',
						'description' => 'Attribute category.',
					),
					'name'     => array(
						'type'        => 'string',
						'description' => 'Attribute name.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$category = sanitize_text_field( $input['category'] ?? '' );
				$name     = sanitize_text_field( $input['name'] ?? '' );

				if ( empty( $category ) || empty( $name ) ) {
					return array( 'success' => false, 'message' => 'category and name are required.' );
				}

				return mcp_brevo_api_request( 'DELETE', 'contacts/attributes/' . $category . '/' . $name );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	// =========================================================================
	// WEBHOOKS - Marketing and transactional webhooks.
	// =========================================================================
	wp_register_ability(
		'brevo/list-webhooks',
		array(
			'label'               => 'List Brevo Webhooks',
			'description'         => 'List Brevo webhooks. Use type to filter transactional or marketing webhooks when needed.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type' => array(
						'type'        => 'string',
						'description' => 'Optional webhook type, for example transactional or marketing.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'webhooks' => array( 'type' => 'array' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$endpoint = 'webhooks';
				if ( ! empty( $input['type'] ) ) {
					$endpoint .= '?type=' . rawurlencode( sanitize_text_field( (string) $input['type'] ) );
				}
				$result = mcp_brevo_api_request( 'GET', $endpoint );
				if ( ! $result['success'] ) {
					return $result;
				}
				return array(
					'success'  => true,
					'webhooks' => $result['data']['webhooks'] ?? $result['data'] ?? array(),
					'message'  => 'Brevo webhooks retrieved.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/get-webhook',
		array(
			'label'               => 'Get Brevo Webhook',
			'description'         => 'Get a Brevo webhook by ID.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array( 'type' => 'integer' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'data'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$id = (int) ( $input['id'] ?? 0 );
				if ( $id <= 0 ) {
					return array( 'success' => false, 'message' => 'id is required.' );
				}
				return mcp_brevo_api_request( 'GET', 'webhooks/' . $id );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/create-webhook',
		array(
			'label'               => 'Create Brevo Webhook',
			'description'         => 'Create a Brevo webhook. Body is passed through to the Brevo API after basic URL validation.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'url', 'events' ),
				'properties'           => array(
					'url'         => array( 'type' => 'string' ),
					'events'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'description' => array( 'type' => 'string' ),
					'type'        => array( 'type' => 'string' ),
					'body'        => array( 'type' => 'object' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'data'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$url = esc_url_raw( (string) ( $input['url'] ?? '' ) );
				if ( '' === $url || empty( $input['events'] ) ) {
					return array( 'success' => false, 'message' => 'url and events are required.' );
				}
				$body = (array) ( $input['body'] ?? array() );
				$body['url'] = $url;
				$body['events'] = array_values( array_map( 'sanitize_text_field', (array) $input['events'] ) );
				if ( isset( $input['description'] ) ) {
					$body['description'] = sanitize_text_field( (string) $input['description'] );
				}
				if ( isset( $input['type'] ) ) {
					$body['type'] = sanitize_text_field( (string) $input['type'] );
				}
				return mcp_brevo_api_request( 'POST', 'webhooks', $body );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/update-webhook',
		array(
			'label'               => 'Update Brevo Webhook',
			'description'         => 'Update a Brevo webhook. Body is passed through to the Brevo API after basic validation.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array( 'type' => 'integer' ),
					'url'         => array( 'type' => 'string' ),
					'events'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'description' => array( 'type' => 'string' ),
					'type'        => array( 'type' => 'string' ),
					'body'        => array( 'type' => 'object' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'data'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$id = (int) ( $input['id'] ?? 0 );
				if ( $id <= 0 ) {
					return array( 'success' => false, 'message' => 'id is required.' );
				}
				$body = (array) ( $input['body'] ?? array() );
				if ( isset( $input['url'] ) ) {
					$body['url'] = esc_url_raw( (string) $input['url'] );
				}
				if ( isset( $input['events'] ) ) {
					$body['events'] = array_values( array_map( 'sanitize_text_field', (array) $input['events'] ) );
				}
				if ( isset( $input['description'] ) ) {
					$body['description'] = sanitize_text_field( (string) $input['description'] );
				}
				if ( isset( $input['type'] ) ) {
					$body['type'] = sanitize_text_field( (string) $input['type'] );
				}
				if ( empty( $body ) ) {
					return array( 'success' => false, 'message' => 'No webhook fields provided.' );
				}
				return mcp_brevo_api_request( 'PUT', 'webhooks/' . $id, $body );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/delete-webhook',
		array(
			'label'               => 'Delete Brevo Webhook',
			'description'         => 'Delete a Brevo webhook by ID.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array( 'type' => 'integer' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$id = (int) ( $input['id'] ?? 0 );
				if ( $id <= 0 ) {
					return array( 'success' => false, 'message' => 'id is required.' );
				}
				return mcp_brevo_api_request( 'DELETE', 'webhooks/' . $id );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'brevo/list-senders',
		array(
			'label'               => 'List Senders',
			'description'         => 'List Brevo email senders.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'senders' => array( 'type' => 'array' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function (): array {
				return mcp_brevo_api_request( 'GET', 'senders' );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/list-sender-domains',
		array(
			'label'               => 'List Sender Domains',
			'description'         => 'List Brevo sender domains and their authentication status.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'data'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function (): array {
				return mcp_brevo_api_request( 'GET', 'senders/domains' );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/create-sender',
		array(
			'label'               => 'Create Sender',
			'description'         => 'Create a Brevo email sender identity.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'description' => 'Sender display name.',
					),
					'email' => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => 'Sender email address.',
					),
				),
				'required'             => array( 'name', 'email' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'data'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$name  = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
				$email = sanitize_email( (string) ( $input['email'] ?? '' ) );

				if ( '' === $name || ! is_email( $email ) ) {
					return array(
						'success' => false,
						'message' => 'A valid sender name and email are required.',
					);
				}

				return mcp_brevo_api_request(
					'POST',
					'senders',
					array(
						'name'  => $name,
						'email' => $email,
					)
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/list-templates',
		array(
			'label'               => 'List SMTP Templates',
			'description'         => 'List transactional email templates.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit'  => array( 'type' => 'integer', 'default' => 20 ),
					'offset' => array( 'type' => 'integer', 'default' => 0 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'templates' => array( 'type' => 'array' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$limit  = max( 1, min( 100, (int) ( $input['limit'] ?? 20 ) ) );
				$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

				return mcp_brevo_api_request( 'GET', 'smtp/templates?limit=' . $limit . '&offset=' . $offset );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	wp_register_ability(
		'brevo/get-template',
		array(
			'label'               => 'Get SMTP Template',
			'description'         => 'Get a transactional email template by ID.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'template_id' ),
				'properties'           => array(
					'template_id' => array(
						'type'        => 'integer',
						'description' => 'Template ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'template' => array( 'type' => 'object' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$template_id = (int) ( $input['template_id'] ?? 0 );
				if ( $template_id <= 0 ) {
					return array( 'success' => false, 'message' => 'template_id is required.' );
				}

				return mcp_brevo_api_request( 'GET', 'smtp/templates/' . $template_id );
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
		)
	);

	// =========================================================================
	// LISTS - Add Contacts to List
	// =========================================================================
	wp_register_ability(
		'brevo/add-to-list',
		array(
			'label'               => 'Add Contacts to List',
			'description'         => 'Add contacts to a Brevo list.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'listId', 'emails' ),
				'properties'           => array(
					'listId' => array(
						'type'        => 'integer',
						'description' => 'ID of the list.',
					),
					'emails' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Array of email addresses to add.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['listId'] ) || empty( $input['emails'] ) ) {
					return array( 'success' => false, 'message' => 'listId and emails are required.' );
				}

				$emails = array_map( 'sanitize_email', (array) $input['emails'] );
				$emails = array_filter( $emails );

				if ( empty( $emails ) ) {
					return array( 'success' => false, 'message' => 'No valid email addresses provided.' );
				}

				$body = array( 'emails' => array_values( $emails ) );

				$result = mcp_brevo_api_request(
					'POST',
					'contacts/lists/' . (int) $input['listId'] . '/contacts/add',
					$body
				);

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success' => true,
					'message' => 'Added ' . count( $emails ) . ' contact(s) to list.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// LISTS - Remove Contacts from List
	// =========================================================================
	wp_register_ability(
		'brevo/remove-from-list',
		array(
			'label'               => 'Remove Contacts from List',
			'description'         => 'Remove contacts from a Brevo list.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'listId', 'emails' ),
				'properties'           => array(
					'listId' => array(
						'type'        => 'integer',
						'description' => 'ID of the list.',
					),
					'emails' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Array of email addresses to remove.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['listId'] ) || empty( $input['emails'] ) ) {
					return array( 'success' => false, 'message' => 'listId and emails are required.' );
				}

				$emails = array_map( 'sanitize_email', (array) $input['emails'] );
				$emails = array_filter( $emails );

				if ( empty( $emails ) ) {
					return array( 'success' => false, 'message' => 'No valid email addresses provided.' );
				}

				$body = array( 'emails' => array_values( $emails ) );

				$result = mcp_brevo_api_request(
					'POST',
					'contacts/lists/' . (int) $input['listId'] . '/contacts/remove',
					$body
				);

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success' => true,
					'message' => 'Removed ' . count( $emails ) . ' contact(s) from list.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// EMAIL - Send Transactional Email
	// =========================================================================
	wp_register_ability(
		'brevo/send-email',
		array(
			'label'               => 'Send Transactional Email',
			'description'         => 'Send a transactional email via Brevo.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'to', 'subject' ),
				'properties'           => array(
					'to'          => array(
						'type'        => 'array',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'email' => array( 'type' => 'string' ),
								'name'  => array( 'type' => 'string' ),
							),
						),
						'description' => 'Array of recipients with email and optional name.',
					),
					'subject'     => array(
						'type'        => 'string',
						'description' => 'Email subject.',
					),
					'htmlContent' => array(
						'type'        => 'string',
						'description' => 'HTML content of the email.',
					),
					'textContent' => array(
						'type'        => 'string',
						'description' => 'Plain text content of the email.',
					),
					'sender'      => array(
						'type'        => 'object',
						'properties'  => array(
							'email' => array( 'type' => 'string' ),
							'name'  => array( 'type' => 'string' ),
						),
						'description' => 'Sender email and name (must be verified in Brevo).',
					),
					'replyTo'     => array(
						'type'        => 'object',
						'properties'  => array(
							'email' => array( 'type' => 'string' ),
							'name'  => array( 'type' => 'string' ),
						),
						'description' => 'Reply-to email and name.',
					),
					'templateId'  => array(
						'type'        => 'integer',
						'description' => 'ID of a Brevo template to use.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'messageId' => array( 'type' => 'string' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['to'] ) || empty( $input['subject'] ) ) {
					return array( 'success' => false, 'message' => 'to and subject are required.' );
				}

				$body = array(
					'to'      => $input['to'],
					'subject' => sanitize_text_field( $input['subject'] ),
				);

				// Content - either htmlContent, textContent, or templateId.
				if ( ! empty( $input['htmlContent'] ) ) {
					$body['htmlContent'] = $input['htmlContent'];
				}
				if ( ! empty( $input['textContent'] ) ) {
					$body['textContent'] = sanitize_textarea_field( $input['textContent'] );
				}
				if ( ! empty( $input['templateId'] ) ) {
					$body['templateId'] = (int) $input['templateId'];
				}

				// Sender - use provided or get from options.
				if ( ! empty( $input['sender'] ) ) {
					$body['sender'] = $input['sender'];
				} else {
					$home_option = get_option( 'sib_home_option', array() );
					if ( ! empty( $home_option['from_email'] ) ) {
						$body['sender'] = array(
							'email' => $home_option['from_email'],
							'name'  => $home_option['from_name'] ?? '',
						);
					}
				}

				if ( ! empty( $input['replyTo'] ) ) {
					$body['replyTo'] = $input['replyTo'];
				}

				$result = mcp_brevo_api_request( 'POST', 'smtp/email', $body );

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success'   => true,
					'messageId' => $result['data']['messageId'] ?? '',
					'message'   => 'Email sent successfully.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// EMAIL - List Campaigns
	// =========================================================================
	wp_register_ability(
		'brevo/list-campaigns',
		array(
			'label'               => 'List Email Campaigns',
			'description'         => 'Get all email campaigns from Brevo.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type'   => array(
						'type'        => 'string',
						'enum'        => array( 'classic', 'trigger' ),
						'default'     => 'classic',
						'description' => 'Campaign type.',
					),
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'suspended', 'archive', 'sent', 'queued', 'draft', 'inProcess' ),
						'description' => 'Filter by campaign status.',
					),
					'limit'  => array(
						'type'        => 'integer',
						'default'     => 50,
						'description' => 'Number of campaigns to return.',
					),
					'offset' => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Pagination offset.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'campaigns' => array( 'type' => 'array' ),
					'count'     => array( 'type' => 'integer' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$type   = isset( $input['type'] ) ? sanitize_text_field( $input['type'] ) : 'classic';
				$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
				$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;

				$query = 'emailCampaigns?type=' . $type . '&limit=' . $limit . '&offset=' . $offset;

				if ( ! empty( $input['status'] ) ) {
					$query .= '&status=' . sanitize_text_field( $input['status'] );
				}

				$result = mcp_brevo_api_request( 'GET', $query );

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success'   => true,
					'campaigns' => $result['data']['campaigns'] ?? array(),
					'count'     => $result['data']['count'] ?? 0,
					'message'   => 'Retrieved ' . count( $result['data']['campaigns'] ?? array() ) . ' campaigns.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// EMAIL - Send Campaign Now
	// =========================================================================
	wp_register_ability(
		'brevo/send-campaign',
		array(
			'label'               => 'Send Email Campaign',
			'description'         => 'Send an email campaign immediately.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'campaignId' ),
				'properties'           => array(
					'campaignId' => array(
						'type'        => 'integer',
						'description' => 'ID of the campaign to send.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['campaignId'] ) ) {
					return array( 'success' => false, 'message' => 'campaignId is required.' );
				}

				$result = mcp_brevo_api_request(
					'POST',
					'emailCampaigns/' . (int) $input['campaignId'] . '/sendNow',
					array()
				);

				if ( ! $result['success'] ) {
					return $result;
				}

				return array(
					'success' => true,
					'message' => 'Campaign sent successfully.',
				);
			},
			'permission_callback' => 'mcp_brevo_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);
}
add_filter( 'wp_register_ability_args', 'mcp_brevo_add_default_annotations', 10, 2 );
add_action( 'wp_abilities_api_init', 'mcp_register_brevo_abilities' );
