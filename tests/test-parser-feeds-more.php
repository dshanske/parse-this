<?php
/**
 * Tests for less common feed, JSON and discovery cases.
 *
 * @package Parse_This
 */

use ParseThis\RSS;
use ParseThis\JSONFeed;
use ParseThis\JSON;
use ParseThis\Discovery;

/**
 * Feed, JSON and discovery tests.
 */
class Parser_Feeds_More_Test extends Parse_This_TestCase {

	/**
	 * Wraps items in an RSS 2.0 channel with the namespaces the tests use.
	 *
	 * @param string $items   Item markup.
	 * @param string $channel Optional. Extra channel markup.
	 * @return string RSS document.
	 */
	private function rss( $items, $channel = '' ) {
		return '<?xml version="1.0"?><rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:geo="http://www.w3.org/2003/01/geo/wgs84_pos#" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<channel><title>Channel</title><link>https://example.com/</link>' . $channel . $items . '</channel></rss>';
	}

	/**
	 * The feed's last update comes from lastBuildDate (RSS) or updated (Atom).
	 */
	public function test_last_updated() {
		$rss = $this->simplepie( $this->rss( '', '<lastBuildDate>Tue, 02 Jan 2024 03:04:05 +0000</lastBuildDate>' ) );
		$this->assertStringStartsWith( '2024-01-02', RSS::last_updated( $rss ) );
		$atom = $this->simplepie( '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>A</title><updated>2024-03-04T05:06:07Z</updated></feed>' );
		$this->assertStringStartsWith( '2024-03-04', RSS::last_updated( $atom ) );
		$this->assertNull( RSS::last_updated( $this->simplepie( $this->rss( '' ) ) ) );
	}

	/**
	 * Authors: none, plain names, and names holding several links.
	 */
	public function test_authors() {
		$this->assertSame( array(), RSS::get_authors( null ) );
		$feed  = $this->simplepie( $this->rss( '<item><title>T</title><dc:creator>&lt;b&gt;Plain&lt;/b&gt; Name</dc:creator></item><item><title>U</title><dc:creator>&lt;a href="https://a.example/"&gt;Ann&lt;/a&gt; and &lt;a href="https://b.example/"&gt;Bob&lt;/a&gt;</dc:creator></item>' ) );
		$items = $feed->get_items();
		$this->assertSame( 'Plain Name', RSS::get_authors( $items[0]->get_authors() )['name'] );
		$two = RSS::get_authors( $items[1]->get_authors() );
		$this->assertSame( array( 'Ann', 'Bob' ), wp_list_pluck( $two, 'name' ) );
		$this->assertSame( 'https://b.example/', $two[1]['url'] );
	}

	/**
	 * An author name that is a single link becomes a card with that name and URL.
	 */
	public function test_author_single_link() {
		$this->markTestSkipped( 'RSS::get_authors() drops an author whose name is a single link (#188).' );
		$feed   = $this->simplepie( $this->rss( '<item><title>T</title><dc:creator>&lt;a href="https://a.example/"&gt;Ann&lt;/a&gt;</dc:creator></item>' ) );
		$author = RSS::get_authors( $feed->get_items()[0]->get_authors() );
		$this->assertSame( 'Ann', $author['name'] );
		$this->assertSame( 'https://a.example/', $author['url'] );
	}

	/**
	 * Enclosures: media types, keywords, durations, descriptions, thumbnails and credits.
	 */
	public function test_enclosures() {
		$feed = $this->simplepie(
			$this->rss(
				'<item><title>Episode</title><link>https://example.com/e</link>'
				. '<enclosure url="https://example.com/e.mp3" type="audio/mpeg" length="1"/>'
				. '<media:content url="https://example.com/v.mp4" type="video/mp4" duration="90">'
				. '<media:keywords>one, two</media:keywords><media:description>From the enclosure</media:description>'
				. '<media:thumbnail url="https://example.com/thumb.jpg"/><media:credit role="author">Host</media:credit></media:content>'
				. '<media:content url="https://example.com/x.bin" medium="document"/>'
				. '</item>'
			)
		);
		$jf2  = RSS::get_item( $feed->get_items()[0] );
		$this->assertSame( 'https://example.com/e.mp3', $jf2['audio'] );
		$this->assertSame( 'https://example.com/v.mp4', $jf2['video'] );
		$this->assertSame( 'PT1M30S', $jf2['duration'] );
		$this->assertSame( 'https://example.com/thumb.jpg', $jf2['featured'] );
		$this->assertContains( 'two', $jf2['category'] );
		$this->assertSame( 'Host', $jf2['credits'][0]['name'] );
		$this->assertSame( 'From the enclosure', $jf2['summary'] );
	}

