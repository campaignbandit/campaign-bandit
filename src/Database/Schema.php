<?php
/**
 * Tables owned by the free plugin (decision 0006).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Database;

/**
 * Versions, deployments, leads, daily metrics and the audit trail.
 * Needed on every install, including connected sites. `site_id` 0 means "this install".
 */
final class Schema implements SchemaDefinition {

	public const VERSIONS    = 'campaignbandit_versions';
	public const DEPLOYMENTS = 'campaignbandit_deployments';
	public const LEADS       = 'campaignbandit_leads';
	public const METRICS     = 'campaignbandit_metrics_daily';
	public const AUDIT       = 'campaignbandit_audit_events';

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 7;
	}

	/**
	 * {@inheritDoc}
	 */
	public function option_name(): string {
		return 'campaignbandit_db_version';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $prefix          Database table prefix.
	 * @param string $charset_collate Charset and collation clause.
	 * @return array<string, string>
	 */
	public function tables( string $prefix, string $charset_collate ): array {
		$versions    = $prefix . self::VERSIONS;
		$deployments = $prefix . self::DEPLOYMENTS;
		$leads       = $prefix . self::LEADS;
		$metrics     = $prefix . self::METRICS;
		$audit       = $prefix . self::AUDIT;

		return array(
			$versions    => "CREATE TABLE {$versions} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
campaign_uid char(26) NOT NULL,
number int(10) unsigned NOT NULL,
content longtext NOT NULL,
settings longtext NOT NULL,
change_summary text NULL,
published_by bigint(20) unsigned NOT NULL DEFAULT 0,
published_by_name varchar(191) NOT NULL DEFAULT '',
published_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY campaign_number (campaign_uid,number)
) {$charset_collate};",

			$deployments => "CREATE TABLE {$deployments} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
campaign_uid char(26) NOT NULL,
site_id bigint(20) unsigned NOT NULL DEFAULT 0,
version_id bigint(20) unsigned NOT NULL,
version_number int(10) unsigned NOT NULL,
state varchar(20) NOT NULL,
overrides longtext NULL,
destinations longtext NULL,
scheduled_version_number int(10) unsigned NULL,
scheduled_at datetime NULL,
shows_reset_at datetime NULL,
published_at datetime NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY campaign_site (campaign_uid,site_id),
KEY site_state (site_id,state)
) {$charset_collate};",

			$leads       => "CREATE TABLE {$leads} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
uid char(26) NOT NULL,
site_id bigint(20) unsigned NOT NULL DEFAULT 0,
campaign_uid char(26) NOT NULL,
version_number int(10) unsigned NOT NULL,
deployment_id bigint(20) unsigned NULL,
submitted_at datetime NOT NULL,
email varchar(191) NULL,
name varchar(191) NULL,
fields longtext NOT NULL,
consent_text text NULL,
consent text NULL,
page_url text NOT NULL,
utm text NULL,
source text NULL,
explanation longtext NULL,
status varchar(20) NOT NULL DEFAULT 'stored_only',
forward_status varchar(20) NOT NULL DEFAULT 'none',
forward_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
next_forward_at datetime NULL,
forwarded_at datetime NULL,
is_test tinyint(1) NOT NULL DEFAULT 0,
delivered_by_site tinyint(1) NOT NULL DEFAULT 0,
notified varchar(10) NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uid (uid),
KEY site_submitted (site_id,submitted_at),
KEY campaign (campaign_uid),
KEY email (email),
KEY status (status),
KEY forward (forward_status,next_forward_at)
) {$charset_collate};",

			$metrics     => "CREATE TABLE {$metrics} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
campaign_uid char(26) NOT NULL,
site_id bigint(20) unsigned NOT NULL DEFAULT 0,
version_number int(10) unsigned NOT NULL,
day date NOT NULL,
device varchar(10) NOT NULL DEFAULT '',
page_group varchar(64) NOT NULL DEFAULT '/',
views int(10) unsigned NOT NULL DEFAULT 0,
conversions int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY metric (campaign_uid,site_id,version_number,day,device,page_group),
KEY day (day)
) {$charset_collate};",

			$audit       => "CREATE TABLE {$audit} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
occurred_at datetime NOT NULL,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
user_name varchar(191) NOT NULL DEFAULT '',
action varchar(64) NOT NULL,
object_type varchar(32) NOT NULL,
object_id varchar(64) NOT NULL DEFAULT '',
channel varchar(16) NOT NULL DEFAULT 'screen',
details longtext NULL,
PRIMARY KEY  (id),
KEY occurred (occurred_at),
KEY object (object_type,object_id)
) {$charset_collate};",
		);
	}
}
