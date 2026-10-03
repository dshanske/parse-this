<?php
/**
 * Tests for the parse-this/1.0/parse REST route.
 *
 * @package Parse_This
 */

/**
 * REST endpoint tests.
 */
class REST_Endpoint_Test extends Parse_This_TestCase {

	const PAGE = '<html><head><title>Cached page</title></head><body><article class="h-entry"><h1 class="p-name">Hello</h1><div class="e-content">Some content.</div></article></body></html>';

	/**
	 * Dispatches a parse request.
	 *
	 * @param array $params Request parameters.
	 * @return mixed Response data.
	 */
	private function dispatch( $params ) {
		$request = new WP_REST_Request( 'GET', '/parse-this/1.0/parse' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return ParseThis\REST_Endpoint::read( $request );
	}

	/**
	 * Repeated requests are answered from the cache; nocache and debug fetch again (P-5).
	 */
	public function test_results_are_cached() {
		$this->respond( 'https://example.com/cached/', self::PAGE );

		$first  = $this->dispatch( array( 'url' => 'https://example.com/cached/' ) );
		$second = $this->dispatch( array( 'url' => 'https://example.com/cached/' ) );
		$this->assertSame( 'Hello', $first['name'] );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->requests );

		$this->dispatch(
			array(
				'url'     => 'https://example.com/cached/',
				'nocache' => '1',
			)
		);
		$this->assertCount( 2, $this->requests );

		$this->dispatch(
			array(
				'url'   => 'https://example.com/cached/',
				'debug' => '1',
			)
		);
		$this->assertCount( 3, $this->requests );

		// Different arguments are cached separately.
		$this->dispatch(
			array(
				'url' => 'https://example.com/cached/',
				'mf2' => '1',
			)
		);
		$this->assertCount( 4, $this->requests );
	}

	/**
	 * A lifetime of 0 from the filter turns caching off (P-5).
	 */
	public function test_cache_can_be_disabled() {
		$this->respond( 'https://example.com/uncached/', self::PAGE );
		add_filter( 'parse_this_cache_lifetime', '__return_zero' );

		$this->dispatch( array( 'url' => 'https://example.com/uncached/' ) );
		$this->dispatch( array( 'url' => 'https://example.com/uncached/' ) );

		remove_filter( 'parse_this_cache_lifetime', '__return_zero' );
		$this->assertCount( 2, $this->requests );
	}

	/**
	 * The route requires edit_posts, and the capability is filterable (S-1).
	 */
	public function test_route_requires_edit_posts() {
		$this->respond( 'https://example.com/perm/', self::PAGE );
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/parse-this/1.0/parse' );
		$request->set_param( 'url', 'https://example.com/perm/' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );

		$capability = function () {
			return 'manage_options';
		};
		add_filter( 'parse_this_rest_capability', $capability );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		remove_filter( 'parse_this_rest_capability', $capability );
	}

	/**
	 * Route parameters are typed: booleans and the return enum are validated (S-2).
	 */
	public function test_route_parameters_are_validated() {
		$this->respond( 'https://example.com/typed/', self::PAGE );
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$dispatch = function ( $params ) {
			$request = new WP_REST_Request( 'GET', '/parse-this/1.0/parse' );
			$request->set_query_params( array_merge( array( 'url' => 'https://example.com/typed/' ), $params ) );
			return rest_get_server()->dispatch( $request );
		};

		// As Post Kinds sends it.
		$this->assertSame( 200, $dispatch( array( 'follow' => 'true' ) )->get_status() );
		$this->assertSame( 200, $dispatch( array( 'return' => 'feed' ) )->get_status() );
		$this->assertSame( 400, $dispatch( array( 'return' => 'everything' ) )->get_status() );
		$this->assertSame( 400, $dispatch( array( 'mf2' => 'maybe' ) )->get_status() );

		// A typed boolean "0" means false: the result is jf2, not mf2.
		$data = $dispatch( array( 'mf2' => '0' ) )->get_data();
		$this->assertSame( 'Hello', $data['name'] );
	}

	/**
	 * The debug page keeps the nonce out of the form and uses the route's capability (S-6).
	 */
	public function test_debug_page() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		ob_start();
		ParseThis\REST_Endpoint::debug();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="parse-this-debug"', $html );
		$this->assertStringNotContainsString( '_wpnonce', $html );
		$this->assertStringNotContainsString( 'action=', $html );
		$this->assertTrue( wp_script_is( 'parse-this-debug', 'enqueued' ) );
		$this->assertStringContainsString( 'var parseThisDebug', implode( '', wp_scripts()->get_data( 'parse-this-debug', 'before' ) ) );

		$capability = function () {
			return 'manage_options';
		};
		add_filter( 'parse_this_rest_capability', $capability );
		$this->assertSame( 'manage_options', ParseThis\REST_Endpoint::required_capability() );
		remove_filter( 'parse_this_rest_capability', $capability );
		$this->assertSame( 'edit_posts', ParseThis\REST_Endpoint::required_capability() );
	}
}
