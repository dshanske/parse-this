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
		$this->markTestSkipped( 'Known bug C-35 (issue 113): parse() overwrites the converted feed with false.' );

		$url = 'https://example.com/feed.json';
		$this->respond( $url, $this->fixture( 'jsonfeed.json' ), 'application/json' );
		$jf2 = $this->fetch_and_parse( $url );
		$this->assertSame( 'jsonfeed', $jf2['_feed_type'] );
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
		$this->markTestSkipped( 'Known bug C-45 (issue 129): the raw mf2 document is merged into the result.' );

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
		$this->assertSame( 'From mf2', $result['name'] );
	}
}
