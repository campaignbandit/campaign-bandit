<?php
/**
 * Plugin Name:       Campaign Bandit
 * Plugin URI:        https://github.com/campaignbandit/campaign-bandit
 * Description:       Conversion campaigns (popups and inline forms) built in the block editor, with leads stored in WordPress.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Campaign Bandit
 * Author URI:        https://campaignbandit.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       campaign-bandit
 *
 * @package CampaignBandit
 */

defined( 'ABSPATH' ) || exit;

define( 'CAMPAIGNBANDIT_VERSION', '1.0.0' );
define( 'CAMPAIGNBANDIT_FILE', __FILE__ );
define( 'CAMPAIGNBANDIT_DIR', __DIR__ );

require_once __DIR__ . '/src/Autoloader.php';
\CampaignBandit\Autoloader::register( 'CampaignBandit\\', __DIR__ . '/src/' );

register_activation_hook( __FILE__, array( \CampaignBandit\Lifecycle\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \CampaignBandit\Lifecycle\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \CampaignBandit\Plugin::class, 'boot' ), 5 );
