<?php
/**
 * Plugin Name: MCP Abilities - Brevo
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-brevo
 * Description: Brevo (Sendinblue) abilities for MCP. Manage contacts, lists, and send emails via Brevo API.
 * Version: 1.0.2
 * Author: Devenia
 * Author URI: https://devenia.com
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

/**
 * Check if Abilities API is available.
 */
function mcp_brevo_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>MCP Abilities - Brevo</strong> requires the <a href="https://github.com/WordPress/abilities-api">Abilities API</a> plugin to be installed and activated.</p></div>';
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

	if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT' ), true ) ) {
		$args['body'] = wp_json_encode( $body );
	}

	$response = wp_remote_request( 'https://api.brevo.com/v3/' . $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		return array(
			'success' => false,
			'message' => 'API request failed: ' . $response->get_error_message(),
		);
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
 * Register Brevo abilities.
 */
function mcp_register_brevo_abilities(): void {
	if ( ! mcp_brevo_check_dependencies() ) {
		return;
	}

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
				$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
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
add_action( 'wp_abilities_api_init', 'mcp_register_brevo_abilities' );
