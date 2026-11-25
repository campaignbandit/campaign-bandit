<?php
/**
 * A campaign form's fields, read from the version's blocks (FR-23, BR-45).
 *
 * The server always uses the fields of the published version, never what the
 * browser claims, so a submission can't add fields or change the consent text.
 * WordPress-free: works on the parse_blocks() shape.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Forms;

/**
 * Fields of the first Campaign Bandit form in a version.
 */
final class FormSchema {

	/**
	 * Consent purposes (BR-44): contact about this request, email marketing, text messages.
	 */
	public const PURPOSES = array( 'contact', 'email', 'sms' );

	public const FIELD_BLOCKS = array(
		'campaign-bandit/text'      => 'text',
		'campaign-bandit/long-text' => 'long_text',
		'campaign-bandit/email'     => 'email',
		'campaign-bandit/phone'     => 'phone',
		'campaign-bandit/date'      => 'date',
		'campaign-bandit/choice'    => 'choice',
		'campaign-bandit/consent'   => 'consent',
		'campaign-bandit/hidden'    => 'hidden',
	);

	/**
	 * Block attribute defaults by block name. Saved block markup leaves out attributes
	 * that equal their default, so they are filled in from the registered blocks.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $defaults = array();

	/**
	 * Set the attribute defaults of the field blocks (from their block.json).
	 *
	 * @param array<string, array<string, mixed>> $defaults Defaults by block name.
	 */
	public static function set_defaults( array $defaults ): void {
		self::$defaults = $defaults;
	}

	/**
	 * Create a schema.
	 *
	 * @param bool                             $has_form Whether the version has a form.
	 * @param array<int, array<string, mixed>> $fields   Fields: key, type, label, required, options, multiple, text, purpose (consent).
	 */
	public function __construct(
		public readonly bool $has_form,
		public readonly array $fields,
	) {}

