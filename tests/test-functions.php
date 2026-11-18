<?php
/**
 * Tests for the helper functions.
 *
 * @package Parse_This
 */

/**
 * Helper function tests.
 */
class Functions_Test extends Parse_This_TestCase {

	/**
	 * Link headers: several links, commas in URLs, bare parameters (C-30).
	 */
	public function test_parse_header_links() {
		$links = ParseThis\pt_parse_header_links( '<https://example.com/a,b>; rel="alternate"; type="application/json", <https://example.com/wp-json/>; rel="https://api.w.org/", <https://example.com/x>; crossorigin' );

		$this->assertCount( 3, $links );
		$this->assertSame( 'https://example.com/a,b', $links[0]['uri'] );
		$this->assertSame( 'application/json', $links[0]['type'] );
		$this->assertSame( 'https://example.com/wp-json/', ParseThis\pt_find_rest_endpoint( $links ) );
		$this->assertSame( 'https://example.com/a,b', ParseThis\pt_find_rest_alternate( $links ) );
	}

	/**
	 * A link with several rel values yields one entry per rel.
	 */
	public function test_parse_header_links_multiple_rels() {
		$links = ParseThis\pt_parse_header_links( array( '<https://example.com/>; rel="me author"' ) );
		$this->assertSame( array( 'me', 'author' ), wp_list_pluck( $links, 'rel' ) );
	}

	/**
	 * Date normalization (C-24).
	 */
	public function test_normalize_iso8601() {
		$this->assertSame( '2026-09-01T10:00:00+00:00', ParseThis\normalize_iso8601( '2026-09-01 10:00:00 UTC' ) );
		$this->assertNull( ParseThis\normalize_iso8601( '' ) );
		$this->assertNull( ParseThis\normalize_iso8601( null ) );
		$this->assertSame( 'not a date', ParseThis\normalize_iso8601( 'not a date' ) );
	}

	/**
	 * Durations and URLs.
	 */
	public function test_small_helpers() {
		$this->assertSame( 'PT1H2M5S', ParseThis\seconds_to_iso8601( 3725 ) );
		$this->assertSame( 'PT0S', ParseThis\seconds_to_iso8601( 0 ) );
		$this->assertSame( 'https://example.com/', ParseThis\normalize_url( 'https://EXAMPLE.com' ) );
		$this->assertSame( 'https://example.com/a/c', ParseThis\pt_make_absolute_url( '../c', 'https://example.com/a/b/d' ) );
		$this->assertSame( 'https://github.com/x', ParseThis\pt_secure_rewrite( 'http://github.com/x' ) );
		$this->assertSame( 'https://x.com/jack', ParseThis\pt_secure_rewrite( 'http://x.com/jack' ) );
		$this->assertSame( 'http://example.com/', ParseThis\pt_secure_rewrite( 'http://example.com/' ) );
	}

	/**
	 * Post type discovery.
	 *
	 * @dataProvider post_type_provider
	 *
	 * @param array  $jf2      jf2 entry.
	 * @param string $expected Expected post type.
	 */
	public function test_post_type_discovery( $jf2, $expected ) {
		$this->assertSame( $expected, ParseThis\post_type_discovery( $jf2 ) );
	}

	/**
	 * Entries for test_post_type_discovery().
	 *
	 * @return array[]
	 */
	public function post_type_provider() {
		return array(
			'reply'   => array( array( 'type' => 'entry', 'in-reply-to' => 'https://example.org/' ), 'reply' ),
			'like'    => array( array( 'type' => 'entry', 'like-of' => 'https://example.org/' ), 'like' ),
			'photo'   => array( array( 'type' => 'entry', 'photo' => 'https://example.com/p.jpg' ), 'photo' ),
			'article' => array(
				array(
					'type'    => 'entry',
					'name'    => 'A Title',
					'content' => array( 'text' => 'Body text.' ),
				),
				'article',
			),
			'note'    => array(
				array(
					'type'    => 'entry',
					'name'    => 'Body text.',
					'content' => array( 'text' => 'Body text.' ),
				),
				'note',
			),
			'event'   => array( array( 'type' => 'event' ), 'event' ),
			'card'    => array( array( 'type' => 'card' ), '' ),
		);
	}

