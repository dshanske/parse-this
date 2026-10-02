<?php
/**
 * Tests for the deprecated global names in includes/aliases.php.
 *
 * Post Kinds and Yarns call these names. If one stops resolving, those
 * plugins break when the standalone plugin is active.
 *
 * @package Parse_This
 */

/**
 * Deprecated alias tests.
 */
class Aliases_Test extends WP_UnitTestCase {

	/**
	 * Old global class names and the classes they must resolve to.
	 *
	 * @return array[]
	 */
	public function class_alias_provider() {
		return array(
			array( 'Parse_This', 'ParseThis\Parser' ),
			array( 'Parse_This_Discovery', 'ParseThis\Discovery' ),
			array( 'Parse_This_MF2', 'ParseThis\MF2' ),
			array( 'Parse_This_MF2_Utils', 'ParseThis\MF2_Utils' ),
			array( 'REST_Parse_This', 'ParseThis\REST_Endpoint' ),
		);
	}

	/**
	 * Each old class name resolves to its namespaced class.
	 *
	 * @dataProvider class_alias_provider
	 *
	 * @param string $old Old global class name.
	 * @param string $new Namespaced class name.
	 */
	public function test_class_alias( $old, $new ) {
		$this->assertTrue( class_exists( $old ) );
		$reflection = new ReflectionClass( $old );
		$this->assertSame( $new, $reflection->getName() );
	}

	/**
	 * Each old global helper exists and returns what the namespaced one does.
	 */
	public function test_function_aliases() {
		$mf2 = array(
			'type'       => array( 'h-entry' ),
			'properties' => array(
				'url'     => array( 'https://example.com/' ),
				'like-of' => array( 'https://example.org/' ),
			),
		);
		$jf2 = ParseThis\mf2_to_jf2( $mf2 );

		$this->assertSame( $jf2, mf2_to_jf2( $mf2 ) );
		$this->assertSame( ParseThis\jf2_to_mf2( $jf2 ), jf2_to_mf2( $jf2 ) );
		$this->assertSame( 'like', post_type_discovery( $jf2 ) );
		$this->assertSame( 'PT1H2M5S', seconds_to_iso8601( 3725 ) );
		$this->assertInstanceOf( 'DOMDocument', pt_load_domdocument( '<p>Test</p>' ) );
	}

	/**
	 * The calls Post Kinds and Yarns make on the aliased classes work.
	 */
	public function test_aliased_class_usage() {
		$mf2 = array(
			'type'       => array( 'h-entry' ),
			'properties' => array( 'url' => array( 'https://example.com/' ) ),
		);
		$this->assertTrue( Parse_This_MF2_Utils::is_microformat( $mf2 ) );
		$this->assertSame( 'https://example.com/', Parse_This_MF2::get_plaintext( $mf2, 'url' ) );

		$parse = new Parse_This();
		$parse->set( '<div class="h-entry"><a class="u-url" href="https://example.com/">Test</a></div>', 'https://example.com/' );
		$parse->parse();
		$this->assertSame( 'https://example.com/', $parse->get()['url'] );
	}

	/**
	 * The REST route is registered once, by the namespaced endpoint.
	 */
	public function test_rest_route_registered_once() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/parse-this/1.0/parse', $routes );
		$this->assertCount( 1, $routes['/parse-this/1.0/parse'] );
	}
}
