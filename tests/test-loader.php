<?php
/**
 * Tests for loading Parse This.
 *
 * @package Parse_This
 */

/**
 * Loader tests.
 */
class Loader_Test extends WP_UnitTestCase {

	/**
	 * The loaded copy reports its version, and announces that it has loaded.
	 */
	public function test_version_and_loaded_action() {
		$this->assertTrue( defined( 'PARSE_THIS_VERSION' ) );
		$this->assertTrue( version_compare( PARSE_THIS_VERSION, '2.0.0', '>=' ) );
		$this->assertSame( 1, did_action( 'parse_this_loaded' ) );
		$this->assertTrue( class_exists( 'ParseThis\Parser' ) );
	}

	/**
	 * The plugin header, the readme's stable tag and PARSE_THIS_VERSION agree, so a release bumps all three.
	 */
	public function test_versions_agree() {
		$root   = dirname( __DIR__ );
		$plugin = get_file_data( $root . '/parse-this.php', array( 'Version' => 'Version' ) );
		$readme = get_file_data( $root . '/readme.txt', array( 'Stable' => 'Stable tag' ) );
		$this->assertSame( PARSE_THIS_VERSION, $plugin['Version'] );
		$this->assertSame( PARSE_THIS_VERSION, $readme['Stable'] );
	}
}
