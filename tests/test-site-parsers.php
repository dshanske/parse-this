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
	 * A REST API post becomes an entry.
	 */
	public function test_rest_post() {
		$this->respond(
			'https://example.com/wp-json/?_embed=1',
			wp_json_encode(
				array(
					'name'            => 'Example Site',
					'url'             => 'https://example.com',
					'timezone_string' => 'UTC',
				)
			),
			'application/json'
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
		$this->markTestSkipped( 'Known bug C-39 (issue 127): REST API posts have no jf2 type.' );

		$this->respond( 'https://example.com/wp-json/?_embed=1', wp_json_encode( array( 'name' => 'Example Site' ) ), 'application/json' );
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
}
