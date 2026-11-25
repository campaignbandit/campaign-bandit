<?php
/**
 * A versioned set of custom tables.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Database;

/**
 * Implemented by the free plugin's schema and the add-on's schema.
 */
interface SchemaDefinition {

	/**
	 * Schema version. Bump it whenever a table definition changes.
	 */
	public function version(): int;

	/**
	 * Option that stores the installed schema version.
	 */
	public function option_name(): string;

	/**
	 * CREATE TABLE statements in dbDelta format, keyed by full table name.
	 *
	 * @param string $prefix          Database table prefix.
	 * @param string $charset_collate Charset and collation clause.
	 * @return array<string, string>
	 */
	public function tables( string $prefix, string $charset_collate ): array;
}
