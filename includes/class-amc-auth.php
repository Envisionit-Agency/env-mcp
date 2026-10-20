<?php
/**
 * Bearer-token authentication for the Agency MCP server route.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_Auth
 */
final class AMC_Auth {

	private static ?self $instance = null;

	/**
	 * Scope of the token used to authenticate the current request, or null
	 * if the request wasn't authenticated via an Agency MCP token.
	 *
	 * @var string[]|null
	 */
	private static ?array $current_scope = null;

	private static ?int $current_token_id = null;

	private static string $current_scope_mode = 'explicit';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function hooks(): void {
		add_filter( 'determine_current_user', array( $this, 'authenticate' ), 20 );
		add_action( 'rest_api_init', array( $this, 'maybe_touch_token' ), 100 );
		add_filter( 'rest_post_dispatch', array( $this, 'add_auth_challenge' ), 10, 3 );
	}

	/**
	 * @param int|false $user_id
	 * @return int|false
	 */
	public function authenticate( $user_id ) {
		static $resolving = false;
		if ( $resolving || ! empty( $user_id ) ) {
			return $user_id;
		}

		if ( ! $this->is_agency_mcp_request() ) {
			return $user_id;
		}

		$header = $this->get_authorization_header();
		if ( '' === $header || ! str_starts_with( $header, 'Bearer ' ) ) {
			return $user_id;
		}

		$token = trim( substr( $header, 7 ) );
		if ( '' === $token ) {
			return $user_id;
		}

		$resolving = true;
		$row       = AMC_Token_Manager::instance()->validate_token( $token );
		$resolving = false;

		if ( ! $row ) {
			return $user_id;
		}

		// Note: we deliberately do NOT sanitize/intersect against currently
		// available abilities here — determine_current_user can fire before
		// the `init` hook completes, when the Abilities Registry isn't ready
		// yet, which would wipe out the whole scope. Availability narrowing
		// happens later in AMC_Server, once abilities are guaranteed loaded.
		self::$current_scope      = $row['scope'];
		self::$current_token_id   = (int) $row['id'];
		self::$current_scope_mode = $row['scope_mode'] ?? 'explicit';

		return (int) $row['user_id'];
	}

	/**
	 * Touches (updates last_used_at for) the token once we know the request
	 * actually authenticated successfully via it.
	 */
	/**
	 * An unauthenticated MCP request gets a 401 with a WWW-Authenticate header pointing at the OAuth metadata (MCP
	 * authorization spec), so clients such as claude.ai custom connectors discover the sign-in on their own.
	 */
	public function add_auth_challenge( $response, $server, $request ) {
		if ( $response instanceof WP_REST_Response && 401 === $response->get_status() && str_starts_with( (string) $request->get_route(), '/' . AMC_Server::ROUTE_NAMESPACE . '/' ) ) {
			$response->header( 'WWW-Authenticate', sprintf( 'Bearer resource_metadata="%s"', home_url( '/.well-known/oauth-protected-resource' ) ) );
		}
		return $response;
	}

	public function maybe_touch_token(): void {
		if ( null !== self::$current_token_id ) {
			AMC_Token_Manager::instance()->touch_token( self::$current_token_id );
		}
	}

	/**
	 * @return string[]|null Null means "not an Agency MCP token request" (unrestricted).
	 */
	public static function get_current_scope(): ?array {
		return self::$current_scope;
	}

	public static function get_current_token_id(): ?int {
		return self::$current_token_id;
	}

	public static function get_current_scope_mode(): string {
		return self::$current_scope_mode;
	}

	private function is_agency_mcp_request(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' . AMC_Server::ROUTE_NAMESPACE . '/' );
	}

	private function get_authorization_header(): string {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $key => $value ) {
				if ( 'authorization' === strtolower( $key ) ) {
					return sanitize_text_field( $value );
				}
			}
		}

		return '';
	}
}
