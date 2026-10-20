<?php
/**
 * Self-serve "MCP Connect" admin page — every logged-in user can create their
 * own scoped tokens and grab ready-to-paste config for Claude, ChatGPT, or Gemini.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_Admin
 */
final class AMC_Admin {

	private const PAGE_SLUG = 'mcp-env-wp';

	private static ?self $instance = null;

	/** @var array{id:int,plain_token:string}|null */
	private ?array $just_created = null;

	private ?WP_Error $error = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'MCP Connect', 'mcp-env-wp' ),
			__( 'MCP Connect', 'mcp-env-wp' ),
			'read',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'amc-admin', AMC_PLUGIN_URL . 'assets/admin.css', array(), AMC_VERSION );
		wp_enqueue_script( 'amc-admin', AMC_PLUGIN_URL . 'assets/admin.js', array(), AMC_VERSION, true );
		wp_localize_script(
			'amc-admin',
			'amcConnect',
			array(
				'testTokenUrl' => rest_url( 'wp-mcp-connect/v1/test-token' ),
			)
		);
	}

	/**
	 * Handles the "create token", "show setup" (regenerate) and "revoke token" form posts.
	 */
	public function handle_actions(): void {
		if ( ! isset( $_POST['amc_action'] ) || ! is_user_logged_in() ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['amc_action'] ) );
		$user   = wp_get_current_user();

		if ( 'create_token' === $action ) {
			check_admin_referer( 'amc_create_token' );

			$name  = isset( $_POST['amc_name'] ) ? sanitize_text_field( wp_unslash( $_POST['amc_name'] ) ) : '';
			$scope = isset( $_POST['amc_scope'] ) && is_array( $_POST['amc_scope'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['amc_scope'] ) )
				: array();
			$expires_days = isset( $_POST['amc_expires_days'] ) ? absint( $_POST['amc_expires_days'] ) : 0;
			$scope_mode   = isset( $_POST['amc_scope_mode'] ) ? sanitize_key( wp_unslash( $_POST['amc_scope_mode'] ) ) : 'explicit';

			$result = AMC_Token_Manager::instance()->create_token( $user->ID, $name, $scope, $expires_days, $scope_mode );

			if ( is_wp_error( $result ) ) {
				$this->error = $result;
			} else {
				$this->just_created = $result;
			}
		}

		if ( 'regenerate_token' === $action ) {
			check_admin_referer( 'amc_regenerate_token' );

			$token_id = isset( $_POST['amc_token_id'] ) ? absint( $_POST['amc_token_id'] ) : 0;
			$result   = AMC_Token_Manager::instance()->regenerate_token( $token_id, $user->ID );

			if ( is_wp_error( $result ) ) {
				$this->error = $result;
			} else {
				$this->just_created = $result;
			}
		}

		if ( 'revoke_token' === $action ) {
			check_admin_referer( 'amc_revoke_token' );

			$token_id = isset( $_POST['amc_token_id'] ) ? absint( $_POST['amc_token_id'] ) : 0;
			$force    = current_user_can( 'manage_options' ) && isset( $_POST['amc_force'] );

			$result = AMC_Token_Manager::instance()->revoke_token( $token_id, $user->ID, $force );

			if ( is_wp_error( $result ) ) {
				$this->error = $result;
			} else {
				wp_safe_redirect( add_query_arg( 'amc_revoked', '1', wp_get_referer() ?: admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
				exit;
			}
		}
	}

	public function render_page(): void {
		$user      = wp_get_current_user();
		$tokens    = AMC_Token_Manager::instance()->list_tokens_for_user( $user->ID );
		$abilities = AMC_Scopes::get_available_abilities();
		$grouped   = array();
		foreach ( $abilities as $ability ) {
			$grouped[ $ability['category'] ][] = $ability;
		}
		$is_admin      = current_user_can( 'manage_options' );
		$my_activity   = AMC_Activity::instance()->recent_for_user( $user->ID, 15 );
		$all_activity  = $is_admin ? AMC_Activity::instance()->recent_all( 30 ) : array();

		require AMC_PLUGIN_DIR . 'templates/page-connect.php';
	}
}
