<?php
/**
 * Storage for OAuth refresh tokens — kept separate from amc_tokens (which
 * holds only bearer *access* tokens used directly against the MCP server)
 * since refresh tokens have different lifetime/rotation rules and are only
 * ever presented to the token endpoint, never to the MCP route itself.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_OAuth_Refresh_Tokens
 */
final class AMC_OAuth_Refresh_Tokens {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public static function install_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . AMC_OAUTH_REFRESH_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token_hash CHAR(64) NOT NULL,
			client_id VARCHAR(64) NOT NULL,
			client_name VARCHAR(191) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			access_token_id BIGINT UNSIGNED NOT NULL,
			scope LONGTEXT NOT NULL,
			scope_mode VARCHAR(20) NOT NULL DEFAULT 'explicit',
			expires_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . AMC_OAUTH_REFRESH_TABLE;
	}

	/**
	 * @param string[] $scope
	 * @return string Plain refresh token.
	 */
	public function create( int $user_id, string $client_id, string $client_name, int $access_token_id, array $scope, string $scope_mode, int $ttl_days ): string {
		global $wpdb;

		$plain = 'amcr_' . wp_generate_password( 48, false, false );

		$wpdb->insert(
			$this->table(),
			array(
				'token_hash'      => hash( 'sha256', $plain ),
				'client_id'       => $client_id,
				'client_name'     => $client_name,
				'user_id'         => $user_id,
				'access_token_id' => $access_token_id,
				'scope'           => wp_json_encode( $scope ),
				'scope_mode'      => $scope_mode,
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + ( $ttl_days * DAY_IN_SECONDS ) ),
				'revoked_at'      => null,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $plain;
	}

	/**
	 * Validates a refresh token and marks it consumed (single use — callers
	 * must issue a new refresh token as part of rotation).
	 *
	 * @return array<string,mixed>|null
	 */
	public function validate_and_consume( string $plain_token, string $client_id ): ?array {
		global $wpdb;

		if ( ! str_starts_with( $plain_token, 'amcr_' ) ) {
			return null;
		}

		$hash = hash( 'sha256', $plain_token );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE token_hash = %s AND client_id = %s AND revoked_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash,
				$client_id
			),
			ARRAY_A
		);

		if ( ! $row || strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}

		$wpdb->update( $this->table(), array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'id' => $row['id'] ) );

		$row['scope'] = json_decode( (string) $row['scope'], true ) ?: array();

		return $row;
	}
}
