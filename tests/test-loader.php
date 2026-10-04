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
}
