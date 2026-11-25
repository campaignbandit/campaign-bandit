<?php
/**
 * Server rendering for the form blocks (FR-23): accessible labels, required fields
 * marked in text, error slots linked to their fields, a spam trap (BR-46), and the
 * success state the editor designed.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Forms;

/**
 * Registers and renders Form, Text, Email, Phone, Choice and Consent.
 */
final class FormBlocks {

	public const BLOCKS = array( 'form', 'text', 'long-text', 'email', 'phone', 'date', 'choice', 'consent', 'hidden', 'success' );

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ) );
	}

	/**
	 * Register the blocks from their built metadata.
	 */
	public static function register_blocks(): void {
		$defaults = array();
		foreach ( self::BLOCKS as $name ) {
			$dir = CAMPAIGNBANDIT_DIR . '/build/blocks/' . $name;
			if ( file_exists( $dir . '/block.json' ) ) {
				$type = register_block_type( $dir, array( 'render_callback' => array( self::class, 'render_' . str_replace( '-', '_', $name ) ) ) );
				if ( $type ) {
					$defaults[ $type->name ] = array_map(
						static fn ( $schema ) => is_array( $schema ) ? ( $schema['default'] ?? null ) : null,
						(array) $type->attributes
					);
				}
			}
		}
		FormSchema::set_defaults( $defaults );
	}

	/**
	 * Form: fields, spam trap, submit button, status line, success message.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Rendered inner blocks (the fields).
	 */
	public static function render_form( array $attributes, string $content ): string {
		$submit  = (string) ( $attributes['submitLabel'] ?? '' );
		$success = (string) ( $attributes['successMessage'] ?? '' );
		// One row: fields and button side by side, stacking on small screens.
		$layout = 'row' === ( $attributes['layout'] ?? '' ) ? ' cbandit-form--row' : '';
		$style  = '';
		foreach ( array(
			'background-color' => $attributes['buttonBackground'] ?? '',
			'color'            => $attributes['buttonText'] ?? '',
		) as $property => $color ) {
			$color  = sanitize_hex_color( is_string( $color ) ? $color : '' );
			$style .= null !== $color && '' !== $color ? $property . ':' . $color . ';' : '';
		}

		return sprintf(
			'<div class="cbandit-form-wrap"><form class="cbandit-form%5$s" data-cbandit-form novalidate>%1$s'
			. '<p class="cbandit-hp" aria-hidden="true" style="position:absolute;left:-9999px"><label>%2$s <input type="text" name="cbandit_hp" value="" tabindex="-1" autocomplete="off"></label></p>'
			. '<button type="submit" class="wp-element-button cbandit-submit"%6$s>%3$s</button>'
			. '<p class="cbandit-form-status" role="status" aria-live="polite"></p></form>'
			. '<div class="cbandit-success" data-cbandit-success role="status" hidden>%4$s</div></div>',
			$content,
			esc_html__( 'Leave this field empty', 'campaign-bandit' ),
			esc_html( '' !== $submit ? $submit : __( 'Submit', 'campaign-bandit' ) ),
			wp_kses_post( '' !== $success ? wpautop( $success ) : '<p>' . __( 'Thank you!', 'campaign-bandit' ) . '</p>' ),
			$layout,
			'' !== $style ? ' style="' . esc_attr( $style ) . '"' : ''
		);
	}

	/**
	 * Success screen: hidden until the form is sent, then shown in place of the blocks
	 * beside it and announced. Without one, the form's message replaces the form.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Rendered inner blocks.
	 */
	public static function render_success( array $attributes, string $content ): string {
		unset( $attributes );
		return sprintf(
			'<div %1$s hidden data-cbandit-after>%2$s</div>',
			get_block_wrapper_attributes(
				array(
					'class'    => 'cbandit-after',
					'role'     => 'status',
					'tabindex' => '-1',
				)
			),
			$content
		);
	}

	/**
	 * Text field.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_text( array $attributes ): string {
		return self::input( $attributes, 'text', 'text', 'name' );
	}

	/**
	 * Email field.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_email( array $attributes ): string {
		return self::input( $attributes, 'email', 'email', 'email' );
	}

	/**
	 * Long text: a labelled textarea, capped at the length the server keeps.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_long_text( array $attributes ): string {
		$key      = self::key( $attributes );
		$id       = wp_unique_id( 'cbandit-f-' );
		$required = true === ( $attributes['required'] ?? false );
		return sprintf(
			'<div class="cbandit-field cbandit-field--long_text"><label for="%1$s"%7$s>%2$s</label><textarea id="%1$s" name="%3$s" data-field-key="%3$s" data-field-type="long_text" rows="4" maxlength="%4$d" placeholder="%5$s"%6$s aria-describedby="%1$s-error"></textarea><p class="cbandit-error" id="%1$s-error" hidden></p></div>',
			esc_attr( $id ),
			self::label_text( $attributes, $required ),
			esc_attr( $key ),
			SubmissionValidator::MAX_LENGTH,
			esc_attr( (string) ( $attributes['placeholder'] ?? '' ) ),
			$required ? ' required aria-required="true"' : '',
			self::label_class( $attributes )
		);
	}

	/**
	 * Phone field.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_phone( array $attributes ): string {
		return self::input( $attributes, 'phone', 'tel', 'tel' );
	}

	/**
	 * Date field: the browser's own date picker. The dates allowed travel as data
	 * attributes; the runtime turns them into min and max for the visitor's today.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_date( array $attributes ): string {
		$range = DateRange::from_attributes( $attributes );
		$html  = self::input( $attributes, 'date', 'date', 'off' );
		$data  = sprintf(
			' data-date-range="%1$s" data-date-days="%2$d" data-date-from="%3$s" data-date-to="%4$s"',
			esc_attr( $range['type'] ),
			$range['days'],
			esc_attr( $range['from'] ),
			esc_attr( $range['to'] )
		);
		return (string) preg_replace( '/<input /', '<input' . $data . ' ', $html, 1 );
	}

	/**
	 * Hidden field: nothing on the page. The server adds its value to each lead from
	 * the published version, or from this site's own value (BR-21).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_hidden( array $attributes ): string {
		unset( $attributes );
		return '';
	}

	/**
	 * Choice: radio buttons (single) or checkboxes (multiple), in a fieldset.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_choice( array $attributes ): string {
		$key      = self::key( $attributes );
		$id       = wp_unique_id( 'cbandit-f-' );
		$required = true === ( $attributes['required'] ?? false );
		$multiple = true === ( $attributes['multiple'] ?? false );
		if ( ! $multiple && 'dropdown' === ( $attributes['display'] ?? 'options' ) ) {
			return self::dropdown( $attributes, $key, $id, $required );
		}
		$chips   = 'chips' === ( $attributes['display'] ?? '' );
		$options = '';
		foreach ( (array) ( $attributes['options'] ?? array() ) as $i => $option ) {
			if ( ! is_string( $option ) || '' === $option ) {
				continue;
			}
			$options .= sprintf(
				'<label class="cbandit-option' . ( $chips ? ' cbandit-chip' : '' ) . '"><input type="%1$s" name="%2$s" value="%3$s" data-field-key="%2$s" id="%4$s"%5$s> %6$s</label>',
				$multiple ? 'checkbox' : 'radio',
				esc_attr( $key ),
				esc_attr( $option ),
				esc_attr( $id . '-' . $i ),
				$required && ! $multiple ? ' required' : '',
				esc_html( $option )
			);
		}
		return sprintf(
			'<fieldset class="cbandit-field cbandit-field--choice%6$s" data-field-key="%1$s" data-field-type="choice"%2$s aria-describedby="%3$s-error"><legend%7$s>%4$s</legend><div class="cbandit-options">%5$s</div><p class="cbandit-error" id="%3$s-error" hidden></p></fieldset>',
			esc_attr( $key ),
			$required ? ' data-required="1"' : '',
			esc_attr( $id ),
			self::label_text( $attributes, $required ),
			$options,
			$chips ? ' cbandit-field--chips' : '',
			self::label_class( $attributes )
		);
	}

	/**
	 * Single choice shown as a dropdown, with an empty first entry so nothing is
	 * chosen until the visitor picks (a required dropdown then can't pass by default).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $key        Field key.
	 * @param string               $id         Element ID.
	 * @param bool                 $required   Whether the field is required.
	 */
	private static function dropdown( array $attributes, string $key, string $id, bool $required ): string {
		$placeholder = (string) ( $attributes['placeholder'] ?? '' );
		$options     = sprintf( '<option value="">%s</option>', esc_html( '' !== $placeholder ? $placeholder : __( 'Choose…', 'campaign-bandit' ) ) );
		foreach ( (array) ( $attributes['options'] ?? array() ) as $option ) {
			if ( is_string( $option ) && '' !== $option ) {
				$options .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $option ), esc_html( $option ) );
			}
		}
		return sprintf(
			'<div class="cbandit-field cbandit-field--dropdown"><label for="%1$s"%6$s>%2$s</label><select id="%1$s" name="%3$s" data-field-key="%3$s" data-field-type="choice"%4$s aria-describedby="%1$s-error">%5$s</select><p class="cbandit-error" id="%1$s-error" hidden></p></div>',
			esc_attr( $id ),
			self::label_text( $attributes, $required ),
			esc_attr( $key ),
			$required ? ' required aria-required="true" data-required="1"' : '',
			$options,
			self::label_class( $attributes )
		);
	}

	/**
	 * Consent checkbox. Its text is what the lead stores (FR-23), from the published version.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_consent( array $attributes ): string {
		$key      = self::key( $attributes );
		$id       = wp_unique_id( 'cbandit-f-' );
		$required = false !== ( $attributes['required'] ?? true );
		return sprintf(
			'<div class="cbandit-field cbandit-field--consent"><input type="checkbox" id="%1$s" name="%2$s" value="1" data-field-key="%2$s" data-field-type="consent"%3$s aria-describedby="%1$s-error"> <label for="%1$s">%4$s%5$s</label><p class="cbandit-error" id="%1$s-error" hidden></p></div>',
			esc_attr( $id ),
			esc_attr( $key ),
			$required ? ' required aria-required="true"' : '',
			wp_kses(
				(string) ( $attributes['text'] ?? '' ),
				array(
					'a'      => array( 'href' => true ),
					'strong' => array(),
					'em'     => array(),
				)
			),
			$required ? ' <span class="cbandit-required">' . esc_html__( '(required)', 'campaign-bandit' ) . '</span>' : ''
		);
	}

	/**
	 * A labelled input with its error slot.
	 *
	 * @param array<string, mixed> $attributes   Block attributes.
	 * @param string               $field_type   Field type for the runtime.
	 * @param string               $input_type   HTML input type.
	 * @param string               $autocomplete Autocomplete hint.
	 */
	private static function input( array $attributes, string $field_type, string $input_type, string $autocomplete ): string {
		$key      = self::key( $attributes );
		$id       = wp_unique_id( 'cbandit-f-' );
		$required = true === ( $attributes['required'] ?? false );
		return sprintf(
			'<div class="cbandit-field cbandit-field--%1$s"><label for="%2$s"%9$s>%3$s</label><input type="%4$s" id="%2$s" name="%5$s" data-field-key="%5$s" data-field-type="%1$s" placeholder="%6$s" autocomplete="%7$s"%8$s aria-describedby="%2$s-error"><p class="cbandit-error" id="%2$s-error" hidden></p></div>',
			esc_attr( $field_type ),
			esc_attr( $id ),
			self::label_text( $attributes, $required ),
			esc_attr( $input_type ),
			esc_attr( $key ),
			esc_attr( (string) ( $attributes['placeholder'] ?? '' ) ),
			esc_attr( $autocomplete ),
			$required ? ' required aria-required="true"' : '',
			self::label_class( $attributes )
		);
	}

	/**
	 * A visually hidden label, when the field asks for one: it is still read out, which
	 * is enough on a single-field form (05 §6).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private static function label_class( array $attributes ): string {
		return true === ( $attributes['hideLabel'] ?? false ) ? ' class="cbandit-sr"' : '';
	}

	/**
	 * The label, with "(required)" in text, not colour alone (05 §6).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $required   Whether the field is required.
	 */
	private static function label_text( array $attributes, bool $required ): string {
		return esc_html( (string) ( $attributes['label'] ?? '' ) )
			. ( $required ? ' <span class="cbandit-required">' . esc_html__( '(required)', 'campaign-bandit' ) . '</span>' : '' );
	}

	/**
	 * The field key, or a key suggested from the label (BR-45).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private static function key( array $attributes ): string {
		$key = (string) ( $attributes['fieldKey'] ?? '' );
		return FormSchema::valid_key( $key ) ? $key : FormSchema::suggest_key( (string) ( $attributes['label'] ?? 'field' ) );
	}
}
