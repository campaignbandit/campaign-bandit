<?php
/**
 * Installs and upgrades a schema with dbDelta.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Database;

/**
 * Runs on activation and on every load, because activation hooks don't run on plugin updates.
 */
final class Migrator {

	/**
	 * Install or upgrade the schema if the stored version is older.
	 *
	 * @param SchemaDefinition $schema Schema to apply.
	 * @return bool Whether an upgrade ran.
	 */
	public static function maybe_upgrade( SchemaDefinition $schema ): bool {
		if ( (int) get_option( $schema->option_name(), 0 ) >= $schema->version() ) {
			return false;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $schema->tables( $wpdb->prefix, $wpdb->get_charset_collate() ) as $table => $sql ) {
			dbDelta( $sql );
			self::drop_old_indexes( (string) $table, self::declared_indexes( $sql ) );
		}

		update_option( $schema->option_name(), $schema->version(), false );
		return true;
	}

	/**
	 * Index names a CREATE TABLE statement declares (PRIMARY not included).
	 *
	 * @param string $sql CREATE TABLE statement in dbDelta format.
	 * @return string[]
	 */
	public static function declared_indexes( string $sql ): array {
		preg_match_all( '/^\s*(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?(?:KEY|INDEX)\s+`?(\w+)`?\s*\(/mi', $sql, $matches );
		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Drop indexes a table has that its definition no longer declares. dbDelta adds
	 * indexes but never removes them, so an index replaced by a wider one would stay and
	 * keep its old rule: an old unique key on metrics merged rows the new one keeps apart.
	 *
	 * @param string   $table    Full table name.
	 * @param string[] $declared Index names the definition declares.
	 */
	private static function drop_old_indexes( string $table, array $declared ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$have = array_unique( array_column( is_array( $rows ) ? $rows : array(), 'Key_name' ) );
		foreach ( array_diff( $have, $declared, array( 'PRIMARY' ) ) as $index ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, $index ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Drop every table in the schema and forget its version.
	 *
	 * @param SchemaDefinition $schema Schema to remove.
	 */
	public static function drop( SchemaDefinition $schema ): void {
		global $wpdb;

		foreach ( array_keys( $schema->tables( $wpdb->prefix, '' ) ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		delete_option( $schema->option_name() );
	}
}
