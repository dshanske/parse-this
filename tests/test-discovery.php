<?php
/**
 * Tests for feed discovery.
 *
 * @package Parse_This
 */

/**
 * Discovery tests.
 */
class Discovery_Test extends Parse_This_TestCase {

	/**
	 * Advertised feeds and the REST API root are found, ranked and de-duplicated.
	 */
	public function test_html_page() {
		$html = '<html><head>'
			. '<link rel="alternate" type="application/rss+xml" title="RSS" href="/feed/">'
			. '<link rel="alternate" type="application/atom+xml" title="Atom" href="https://example.com/atom/">'
			. '<link rel="alternate" type="application/feed+json" title="JSON Feed" href="/feed.json">'
			. '<link rel="alternate" type="application/rss+xml" title="RSS again" href="/feed/">'
			. '</head><body></body></html>';
		$this->respond( 'https://example.com/', $html, 'text/html', array( 'link' => '<https://example.com/wp-json/>; rel="https://api.w.org/"' ) );

		$results = ( new ParseThis\Discovery() )->fetch( 'https://example.com/' )['results'];
		$this->assertSame(
			array( 'jsonfeed', 'wordpress', 'atom', 'rss' ),
			wp_list_pluck( $results, '_feed_type' )
		);
		$this->assertSame( 'https://example.com/feed/', $results[3]['url'] ); // Relative URL resolved.
		$this->assertSame( 'https://example.com/wp-json', $results[1]['url'] );
	}

	/**
	 * A YouTube handle page lists its advertised feed and no NULL URL (CMP-8).
	 */
	public function test_youtube_handle() {
		$feed = 'https://www.youtube.com/feeds/videos.xml?channel_id=UC123';
		$this->respond( 'https://www.youtube.com/@example', '<html><head><link rel="alternate" type="application/rss+xml" title="RSS" href="' . $feed . '"></head></html>' );

		$results = ( new ParseThis\Discovery() )->fetch( 'https://www.youtube.com/@example' )['results'];
		$this->assertSame( array( $feed ), wp_list_pluck( $results, 'url' ) );
	}

	/**
	 * A feed URL is reported as itself.
	 */
	public function test_feed_url() {
		$this->respond( 'https://example.com/podcast/feed/', $this->fixture( 'rss2.xml' ), 'application/rss+xml' );

		$results = ( new ParseThis\Discovery() )->fetch( 'https://example.com/podcast/feed/' )['results'];
		$this->assertSame( 'RSS', $results[0]['_feed_type'] );
		$this->assertSame( 'Example Podcast', $results[0]['name'] );
		$this->assertCount( 1, $this->requests ); // P-1.
	}

	/**
	 * An unreachable URL returns the error (C-28).
	 */
	public function test_error() {
		$this->assertWPError( ( new ParseThis\Discovery() )->fetch( 'https://example.com/missing/' ) );
	}

	/**
	 * Discovery recognizes mf2 JSON feeds, sniffed feeds, and permanent redirects (X-11).
	 */
	public function test_discovery_formats_and_redirects() {
		$this->respond( 'https://example.com/mf2-feed', wp_json_encode( array( 'items' => array( array( 'type' => array( 'h-feed' ), 'properties' => array(), 'children' => array() ) ) ) ), 'application/mf2+json' );
		$results = ( new ParseThis\Discovery() )->fetch( 'https://example.com/mf2-feed' );
		$this->assertSame( 'microformats', $results['results'][0]['_feed_type'] );
		$this->assertSame( 'https://example.com/mf2-feed', $results['results'][0]['url'] );

		$this->respond( 'https://example.com/plain-rss', '<?xml version="1.0"?><rss version="2.0"><channel><title>Plain</title><link>https://example.com/</link></channel></rss>', 'text/plain' );
		$results = ( new ParseThis\Discovery() )->fetch( 'https://example.com/plain-rss' );
		$this->assertSame( 'Plain', $results['results'][0]['name'] );

		// The feed URL follows a permanent redirect, not a temporary one.
		$feed_url = new ReflectionMethod( ParseThis\Discovery::class, 'feed_url' );
		$feed_url->setAccessible( true );
		foreach ( array( 301 => 'https://example.com/new-feed', 308 => 'https://example.com/new-feed', 302 => 'https://example.com/old-feed', 307 => 'https://example.com/old-feed' ) as $code => $expected ) {
			$hop              = new WpOrg\Requests\Response();
			$hop->status_code = $code;
			$final            = new WpOrg\Requests\Response();
			$final->url       = 'https://example.com/new-feed';
			$final->history   = array( $hop );
			$response         = array( 'http_response' => new WP_HTTP_Requests_Response( $final ) );
			$this->assertSame( $expected, $feed_url->invoke( null, 'https://example.com/old-feed', $response ), "HTTP $code" );
		}
		$this->assertSame( 'https://example.com/x', $feed_url->invoke( null, 'https://example.com/x', array() ) );
	}
}
