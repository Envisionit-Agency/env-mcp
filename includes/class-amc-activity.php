<?php
/**
 * Per-token activity logging and basic rate limiting.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_Activity
 */
final class AMC_Activity {

	/** Max tool calls allowed per token per minute. */
	private const RATE_LIMIT_PER_MINUTE = 60;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . AMC_LOG_TABLE;
	}

	/**
	 * Checks whether a token is currently over the per-minute rate limit.
	 * Uses the object cache / transient API so it works without extra DB load.
	 */
	public function is_rate_limited( int $token_id ): bool {
		$key   = 'amc_rate_' . $token_id . '_' . floor( time() / 60 );
		$count = (int) get_transient( $key );

		return $count >= self::RATE_LIMIT_PER_MINUTE;
	}

	/**
	 * Increments the current minute's call counter for a token.
	 */
	public function bump_rate_counter( int $token_id ): void {
		$key   = 'amc_rate_' . $token_id . '_' . floor( time() / 60 );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Records one tool call for a token.
	 */
	public function log( int $token_id, string $tool_name, bool $success ): void {
		global $wpdb;

		$wpdb->insert(
			$this->table(),
			array(
				'token_id'   => $token_id,
				'tool_name'  => $tool_name,
				'success'    => $success ? 1 : 0,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_for_token( int $token_id, int $limit = 20 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tool_name, success, created_at FROM {$this->table()} WHERE token_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token_id,
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Recent activity across all of one user's own tokens.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_for_user( int $user_id, int $limit = 20 ): array {
		global $wpdb;
		$tokens_table = $wpdb->prefix . AMC_TABLE;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.tool_name, l.success, l.created_at, t.name AS token_name
				 FROM {$this->table()} l
				 INNER JOIN {$tokens_table} t ON t.id = l.token_id
				 WHERE t.user_id = %d
				 ORDER BY l.created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Site-wide recent activity, joined with token/user names, for the admin view.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_all( int $limit = 50 ): array {
		global $wpdb;
		$tokens_table = $wpdb->prefix . AMC_TABLE;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.tool_name, l.success, l.created_at, t.name AS token_name, u.display_name
				 FROM {$this->table()} l
				 LEFT JOIN {$tokens_table} t ON t.id = l.token_id
				 LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
				 ORDER BY l.created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		) ?: array();
	}
}
