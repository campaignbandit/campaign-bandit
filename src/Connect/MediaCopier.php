<?php
/**
 * Copies hub campaign images into this site's uploads (decision 0002), so visitors
 * never load anything from the hub (05 §7) and images survive a hub outage.
 *
 * Each image is downloaded once, checked against the hash the hub signed, and stored
 * under a name that starts with that hash. Copies no hub campaign uses are removed.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Downloads and tidies campaign images.
 */
final class MediaCopier {

	public const DIR = 'campaign-bandit/media';

	/**
	 * Copy a campaign's images.
	 *
	 * @param array<int, mixed> $media       Media list from the package.
	 * @param string            $hub_uploads Hub uploads base URL from the package.
	 * @return array{map: array<string, string>, failed: string[]} Hub URL => local URL, and URLs that could not be copied.
	 */
	public static function copy( array $media, string $hub_uploads ): array {
		$map    = array();
		$failed = array();
		$dir    = self::directory();
		foreach ( $media as $item ) {
			if ( ! MediaUrls::valid( $item, $hub_uploads ) ) {
				// Not ours to copy (another host, SVG, too large): left as it is.
				continue;
			}
			$name = MediaUrls::local_name( $item['url'], $item['sha256'] );
			if ( null === $dir ) {
				$failed[] = $item['url'];
				continue;
			}
			$file = $dir['path'] . '/' . $name;
			if ( ! is_file( $file ) || hash_file( 'sha256', $file ) !== $item['sha256'] ) {
				if ( ! self::download( $item['url'], $file, $item['sha256'] ) ) {
					$failed[] = $item['url'];
					continue;
				}
			}
			$map[ $item['url'] ] = $dir['url'] . '/' . $name;
		}
		return array(
			'map'    => $map,
			'failed' => $failed,
		);
	}

	/**
	 * Remove copies that no hub campaign uses any more.
	 *
	 * @param string[] $keep Local file names in use.
	 */
	public static function remove_unused( array $keep ): void {
		$dir = self::directory();
		if ( null === $dir ) {
			return;
		}
		$keep = array_flip( $keep );
		foreach ( (array) glob( $dir['path'] . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) && ! isset( $keep[ basename( $file ) ] ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Download one image, checking its hash before it is kept.
	 *
	 * @param string $url    Hub URL.
	 * @param string $file   Where to store it.
	 * @param string $sha256 Expected hash.
	 */
	private static function download( string $url, string $file, string $sha256 ): bool {
		$tmp = $file . '.' . wp_generate_password( 8, false ) . '.part';
		/** This filter is documented in src/Connect/Connector.php */
		$address = (string) apply_filters( 'campaignbandit_hub_address', $url );
		// wp_remote_get with reject_unsafe_urls, as wp_safe_remote_get would, except on
		// local and development sites, whose hub is at a private address.
		$response = wp_remote_get(
			$address,
			array(
				'timeout'             => 30,
				'redirection'         => 2,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => MediaUrls::MAX_BYTES,
				// Local development hubs are reached over plain http at a private address.
				'reject_unsafe_urls'  => ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ),
			)
		);
		$ok       = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response )
			&& is_file( $tmp ) && hash_file( 'sha256', $tmp ) === $sha256;
		if ( $ok ) {
			$ok = rename( $tmp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic move into place.
		}
		if ( is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		return $ok;
	}

	/**
	 * The media folder in uploads, created when missing.
	 *
	 * @return array{path: string, url: string}|null
	 */
	private static function directory(): ?array {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$path = $uploads['basedir'] . '/' . self::DIR;
		if ( ! wp_mkdir_p( $path ) ) {
			return null;
		}
		return array(
			'path' => $path,
			'url'  => set_url_scheme( $uploads['baseurl'] ) . '/' . self::DIR,
		);
	}
}
