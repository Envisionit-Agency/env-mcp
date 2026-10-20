<?php
/**
 * OAuth 2.1 authorization server for the Agency MCP connection — lets
 * claude.ai's (or any) web-based MCP connector register itself and get a
 * user-authorized, scoped access token via the standard authorization-code
 * + PKCE flow, instead of requiring a manually-pasted Bearer token.
 *
 * Design choice: the access token this issues IS a normal row in the same
 * amc_tokens table that manually-created connections use — so every bit of
 * scope enforcement, activity logging, and revocation UI built for the
 * manual flow applies here unchanged. OAuth is just another way to *mint*
 * one of those tokens, not a parallel permission system.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_OAuth_Server
 */
final class AMC_OAuth_Server {

	private const REST_NAMESPACE   = 'wp-mcp-connect/v1';
	private const AUTH_CODE_TTL    = 120; // seconds
	private const REFRESH_TOKEN_TTL_DAYS = 90;

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
		add_action( 'init', array( $this, 'maybe_serve_discovery_document' ), 1 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_post_amc_oauth_authorize', array( $this, 'handle_authorize' ) );
		add_action( 'admin_post_nopriv_amc_oauth_authorize', array( $this, 'handle_authorize_nopriv' ) );
		add_action( 'admin_post_amc_oauth_consent', array( $this, 'handle_consent' ) );
	}

	// -----------------------------------------------------------------
	// Discovery — RFC 8414, served at the well-known site-root paths
	// (not under /wp-json/) since that's where clients look for them.
	// -----------------------------------------------------------------

	public function maybe_serve_discovery_document(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( '/.well-known/oauth-authorization-server' !== $path && '/.well-known/oauth-protected-resource' !== $path ) {
			return;
		}

		$metadata = array(
			'issuer'                                => home_url( '/' ),
			'authorization_endpoint'                 => admin_url( 'admin-post.php?action=amc_oauth_authorize' ),
			'token_endpoint'                          => rest_url( self::REST_NAMESPACE . '/oauth/token' ),
			'registration_endpoint'                   => rest_url( self::REST_NAMESPACE . '/oauth/register' ),
			'resource'                                => rest_url( AMC_Server::ROUTE_NAMESPACE . '/' . AMC_Server::ROUTE ),
			'response_types_supported'                => array( 'code' ),
			'grant_types_supported'                   => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'        => array( 'S256' ),
			'token_endpoint_auth_methods_supported'   => array( 'none' ),
			'scopes_supported'                        => array( 'read', 'read_write', 'full' ),
		);

		header( 'Content-Type: application/json' );
		echo wp_json_encode( $metadata );
		exit;
	}

