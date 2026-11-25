<?php
/**
 * Minimal PSR-4 autoloader, so the plugin ships without a vendor folder.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit;

/**
 * Registers a PSR-4 prefix for a base directory.
 */
final class Autoloader {

	/**
	 * Register an autoloader for one namespace prefix.
	 *
	 * @param string $prefix   Namespace prefix, with trailing backslash.
	 * @param string $base_dir Directory holding the classes, with trailing slash.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir ): void {
				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		);
	}
}
