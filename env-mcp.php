<?php
/**
 * Plugin Name:       MCP ENV WP
 * Description:       Lets every agency user generate their own scoped MCP access token and connect Claude, ChatGPT, or Gemini to this WordPress site — with per-token control over which abilities (read/write/delete) are allowed.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Envisionit
 * Text Domain:       mcp-env-wp
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'AMC_VERSION', '1.1.0' );
define( 'AMC_PLUGIN_FILE', __FILE__ );
define( 'AMC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AMC_TABLE', 'amc_tokens' );
define( 'AMC_LOG_TABLE', 'amc_token_log' );
define( 'AMC_OAUTH_CLIENTS_TABLE', 'amc_oauth_clients' );
define( 'AMC_OAUTH_REFRESH_TABLE', 'amc_oauth_refresh_tokens' );

require_once AMC_PLUGIN_DIR . 'includes/class-amc-seo-adapter.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-abilities.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-acf-abilities.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-media-audit.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-prompts.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-token-manager.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-activity.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-scopes.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-auth.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-server.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-connect-config.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-admin.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-rest.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-oauth-clients.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-oauth-refresh-tokens.php';
require_once AMC_PLUGIN_DIR . 'includes/class-amc-oauth-server.php';

/**
 * Boots the plugin once all plugins have loaded (so the Abilities API / mcp-adapter
 * dependency, if active, is available).
 */
function amc_boot(): void {
	amc_maybe_migrate_db();

	if ( ! function_exists( 'wp_get_abilities' ) || ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		add_action(
			'admin_notices',
			static function () {
				wp_admin_notice(
					__( 'Agency MCP Connect requires WordPress 6.9+ (Abilities API) and the "MCP Adapter" plugin to be active. No other plugin is required — "Enable Abilities for MCP" can be deactivated.', 'mcp-env-wp' ),
					array(
						'type'    => 'error',
						'dismiss' => false,
					)
				);
			}
		);
		return;
	}

	AMC_Abilities::instance();
	AMC_ACF_Abilities::instance();
	AMC_Media_Audit::instance();
	AMC_Prompts::instance();
	AMC_Token_Manager::instance();
	AMC_Auth::instance();
	AMC_Server::instance();
	AMC_Admin::instance();
	AMC_Rest::instance();
	AMC_OAuth_Server::instance();
}
add_action( 'plugins_loaded', 'amc_boot', 20 );

/**
 * Re-runs the schema migrations whenever the plugin's code is newer than
 * the DB version it last set up — not just on activation. dbDelta() is
 * always safe to re-run (it only adds/adjusts what's missing), so this
 * makes "replace the plugin files, don't remember to reactivate" safe,
 * which is exactly how updates get deployed here (upload a new zip over
 * the old one) rather than through the WP.org updater.
 */
function amc_maybe_migrate_db(): void {
	if ( get_option( 'amc_db_version' ) === AMC_VERSION ) {
		return;
	}

	AMC_Token_Manager::install_table();
	AMC_OAuth_Clients::install_table();
	AMC_OAuth_Refresh_Tokens::install_table();

	update_option( 'amc_db_version', AMC_VERSION );
}

register_activation_hook( AMC_PLUGIN_FILE, array( 'AMC_Token_Manager', 'install_table' ) );
register_activation_hook( AMC_PLUGIN_FILE, array( 'AMC_OAuth_Clients', 'install_table' ) );
register_activation_hook( AMC_PLUGIN_FILE, array( 'AMC_OAuth_Refresh_Tokens', 'install_table' ) );