	/**
	 * Nested citations move to refs, and location is flattened.
	 */
	public function test_references_and_location() {
		$jf2 = ParseThis\jf2_references(
			array(
				'type'    => 'entry',
				'like-of' => array(
					'type' => 'cite',
					'url'  => 'https://example.org/',
					'name' => 'Liked',
				),
			)
		);
		$this->assertSame( array( 'https://example.org/' ), $jf2['like-of'] );
		$this->assertSame( 'Liked', $jf2['refs']['https://example.org/']['name'] );

		$jf2 = ParseThis\jf2_location(
			array(
				'location' => array(
					'name'      => 'Cafe',
					'latitude'  => '1',
					'longitude' => '2',
				),
			)
		);
		$this->assertSame( 'Cafe', $jf2['location'] );
		$this->assertSame( '1', $jf2['latitude'] );
	}

	/**
	 * Authors become jf2 cards.
	 */
	public function test_author_to_card() {
		$this->assertSame( array( 'type' => 'card', 'name' => 'Jane Doe' ), ParseThis\jf2_author_to_card( 'Jane Doe' ) );
		$this->assertSame( array( 'type' => 'card', 'url' => 'https://example.com/jane' ), ParseThis\jf2_author_to_card( 'https://example.com/jane' ) );
		$this->assertSame( array( 'type' => 'card', 'name' => 'Jane' ), ParseThis\jf2_author_to_card( array( 'name' => 'Jane' ) ) );
		$this->assertSame( array( 'type' => 'card', 'name' => 'Jane' ), ParseThis\jf2_author_to_card( array( 'Jane' ) ) ); // A list of one.
		$this->assertCount( 2, ParseThis\jf2_author_to_card( array( 'Jane', array( 'name' => 'Bob' ) ) ) );
		$this->assertNull( ParseThis\jf2_author_to_card( '' ) );
		$this->assertNull( ParseThis\jf2_author_to_card( array() ) );
	}

	/**
	 * Post Type Discovery follows the spec for names without content (C-54).
	 */
	public function test_post_type_discovery_name_and_content() {
		$this->assertSame( 'article', ParseThis\post_type_discovery( array( 'type' => 'entry', 'name' => 'One' ) ) );
		$this->assertSame( 'article', ParseThis\post_type_discovery( array( 'type' => 'entry', 'name' => 'Title', 'summary' => 'Something else' ) ) );
		$this->assertSame( 'note', ParseThis\post_type_discovery( array( 'type' => 'entry', 'name' => 'Hello there', 'summary' => 'Hello there, world' ) ) );
		$this->assertSame( 'note', ParseThis\post_type_discovery( array( 'type' => 'entry', 'name' => "Hello\n  there", 'content' => array( 'text' => 'Hello there world' ) ) ) );
		$this->assertSame( 'article', ParseThis\post_type_discovery( array( 'type' => 'entry', 'name' => 'Title', 'content' => array( 'text' => 'Body' ) ) ) );
		$this->assertSame( 'note', ParseThis\post_type_discovery( array( 'type' => 'entry', 'content' => array( 'text' => 'Body' ) ) ) );
		$this->assertSame( 'note', ParseThis\post_type_discovery( array( 'type' => 'entry', 'name' => '   ' ) ) );
	}

