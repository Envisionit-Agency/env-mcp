<?php
/**
 * Storage and lifecycle for scoped MCP access tokens.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_Token_Manager
 */
final class AMC_Token_Manager {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Creates the tokens table on activation.
	 */
	public static function install_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . AMC_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(191) NOT NULL,
			token_hash CHAR(64) NOT NULL,
			token_prefix VARCHAR(24) NOT NULL,
			scope LONGTEXT NOT NULL,
			scope_mode VARCHAR(20) NOT NULL DEFAULT 'explicit',
			created_at DATETIME NOT NULL,
			last_used_at DATETIME NULL,
			revoked_at DATETIME NULL,
			expires_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id)
		) {$charset_collate};";

		dbDelta( $sql );

		$log_table = $wpdb->prefix . AMC_LOG_TABLE;
		$log_sql   = "CREATE TABLE {$log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token_id BIGINT UNSIGNED NOT NULL,
			tool_name VARCHAR(191) NOT NULL,
			success TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY token_id (token_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $log_sql );

		update_option( 'amc_db_version', AMC_VERSION );
	}

	public function is_expired( array $token ): bool {
		return ! empty( $token['expires_at'] ) && strtotime( $token['expires_at'] . ' UTC' ) < time();
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . AMC_TABLE;
	}

	/**
	 * Valid "live" scope modes — for these, the stored scope list is just a
	 * snapshot for display; actual permission checks re-expand the preset
	 * against whatever abilities exist *right now*, so a token granted
	 * e.g. "Full access" automatically gains any ability added later.
	 */
	public const LIVE_SCOPE_MODES = array( 'read', 'read_write', 'full' );

	/**
	 * Creates a new token for a user, scoped to a specific list of ability names.
	 *
	 * @param int      $user_id    User the token acts as.
	 * @param string   $name       Human-readable label, e.g. "Claude Desktop".
	 * @param string[] $scope      Ability names this token is allowed to use (used as-is for
	 *                             'explicit' mode, or as an initial snapshot for a live mode).
	 * @param int      $expires_in_days
	 * @param string   $scope_mode 'explicit' (fixed list) or one of self::LIVE_SCOPE_MODES
	 *                             (list is re-evaluated live against current abilities).
	 *
	 * @return array{id:int,plain_token:string}|WP_Error
	 */
	public function create_token( int $user_id, string $name, array $scope, int $expires_in_days = 0, string $scope_mode = 'explicit' ) {
		global $wpdb;

		$name = trim( $name );
		if ( '' === $name ) {
			return new WP_Error( 'amc_invalid_name', __( 'Please give this connection a name.', 'mcp-env-wp' ) );
		}

		if ( 'explicit' !== $scope_mode && ! in_array( $scope_mode, self::LIVE_SCOPE_MODES, true ) ) {
			$scope_mode = 'explicit';
		}

		$scope = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $scope ) ) ) );
		if ( empty( $scope ) ) {
			return new WP_Error( 'amc_invalid_scope', __( 'Select at least one ability to allow.', 'mcp-env-wp' ) );
		}

		$plain_token = 'amc_' . wp_generate_password( 40, false, false );
		$hash        = hash( 'sha256', $plain_token );
		$prefix      = substr( $plain_token, 0, 12 ) . '...';

		$expires_at = null;
		if ( $expires_in_days > 0 ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $expires_in_days * DAY_IN_SECONDS ) );
		}

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'user_id'      => $user_id,
				'name'         => $name,
				'token_hash'   => $hash,
				'token_prefix' => $prefix,
				'scope'        => wp_json_encode( $scope ),
				'scope_mode'   => $scope_mode,
				'created_at'   => current_time( 'mysql', true ),
				'last_used_at' => null,
				'revoked_at'   => null,
				'expires_at'   => $expires_at,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'amc_db_error', __( 'Could not create the token. Please try again.', 'mcp-env-wp' ) );
		}

		return array(
			'id'          => (int) $wpdb->insert_id,
			'plain_token' => $plain_token,
		);
	}

	/**
	 * Validates a plain-text bearer token and returns its row if active.
	 *
	 * @return array<string,mixed>|null
	 */
	public function validate_token( string $plain_token ): ?array {
		global $wpdb;

		if ( ! str_starts_with( $plain_token, 'amc_' ) ) {
			return null;
		}

		$hash = hash( 'sha256', $plain_token );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE token_hash = %s AND revoked_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}

		$row['scope'] = json_decode( (string) $row['scope'], true ) ?: array();

		return $row;
	}

	/**
	 * Records that a token was just used.
	 */
	public function touch_token( int $token_id ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => $token_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Lists all (non-revoked) tokens belonging to a user.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_tokens_for_user( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, token_prefix, scope, scope_mode, created_at, last_used_at, expires_at FROM {$this->table()} WHERE user_id = %d AND revoked_at IS NULL ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['scope'] = json_decode( (string) $row['scope'], true ) ?: array();
		}

		return $rows ?: array();
	}

	/**
	 * Lists every active token across all users (for the site-wide admin view).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_all_tokens(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT t.id, t.user_id, t.name, t.token_prefix, t.scope, t.scope_mode, t.created_at, t.last_used_at, t.expires_at, u.display_name, u.user_email
			 FROM {$this->table()} t
			 LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
			 WHERE t.revoked_at IS NULL
			 ORDER BY t.created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['scope'] = json_decode( (string) $row['scope'], true ) ?: array();
		}

		return $rows ?: array();
	}

	/**
	 * Revokes a token, only if it belongs to the given user (unless $force is true for admins).
	 */
	public function revoke_token( int $token_id, int $user_id, bool $force = false ) {
		global $wpdb;

		$where       = array( 'id' => $token_id );
		$where_types = array( '%d' );

		if ( ! $force ) {
			$where['user_id'] = $user_id;
			$where_types[]     = '%d';
		}

		$updated = $wpdb->update(
			$this->table(),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			$where,
			array( '%s' ),
			$where_types
		);

		if ( ! $updated ) {
			return new WP_Error( 'amc_not_found', __( 'Token not found or already revoked.', 'mcp-env-wp' ) );
		}

		return true;
	}
}
