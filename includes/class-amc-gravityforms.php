<?php
/**
 * Gravity Forms through this plugin's MCP server, so a site needs one connection, not a second one for Gravity Forms.
 *
 * Gravity Forms (3.2+) registers its tools as abilities for its own MCP server. This class runs Gravity Forms' MCP
 * settings instead of the admin: MCP on (Gravity Forms only registers its tools then), endpoint mode "Site MCP" (no
 * dedicated Gravity Forms endpoint), and its enabled tools set to the allowlist below, which is also what is made
 * public here. Entry data never leaves the site: tools that read, create or change entries, entry notes or submissions,
 * or that send a notification for an entry, are off (Gravity Forms refuses them on every route, REST included).
 * Entries can only be counted (gravityforms/entries-count), filtered by the tool's own arguments.
 * Each tool keeps Gravity Forms' own capability checks, and connection scopes apply as for every other ability.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_GravityForms
 */
final class AMC_GravityForms {

	private const ALLOWED = array(
		'gravityforms/forms-list',
		'gravityforms/forms-get',
		'gravityforms/forms-create',
		'gravityforms/forms-update',
		'gravityforms/forms-duplicate',
		'gravityforms/forms-delete',
		'gravityforms/forms-analyze-logic',
		'gravityforms/entries-count',
		'gravityforms/feeds-list',
		'gravityforms/feeds-create',
		'gravityforms/feeds-update',
		'gravityforms/feeds-delete',
		'gravityforms/notifications-list',
		'gravityforms/notifications-create',
		'gravityforms/notifications-update',
		'gravityforms/notifications-delete',
		'gravityforms/confirmations-list',
		'gravityforms/confirmations-create',
		'gravityforms/confirmations-update',
		'gravityforms/confirmations-delete',
		'gravityforms/system-info',
		'gravityforms/system-field-types',
	);

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_filter( 'wp_register_ability_args', array( self::$instance, 'expose' ), 20, 2 );
			// Gravity Forms reads these on plugins_loaded (priority 10), so they are set when this file loads.
			add_filter( 'pre_option_gform_mcp_enabled', static fn() => '1' );
			add_filter( 'pre_option_gform_mcp_endpoint_mode', static fn() => 'site' );
			add_filter( 'pre_option_gform_mcp_enabled_tools', static fn() => self::ALLOWED );
		}
		return self::$instance;
	}

	/**
	 * Runs as each ability registers, after Gravity Forms has set its own MCP flags, so this has the last word.
	 *
	 * @param array<string,mixed> $args Ability arguments.
	 * @param string              $name Ability name.
	 * @return array<string,mixed>
	 */
	public function expose( array $args, string $name ): array {
		if ( ! str_starts_with( $name, 'gravityforms/' ) ) {
			return $args;
		}
		$args['meta']['mcp']['public'] = in_array( $name, self::ALLOWED, true );
		return $args;
	}
}

AMC_GravityForms::instance();
