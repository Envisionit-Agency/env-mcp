<?php
/**
 * Registers the Agency MCP server and enforces per-token scoping on it.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Domain\Utils\McpNameSanitizer;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

/**
 * Class AMC_Server
 */
final class AMC_Server {

	public const SERVER_ID         = 'wp-mcp';
	public const ROUTE_NAMESPACE   = 'wp-mcp';
	public const ROUTE             = 'v1';

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
		add_action( 'mcp_adapter_init', array( $this, 'register_server' ) );
		add_filter( 'mcp_adapter_tools_list', array( $this, 'filter_tools_list' ), 10, 2 );
		add_filter( 'mcp_adapter_pre_tool_call', array( $this, 'enforce_scope_on_call' ), 10, 4 );
		add_filter( 'mcp_adapter_tool_call_result', array( $this, 'log_tool_call' ), 10, 4 );
	}

	/**
	 * Every request re-registers the server with all currently-public tool
	 * abilities; per-request narrowing to the authenticated token's scope
	 * happens in filter_tools_list()/enforce_scope_on_call() below.
	 */
	public function register_server( McpAdapter $adapter ): void {
		$tools   = wp_list_pluck( AMC_Scopes::get_available_abilities(), 'name' );
		$prompts = wp_list_pluck( AMC_Scopes::get_available_prompts(), 'name' );

		$adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			__( 'Agency MCP', 'mcp-env-wp' ),
			__( 'Scoped WordPress access for agency team members — each connection only sees the abilities its token was granted.', 'mcp-env-wp' ),
			AMC_VERSION,
			array( HttpTransport::class ),
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			$tools,
			array(),
			$prompts
		);
	}

	/**
	 * @param array<int,\WP\McpSchema\Server\Tools\DTO\Tool> $tools
	 * @param \WP\MCP\Core\McpServer                          $server
	 * @return array<int,\WP\McpSchema\Server\Tools\DTO\Tool>
	 */
	public function filter_tools_list( array $tools, $server ): array {
		if ( self::SERVER_ID !== $server->get_server_id() ) {
			return $tools;
		}

		$scope = AMC_Auth::get_current_scope();

		// Null scope means the request wasn't authenticated via an Agency MCP
		// token (e.g. an admin browsing while logged into wp-admin) — in that
		// case fall back to "everything the site has made public", same as
		// the default server would show.
		if ( null === $scope ) {
			return $tools;
		}

		$allowed_tool_names = $this->ability_names_to_tool_names( $this->resolve_effective_scope( $scope ) );

		return array_values(
			array_filter(
				$tools,
				static fn( $tool ) => in_array( $tool->getName(), $allowed_tool_names, true )
			)
		);
	}

	/**
	 * @param array|\WP_Error              $args
	 * @param string                       $tool_name
	 * @param \WP\MCP\Domain\Tools\McpTool $mcp_tool
	 * @param \WP\MCP\Core\McpServer       $server
	 * @return array|\WP_Error
	 */
	public function enforce_scope_on_call( $args, string $tool_name, $mcp_tool, $server ) {
		if ( is_wp_error( $args ) || self::SERVER_ID !== $server->get_server_id() ) {
			return $args;
		}

		$scope = AMC_Auth::get_current_scope();
		if ( null === $scope ) {
			return $args;
		}

		$allowed_tool_names = $this->ability_names_to_tool_names( $this->resolve_effective_scope( $scope ) );

		if ( ! in_array( $tool_name, $allowed_tool_names, true ) ) {
			return new WP_Error(
				'amc_scope_denied',
				__( 'This connection is not authorized to use this tool. Ask the token owner to add it in Agency MCP Connect.', 'mcp-env-wp' )
			);
		}

		$token_id = AMC_Auth::get_current_token_id();
		if ( null !== $token_id ) {
			if ( AMC_Activity::instance()->is_rate_limited( $token_id ) ) {
				return new WP_Error(
					'amc_rate_limited',
					__( 'This connection is making requests too quickly. Please slow down and try again in a moment.', 'mcp-env-wp' )
				);
			}
			AMC_Activity::instance()->bump_rate_counter( $token_id );
		}

		return $args;
	}

	/**
	 * @param mixed|\WP_Error              $result
	 * @param array                        $args
	 * @param string                       $tool_name
	 * @param \WP\MCP\Domain\Tools\McpTool $mcp_tool
	 */
	public function log_tool_call( $result, $args, string $tool_name, $mcp_tool ) {
		$token_id = AMC_Auth::get_current_token_id();
		if ( null !== $token_id ) {
			AMC_Activity::instance()->log( $token_id, $tool_name, ! is_wp_error( $result ) );
		}

		return $result;
	}

	/**
	 * Resolves the ability names a token is actually allowed to use right now.
	 *
	 * For a "live" scope mode (read / read_write / full), this re-expands the
	 * preset against whatever abilities exist *at this moment* — so a token
	 * granted e.g. "Full access" automatically covers abilities added after
	 * the token was created, rather than being frozen to a snapshot list.
	 * For 'explicit' mode (a hand-picked ability list), the stored list is
	 * used as-is, narrowed to abilities that still exist.
	 *
	 * @param string[] $stored_scope
	 * @return string[]
	 */
	private function resolve_effective_scope( array $stored_scope ): array {
		$mode = AMC_Auth::get_current_scope_mode();

		if ( in_array( $mode, AMC_Token_Manager::LIVE_SCOPE_MODES, true ) ) {
			return AMC_Scopes::expand_preset( $mode );
		}

		return AMC_Scopes::sanitize_scope( $stored_scope );
	}

	/**
	 * @param string[] $ability_names
	 * @return string[]
	 */
	private function ability_names_to_tool_names( array $ability_names ): array {
		$tool_names = array();

		foreach ( $ability_names as $ability_name ) {
			$sanitized = McpNameSanitizer::sanitize_name( $ability_name );
			if ( ! is_wp_error( $sanitized ) ) {
				$tool_names[] = $sanitized;
			}
		}

		return $tool_names;
	}
}
