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
	}

	/**
	 * An unreachable URL returns the error (C-28).
	 */
	public function test_error() {
		$this->assertWPError( ( new ParseThis\Discovery() )->fetch( 'https://example.com/missing/' ) );
	}
}
