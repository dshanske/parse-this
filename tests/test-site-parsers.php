<?php
/**
 * Tests for the REST API client and the X/Twitter and YouTube parsers.
 *
 * @package Parse_This
 */

/**
 * Site-specific parser tests.
 */
class Site_Parsers_Test extends Parse_This_TestCase {

	/**
	 * REST route URLs for pretty and plain permalinks (C-4).
	 */
	public function test_rest_urls() {
		$this->assertSame( 'https://example.com/wp-json/wp/v2/posts?_embed=1', ParseThis\RESTAPI::get_rest_url( 'https://example.com/wp-json/', '/wp/v2/posts' ) );
		$this->assertSame( 'https://example.com/?rest_route=/wp/v2/posts&_embed=1', ParseThis\RESTAPI::get_rest_url( 'https://example.com/?rest_route=/', '/wp/v2/posts' ) );
		$this->assertSame( '/wp/v2/posts/5', ParseThis\RESTAPI::get_rest_path( 'https://example.com/wp-json/', 'https://example.com/wp-json/wp/v2/posts/5' ) );
		$this->assertSame( '/wp/v2/posts/5', ParseThis\RESTAPI::get_rest_path( 'https://example.com/?rest_route=/', 'https://example.com/?rest_route=/wp/v2/posts/5' ) );
	}

	/**
	 * The REST API root is worked out from REST API URLs (C-46).
	 */
	public function test_rest_root() {
		$this->assertSame( 'https://example.com/wp-json/', ParseThis\RESTAPI::get_rest_root( 'https://example.com/wp-json/wp/v2/posts' ) );
		$this->assertSame( 'https://example.com/blog/wp-json/', ParseThis\RESTAPI::get_rest_root( 'https://example.com/blog/wp-json/wp/v2/posts?per_page=2' ) );
		$this->assertSame( 'https://example.com/?rest_route=/', ParseThis\RESTAPI::get_rest_root( 'https://example.com/?rest_route=/wp/v2/posts' ) );
		$this->assertSame( 'https://example.com/index.php?rest_route=/', ParseThis\RESTAPI::get_rest_root( 'https://example.com/index.php?rest_route=/wp/v2/posts' ) );
		$this->assertFalse( ParseThis\RESTAPI::get_rest_root( 'https://example.com/api/wp/v2/posts' ) );
	}

	/**
	 * A REST API post becomes an entry.
	 */
	public function test_rest_post() {
		$this->respond_site_data(
			'https://example.com/wp-json/',
			array(
				'name'            => 'Example Site',
				'url'             => 'https://example.com',
				'timezone_string' => 'UTC',
			)
		);
		$post = array(
			'id'        => 5,
			'link'      => 'https://example.com/hello/',
			'date'      => '2026-09-01T10:00:00',
			'modified'  => '2026-09-02T10:00:00',
			'title'     => array( 'rendered' => 'Hello' ),
			'content'   => array( 'rendered' => '<p>Hello world.</p>' ),
			'excerpt'   => array( 'rendered' => 'Hello world.' ),
			'_embedded' => array(
				'author' => array(
					array(
						'name' => 'Jane Doe',
						'url'  => 'https://example.com/',
					),
				),
			),
		);

		$jf2 = ParseThis\RESTAPI::parse( $post, 'https://example.com/wp-json/', array( 'return' => 'single' ) );
		$this->assertSame( 'Hello', $jf2['name'] );
		$this->assertSame( 'https://example.com/hello/', $jf2['url'] );
		$this->assertSame( '<p>Hello world.</p>', $jf2['content']['html'] );
		$this->assertSame( '2026-09-01T10:00:00+00:00', $jf2['published'] );
		$this->assertSame( 'Jane Doe', $jf2['author']['name'] );
	}

	/**
	 * REST API posts carry a jf2 type.
	 */
	public function test_rest_post_type() {
		$this->respond_site_data( 'https://example.com/wp-json/', wp_json_encode( array( 'name' => 'Example Site' ) ) );
		$jf2 = ParseThis\RESTAPI::parse(
			array(
				'id'    => 5,
				'link'  => 'https://example.com/hello/',
				'title' => array( 'rendered' => 'Hello' ),
			),
			'https://example.com/wp-json/',
			array( 'return' => 'single' )
		);
		$this->assertSame( 'entry', $jf2['type'] );
	}

	/**
	 * The parse_this_rest_api_jf2_type filter can choose another type.
	 */
	public function test_rest_post_type_filter() {
		$this->respond_site_data( 'https://example.com/wp-json/', wp_json_encode( array( 'name' => 'Example Site' ) ) );
		$callback = function ( $type, $item ) {
			return ( isset( $item['type'] ) && 'tribe_events' === $item['type'] ) ? 'event' : $type;
		};
		add_filter( 'parse_this_rest_api_jf2_type', $callback, 10, 2 );

		$event = ParseThis\RESTAPI::parse(
			array(
				'id'   => 6,
				'type' => 'tribe_events',
				'link' => 'https://example.com/event/',
			),
			'https://example.com/wp-json/',
			array( 'return' => 'single' )
		);
		$feed  = ParseThis\RESTAPI::posts_to_feed(
			array(
				'items' => array(
					array(
						'id'   => 7,
						'type' => 'post',
						'link' => 'https://example.com/post/',
					),
					array(
						'id'   => 8,
						'type' => 'tribe_events',
						'link' => 'https://example.com/event-2/',
					),
				),
			),
			'https://example.com/wp-json/'
		);
		remove_filter( 'parse_this_rest_api_jf2_type', $callback, 10 );

		$this->assertSame( 'event', $event['type'] );
		$this->assertSame( array( 'entry', 'event' ), wp_list_pluck( $feed['items'], 'type' ) );
	}

