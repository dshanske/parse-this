<?php
/**
 * Tests for the RSS/Atom and JSON Feed parsers.
 *
 * @package Parse_This
 */

/**
 * Feed parser tests.
 */
class Parser_Feeds_Test extends Parse_This_TestCase {

	/**
	 * An RSS 2.0 podcast feed with enclosures.
	 */
	public function test_rss() {
		$jf2 = ParseThis\RSS::parse( $this->simplepie( $this->fixture( 'rss2.xml' ) ), 'https://example.com/podcast/feed/' );

		$this->assertSame( 'feed', $jf2['type'] );
		$this->assertSame( 'RSS', $jf2['_feed_type'] );
		$this->assertSame( 'Example Podcast', $jf2['name'] );
		$this->assertSame( 'https://example.com/podcast/', $jf2['url'] );
		$this->assertSame( '2026-09-02T10:00:00+00:00', $jf2['_last_published'] );
		$this->assertCount( 2, $jf2['items'] );

		$item = $jf2['items'][0];
		$this->assertSame( 'entry', $item['type'] );
		$this->assertSame( 'Episode Two', $item['name'] );
		$this->assertSame( 'https://example.com/podcast/2/', $item['url'] );
		$this->assertSame( '2026-09-02T10:00:00+00:00', $item['published'] );
		$this->assertSame( 'https://example.com/podcast/2.mp3', $item['audio'] );
		$this->assertSame( 'PT1H2M5S', $item['duration'] );
		$this->assertSame( array( 'Audio' ), $item['category'] );
		$this->assertSame( 'audio', $item['post_type'] );
	}

	/**
	 * An Atom feed with an author, HTML content and a source.
	 */
	public function test_atom() {
		$jf2 = ParseThis\RSS::parse( $this->simplepie( $this->fixture( 'atom.xml' ) ), 'https://example.com/atom' );

		$this->assertSame( 'atom', $jf2['_feed_type'] );
		$this->assertSame( 'Jane Doe', $jf2['author']['name'] );
		$this->assertSame( '2026-09-02T10:00:00+00:00', $jf2['_last_updated'] );

		$item = $jf2['items'][0];
		$this->assertSame( '<p>Atom <em>content</em>.</p>', $item['content']['html'] );
		$this->assertSame( 'Atom content.', $item['content']['text'] );
		$this->assertSame( '2026-09-01T10:00:00+00:00', $item['published'] );
		$this->assertSame( '2026-09-02T10:00:00+00:00', $item['updated'] );
		$this->assertSame( 'Original Feed', $item['_source']['name'] ); // C-7.
	}

	/**
	 * A feed with no items parses without errors (C-6).
	 */
	public function test_empty_feed() {
		$jf2 = ParseThis\RSS::parse( $this->simplepie( '<?xml version="1.0"?><rss version="2.0"><channel><title>Empty</title><link>https://example.com/</link></channel></rss>' ), 'https://example.com/feed' );
		$this->assertSame( 'Empty', $jf2['name'] );
		$this->assertArrayNotHasKey( 'items', $jf2 );
	}

	/**
	 * Items without enclosures or dates parse without warnings.
	 */
	public function test_item_without_enclosure() {
		$jf2 = ParseThis\RSS::parse( $this->simplepie( '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><item><title>I</title><link>https://example.com/1</link></item></channel></rss>' ), 'https://example.com/feed' );
		$this->assertArrayNotHasKey( 'published', $jf2['items'][0] ); // C-24: no invented date.
	}

	/**
	 * A JSON Feed 1.1 document becomes a jf2 feed.
	 */
	public function test_jsonfeed() {
		$jf2 = ParseThis\JSONFeed::to_jf2( json_decode( $this->fixture( 'jsonfeed.json' ), true ), 'https://example.com/feed.json' );

		$this->assertSame( 'feed', $jf2['type'] );
		$this->assertSame( 'Example JSON Feed', $jf2['name'] );
		$this->assertSame( 'Jane Doe', $jf2['author']['name'] );
		$this->assertSame( 'https://example.com/jane.jpg', $jf2['author']['photo'] );
		$this->assertCount( 2, $jf2['items'] );
		$this->assertSame( '<p>Second item.</p>', $jf2['items'][0]['content']['html'] ); // Script removed.
		$this->assertSame( array( 'json', 'feed' ), $jf2['items'][0]['category'] );
		$this->assertSame( 'https://example.com/json/2.mp3', $jf2['items'][0]['audio'] );
		$this->assertSame( 'PT1M30S', $jf2['items'][0]['duration'] );
		$this->assertSame( 'First item.', $jf2['items'][1]['content']['text'] );
		$this->assertSame( '2026-09-02T10:00:00+00:00', $jf2['_last_published'] );
	}

	/**
	 * JSON Feed items and authors carry jf2 types.
	 */
	public function test_jsonfeed_types() {
		$jf2 = ParseThis\JSONFeed::to_jf2( json_decode( $this->fixture( 'jsonfeed.json' ), true ), 'https://example.com/feed.json' );
		$this->assertSame( 'entry', $jf2['items'][0]['type'] );
		$this->assertSame( 'card', $jf2['author']['type'] );
		$this->assertArrayNotHasKey( 'author', $jf2['items'][1] ); // Empty authors list.
	}
}