	/**
	 * Read the schema from parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	public static function from_blocks( array $blocks ): self {
		$form = self::find_form( $blocks );
		if ( null === $form ) {
			return new self( false, array() );
		}
		$fields = array();
		foreach ( self::flatten( $form['innerBlocks'] ?? array() ) as $block ) {
			$type = self::FIELD_BLOCKS[ $block['blockName'] ?? '' ] ?? null;
			if ( null === $type ) {
				continue;
			}
			$attrs    = array_merge( self::$defaults[ $block['blockName'] ] ?? array(), is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() );
			$fields[] = array(
				'key'      => is_string( $attrs['fieldKey'] ?? null ) ? $attrs['fieldKey'] : '',
				'type'     => $type,
				'label'    => is_string( $attrs['label'] ?? null ) ? $attrs['label'] : '',
				// Consent is required unless the editor turns that off.
				'required' => 'consent' === $type ? false !== ( $attrs['required'] ?? true ) : true === ( $attrs['required'] ?? false ),
				'options'  => array_values( array_filter( (array) ( $attrs['options'] ?? array() ), 'is_string' ) ),
				'multiple' => true === ( $attrs['multiple'] ?? false ),
				'text'     => is_string( $attrs['text'] ?? null ) ? $attrs['text'] : '',
				// Date only: the dates allowed (FR-23).
				'range'    => 'date' === $type ? DateRange::from_attributes( $attrs ) : null,
				// Hidden only: the value sent with each lead.
				'value'    => 'hidden' === $type && is_string( $attrs['value'] ?? null ) ? mb_substr( $attrs['value'], 0, 200 ) : '',
				// Consent only: contact (default), email or sms (BR-44).
				'purpose'  => 'consent' === $type && in_array( $attrs['purpose'] ?? null, self::PURPOSES, true ) ? $attrs['purpose'] : self::PURPOSES[0],
			);
		}//end foreach
		return new self( true, $fields );
	}

	/**
	 * Whether a field key follows BR-45: letters, digits and underscore, starting with a letter.
	 *
	 * @param string $key Field key.
	 */
	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key );
	}

	/**
	 * Suggest a field key from a label (BR-45).
	 *
	 * @param string $label Field label.
	 */
	public static function suggest_key( string $label ): string {
		$key = strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', $label ), '_' ) );
		if ( '' === $key || ! ctype_alpha( $key[0] ) ) {
			$key = 'field_' . $key;
		}
		return substr( rtrim( $key, '_' ), 0, 64 );
	}

	/**
	 * Problems with the form, for pre-publish checks (FR-32): missing, invalid or duplicate keys.
	 *
	 * @return array<int, array{id: string, message: string}>
	 */
	public function problems(): array {
		$problems = array();
		if ( $this->has_form && array() === $this->fields ) {
			$problems[] = array(
				'id'      => 'form_no_fields',
				'message' => 'The form has no fields.',
			);
		}
		$seen     = array();
		$purposes = array();
		foreach ( $this->fields as $field ) {
			if ( '' === $field['key'] ) {
				$problems[] = array(
					'id'      => 'form_missing_key',
					'message' => sprintf( 'The field "%s" needs a field key.', '' !== $field['label'] ? $field['label'] : $field['type'] ),
				);
			} elseif ( ! self::valid_key( $field['key'] ) ) {
				$problems[] = array(
					'id'      => 'form_invalid_key',
					'message' => sprintf( 'The field key "%s" can only use letters, digits and underscores, starting with a letter.', $field['key'] ),
				);
			} elseif ( isset( $seen[ strtolower( $field['key'] ) ] ) ) {
				$problems[] = array(
					'id'      => 'form_duplicate_key',
					'message' => sprintf( 'Two fields use the key "%s".', $field['key'] ),
				);
			}
			$seen[ strtolower( $field['key'] ) ] = true;
			if ( 'consent' === $field['type'] ) {
				if ( isset( $purposes[ $field['purpose'] ] ) ) {
					$problems[] = array(
						'id'      => 'form_duplicate_consent',
						'message' => 'Two Consent blocks have the same purpose. Give each one a different purpose.',
					);
				}
				$purposes[ $field['purpose'] ] = true;
			}
		}//end foreach
		return $problems;
	}

	/**
	 * Each Consent block's answer and exact text, keyed by purpose (FR-23, BR-44).
	 *
	 * @param array<string, mixed> $values Clean submitted values by field key.
	 * @return array<string, array{given: bool, text: string}>
	 */
	public function consents( array $values ): array {
		$out = array();
		foreach ( $this->fields as $field ) {
			if ( 'consent' === $field['type'] && ! isset( $out[ $field['purpose'] ] ) ) {
				$out[ $field['purpose'] ] = array(
					'given' => true === ( $values[ $field['key'] ] ?? false ),
					'text'  => self::plain( $field['text'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Text without tags and with single spaces.
	 *
	 * @param string $text Text, possibly with inline formatting.
	 */
	private static function plain( string $text ): string {
		return trim(
			(string) preg_replace(
				'/\s+/',
				' ',
				strip_tags( $text ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free class; only plain text is kept.
			)
		);
	}

	/**
	 * The consent text shown to the visitor, from the version (FR-23).
	 */
	public function consent_text(): string {
		foreach ( $this->fields as $field ) {
			if ( 'consent' === $field['type'] ) {
				return trim(
					(string) preg_replace(
						'/\s+/',
						' ',
						strip_tags( $field['text'] ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free class; only plain text is kept.
					)
				);
			}
		}
		return '';
	}

	/**
	 * The first form block, searching nested blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null
	 */
	private static function find_form( array $blocks ): ?array {
		foreach ( self::flatten( $blocks ) as $block ) {
			if ( 'campaign-bandit/form' === ( $block['blockName'] ?? '' ) ) {
				return $block;
			}
		}
		return null;
	}

	/**
	 * Blocks and their inner blocks, depth first.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$out[] = $block;
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::flatten( $block['innerBlocks'] ) );
			}
		}
		return $out;
	}
}