	/**
	 * An X post is parsed from the oEmbed response (CMP-6).
	 */
	public function test_twitter() {
		$url = 'https://x.com/jack/status/20';
		$this->respond( add_query_arg( 'url', $url, 'https://publish.x.com/oembed' ), $this->fixture( 'twitter-oembed.json' ), 'application/json' );

		$jf2 = ParseThis\Twitter::parse( $url, array() );
		$this->assertSame( 'https://x.com/jack/status/20', $jf2['url'] );
		$this->assertSame( 'jack', $jf2['author']['name'] );
		$this->assertSame( 'just setting up my #twttr with @biz', $jf2['content']['value'] );
		$this->assertContains( 'twttr', $jf2['category'] );
		$this->assertSame( '2006-03-21T00:00:00+00:00', $jf2['published'] );
	}

	/**
	 * A failed oEmbed request returns an empty result (C-25).
	 */
	public function test_twitter_failure() {
		$this->assertSame( array(), ParseThis\Twitter::parse( 'https://x.com/jack/status/21', array() ) );
	}

	/**
	 * A YouTube watch page is parsed from its player response (CMP-8).
	 */
	public function test_youtube() {
		$jf2 = ParseThis\YouTube::parse( $this->fixture( 'youtube-watch.html' ), 'https://www.youtube.com/watch?v=abc123XYZ00', array() );
		$this->assertSame( 'abc123XYZ00', $jf2['uid'] );
		$this->assertSame( 'A Test Video', $jf2['name'] );
		$this->assertSame( 'PT1H2M5S', $jf2['duration'] );
		$this->assertSame( 'Test Channel', $jf2['author']['name'] );
		$this->assertSame( 'https://i.ytimg.com/vi/abc123XYZ00/maxresdefault.jpg', $jf2['featured'] );
		$this->assertSame( array(), ParseThis\YouTube::parse( '<html>no player</html>', 'https://www.youtube.com/watch?v=x', array() ) ); // C-26.
	}

	/**
	 * Categories and tags come from the embedded terms, without extra requests (P-3).
	 */
	public function test_rest_post_terms_from_embedded_data() {
		$this->respond_site_data( 'https://example.com/wp-json/', wp_json_encode( array( 'name' => 'Example Site' ) ) );
		$post = array(
			'id'        => 5,
			'link'      => 'https://example.com/hello/',
			'title'     => array( 'rendered' => 'Hello' ),
			'tags'      => array( 7 ),
			'_links'    => array( 'wp:term' => array( array( 'taxonomy' => 'post_tag', 'href' => 'https://example.com/wp-json/wp/v2/tags?post=5' ) ) ),
			'_embedded' => array(
				'wp:term' => array(
					array(
						array( 'taxonomy' => 'category', 'name' => 'News' ),
						array( 'taxonomy' => 'category', 'name' => 'Uncategorized' ),
					),
					array( array( 'taxonomy' => 'post_tag', 'name' => 'release' ) ),
				),
			),
		);

		$single = ParseThis\RESTAPI::get_post( $post, 'https://example.com/wp-json/' );
		$feed   = ParseThis\RESTAPI::posts_to_feed( array( 'items' => array( $post ) ), 'https://example.com/wp-json/' );

		$this->assertSame( array( 'News', 'release' ), $single['category'] );
		$this->assertSame( array( 'News', 'release' ), $feed['items'][0]['category'] );
		$this->assertNotContains( 'https://example.com/wp-json/wp/v2/tags?post=5&_embed=1', wp_list_pluck( $this->requests, 'url' ) );
	}

	/**
	 * REST API dates come from date_gmt, shown in the site's timezone when known.
	 */
	public function test_rest_post_dates_from_gmt() {
		$post = array(
			'id'           => 5,
			'link'         => 'https://example.com/hello/',
			'title'        => array( 'rendered' => 'Hello' ),
			'date'         => '2026-09-29T10:00:00',
			'date_gmt'     => '2026-09-29T14:00:00',
			'modified'     => '2026-09-29T11:00:00',
			'modified_gmt' => '2026-09-29T15:00:00',
		);

		// Without site data the instant is still right, in UTC.
		$jf2 = ParseThis\RESTAPI::get_post( $post, 'https://example.com/wp-json/' );
		$this->assertSame( '2026-09-29T14:00:00+00:00', $jf2['published'] );
		$this->assertSame( '2026-09-29T15:00:00+00:00', $jf2['updated'] );

		// With the site's timezone it is shown in local time.
		$this->respond_site_data( 'https://example.org/wp-json/', array( 'timezone_string' => 'America/New_York' ) );
		$jf2 = ParseThis\RESTAPI::get_post( $post, 'https://example.org/wp-json/' );
		$this->assertSame( '2026-09-29T10:00:00-04:00', $jf2['published'] );

		// Without date_gmt, the local date is read in the site's timezone as before.
		unset( $post['date_gmt'] );
		$jf2 = ParseThis\RESTAPI::get_post( $post, 'https://example.org/wp-json/' );
		$this->assertSame( '2026-09-29T10:00:00-04:00', $jf2['published'] );
	}
}
