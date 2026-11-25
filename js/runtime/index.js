/**
 * Visitor runtime entry point (decision 0009). The configuration is printed inline
 * by PHP only while at least one deployment is live (FR-01).
 */
import { start } from './boot.js';

if ( window.campaignBanditConfig ) {
	start( window.campaignBanditConfig );
}
