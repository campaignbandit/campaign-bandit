<?php
/**
 * A lead's consent by purpose (BR-44): contact about this request, email marketing,
 * text messages. Each entry holds whether it was given and the exact text shown.
 *
 * Leads stored before purposes existed have a single consent text; it reads as the
 * contact purpose.
 *
 * WordPress-free, so the rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Leads;

use CampaignBandit\Forms\FormSchema;

/**
 * Consent by purpose.
 */
final class Consents {

	/**
	 * A clean consent map from untrusted input (a connected site, a stored row).
	 *
	 * @param mixed $raw Map of purpose => {given, text}.
	 * @return array<string, array{given: bool, text: string}>
	 */
	public static function clean( $raw ): array {
		$out = array();
		foreach ( FormSchema::PURPOSES as $purpose ) {
			$entry = is_array( $raw ) ? ( $raw[ $purpose ] ?? null ) : null;
			if ( is_array( $entry ) ) {
				$text            = is_string( $entry['text'] ?? null ) ? $entry['text'] : '';
				$out[ $purpose ] = array(
					'given' => true === ( $entry['given'] ?? false ),
					'text'  => trim( mb_substr( (string) preg_replace( '/<[^>]*>|[\x00-\x1F\x7F]/u', '', $text ), 0, 2000 ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * A lead row's consents. A row without the consent column (stored before purposes)
	 * gives its single consent text as the contact purpose, given when the form's
	 * consent box was ticked (consent is the only true/false field value, BR-44).
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array<string, array{given: bool, text: string}>
	 */
	public static function for_lead( array $lead ): array {
		if ( is_string( $lead['consent'] ?? null ) && '' !== $lead['consent'] ) {
			return self::clean( json_decode( $lead['consent'], true ) );
		}
		$text = trim( (string) ( $lead['consent_text'] ?? '' ) );
		if ( '' === $text ) {
			return array();
		}
		$fields = json_decode( (string) ( $lead['fields'] ?? '' ), true );
		$given  = is_array( $fields ) && in_array( true, $fields, true );
		return array(
			'contact' => array(
				'given' => $given,
				'text'  => $text,
			),
		);
	}
}
