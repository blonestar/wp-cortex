<?php
/**
 * Contract for extractors whose fields can be assigned to an index.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing\Extractors;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the fields an extractor adds, so the settings screen can choose the index
 * (admin, public, both or none) of each one.
 */
interface DescribesFields {

	/**
	 * Group label on the settings screen.
	 */
	public function fields_label(): string;

	/**
	 * Fields the extractor may add: field key ("source:name", may end in "*") => label,
	 * whether the field goes into the public index by default, and optionally a subgroup
	 * (for example the ACF field group) shown as a collapsible block, a short type hint and
	 * the post types the field exists on (the settings screen only lists it for an index
	 * that contains one of them; omitted or empty means every post type). A field that
	 * belongs to several subgroups lists them in "groups" (subgroup => label, type and post
	 * types); it is shown in each of them with one shared choice.
	 *
	 * @return array<string, array{label: string, public: bool, group?: string, type?: string, post_types?: string[], groups?: array<string, array{label: string, type: string, post_types: string[]}>}>
	 */
	public function fields(): array;
}