	/**
	 * PHP's HTML5 parser is used when available, and can be turned off.
	 */
	public function test_native_html_parser() {
		$html = '<div class="h-entry"><p class="p-name">Hello World<article class="e-content">Body</article></div><!-- a -- comment --><button @click="go()" x-on:click="go()">Go</button><span id="t">AT&T &copy 2026</span>';

		if ( ! class_exists( 'Dom\HTMLDocument' ) ) {
			$this->assertNull( ParseThis\pt_native_html_document( $html ) );
			$this->markTestSkipped( 'PHP\'s HTML5 parser needs PHP 8.4 or later.' );
		}

		$doc = ParseThis\pt_native_html_document( $html );
		$this->assertInstanceOf( 'DOMDocument', $doc );
		// The HTML5 algorithm: an entity without a semicolon is decoded, and the button survives
		// without its non-XML attributes.
		$this->assertSame( 'AT&T © 2026', $doc->getElementById( 't' ) ? $doc->getElementById( 't' )->textContent : ( new DOMXPath( $doc ) )->query( '//span' )->item( 0 )->textContent );
		$this->assertSame( 1, ( new DOMXPath( $doc ) )->query( '//button' )->length );
		// <article> closes the <p>, as browsers do, so the name is just the title.
		$mf2 = ( new Mf2\Parser( $doc, 'https://example.com/' ) )->parse();
		$this->assertSame( array( 'Hello World' ), $mf2['items'][0]['properties']['name'] );

		// pt_load_domdocument() uses it by default (unless the suite runs with PARSE_THIS_NATIVE_HTML=0)...
		if ( apply_filters( 'parse_this_native_html_parser', true ) ) {
			$this->assertSame( 'AT&T © 2026', ( new DOMXPath( ParseThis\pt_load_domdocument( $html ) ) )->query( '//span' )->item( 0 )->textContent );
		}
		// ...and not when the filter turns it off (masterminds leaves &copy without a semicolon as it is).
		add_filter( 'parse_this_native_html_parser', '__return_false' );
		$text = ( new DOMXPath( ParseThis\pt_load_domdocument( $html ) ) )->query( '//span' )->item( 0 )->textContent;
		remove_filter( 'parse_this_native_html_parser', '__return_false' );
		$this->assertSame( 'AT&T &copy 2026', $text );
	}

	/**
	 * Empty HTML gives an empty document rather than an error.
	 */
	public function test_load_domdocument_empty() {
		$this->assertInstanceOf( 'DOMDocument', ParseThis\pt_load_domdocument( '' ) );
	}

	/**
	 * jf2 to mf2 and back: non-arrays, mf2 passed through, lists and feeds.
	 */
	public function test_jf2_mf2_round_trip_edges() {
		$this->assertSame( 'text', ParseThis\jf2_to_mf2( 'text' ) );
		$this->assertSame( array(), ParseThis\jf2_to_mf2( array() ) );
		$mf2 = array(
			'type'       => array( 'h-entry' ),
			'properties' => array(),
		);
		$this->assertSame( $mf2, ParseThis\jf2_to_mf2( $mf2 ) );
		$this->assertSame( array( 'name' => 'No type' ), ParseThis\jf2_to_mf2( array( 'name' => 'No type' ) ) );

		$converted = ParseThis\jf2_to_mf2(
			array(
				'type'     => 'entry',
				'category' => array( 'a', 'b' ),
				'_raw'     => 'skipped',
				'empty'    => '',
			)
		);
		$this->assertSame( array( 'a', 'b' ), $converted['properties']['category'] );
		$this->assertArrayNotHasKey( '_raw', $converted['properties'] );
		$this->assertArrayNotHasKey( 'empty', $converted['properties'] );

		$this->assertSame( 'text', ParseThis\mf2_to_jf2( 'text' ) );
		$one = ParseThis\mf2_to_jf2(
			array(
				array(
					'type'       => array( 'h-card' ),
					'properties' => array( 'name' => array( 'One' ) ),
				),
			)
		);
		$this->assertSame( 'One', $one['name'] );
		$two = ParseThis\mf2_to_jf2(
			array(
				array(
					'type'       => array( 'h-card' ),
					'properties' => array( 'name' => array( 'One' ) ),
				),
				array(
					'type'       => array( 'h-card' ),
					'properties' => array( 'name' => array( 'Two' ) ),
				),
			)
		);
		$this->assertSame( array( 'One', 'Two' ), wp_list_pluck( $two, 'name' ) );
	}