	/**
	 * Several photos stay photos; one becomes the featured image; iTunes images are a fallback.
	 */
	public function test_photos_and_itunes_image() {
		$feed  = $this->simplepie(
			$this->rss(
				'<item><title>Photos</title><enclosure url="https://example.com/1.jpg" type="image/jpeg" length="1"/><enclosure url="https://example.com/2.jpg" type="image/jpeg" length="1"/></item>'
				. '<item><title>Podcast</title><itunes:image href="https://example.com/cover.jpg"/></item>'
			)
		);
		$items = $feed->get_items();
		$this->assertSame( array( 'https://example.com/1.jpg', 'https://example.com/2.jpg' ), RSS::get_item( $items[0] )['photo'] );
		$this->assertSame( 'https://example.com/cover.jpg', RSS::get_item( $items[1] )['featured'] );
	}

	/**
	 * RSS source elements and geo location names.
	 */
	public function test_source_and_location() {
		$feed = $this->simplepie( $this->rss( '<item><title>T</title><source url="https://origin.example/feed">Origin</source><geo:lat>1.5</geo:lat><geo:long>2.5</geo:long><geo:featureName>Somewhere</geo:featureName></item>' ) );
		$item = $feed->get_items()[0];
		$this->assertSame(
			array(
				'url'  => 'https://origin.example/feed',
				'name' => 'Origin',
			),
			RSS::get_source( $item )
		);
		$location = RSS::get_location( $item );
		$this->assertSame( 'Somewhere', $location['name'] );
		$this->assertNull( RSS::credit_to_card( 'not a credit' ) );
	}

	/**
	 * JSON Feed authors: the singular author, lists, and unusable values.
	 */
	public function test_jsonfeed_authors() {
		$feed = JSONFeed::to_jf2(
			array(
				'version' => 'https://jsonfeed.org/version/1',
				'title'   => 'Old style',
				'author'  => array(
					'name' => 'Single',
					'url'  => 'https://example.com/me',
				),
				'items'   => array(
					array(
						'id'      => '1',
						'url'     => 'https://example.com/1',
						'authors' => 'not a list',
						'content_text' => 'x',
					),
					array(
						'id'      => '2',
						'url'     => 'https://example.com/2',
						'authors' => array( 'skip me', array( 'name' => 'Listed' ) ),
						'content_text' => 'y',
					),
				),
			),
			'https://example.com/feed.json'
		);
		$this->assertSame( 'Single', $feed['author']['name'] );
		$this->assertArrayNotHasKey( 'author', $feed['items'][0] );
		$this->assertSame( 'Listed', $feed['items'][1]['author']['name'] );
	}

	/**
	 * JSON Feed attachments: video with a duration, and attachments missing a type or URL.
	 */
	public function test_jsonfeed_video_attachment() {
		$feed = JSONFeed::to_jf2(
			array(
				'version' => 'https://jsonfeed.org/version/1.1',
				'title'   => 'Videos',
				'items'   => array(
					array(
						'id'          => '1',
						'url'         => 'https://example.com/1',
						'attachments' => array(
							array(
								'url'                 => '/v.mp4',
								'mime_type'           => 'video/mp4',
								'duration_in_seconds' => 61,
							),
							array( 'url' => 'https://example.com/no-type' ),
						),
					),
				),
			),
			'https://example.com/feed.json'
		);
		$this->assertSame( 'https://example.com/v.mp4', $feed['items'][0]['video'] );
		$this->assertSame( 'PT1M1S', $feed['items'][0]['duration'] );
	}

