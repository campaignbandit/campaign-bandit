<?php
/**
 * Plugin activation.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Lifecycle;

use CampaignBandit\Database\Migrator;
use CampaignBandit\Database\Schema;
use CampaignBandit\Security\Roles;

/**
 * Creates tables and roles. Adds no visitor-facing output (FR-01).
 */
final class Activator {

	/**
	 * Run on activation.
	 */
	public static function activate(): void {
		Migrator::maybe_upgrade( new Schema() );
		Roles::install();
	}
}
