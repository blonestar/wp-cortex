<?php
/**
 * Admin chat tool: edit_fields.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Changes ACF fields of the post open in the block editor: top-level fields of simple
 * types (text, textarea, number, email, URL, select, radio, true/false, range) in the
 * field groups shown for the post. Like edit_post, it only changes the editor; the user
 * saves the post.
 */
final class EditFields extends AdminTool {

	/**
	 * Longest current value shown in the system prompt.
	 */
	private const PROMPT_VALUE_LENGTH = 300;

	public function name(): string {
		return 'edit_fields';
	}

	public function label(): string {
		return __( 'Edit ACF fields in the editor', 'wp-cortex' );
	}

	/**
	 * Offered while the block editor shows ACF fields the tool can change.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		$editor = $this->admin( $context )->editor();

		return null !== $editor && ! empty( $editor['fields'] ) && EditorState::has_fields();
	}

	/**
	 * What else decides whether the tool is offered.
	 */
	public function availability_note(): string {
		return __( 'Only while the block editor shows ACF fields of a simple type (text, number, select, ...).', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Changes ACF custom fields of the post open in the editor, by field key. The change is applied in the editor only and is not saved: the user reviews it and saves the post. Call it only when the user asks for the change or accepts a value you suggested.';
	}

	/**
	 * Arguments.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function parameters( ToolContext $context ): ?array {
		$keys = array_keys( (array) ( $this->admin( $context )->editor()['fields'] ?? array() ) );
		$key  = array(
			'type'        => 'string',
			'description' => 'Field key from the list of fields.',
		);

		if ( $keys ) {
			$key['enum'] = $keys;
		}

		return array(
			'type'       => 'object',
			'properties' => array(
				'fields' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'   => $key,
							'value' => array(
								'type'        => 'string',
								'description' => 'The new value. For select and radio fields one of the choice values, for true/false fields "1" or "0".',
							),
						),
						'required'   => array( 'key', 'value' ),
					),
				),
			),
			'required'   => array( 'fields' ),
		);
	}

	/**
	 * System instruction lines: the fields with their current values.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		$lines = array( 'ACF fields of the post currently in the editor (possibly not saved yet), as key: label (field name, type, group) = current value:' );

		foreach ( (array) ( $this->admin( $context )->editor()['fields'] ?? array() ) as $field ) {
			$value = (string) $field['value'];

			if ( mb_strlen( $value ) > self::PROMPT_VALUE_LENGTH ) {
				$value = mb_substr( $value, 0, self::PROMPT_VALUE_LENGTH ) . '…';
			}

			$line = sprintf( '- %1$s: %2$s (%3$s, %4$s, %5$s) = %6$s', $field['key'], $field['label'], $field['name'], $field['type'], $field['group'], '' !== $value ? '«' . $value . '»' : '(empty)' );

			if ( $field['choices'] ) {
				$choices = array();

				foreach ( $field['choices'] as $choice => $label ) {
					$choices[] = $choice === $label ? $choice : $choice . ' (' . $label . ')';
				}

				$line .= '; choices: ' . implode( ', ', $choices );
			}

			$lines[] = $line;
		}

		$lines[] = 'To change these fields, call edit_fields, with the same rules as edit_post. Other custom fields cannot be changed.';

		return $lines;
	}

	/**
	 * Queues the changes for the editor.
	 *
	 * @param array       $args    Arguments: fields.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin   = $this->admin( $context );
		$known   = (array) ( $admin->editor()['fields'] ?? array() );
		$changes = array();
		$labels  = array();

		foreach ( array_slice( (array) ( $args['fields'] ?? array() ), 0, EditorState::MAX_FIELDS ) as $item ) {
			$key   = is_array( $item ) ? (string) ( $item['key'] ?? '' ) : '';
			$field = $known[ $key ] ?? null;

			if ( null === $field ) {
				/* translators: %s: field key */
				return $this->error( sprintf( __( 'Unknown field: %s. Use one of the listed field keys.', 'wp-cortex' ), sanitize_text_field( $key ) ) );
			}

			$value = EditorState::field_value( $field, $item['value'] ?? null );

			if ( null === $value ) {
				/* translators: %s: field label */
				return $this->error( sprintf( __( 'The value is not valid for the field “%s”; nothing was changed.', 'wp-cortex' ), $field['label'] ) );
			}

			$changes[] = array(
				'key'   => $key,
				'type'  => $field['type'],
				'value' => $value,
			);
			$labels[]  = $field['label'];
		}

		if ( ! $changes ) {
			return $this->error( __( 'Nothing to change. Pass at least one field.', 'wp-cortex' ) );
		}

		$admin->add_action(
			array(
				'type'    => 'edit_fields',
				'post_id' => $admin->post_id(),
				'fields'  => $changes,
				'labels'  => $labels,
			)
		);

		return array(
			'ok'      => true,
			'changed' => $labels,
			'saved'   => false,
		);
	}

	/**
	 * Error response.
	 *
	 * @param string $message Message.
	 * @return array{ok: false, error: string}
	 */
	private function error( string $message ): array {
		return array(
			'ok'    => false,
			'error' => $message,
		);
	}
}
