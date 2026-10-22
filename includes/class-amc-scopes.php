<?php
/**
 * Discovers available abilities and classifies them into read / write / destructive
 * buckets so the admin UI can offer quick presets alongside per-ability control.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_Scopes
 */
final class AMC_Scopes {

	private const READ_PREFIXES = array( 'get-', 'list-', 'search-' );

	/**
	 * Ability action-name suffixes that don't match a read verb prefix but
	 * are still read-only (no side effects).
	 */
	private const READ_EXCEPTIONS = array( 'site-stats' );

	private const DESTRUCTIVE_PREFIXES = array( 'delete-', 'remove-', 'trash-', 'moderate-' );

	/**
	 * Returns every ability currently exposed publicly to MCP (i.e. enabled via
	 * "Enable Abilities for MCP" or any other plugin using the same mcp.public meta),
	 * enriched with a guessed risk level.
	 *
	 * @return array<int,array{name:string,label:string,description:string,category:string,level:string}>
	 */
	public static function get_available_abilities(): array {
		return self::get_available_by_type( 'tool' );
	}

	/**
	 * Returns every ability publicly exposed as an MCP prompt (guided,
	 * multi-turn flows rather than a single callable action).
	 *
	 * @return array<int,array{name:string,label:string,description:string,category:string,level:string}>
	 */
	public static function get_available_prompts(): array {
		return self::get_available_by_type( 'prompt' );
	}

	/**
	 * @return array<int,array{name:string,label:string,description:string,category:string,level:string}>
	 */
	private static function get_available_by_type( string $wanted_type ): array {
		$abilities = array();

		foreach ( wp_get_abilities() as $ability ) {
			$meta = $ability->get_meta();

			if ( empty( $meta['mcp']['public'] ) ) {
				continue;
			}

			$type = $meta['mcp']['type'] ?? 'tool';
			if ( $wanted_type !== $type ) {
				continue;
			}

			$name = $ability->get_name();

			$abilities[] = array(
				'name'        => $name,
				'label'       => $ability->get_label(),
				'description' => (string) $ability->get_description(),
				'category'    => self::category_from_name( $name ),
				'level'       => self::guess_level( $name, $meta['annotations'] ?? array() ),
			);
		}

		usort(
			$abilities,
			static fn( $a, $b ) => $a['category'] <=> $b['category'] ?: $a['name'] <=> $b['name']
		);

		return $abilities;
	}

	/**
	 * Groups the "namespace" prefix of an ability (e.g. "ewpa/get-posts" -> "ewpa")
	 * into a readable category label.
	 */
	private static function category_from_name( string $name ): string {
		$slash = strpos( $name, '/' );
		$ns    = $slash ? substr( $name, 0, $slash ) : $name;

		$map = array(
			'core' => __( 'WordPress Core', 'mcp-env-wp' ),
			'env-site' => __( 'Content & Site', 'mcp-env-wp' ),
			'gravityforms' => __( 'Gravity Forms', 'mcp-env-wp' ),
		);

		return $map[ $ns ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $ns ) );
	}

	/**
	 * Risk level of an ability. Destructive: a delete-type verb anywhere in its action (delete-post, entries-delete).
	 * Read: its readonly annotation when it declares one, else a read verb or a known read-only name. Otherwise write.
	 * (The destructive annotation is not used: MCP sets it for anything that overwrites, so creates and updates carry it.)
	 *
	 * @param array<string,mixed> $annotations Ability meta annotations.
	 */
	public static function guess_level( string $name, array $annotations = array() ): string {
		$slash  = strpos( $name, '/' );
		$action = $slash ? substr( $name, $slash + 1 ) : $name;
		$verbs  = array_map( static fn( $p ) => rtrim( $p, '-' ), self::DESTRUCTIVE_PREFIXES );

		if ( array_intersect( explode( '-', $action ), $verbs ) ) {
			return 'destructive';
		}

		if ( isset( $annotations['readonly'] ) ) {
			return $annotations['readonly'] ? 'read' : 'write';
		}

		if ( in_array( $action, self::READ_EXCEPTIONS, true ) ) {
			return 'read';
		}

		foreach ( self::READ_PREFIXES as $prefix ) {
			if ( str_starts_with( $action, $prefix ) ) {
				return 'read';
			}
		}

		return 'write';
	}

	/**
	 * Given a preset key, returns the matching ability names out of what's available.
	 *
	 * @return string[]
	 */
	public static function expand_preset( string $preset ): array {
		$all = self::get_available_abilities();

		return match ( $preset ) {
			'read'       => self::names_at_or_below( $all, array( 'read' ) ),
			'read_write' => self::names_at_or_below( $all, array( 'read', 'write' ) ),
			'full'       => wp_list_pluck( $all, 'name' ),
			default      => array(),
		};
	}

	/**
	 * @param array<int,array{name:string,level:string}> $abilities
	 * @param string[]                                    $levels
	 * @return string[]
	 */
	private static function names_at_or_below( array $abilities, array $levels ): array {
		return wp_list_pluck(
			array_filter( $abilities, static fn( $a ) => in_array( $a['level'], $levels, true ) ),
			'name'
		);
	}

	/**
	 * Narrows a requested scope down to abilities that are actually still available
	 * (so a scope saved before an admin disables an ability quietly loses access to it).
	 *
	 * @param string[] $requested
	 * @return string[]
	 */
	public static function sanitize_scope( array $requested ): array {
		$available = wp_list_pluck( self::get_available_abilities(), 'name' );
		return array_values( array_intersect( $requested, $available ) );
	}
}