	// -----------------------------------------------------------------
	// REST: dynamic client registration + token endpoint
	// -----------------------------------------------------------------

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'rest_register_client' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'rest_token' ),
			)
		);
	}

	public function rest_register_client( WP_REST_Request $request ) {
		$params        = $request->get_json_params() ?: array();
		$redirect_uris = isset( $params['redirect_uris'] ) && is_array( $params['redirect_uris'] ) ? $params['redirect_uris'] : array();
		$client_name   = isset( $params['client_name'] ) ? (string) $params['client_name'] : '';

		$result = AMC_OAuth_Clients::instance()->register( $client_name, $redirect_uris );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_code(), 'error_description' => $result->get_error_message() ), $result->get_error_data()['status'] ?? 400 );
		}

		return new WP_REST_Response(
			array(
				'client_id'                  => $result['client_id'],
				'client_name'                => $client_name,
				'redirect_uris'              => $redirect_uris,
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
			),
			201
		);
	}

	public function rest_token( WP_REST_Request $request ) {
		$params     = $request->get_body_params();
		if ( empty( $params ) ) {
			// Some clients send JSON instead of form-encoded.
			$params = $request->get_json_params() ?: array();
		}

		$grant_type = $params['grant_type'] ?? '';

		if ( 'authorization_code' === $grant_type ) {
			return $this->exchange_authorization_code( $params );
		}

		if ( 'refresh_token' === $grant_type ) {
			return $this->exchange_refresh_token( $params );
		}

		return new WP_REST_Response( array( 'error' => 'unsupported_grant_type' ), 400 );
	}

	private function exchange_authorization_code( array $params ) {
		$code          = (string) ( $params['code'] ?? '' );
		$client_id     = (string) ( $params['client_id'] ?? '' );
		$redirect_uri  = (string) ( $params['redirect_uri'] ?? '' );
		$code_verifier = (string) ( $params['code_verifier'] ?? '' );

		$data = get_transient( 'amc_authcode_' . $code );
		if ( ! $data ) {
			return new WP_REST_Response( array( 'error' => 'invalid_grant', 'error_description' => 'Unknown or expired code.' ), 400 );
		}
		delete_transient( 'amc_authcode_' . $code ); // Single use.

		if ( $data['client_id'] !== $client_id || $data['redirect_uri'] !== $redirect_uri ) {
			return new WP_REST_Response( array( 'error' => 'invalid_grant', 'error_description' => 'Client/redirect mismatch.' ), 400 );
		}

		$expected_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( $data['code_challenge'], $expected_challenge ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.' ), 400 );
		}

		return $this->issue_token_pair( (int) $data['user_id'], $client_id, $data['scope'], $data['scope_mode'], $data['client_name'] );
	}

	private function exchange_refresh_token( array $params ) {
		$refresh_token = (string) ( $params['refresh_token'] ?? '' );
		$client_id     = (string) ( $params['client_id'] ?? '' );

		$row = AMC_OAuth_Refresh_Tokens::instance()->validate_and_consume( $refresh_token, $client_id );
		if ( ! $row ) {
			return new WP_REST_Response( array( 'error' => 'invalid_grant', 'error_description' => 'Unknown, expired, or already-used refresh token.' ), 400 );
		}

		// Revoke the access token this refresh token was paired with — the
		// new pair below replaces it (standard refresh-token rotation).
		if ( ! empty( $row['access_token_id'] ) ) {
			AMC_Token_Manager::instance()->revoke_token( (int) $row['access_token_id'], (int) $row['user_id'], true );
		}

		return $this->issue_token_pair( (int) $row['user_id'], $client_id, $row['scope'], $row['scope_mode'], $row['client_name'] );
	}

	private function issue_token_pair( int $user_id, string $client_id, array $scope, string $scope_mode, string $client_name ) {
		$name   = sprintf( /* translators: %s: OAuth client name */ __( '%s (OAuth)', 'mcp-env-wp' ), $client_name ?: $client_id );
		$access = AMC_Token_Manager::instance()->create_token( $user_id, $name, $scope, 0, $scope_mode );

		if ( is_wp_error( $access ) ) {
			return new WP_REST_Response( array( 'error' => 'server_error', 'error_description' => $access->get_error_message() ), 500 );
		}

		$refresh_token = AMC_OAuth_Refresh_Tokens::instance()->create(
			$user_id,
			$client_id,
			$client_name,
			(int) $access['id'],
			$scope,
			$scope_mode,
			self::REFRESH_TOKEN_TTL_DAYS
		);

		return new WP_REST_Response(
			array(
				'access_token'  => $access['plain_token'],
				'token_type'    => 'Bearer',
				'refresh_token' => $refresh_token,
				'scope'         => in_array( $scope_mode, AMC_Token_Manager::LIVE_SCOPE_MODES, true ) ? $scope_mode : 'custom',
			)
		);
	}

	// -----------------------------------------------------------------
	// Authorization endpoint + consent screen (browser-facing, cookie auth)
	// -----------------------------------------------------------------

	public function handle_authorize_nopriv(): void {
		$redirect_back = admin_url( 'admin-post.php?' . $_SERVER['QUERY_STRING'] );
		wp_safe_redirect( wp_login_url( $redirect_back ) );
		exit;
	}

	public function handle_authorize(): void {
		$client_id            = sanitize_text_field( wp_unslash( $_GET['client_id'] ?? '' ) );
		$redirect_uri         = esc_url_raw( wp_unslash( $_GET['redirect_uri'] ?? '' ) );
		$state                = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$code_challenge       = sanitize_text_field( wp_unslash( $_GET['code_challenge'] ?? '' ) );
		$code_challenge_method = sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ?? '' ) );

		$client = AMC_OAuth_Clients::instance()->get( $client_id );

		if ( ! $client || ! AMC_OAuth_Clients::instance()->redirect_uri_is_registered( $client, $redirect_uri ) ) {
			wp_die( esc_html__( 'Unknown client or unregistered redirect URI.', 'mcp-env-wp' ), 400 );
		}

		if ( 'S256' !== $code_challenge_method || '' === $code_challenge ) {
			wp_die( esc_html__( 'This authorization request is missing required PKCE parameters (code_challenge_method=S256).', 'mcp-env-wp' ), 400 );
		}

		$abilities = AMC_Scopes::get_available_abilities();

		require AMC_PLUGIN_DIR . 'templates/oauth-consent.php';
		exit;
	}

	public function handle_consent(): void {
		check_admin_referer( 'amc_oauth_consent' );

		if ( isset( $_POST['amc_deny'] ) ) {
			$redirect_uri = esc_url_raw( wp_unslash( $_POST['redirect_uri'] ?? '' ) );
			$state        = sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) );
			// wp_redirect(), not wp_safe_redirect() — $redirect_uri is legitimately
			// an external host (the OAuth client's callback URL), which is exactly
			// why it was validated against the client's registered redirect_uris
			// above rather than relying on wp_safe_redirect()'s same-host check.
			wp_redirect( add_query_arg( array( 'error' => 'access_denied', 'state' => $state ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		$client_id             = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		$client_name           = sanitize_text_field( wp_unslash( $_POST['client_name'] ?? '' ) );
		$redirect_uri          = esc_url_raw( wp_unslash( $_POST['redirect_uri'] ?? '' ) );
		$state                 = sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) );
		$code_challenge        = sanitize_text_field( wp_unslash( $_POST['code_challenge'] ?? '' ) );
		$scope_mode            = sanitize_key( wp_unslash( $_POST['amc_scope_mode'] ?? 'read' ) );

		if ( ! in_array( $scope_mode, AMC_Token_Manager::LIVE_SCOPE_MODES, true ) ) {
			$scope_mode = 'read';
		}

		$client = AMC_OAuth_Clients::instance()->get( $client_id );
		if ( ! $client || ! AMC_OAuth_Clients::instance()->redirect_uri_is_registered( $client, $redirect_uri ) ) {
			wp_die( esc_html__( 'Unknown client or unregistered redirect URI.', 'mcp-env-wp' ), 400 );
		}

		$scope = AMC_Scopes::expand_preset( $scope_mode );

		$code = wp_generate_password( 48, false, false );
		set_transient(
			'amc_authcode_' . $code,
			array(
				'client_id'      => $client_id,
				'client_name'    => $client_name,
				'user_id'        => get_current_user_id(),
				'redirect_uri'   => $redirect_uri,
				'code_challenge' => $code_challenge,
				'scope'          => $scope,
				'scope_mode'     => $scope_mode,
			),
			self::AUTH_CODE_TTL
		);

		// See the comment on the "access_denied" redirect above — external
		// redirect_uri by design, already validated against this client's
		// registered list.
		wp_redirect( add_query_arg( array( 'code' => $code, 'state' => $state ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}
}
