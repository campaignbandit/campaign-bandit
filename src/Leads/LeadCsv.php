<?php
/**
 * CSV rows for lead export (FR-50, FR-54).
 *
 * WordPress-free, so the column rules and the formula guard are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Leads;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Builds the header and rows of a lead export.
 */
final class LeadCsv {

	private const UTM = array( 'source', 'medium', 'campaign', 'term', 'content' );

	/**
	 * Lead source columns (BR-47), after the UTM columns.
	 */
	private const SOURCE = array(
		'first_source',
		'first_medium',
		'first_campaign',
		'first_referrer',
		'first_landing_page',
		'last_source',
		'last_medium',
		'last_campaign',
		'last_referrer',
		'last_landing_page',
		'gclid',
		'gbraid',
		'wbraid',
		'fbclid',
		'msclkid',
		'visits',
	);

	/**
	 * Form field keys across leads, in first-seen order.
	 *
	 * @param iterable<array<string, mixed>> $leads Lead rows (only `fields` is read).
	 * @return string[]
	 */
	public static function field_keys( iterable $leads ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- a generator of rows.
		$keys = array();
		foreach ( $leads as $lead ) {
			foreach ( array_keys( self::decode( $lead['fields'] ?? null ) ) as $key ) {
				$keys[ (string) $key ] = true;
			}
		}
		return array_keys( $keys );
	}

	/**
	 * The header row. A form field whose key matches a fixed column (a form with a
	 * field keyed `email`, say) is named `field_<key>`, so every header is unique.
	 *
	 * @param string[] $field_keys Form field keys.
	 * @return string[]
	 */
	public static function header( array $field_keys ): array {
		$fixed = array_merge(
			array( 'lead_id', 'received', 'campaign', 'campaign_id', 'version', 'name', 'email', 'consent_text', 'page_url', 'test' ),
			array_map( static fn ( string $part ): string => 'utm_' . $part, self::UTM ),
			self::SOURCE
		);
		$names = array_map(
			static fn ( string $key ): string => self::cell( in_array( strtolower( $key ), $fixed, true ) ? 'field_' . $key : $key ),
			$field_keys
		);

		return array_merge(
			array( 'lead_id', 'received', 'campaign', 'campaign_id', 'version', 'name', 'email' ),
			$names,
			array( 'consent_text', 'page_url' ),
			array_map( static fn ( string $part ): string => 'utm_' . $part, self::UTM ),
			self::SOURCE,
			array( 'test' )
		);
	}

	/**
	 * One lead as a row matching header().
	 *
	 * @param array<string, mixed> $lead          Lead row.
	 * @param string[]             $field_keys    Form field keys.
	 * @param DateTimeZone         $site_timezone Site timezone, for the received time.
	 * @param string               $campaign_name Campaign name ('' if deleted).
	 * @return string[]
	 */
	public static function row( array $lead, array $field_keys, DateTimeZone $site_timezone, string $campaign_name ): array {
		$fields = self::decode( $lead['fields'] ?? null );
		$utm    = self::decode( $lead['utm'] ?? null );

		$row = array(
			(string) ( $lead['uid'] ?? '' ),
			self::site_time( (string) ( $lead['submitted_at'] ?? '' ), $site_timezone ),
			$campaign_name,
			(string) ( $lead['campaign_uid'] ?? '' ),
			(string) ( $lead['version_number'] ?? '' ),
			(string) ( $lead['name'] ?? '' ),
			(string) ( $lead['email'] ?? '' ),
		);
		foreach ( $field_keys as $key ) {
			$row[] = self::value( $fields[ $key ] ?? '' );
		}
		$row[] = (string) ( $lead['consent_text'] ?? '' );
		$row[] = (string) ( $lead['page_url'] ?? '' );
		foreach ( self::UTM as $part ) {
			$row[] = self::value( $utm[ $part ] ?? '' );
		}
		$source = LeadSource::from_json( $lead['source'] ?? null );
		foreach ( array( 'first', 'last' ) as $visit ) {
			$row[] = (string) ( $source[ $visit ]['utm']['source'] ?? '' );
			$row[] = (string) ( $source[ $visit ]['utm']['medium'] ?? '' );
			$row[] = (string) ( $source[ $visit ]['utm']['campaign'] ?? '' );
			$row[] = (string) ( $source[ $visit ]['referrer'] ?? '' );
			$row[] = (string) ( $source[ $visit ]['landing_page'] ?? '' );
		}
		foreach ( LeadSource::CLICK_IDS as $name ) {
			$row[] = (string) ( $source['click_ids'][ $name ] ?? '' );
		}
		$row[] = isset( $source['visits'] ) ? (string) $source['visits'] : '';
		$row[] = ! empty( $lead['is_test'] ) ? 'yes' : 'no';

		return array_map( array( self::class, 'cell' ), $row );
	}

	/**
	 * Guard a cell against spreadsheet formulas (CSV injection): text starting with
	 * =, +, -, @, a tab or a carriage return gets a leading apostrophe.
	 *
	 * @param string $value Cell text.
	 */
	public static function cell( string $value ): string {
		return '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * A form value as text: lists joined with "; ", booleans as yes or no.
	 *
	 * @param mixed $value Stored value.
	 */
	public static function value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_array( $value ) ) {
			return implode( '; ', array_map( static fn ( $item ): string => is_scalar( $item ) ? (string) $item : '', $value ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A stored UTC "Y-m-d H:i:s" as site time "Y-m-d H:i".
	 *
	 * @param string       $utc           UTC date and time.
	 * @param DateTimeZone $site_timezone Site timezone.
	 */
	public static function site_time( string $utc, DateTimeZone $site_timezone ): string {
		try {
			return ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( $site_timezone )->format( 'Y-m-d H:i' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * A JSON object column as an array.
	 *
	 * @param mixed $json Stored JSON.
	 * @return array<mixed>
	 */
	private static function decode( $json ): array {
		$value = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $value ) ? $value : array();
	}
}
