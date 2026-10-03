<?php
/**
 * Tests for Parser::fetch() and parse() with mocked HTTP responses.
 *
 * @package Parse_This
 */

/**
 * Fetch tests.
 */
class Parser_Fetch_Test extends Parse_This_TestCase {

	/**
	 * Fetches and parses a URL.
	 *
	 * @param string $url  URL.
	 * @param array  $args Optional. Parse arguments.
	 * @return array|WP_Error jf2, or the fetch error.
	 */
	private function fetch_and_parse( $url, $args = array() ) {
		$parser = new ParseThis\Parser( $url );
		$result = $parser->fetch();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$parser->parse( $args );
		return $parser->get();
	}

	/**
	 * An HTML page is fetched, and its Link headers are recorded.
	 */
	public function test_html_page() {
		$url = 'https://example.com/2026/09/a-like/';
		$this->respond( $url, $this->fixture( 'mf2-note.html' ), 'text/html; charset=UTF-8', array( 'link' => '<https://example.com/webmention>; rel="webmention"' ) );

		$parser = new ParseThis\Parser( $url );
		$this->assertTrue( $parser->fetch() );
		$this->assertSame( 'text/html', $parser->get( 'content_type' ) );
		$this->assertSame( 'webmention', $parser->get( 'links' )[0]['rel'] );

		$parser->parse();
		$this->assertSame( 'like', $parser->get()['post-type'] );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * A 403 is retried once with a browser user agent, then reported.
	 */
	public function test_403_is_retried_with_browser_user_agent() {
		$url = 'https://example.com/blocked/';
		$this->respond( $url, 'Forbidden', 'text/html', array(), 403 );

		$result = $this->fetch_and_parse( $url );
		$this->assertWPError( $result );
		$this->assertSame( 'source_error', $result->get_error_code() );
		$this->assertCount( 2, $this->requests );
		$this->assertStringContainsString( 'Parse This', $this->requests[1]['args']['user-agent'] );
	}

	/**
	 * Request failures and unsupported content types are returned as errors (C-29).
	 */
	public function test_errors() {
		$result = $this->fetch_and_parse( 'https://example.com/not-mocked/' );
		$this->assertSame( 'http_request_not_mocked', $result->get_error_code() );

		$this->respond( 'https://example.com/image.png', 'PNG', 'image/png' );
		$result = $this->fetch_and_parse( 'https://example.com/image.png' );
		$this->assertSame( 'content-type', $result->get_error_code() );
		$this->assertSame( array( 'content-type' => 'image/png' ), $result->get_error_data() );
	}

	/**
	 * An RSS URL is fetched through core fetch_feed() without caching (CMP-5).
	 */
	public function test_rss_url() {
		global $wpdb;
		$url = 'https://example.com/podcast/feed/';
		$this->respond( $url, $this->fixture( 'rss2.xml' ), 'application/rss+xml; charset=UTF-8' );

		$jf2 = $this->fetch_and_parse( $url );
		$this->assertSame( 'feed', $jf2['type'] );
		$this->assertCount( 2, $jf2['items'] );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_feed\\_%'" ) );
	}

	/**
	 * A JSON Feed served as application/feed+json is parsed.
	 */
	public function test_jsonfeed_url() {
		$url = 'https://example.com/feed.json';
		$this->respond( $url, $this->fixture( 'jsonfeed.json' ), 'application/feed+json' );

		$jf2 = $this->fetch_and_parse( $url );
		$this->assertSame( 'jsonfeed', $jf2['_feed_type'] );
		$this->assertCount( 2, $jf2['items'] );
	}

	/**
	 * A JSON Feed served as application/json is parsed too.
	 */
	public function test_jsonfeed_served_as_json() {
		$url = 'https://example.com/feed.json';
		$this->respond( $url, $this->fixture( 'jsonfeed.json' ), 'application/json' );
		$jf2 = $this->fetch_and_parse( $url );
		$this->assertSame( 'jsonfeed', $jf2['_feed_type'] );
	}

	/**
	 * A WordPress REST API collection served as application/json becomes a feed (C-35).
	 */
	public function test_rest_collection() {
		$posts = array(
			array(
				'id'    => 2,
				'link'  => 'https://example.com/two/',
				'title' => array( 'rendered' => 'Two' ),
			),
			array(
				'id'    => 1,
				'link'  => 'https://example.com/one/',
				'title' => array( 'rendered' => 'One' ),
			),
		);
		$this->respond(
			'https://example.com/wp-json/wp/v2/posts',
			wp_json_encode( $posts ),
			'application/json; charset=UTF-8',
			array(
				'x-wp-total'      => '2',
				'x-wp-totalpages' => '1',
			)
		);

		// Site details come from the REST API root (C-46).
		$this->respond(
			'https://example.com/wp-json/?_embed=1',
			wp_json_encode(
				array(
					'name'        => 'Example Site',
					'description' => 'Just another site',
					'url'         => 'https://example.com',
				)
			),
			'application/json'
		);

		$jf2 = $this->fetch_and_parse( 'https://example.com/wp-json/wp/v2/posts' );
		$this->assertSame( 'feed', $jf2['type'] );
		$this->assertSame( array( 'Two', 'One' ), wp_list_pluck( $jf2['items'], 'name' ) );
		$this->assertSame( '2', $jf2['_total'] );
		$this->assertSame( 'Example Site', $jf2['name'] );
		$this->assertSame( 'Just another site', $jf2['summary'] );
		$this->assertSame( 'https://example.com', $jf2['url'] );
		$this->assertNotContains( 'https://example.com/wp-json/wp/v2/posts/?_embed=1', wp_list_pluck( $this->requests, 'url' ) );
	}

	/**
	 * A collection on a plain-permalink site finds its site details too (C-46).
	 */
	public function test_rest_collection_plain_permalinks() {
		$url = 'https://example.com/?rest_route=/wp/v2/posts&per_page=1';
		$this->respond(
			$url,
			wp_json_encode(
				array(
					array(
						'id'    => 1,
						'link'  => 'https://example.com/?p=1',
						'title' => array( 'rendered' => 'One' ),
					),
				)
			),
			'application/json',
			array( 'x-wp-total' => '1' )
		);
		$this->respond( 'https://example.com/?rest_route=/&_embed=1', wp_json_encode( array( 'name' => 'Plain Site' ) ), 'application/json' );

		$jf2 = $this->fetch_and_parse( $url );
		$this->assertSame( 'Plain Site', $jf2['name'] );
		$this->assertCount( 1, $jf2['items'] );
	}

	/**
	 * Unrecognized JSON is returned as raw content.
	 */
	public function test_unrecognized_json() {
		$this->respond( 'https://example.com/data.json', wp_json_encode( array( 'hello' => 'world' ) ), 'application/json' );

		$jf2 = $this->fetch_and_parse( 'https://example.com/data.json' );
		$this->assertSame( array( 'hello' => 'world' ), $jf2['raw'] );
	}

	/**
	 * A jf2 JSON response is used directly as the result (C-2).
	 */
	public function test_jf2_json() {
		$jf2 = array(
			'type' => 'entry',
			'url'  => 'https://example.com/jf2/',
			'name' => 'From jf2',
		);
		$this->respond( 'https://example.com/jf2/', wp_json_encode( $jf2 ), 'application/jf2+json' );
		$parser = new ParseThis\Parser( 'https://example.com/jf2/' );
		$parser->fetch();
		$this->assertSame( 'From jf2', $parser->get()['name'] );
	}

	/**
	 * An mf2 JSON response is parsed by the MF2 parser (C-2).
	 */
	public function test_mf2_json() {
		$mf2 = array(
			'items' => array(
				array(
					'type'       => array( 'h-entry' ),
					'properties' => array(
						'url'  => array( 'https://example.com/mf2/' ),
						'name' => array( 'From mf2' ),
					),
				),
			),
			'rels'  => array(),
		);
		$this->respond( 'https://example.com/mf2/', wp_json_encode( $mf2 ), 'application/mf2+json' );
		$result = $this->fetch_and_parse( 'https://example.com/mf2/' );
		// The raw mf2 document is not merged into the result (C-45).
		$this->assertArrayNotHasKey( 'items', $result );
		$this->assertArrayNotHasKey( 'rels', $result );
		// The microformats result is the result, even without content (C-47).
		$this->assertSame( 'From mf2', $result['name'] );
		$this->assertArrayNotHasKey( '_jf2', $result );
	}
}
