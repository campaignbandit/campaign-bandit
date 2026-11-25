<?php
/**
 * Validates submitted values against the published form's fields (FR-23).
 *
 * WordPress-free. Only fields the version defines are kept; values are trimmed and
 * length-limited; email and phone formats are checked.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Forms;

/**
 * Turns raw values into clean field values, or field errors.
 */
final class SubmissionValidator {

	public const MAX_LENGTH = 2000;

	/**
	 * Validate.
	 *
	 * @param FormSchema            $schema Published form.
	 * @param array<string, mixed>  $values Submitted values by field key.
	 * @param string                $today  Today in the site's timezone, `YYYY-MM-DD`, for Date fields.
	 * @param array<string, string> $hidden This site's own Hidden field values (BR-21).
	 * @return array{fields: array<string, string|array<int, string>|bool>, errors: array<string, string>}
	 */
	public static function validate( FormSchema $schema, array $values, string $today = '', array $hidden = array() ): array {
		$today  = DateRange::valid( $today ) ? $today : gmdate( 'Y-m-d' );
		$fields = array();
		$errors = array();

		foreach ( $schema->fields as $field ) {
			$key = $field['key'];
			$raw = $values[ $key ] ?? null;

			switch ( $field['type'] ) {
				case 'consent':
					$value = true === $raw || 'true' === $raw || '1' === $raw || 1 === $raw;
					if ( $field['required'] && ! $value ) {
						$errors[ $key ] = 'required';
					}
					$fields[ $key ] = $value;
					break;

				case 'hidden':
					// Never from the browser: the published value, or this site's own.
					$fields[ $key ] = isset( $hidden[ $key ] ) && is_string( $hidden[ $key ] ) ? mb_substr( $hidden[ $key ], 0, 200 ) : (string) $field['value'];
					break;

				case 'choice':
					$picked = array_values(
						array_intersect(
							$field['options'],
							array_map( 'strval', array_filter( (array) $raw, 'is_scalar' ) )
						)
					);
					if ( ! $field['multiple'] ) {
						$picked = array_slice( $picked, 0, 1 );
					}
					if ( $field['required'] && array() === $picked ) {
						$errors[ $key ] = 'required';
					}
					$fields[ $key ] = $field['multiple'] ? $picked : ( $picked[0] ?? '' );
					break;

				default:
					$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
					$value = mb_substr( $value, 0, self::MAX_LENGTH );
					if ( '' === $value ) {
						if ( $field['required'] ) {
							$errors[ $key ] = 'required';
						}
					} elseif ( 'email' === $field['type'] && ! self::is_email( $value ) ) {
						$errors[ $key ] = 'email';
					} elseif ( 'phone' === $field['type'] && ! self::is_phone( $value ) ) {
						$errors[ $key ] = 'phone';
					} elseif ( 'date' === $field['type'] ) {
						$problem = DateRange::error( $value, (array) $field['range'], $today );
						if ( null !== $problem ) {
							$errors[ $key ] = $problem;
						}
					}
					$fields[ $key ] = $value;
			}//end switch
		}//end foreach

		return array(
			'fields' => $fields,
			'errors' => $errors,
		);
	}

	/**
	 * A plausible email address: one @, a dot in the domain, no spaces.
	 *
	 * @param string $value Value.
	 */
	public static function is_email( string $value ): bool {
		return strlen( $value ) <= 254 && 1 === preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $value );
	}

	/**
	 * A plausible phone number: digits with spaces, +, -, dots or brackets, 6 to 20 digits.
	 *
	 * @param string $value Value.
	 */
	public static function is_phone( string $value ): bool {
		$digits = (int) preg_match_all( '/\d/', $value );
		return 1 === preg_match( '/^[0-9+\-().\s]+$/', $value ) && $digits >= 6 && $digits <= 20;
	}
}