	/**
	 * Location: absent, unusable, a label, refs, nameless, and a check-in filled in from it.
	 */
	public function test_jf2_location_edges() {
		$this->assertSame( array( 'name' => 'x' ), ParseThis\jf2_location( array( 'name' => 'x' ) ) );
		$this->assertSame( array( 'location' => 'Text' ), ParseThis\jf2_location( array( 'location' => 'Text' ) ) );

		$labelled = ParseThis\jf2_location(
			array(
				'location' => array(
					'label'    => '1 Main Street',
					'name'     => 'Ignored',
					'altitude' => '30',
				),
			)
		);
		$this->assertSame( '1 Main Street', $labelled['location'] );
		$this->assertSame( '30', $labelled['altitude'] );

		$nameless = ParseThis\jf2_location( array( 'location' => array( 'latitude' => '1' ) ) );
		$this->assertArrayNotHasKey( 'location', $nameless );
		$this->assertSame( '1', $nameless['latitude'] );

		$checkin = ParseThis\jf2_location(
			array(
				'location' => array( 'https://venue.example/' ),
				'checkin'  => 'https://venue.example/',
				'refs'     => array(
					'https://venue.example/' => array(
						'type'     => 'card',
						'name'     => 'Venue',
						'url'      => 'https://venue.example/',
						'latitude' => '5',
					),
				),
			)
		);
		$this->assertSame( 'Venue', $checkin['location'] );
		$this->assertSame( '5', $checkin['latitude'] );
		$this->assertSame( 'Venue', $checkin['checkin']['name'] );
	}

	/**
	 * References: non-arrays, url lists, nested refs, and author lists that hold nothing usable.
	 */
	public function test_references_edges() {
		$this->assertSame( 'x', ParseThis\jf2_references( 'x' ) );
		$jf2 = ParseThis\jf2_references(
			array(
				'type'        => 'entry',
				'in-reply-to' => array(
					array(
						'type' => 'cite',
						'url'  => array( 'https://a.example/', 'https://b.example/' ),
						'refs' => array(
							'https://c.example/' => array( 'name' => 'Nested' ),
						),
					),
					array( 'type' => 'cite' ),
				),
			)
		);
		$this->assertSame( 'https://a.example/', $jf2['in-reply-to'][0] );
		$this->assertSame( 'Nested', $jf2['refs']['https://c.example/']['name'] );
		$this->assertArrayNotHasKey( 'refs', $jf2['in-reply-to'][1] );

		$this->assertNull( ParseThis\jf2_author_to_card( array( '', array() ) ) );
	}

	/**
	 * URL helpers: stripped queries, hostless URLs, fragments and absolute URL edge cases.
	 */
	public function test_url_helper_edges() {
		$this->assertSame( 'https://example.com/a', ParseThis\normalize_url( 'https://example.com/a?b=c', true ) );
		$this->assertNull( ParseThis\normalize_url( '/relative' ) );
		$this->assertSame( 'https://u:p@example.com:8080/a?b#c', ParseThis\build_url( wp_parse_url( 'https://u:p@example.com:8080/a?b#c' ) ) );

		$this->assertSame( 'x', ParseThis\pt_make_absolute_url( 'x', '' ) );
		$this->assertSame( 'x', ParseThis\pt_make_absolute_url( 'x', 'http:///bad' ) );
		$this->assertSame( 'http:///bad', ParseThis\pt_make_absolute_url( 'http:///bad', 'https://example.com/' ) );
		$this->assertSame( 'https://cdn.example:81/i.png', ParseThis\pt_make_absolute_url( '//cdn.example:81/i.png', 'https://example.com/' ) );
		$this->assertSame( 'https://example.com:8443/p?q=1#f', ParseThis\pt_make_absolute_url( '/p?q=1#f', 'https://example.com:8443/a/b' ) );
	}

