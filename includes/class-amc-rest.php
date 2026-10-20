<?php
/**
 * Small REST helper used by the admin "Test connection" button — lets the
 * browser verify a freshly created token works without needing a real
 * MCP client installed.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_Rest
 */
final class AMC_Rest {

	private const NAMESPACE = 'wp-mcp-connect/v1';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/test-token',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'test_token' ),
				'args'                => array(
					'token' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Whether TLS should be verified for the self-test call. Verification is
	 * skipped ONLY when the target host is this same site (a pure loopback
	 * call, e.g. to a local self-signed dev cert) — never for anything that
	 * would actually leave the server.
	 */
	private function should_verify_ssl_for_self_test(): bool {
		$target_host = wp_parse_url( AMC_Connect_Config::server_url(), PHP_URL_HOST );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );

		return $target_host !== $site_host;
	}

	public function test_token( WP_REST_Request $request ): WP_REST_Response {
		$plain_token = (string) $request->get_param( 'token' );
		$row         = AMC_Token_Manager::instance()->validate_token( $plain_token );

		if ( ! $row || (int) $row['user_id'] !== get_current_user_id() ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Token not found or does not belong to you.', 'mcp-env-wp' ),
				),
				404
			);
		}

		// This is a loopback call to this same site (never a third party), so
		// it's safe to tolerate a self-signed/local cert here even though we
		// wouldn't for an outbound request to anywhere else.
		$sslverify = $this->should_verify_ssl_for_self_test();

		$response = wp_remote_post(
			AMC_Connect_Config::server_url(),
			array(
				'headers'   => array(
					'Authorization' => 'Bearer ' . $plain_token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json, text/event-stream',
				),
				'body'      => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'initialize',
						'params'  => array(
							'protocolVersion' => '2024-11-05',
							'capabilities'    => new stdClass(),
							'clientInfo'      => array(
								'name'    => 'agency-mcp-connect-test',
								'version' => AMC_VERSION,
							),
						),
					)
				),
				'timeout'   => 15,
				'sslverify' => $sslverify,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $response->get_error_message(),
				),
				502
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$session = wp_remote_retrieve_header( $response, 'mcp-session-id' );

		if ( $code < 200 || $code >= 300 || ! $session ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => sprintf(
						/* translators: %d: HTTP status code */
						__( 'Server responded with HTTP %d.', 'mcp-env-wp' ),
						$code
					),
				),
				200
			);
		}

		$tools_response = wp_remote_post(
			AMC_Connect_Config::server_url(),
			array(
				'headers' => array(
					'Authorization'  => 'Bearer ' . $plain_token,
					'Content-Type'   => 'application/json',
					'Accept'         => 'application/json, text/event-stream',
					'Mcp-Session-Id' => $session,
				),
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 2,
						'method'  => 'tools/list',
					)
				),
				'timeout'   => 15,
				'sslverify' => $sslverify,
			)
		);

		$tool_count = 0;
		if ( ! is_wp_error( $tools_response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $tools_response ), true );
			$tool_count = is_array( $body['result']['tools'] ?? null ) ? count( $body['result']['tools'] ) : 0;
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %d: number of tools */
					_n( 'Connected! %d tool available.', 'Connected! %d tools available.', $tool_count, 'mcp-env-wp' ),
					$tool_count
				),
			)
		);
	}
}
