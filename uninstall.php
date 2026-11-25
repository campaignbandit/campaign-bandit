<?php
/**
 * Uninstall entry point.
 *
 * @package CampaignBandit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';
\CampaignBandit\Autoloader::register( 'CampaignBandit\\', __DIR__ . '/src/' );

\CampaignBandit\Lifecycle\Uninstaller::uninstall();