	/**
	 * Fragments are found by id, URL-decoded; bad input gives null.
	 */
	public function test_find_fragment_element() {
		$doc = ParseThis\pt_load_domdocument( '<div id="a b">x</div>' );
		$this->assertNull( ParseThis\pt_find_fragment_element( null, 'a' ) );
		$this->assertNull( ParseThis\pt_find_fragment_element( $doc, '' ) );
		$this->assertNull( ParseThis\pt_find_fragment_element( $doc, 'missing' ) );
		$this->assertSame( 'x', ParseThis\pt_find_fragment_element( $doc, 'a%20b' )->textContent );
	}

	/**
	 * The effective URL is the one after redirects, keeping the original fragment.
	 */
	public function test_effective_url() {
		$this->assertSame( 'https://a.example/#f', ParseThis\pt_effective_url( 'https://a.example/#f', array() ) );
		$class        = class_exists( '\WpOrg\Requests\Response' ) ? '\WpOrg\Requests\Response' : 'Requests_Response';
		$raw          = new $class();
		$raw->url     = 'https://93.184.216.34/final';
		$response     = array( 'http_response' => new WP_HTTP_Requests_Response( $raw ) );
		$this->assertSame( 'https://93.184.216.34/final#f', ParseThis\pt_effective_url( 'https://a.example/#f', $response ) );
		$raw->url = 'https://93.184.216.34/final#g';
		$this->assertSame( 'https://93.184.216.34/final#g', ParseThis\pt_effective_url( 'https://a.example/#f', $response ) );
		$raw->url = 'not a url';
		$this->assertSame( 'https://a.example/', ParseThis\pt_effective_url( 'https://a.example/', $response ) );
	}

	/**
	 * Content sniffing: feeds and JSON served with generic types.
	 */
	public function test_sniff_content_type() {
		$this->assertSame( 'text/html', ParseThis\pt_sniff_content_type( 'text/html', '' ) );
		$this->assertSame( 'application/atom+xml', ParseThis\pt_sniff_content_type( 'application/atom+xml', '<rss>' ) );
		$this->assertSame( 'application/rss+xml', ParseThis\pt_sniff_content_type( 'text/xml', "\xEF\xBB\xBF<?xml version=\"1.0\"?><!-- c --><rss version=\"2.0\">" ) );
		$this->assertSame( 'application/atom+xml', ParseThis\pt_sniff_content_type( 'text/xml', '<feed xmlns="http://www.w3.org/2005/Atom">' ) );
		$this->assertSame( 'application/feed+json', ParseThis\pt_sniff_content_type( 'text/plain', '{"version":"https://jsonfeed.org/version/1.1","items":[]}' ) );
		$this->assertSame( 'application/mf2+json', ParseThis\pt_sniff_content_type( '', '{"items":[{"type":["h-entry"],"properties":{}}]}' ) );
		$this->assertSame( 'text/plain', ParseThis\pt_sniff_content_type( 'text/plain', '{"other":true}' ) );
	}

	/**
	 * Small predicates and conversions with unusual input.
	 */
	public function test_predicates_with_bad_input() {
		$this->assertFalse( ParseThis\name_is_content_prefix( null, 'x' ) );
		$this->assertTrue( ParseThis\name_is_content_prefix( 'Hello…', 'Hello world' ) );
		$this->assertSame( '', ParseThis\post_type_discovery( 'entry' ) );
		$this->assertFalse( ParseThis\pt_find_rest_alternate( array( array( 'rel' => 'alternate', 'type' => 'text/html', 'uri' => 'x' ) ) ) );
		$this->assertFalse( ParseThis\pt_find_rest_endpoint( array( array( 'rel' => 'next', 'uri' => 'x' ) ) ) );
		$this->assertSame( 'https://example.com/wp-json/', ParseThis\pt_find_rest_endpoint( array( array( 'rel' => 'https://api.w.org/', 'uri' => 'https://example.com/wp-json/' ) ) ) );
	}

	/**
	 * Link headers without angle brackets, and without a rel.
	 */
	public function test_parse_header_links_loose() {
		$links = ParseThis\pt_parse_header_links( 'https://example.com/a; rel=next; title="A"' );
		$this->assertSame( 'https://example.com/a', $links[0]['uri'] );
		$this->assertSame( 'A', $links[0]['title'] );
		$this->assertSame( array( 'uri' => 'https://example.com/b' ), ParseThis\pt_parse_header_links( '<https://example.com/b>; ;' )[0] );
		$this->assertSame( array(), ParseThis\pt_parse_header_links( array() ) );
	}

