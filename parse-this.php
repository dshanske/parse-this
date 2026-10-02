<?php
/**
 * Plugin Name: Parse This
 * Plugin URI: https://github.com/dshanske/parse-this
 * Description: Turns URLs into structured jf2 data from microformats2, JSON-LD, meta tags and RSS, Atom and JSON feeds.
 * Version: 1.0.1
 * Author: David Shanske
 * Author URI: https://david.shanske.com
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: parse-this
 *
 * @package Parse_This
 */

if ( ! function_exists( 'parse_this_loader' ) ) {
	/**
	 * Loads Parse This.
	 *
	 * Runs on plugins_loaded at priority 9, so that the standalone plugin loads
	 * ahead of copies bundled in other plugins (Post Kinds loads its copy at
	 * priority 11). Functions are guarded by function_exists() and the first
	 * autoloader registered serves the classes, so whichever copy loads first
	 * wins.
	 *
	 * @since 1.0.0
	 */
	function parse_this_loader() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/autoload.php';

		// Functions not available in earlier versions of WordPress.
		require_once plugin_dir_path( __FILE__ ) . 'includes/compat-functions.php';

		require_once plugin_dir_path( __FILE__ ) . 'includes/functions.php';
		// Parse This REST endpoint.
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-rest-parse-this.php';
	}
	add_action( 'plugins_loaded', 'parse_this_loader', 9 );
}
