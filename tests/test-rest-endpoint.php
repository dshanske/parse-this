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
}