	/**
	 * Relative URLs in an HTML fragment are made absolute; fragments and bad input are left alone.
	 */
	public function test_absolute_urls_in_html() {
		$this->assertSame( '<p>No links</p>', ParseThis\pt_absolute_urls_in_html( '<p>No links</p>', 'https://example.com/' ) );
		$this->assertSame( '<a href="/x">x</a>', ParseThis\pt_absolute_urls_in_html( '<a href="/x">x</a>', 'not a url' ) );
		$html = ParseThis\pt_absolute_urls_in_html( '<a href="/x">x</a><a href="#top">t</a><video poster="p.jpg" src="v.mp4"></video>', 'https://example.com/post/' );
		$this->assertStringContainsString( 'href="https://example.com/x"', $html );
		$this->assertStringContainsString( 'href="#top"', $html );
		$this->assertStringContainsString( 'poster="https://example.com/post/p.jpg"', $html );
		$this->assertStringContainsString( 'src="https://example.com/post/v.mp4"', $html );
	}

	/**
	 * Remote fetches: errors pass through, and a refused request is retried once with a browser user agent.
	 */
	public function test_remote_get_retry() {
		$this->assertWPError( ParseThis\pt_remote_get( 'https://unmocked.example/' ) );

		$retry = function ( $pre, $args, $url ) {
			if ( 'https://retry.example/' !== $url ) {
				return $pre;
			}
			$browser = false !== strpos( $args['user-agent'], 'Mozilla' );
			return array(
				'headers'  => array(),
				'body'     => $browser ? 'ok' : '',
				'response' => array(
					'code'    => $browser ? 200 : 403,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $retry, 10, 3 );
		$response = ParseThis\pt_remote_get( 'https://retry.example/' );
		remove_filter( 'pre_http_request', $retry, 10 );
		$this->assertSame( 'ok', wp_remote_retrieve_body( $response ) );

		$this->respond( 'https://refused.example/', '', 'text/html', array(), 403 );
		$error = ParseThis\pt_remote_get( 'https://refused.example/' );
		$this->assertSame( 403, $error->get_error_data()['response_code'] );
	}

	/**
	 * Author archive URLs and profile URLs resolve to local users.
	 */
	public function test_url_to_author_and_user() {
		$this->assertNull( ParseThis\url_to_author( 'https://elsewhere.example/author/admin/' ) );
		$this->assertNull( ParseThis\url_to_user( '' ) );

		$editor = self::factory()->user->create(
			array(
				'role'          => 'editor',
				'user_nicename' => 'writer',
				'user_url'      => 'https://writer.example/',
			)
		);
		$this->assertSame( $editor, ParseThis\url_to_author( home_url( '/?author=' . $editor ) )->ID );
		$this->assertNull( ParseThis\url_to_author( home_url( '/author/writer/' ) ) ); // No rewrite rules.

		$this->set_permalink_structure( '/%postname%/' );
		$this->assertSame( $editor, ParseThis\url_to_author( home_url( '/author/writer/' ) )->ID );
		$this->assertNull( ParseThis\url_to_author( home_url( '/author/nobody/' ) ) );
		$this->assertSame( $editor, ParseThis\url_to_user( home_url( '/author/writer' ) )->ID );
		$this->set_permalink_structure( '' );

		$this->assertSame( $editor, ParseThis\url_to_user( 'https://writer.example' )->ID );
		$this->assertNull( ParseThis\url_to_user( 'https://stranger.example/' ) );
		// Two users can edit posts, so the site URL names neither of them.
		$this->assertNull( ParseThis\url_to_user( home_url( '/' ) ) );
		wp_delete_user( $editor );
		$this->assertSame( 1, ParseThis\url_to_user( home_url() )->ID );
	}
}
