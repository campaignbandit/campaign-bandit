<?php
/**
 * Image weight check (BR-25): a warning when one image is over 200 KB or a campaign's
 * images together are over 500 KB. Heavy images slow the page the campaign shows on.
 *
 * WordPress-free: finds images in parsed blocks; the caller measures the files.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

/**
 * Image weight.
 */
final class ImageWeight {

	/** Most one image may weigh (BR-25). */
	public const MAX_IMAGE = 200 * 1024;

	/** Most a campaign's images may weigh together (BR-25). */
	public const MAX_TOTAL = 500 * 1024;

	/**
	 * Images a campaign shows: Image, Cover and Media & Text blocks, and background
	 * images on any block. Each image once.
	 *
	 * @param array<int, array<string, mixed>> $flat Every block, inner blocks included.
	 * @return array<int, array{id: int, url: string}>
	 */
	public static function images( array $flat ): array {
		$found = array();
		foreach ( $flat as $block ) {
			$attrs = (array) ( $block['attrs'] ?? array() );
			switch ( $block['blockName'] ?? '' ) {
				case 'core/image':
					$url = (string) ( $attrs['url'] ?? '' );
					if ( '' === $url && 1 === preg_match( '/<img[^>]+src="([^"]+)"/', (string) ( $block['innerHTML'] ?? '' ), $m ) ) {
						$url = html_entity_decode( $m[1] );
					}
					$found[] = array( (int) ( $attrs['id'] ?? 0 ), $url );
					break;
				case 'core/cover':
					$found[] = array( (int) ( $attrs['id'] ?? 0 ), (string) ( $attrs['url'] ?? '' ) );
					break;
				case 'core/media-text':
					$found[] = array( (int) ( $attrs['mediaId'] ?? 0 ), (string) ( $attrs['mediaUrl'] ?? '' ) );
					break;
			}
			$background = $attrs['style']['background']['backgroundImage'] ?? null;
			if ( is_array( $background ) ) {
				$found[] = array( (int) ( $background['id'] ?? 0 ), (string) ( $background['url'] ?? '' ) );
			}
		}//end foreach
		$out = array();
		foreach ( $found as [ $id, $url ] ) {
			if ( $id > 0 || '' !== $url ) {
				$out[ $id . '|' . $url ] = array(
					'id'  => max( 0, $id ),
					'url' => $url,
				);
			}
		}
		return array_values( $out );
	}

	/**
	 * What's too heavy, from the measured files. Images that couldn't be measured
	 * (on another site, say) are left out.
	 *
	 * @param array<int, array{name: string, bytes: int}> $files Measured images.
	 * @return array{heavy: array<int, array{name: string, bytes: int}>, total: int, over_total: bool}
	 */
	public static function check( array $files ): array {
		$heavy = array_values( array_filter( $files, static fn ( array $f ): bool => $f['bytes'] > self::MAX_IMAGE ) );
		usort( $heavy, static fn ( array $a, array $b ): int => $b['bytes'] <=> $a['bytes'] );
		$total = array_sum( array_column( $files, 'bytes' ) );
		return array(
			'heavy'      => $heavy,
			'total'      => (int) $total,
			'over_total' => $total > self::MAX_TOTAL,
		);
	}

	/**
	 * Bytes as whole kilobytes, the way the rule is written: "340 KB".
	 *
	 * @param int $bytes Bytes.
	 */
	public static function kb( int $bytes ): int {
		return (int) ceil( $bytes / 1024 );
	}
}