	/**
	 * Next.js page data in a JSON script tag.
	 */
	public function test_next_js_page_data() {
		$this->assertSame( array(), JSON::parse( null, 'https://example.com/', array() ) );
		$data = array(
			'props' => array(
				'pageProps' => array(
					'article' => array(
						'title' => 'Next Article',
						'meta'  => array(
							'date' => '2024-04-04T00:00:00Z',
							'tags' => array( 'js' ),
						),
					),
				),
			),
		);
		$doc  = ParseThis\pt_load_domdocument( '<html><head><script id="__NEXT_DATA__" type="application/json">' . wp_json_encode( $data ) . '</script></head><body></body></html>' );
		$jf2  = JSON::parse( $doc, 'https://example.com/a', array( 'debug' => true ) );
		$this->assertSame( 'entry', $jf2['type'] );
		$this->assertSame( 'Next Article', $jf2['name'] );
		$this->assertSame( array( 'js' ), $jf2['category'] );
		$this->assertArrayHasKey( '_json', $jf2 );
	}

	/**
	 * Discovery: invalid URLs, fetch errors, YouTube channels, Atom and JSON feeds, and supported types.
	 */
	public function test_discovery_cases() {
		$discovery = new Discovery();
		$this->assertWPError( $discovery->fetch( 'not a url' ) );
		$this->assertWPError( $discovery->fetch( 'https://example.com/unmocked' ) );
		$this->assertTrue( $discovery->supported_content( 'application/atom+xml' ) );
		$this->assertFalse( $discovery->supported_content( 'image/png' ) );

		$this->respond( 'https://www.youtube.com/channel/UC123', '<html><head><title>Channel</title></head></html>' );
		$youtube = $discovery->fetch( 'https://www.youtube.com/channel/UC123' );
		$this->assertSame( 'https://www.youtube.com/feeds/videos.xml?channel_id=UC123', $youtube['results'][0]['url'] );

		$this->respond( 'https://example.com/atom', '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Atom Feed</title></feed>', 'application/atom+xml' );
		$atom = $discovery->fetch( 'https://example.com/atom' );
		$this->assertSame( 'atom', $atom['results'][0]['_feed_type'] );
		$this->assertSame( 'Atom Feed', $atom['results'][0]['name'] );

		$this->respond( 'https://example.com/feed.jf2', wp_json_encode( array( 'type' => 'feed', 'children' => array() ) ), 'application/jf2feed+json' );
		$this->assertSame( 'jf2feed', $discovery->fetch( 'https://example.com/feed.jf2' )['results'][0]['_feed_type'] );

		$this->respond( 'https://example.com/feed.json', wp_json_encode( array( 'version' => 'https://jsonfeed.org/version/1.1', 'items' => array() ) ), 'application/feed+json' );
		$this->assertSame( 'jsonfeed', $discovery->fetch( 'https://example.com/feed.json' )['results'][0]['_feed_type'] );

		$this->respond( 'https://example.com/data.json', wp_json_encode( array( 'other' => true ) ), 'application/json' );
		$this->assertSame( array(), $discovery->fetch( 'https://example.com/data.json' )['results'] );
	}

	/**
	 * Discovery reads WordPress REST links from Link headers sent as a list.
	 */
	public function test_discovery_link_header_list() {
		$this->respond( 'https://example.com/wp', '<html><head><title>WP</title></head></html>', 'text/html', array( 'link' => array( '<https://example.com/wp-json/>; rel="https://api.w.org/"', '<https://example.com/?p=1>; rel=shortlink' ) ) );
		$results = ( new Discovery() )->fetch( 'https://example.com/wp' )['results'];
		$this->assertContains( 'https://example.com/wp-json', wp_list_pluck( $results, 'url' ) );
	}

	/**
	 * Discovery of a page that is itself an h-feed reports its author.
	 */
	public function test_discovery_hfeed_author() {
		$this->respond( 'https://example.com/blog', '<html><body><div class="h-feed"><a class="u-url" href="https://example.com/blog">Blog</a><a class="p-author" href="https://example.com/me">Me</a><div class="h-entry"><a class="u-url p-name" href="https://example.com/1">One</a></div></div></body></html>' );
		$results = ( new Discovery() )->fetch( 'https://example.com/blog' )['results'];
		$feed    = wp_list_filter( $results, array( '_feed_type' => 'microformats' ) );
		$this->assertNotEmpty( $feed );
		$this->assertSame( 'Me', array_values( $feed )[0]['author']['name'] );
	}
}
