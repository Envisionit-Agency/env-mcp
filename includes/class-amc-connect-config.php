<?php
/**
 * Generates ready-to-paste connection config for each AI client.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_Connect_Config
 */
final class AMC_Connect_Config {

	public static function server_url(): string {
		return trailingslashit( rest_url( AMC_Server::ROUTE_NAMESPACE . '/' . AMC_Server::ROUTE ) );
	}

	/**
	 * Claude Desktop / Claude Code config block (mcp-remote + Bearer header).
	 */
	public static function claude_json( string $server_label, string $plain_token ): string {
		$config = array(
			'mcpServers' => array(
				$server_label => array(
					'command' => 'npx',
					'args'    => array(
						'-y',
						'mcp-remote',
						self::server_url(),
						'--header',
						'Authorization: Bearer ' . $plain_token,
					),
				),
			),
		);

		return wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * `claude mcp add-json` one-liner.
	 */
	public static function claude_cli_command( string $server_label, string $plain_token ): string {
		$json = wp_json_encode(
			array(
				'command' => 'npx',
				'args'    => array(
					'-y',
					'mcp-remote',
					self::server_url(),
					'--header',
					'Authorization: Bearer ' . $plain_token,
				),
			),
			JSON_UNESCAPED_SLASHES
		);

		return sprintf( "claude mcp add-json %s '%s'", escapeshellarg( $server_label ), $json );
	}

	/**
	 * ChatGPT / OpenAI "custom connector" style config (server URL + bearer token
	 * entered separately in their UI).
	 */
	public static function openai_fields( string $plain_token ): array {
		return array(
			'url'    => self::server_url(),
			'header' => 'Authorization',
			'value'  => 'Bearer ' . $plain_token,
		);
	}

	/**
	 * Gemini / Gemini CLI extension style config.
	 */
	public static function gemini_json( string $server_label, string $plain_token ): string {
		$config = array(
			'mcpServers' => array(
				$server_label => array(
					'httpUrl' => self::server_url(),
					'headers' => array(
						'Authorization' => 'Bearer ' . $plain_token,
					),
				),
			),
		);

		return wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	public static function curl_test_command( string $plain_token ): string {
		return sprintf(
			"curl -s -X POST %s \\\n  -H 'Authorization: Bearer %s' \\\n  -H 'Content-Type: application/json' \\\n  -H 'Accept: application/json, text/event-stream' \\\n  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}'",
			escapeshellarg( self::server_url() ),
			$plain_token
		);
	}
}
