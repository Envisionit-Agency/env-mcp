<?php
/**
 * Generic Advanced Custom Fields abilities — including ACF Flexible Content,
 * which is how this site's pages are actually built (post_content is unused).
 *
 * Rather than hardcoding this site's specific flexible-content layouts (which
 * would go stale the moment the theme's field groups change), these abilities
 * are schema-driven: a dedicated "get schema" ability introspects whatever
 * layouts/sub-fields ACF currently has configured, so an LLM client can always
 * see the current shape before reading or writing content.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_ACF_Abilities
 */
final class AMC_ACF_Abilities {

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
		add_action( 'wp_abilities_api_init', array( $this, 'register_all' ) );
	}

	public function register_all(): void {
		if ( ! function_exists( 'get_field' ) || ! function_exists( 'acf_get_field_groups' ) ) {
			return;
		}

		$this->register_field_groups_discovery();
		$this->register_generic_fields();
		$this->register_flexible_content();
	}

	/** Shorthand for a public MCP tool ability. */
	private function public_tool_meta(): array {
		return array( 'mcp' => array( 'public' => true, 'type' => 'tool' ) );
	}

	// -----------------------------------------------------------------
	// Discovery: which ACF field groups apply to a given post, and their
	// simple (non-flexible-content) fields' current schema.
	// -----------------------------------------------------------------

	private function register_field_groups_discovery(): void {
		wp_register_ability(
			'env-site/get-acf-field-schema',
			array(
				'label'               => __( 'Get ACF Field Schema', 'mcp-env-wp' ),
				'description'         => __( 'Lists every ACF field configured for a given post (name, label, type, and current value), excluding flexible-content fields — use env-site-get-page-sections-schema for those. Call this before writing ACF fields so you know the exact field names and types.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['post_id'] ?? 0 ) ),
				'execute_callback'    => function ( array $args ) {
					$post_id = (int) $args['post_id'];
					$objects = get_field_objects( $post_id );

					if ( ! $objects ) {
						return array( 'fields' => array() );
					}

					$fields = array();
					foreach ( $objects as $field ) {
						if ( 'flexible_content' === $field['type'] ) {
							$fields[] = array(
								'name'  => $field['name'],
								'label' => $field['label'],
								'type'  => 'flexible_content',
								'note'  => __( 'Use env-site-get-page-sections-schema / env-site-get-page-sections / env-site-update-page-sections for this field.', 'mcp-env-wp' ),
							);
							continue;
						}

						$fields[] = array(
							'name'  => $field['name'],
							'label' => $field['label'],
							'type'  => $field['type'],
							'value' => $field['value'],
						);
					}

					return array( 'fields' => $fields );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Generic (non-flexible-content) ACF field read/write.
	// -----------------------------------------------------------------

	private function register_generic_fields(): void {
		wp_register_ability(
			'env-site/update-acf-fields',
			array(
				'label'               => __( 'Update ACF Fields', 'mcp-env-wp' ),
				'description'         => __( 'Writes one or more ACF field values on a post by field name (not flexible-content — use env-site-update-page-sections for that). Call env-site-get-acf-field-schema first to see valid field names and expected value shapes.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'fields'  => array(
							'type'        => 'object',
							'description' => __( 'Map of ACF field name to new value, e.g. {"page_title": "New title", "hide_page_header": true}.', 'mcp-env-wp' ),
						),
					),
					'required'   => array( 'post_id', 'fields' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['post_id'] ?? 0 ) ),
				'execute_callback'    => static function ( array $args ) {
					$post_id = (int) $args['post_id'];
					$updated = array();

					foreach ( (array) $args['fields'] as $name => $value ) {
						$ok = update_field( $name, $value, $post_id );
						if ( false === $ok ) {
							return new WP_Error(
								'amc_acf_update_failed',
								sprintf(
									/* translators: %s: ACF field name */
									__( 'Could not update ACF field "%s" — check the field name is correct for this post.', 'mcp-env-wp' ),
									$name
								)
							);
						}
						$updated[] = $name;
					}

					return array( 'updated' => $updated );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Flexible content ("page_sections") — schema, read, write.
	// -----------------------------------------------------------------

	private function register_flexible_content(): void {
		wp_register_ability(
			'env-site/get-page-sections-schema',
			array(
				'label'               => __( 'Get Page Sections Schema', 'mcp-env-wp' ),
				'description'         => __( 'Introspects the flexible-content field used to build page layouts: every available section layout, its sub-fields, and their types. Call this before building or editing page_sections so you know exactly what shape each section needs — layouts and fields can change over time, so don\'t assume from memory.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'field_name' => array( 'type' => 'string', 'default' => 'page_sections' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => function ( array $args ) {
					$field = $this->find_flexible_content_field( $args['field_name'] ?? 'page_sections' );

					if ( ! $field ) {
						return new WP_Error( 'amc_not_found', __( 'No flexible-content field with that name was found in any ACF field group.', 'mcp-env-wp' ) );
					}

					return array(
						'field_name' => $field['name'],
						'layouts'    => array_values( array_map( array( $this, 'describe_layout' ), $field['layouts'] ) ),
					);
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/get-page-sections',
			array(
				'label'               => __( 'Get Page Sections', 'mcp-env-wp' ),
				'description'         => __( 'Returns the current flexible-content sections for a page, in the same shape you\'d use to write them back with env-site-update-page-sections.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'field_name' => array( 'type' => 'string', 'default' => 'page_sections' ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['post_id'] ?? 0 ) ),
				'execute_callback'    => static function ( array $args ) {
					$sections = get_field( $args['field_name'] ?? 'page_sections', (int) $args['post_id'] );
					return array( 'sections' => $sections ?: array() );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/update-page-sections',
			array(
				'label'               => __( 'Update Page Sections', 'mcp-env-wp' ),
				'description'         => __( 'Replaces the entire flexible-content section list for a page. This is a full replace, not a merge — call env-site-get-page-sections first, edit the array (e.g. append/modify/remove a section), and pass the complete result back. Call env-site-get-page-sections-schema first if you\'re unsure what fields a layout needs. Each section object must include "acf_fc_layout" matching one of the layout names from the schema.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'field_name' => array( 'type' => 'string', 'default' => 'page_sections' ),
						'sections'   => array(
							'type'        => 'array',
							'description' => __( 'The complete list of sections, each an object with "acf_fc_layout" plus that layout\'s sub-field values.', 'mcp-env-wp' ),
							'items'       => array( 'type' => 'object' ),
						),
					),
					'required'   => array( 'post_id', 'sections' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['post_id'] ?? 0 ) ),
				'execute_callback'    => static function ( array $args ) {
					$field_name = $args['field_name'] ?? 'page_sections';
					$post_id    = (int) $args['post_id'];

					foreach ( $args['sections'] as $i => $section ) {
						if ( empty( $section['acf_fc_layout'] ) ) {
							return new WP_Error(
								'amc_missing_layout',
								sprintf(
									/* translators: %d: zero-based section index */
									__( 'Section at index %d is missing "acf_fc_layout" — every section must declare which layout it uses.', 'mcp-env-wp' ),
									$i
								)
							);
						}
					}

					$ok = update_field( $field_name, $args['sections'], $post_id );
					if ( false === $ok ) {
						return new WP_Error( 'amc_acf_update_failed', __( 'Could not update the page sections.', 'mcp-env-wp' ) );
					}

					return array( 'sections' => get_field( $field_name, $post_id ) ?: array() );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	/**
	 * Finds a flexible-content field's definition (including its layouts)
	 * by name, searching every registered ACF field group directly —
	 * deliberately not post-context-dependent (unlike get_field_object()),
	 * since a field's *shape* doesn't depend on which post you ask from,
	 * and a fresh post that's never been saved via ACF can otherwise fail
	 * to resolve field groups through the normal per-post lookup.
	 *
	 * @return array<string,mixed>|null
	 */
	private function find_flexible_content_field( string $field_name ): ?array {
		foreach ( acf_get_field_groups() as $group ) {
			foreach ( acf_get_fields( $group ) as $field ) {
				if ( $field_name === $field['name'] && 'flexible_content' === $field['type'] ) {
					return $field;
				}
			}
		}
		return null;
	}

	/**
	 * Flattens one ACF flexible-content layout definition down to just
	 * what an LLM client needs: name, label, and each sub-field's
	 * name/label/type (one level of repeater nesting included).
	 *
	 * @param array<string,mixed> $layout
	 * @return array<string,mixed>
	 */
	private function describe_layout( array $layout ): array {
		return array(
			'name'       => $layout['name'],
			'label'      => $layout['label'],
			'sub_fields' => array_map( array( $this, 'describe_field' ), $layout['sub_fields'] ?? array() ),
		);
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	private function describe_field( array $field ): array {
		$described = array(
			'name'  => $field['name'],
			'label' => $field['label'],
			'type'  => $field['type'],
		);

		if ( 'repeater' === $field['type'] && ! empty( $field['sub_fields'] ) ) {
			$described['sub_fields'] = array_map( array( $this, 'describe_field' ), $field['sub_fields'] );
		}

		if ( 'select' === $field['type'] && ! empty( $field['choices'] ) ) {
			$described['choices'] = $field['choices'];
		}

		return $described;
	}
}
