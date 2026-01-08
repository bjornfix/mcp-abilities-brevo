<?php
/**
 * Plugin Name: MCP Abilities - Brevo
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-brevo
 * Description: Brevo (Sendinblue) abilities for MCP. Manage contacts, lists, and send emails via Brevo API.
 * Version: 1.0.0
 * Author: Devenia
 * Author URI: https://devenia.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Requires Plugins: abilities-api
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
	$body_raw    = wp_remote_retrieve_body( $response );
	$body_data   = json_decode( $body_raw, true );

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
		'message' => 'API error (' . $status_code . '): ' . $error_message,
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
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
