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
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( array( 'response_code' => 403 ), $result->get_error_data() );
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
		// The feed is downloaded once: SimplePie reuses fetch()'s response (P-1).
		$this->assertCount( 1, $this->requests );
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
		$this->respond_site_data(
			'https://example.com/wp-json/',
			array(
				'name'        => 'Example Site',
				'description' => 'Just another site',
				'url'         => 'https://example.com',
			)
		);

		$jf2 = $this->fetch_and_parse( 'https://example.com/wp-json/wp/v2/posts' );
		$this->assertSame( 'feed', $jf2['type'] );
		$this->assertSame( array( 'Two', 'One' ), wp_list_pluck( $jf2['items'], 'name' ) );
		$this->assertSame( '2', $jf2['_total'] );
		$this->assertSame( 'Example Site', $jf2['name'] );
		$this->assertSame( 'Just another site', $jf2['summary'] );
		$this->assertSame( 'https://example.com', $jf2['url'] );
		$this->assertNotContains( 'https://example.com/wp-json/wp/v2/posts/?_embed=1', wp_list_pluck( $this->requests, 'url' ) );
		// Only the needed site fields are requested, not the whole embedded index.
		$this->assertNotContains( 'https://example.com/wp-json/?_embed=1', wp_list_pluck( $this->requests, 'url' ) );
		$this->assertContains( 'https://example.com/wp-json/?_fields=name,url,timezone_string,gmt_offset,description', wp_list_pluck( $this->requests, 'url' ) );
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
		$this->respond_site_data( 'https://example.com/?rest_route=/', array( 'name' => 'Plain Site' ) );

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

	/**
	 * HTTP error pages are not parsed as content; 410 Gone is (C-51).
	 */
	public function test_http_error_responses() {
		$page = '<html><head><title>Oops</title></head><body><div class="h-entry"><p class="e-content">This post has been deleted.</p></div></body></html>';
		foreach ( array(
			401 => 'unauthorized',
			403 => 'forbidden',
			404 => 'not_found',
			500 => 'http_error',
		) as $code => $error ) {
			$this->respond( 'https://example.com/e' . $code, $page, 'text/html', array(), $code );
			$parser = new ParseThis\Parser( 'https://example.com/e' . $code );
			$result = $parser->fetch();
			$this->assertWPError( $result );
			$this->assertSame( $error, $result->get_error_code() );
			$this->assertSame( array( 'response_code' => $code ), $result->get_error_data() );
		}

		$this->respond( 'https://example.com/gone', $page, 'text/html', array(), 410 );
		$parser = new ParseThis\Parser( 'https://example.com/gone' );
		$this->assertTrue( $parser->fetch() );
		$parser->parse();
		$jf2 = $parser->get();
		$this->assertSame( 'This post has been deleted.', $jf2['content']['text'] );
		$this->assertSame( 410, $jf2['_code'] );
	}

	/**
	 * Feeds and JSON served with a generic or wrong content type are recognized (X-11).
	 */
	public function test_content_type_sniffing() {
		$rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>Sniffed</title><link>https://example.com/</link><item><title>One</title><link>https://example.com/1</link><description>First post</description></item></channel></rss>';
		$this->respond( 'https://example.com/rss-as-text', $rss, 'text/plain' );
		$jf2 = $this->fetch_and_parse( 'https://example.com/rss-as-text' );
		$this->assertSame( 'feed', $jf2['type'] );
		$this->assertSame( 'Sniffed', $jf2['name'] );

		$atom = '<?xml version="1.0" encoding="utf-8"?><!-- generator --><feed xmlns="http://www.w3.org/2005/Atom"><title>Atom</title><id>urn:x</id><updated>2026-01-01T00:00:00Z</updated><entry><title>E</title><id>urn:e</id><link href="https://example.com/e"/><updated>2026-01-01T00:00:00Z</updated><content>Entry</content></entry></feed>';
		$this->respond( 'https://example.com/atom-as-html', $atom, 'text/html; charset=utf-8' );
		$jf2 = $this->fetch_and_parse( 'https://example.com/atom-as-html' );
		$this->assertSame( 'feed', $jf2['type'] );
		$this->assertSame( 'Atom', $jf2['name'] );

		$this->respond( 'https://example.com/feed.json', wp_json_encode( array( 'version' => 'https://jsonfeed.org/version/1.1', 'title' => 'JSON', 'items' => array( array( 'id' => '1', 'url' => 'https://example.com/1', 'content_text' => 'Hi' ) ) ) ), 'text/plain' );
		$jf2 = $this->fetch_and_parse( 'https://example.com/feed.json' );
		$this->assertSame( 'feed', $jf2['type'] );
		$this->assertSame( 'JSON', $jf2['name'] );

		$this->respond( 'https://example.com/mf2.json', wp_json_encode( array( 'items' => array( array( 'type' => array( 'h-entry' ), 'properties' => array( 'name' => array( 'From mf2 JSON' ), 'content' => array( 'Body' ) ) ) ), 'rels' => array(), 'rel-urls' => array() ) ), 'application/octet-stream' );
		$jf2 = $this->fetch_and_parse( 'https://example.com/mf2.json' );
		$this->assertSame( 'From mf2 JSON', $jf2['name'] );

		// HTML, and XHTML with an XML declaration, are left as HTML.
		$this->assertSame( 'text/html', ParseThis\pt_sniff_content_type( 'text/html', '<?xml version="1.0"?><!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml"><body><p>Hi</p></body></html>' ) );
		$this->assertSame( 'text/html', ParseThis\pt_sniff_content_type( 'text/html', '<!DOCTYPE html><html><body>{ not json }</body></html>' ) );
		$this->assertSame( 'application/rss+xml', ParseThis\pt_sniff_content_type( 'application/xml', "\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<rdf:RDF xmlns:rdf=\"x\"></rdf:RDF>" ) );
	}

	/**
	 * Results report the HTTP status and the kind of source (X-4).
	 */
	public function test_response_metadata() {
		$this->respond( 'https://example.com/mf2', '<div class="h-entry"><p class="e-content">Hi</p></div>' );
		$jf2 = $this->fetch_and_parse( 'https://example.com/mf2' );
		$this->assertSame( 200, $jf2['_code'] );
		$this->assertSame( 'mf2+html', $jf2['_source_format'] );

		$this->respond( 'https://example.com/plain', '<html><head><meta property="og:title" content="Plain"></head><body></body></html>' );
		$this->assertSame( 'html', $this->fetch_and_parse( 'https://example.com/plain' )['_source_format'] );

		$this->respond( 'https://example.com/rss', '<?xml version="1.0"?><rss version="2.0"><channel><title>R</title><link>https://example.com/</link></channel></rss>', 'application/rss+xml' );
		$this->assertSame( 'xml', $this->fetch_and_parse( 'https://example.com/rss' )['_source_format'] );

		$this->respond( 'https://example.com/feed.json', wp_json_encode( array( 'version' => 'https://jsonfeed.org/version/1', 'title' => 'J', 'items' => array() ) ), 'application/feed+json' );
		$this->assertSame( 'feed+json', $this->fetch_and_parse( 'https://example.com/feed.json' )['_source_format'] );

		$this->respond( 'https://example.com/mf2.json', wp_json_encode( array( 'items' => array( array( 'type' => array( 'h-entry' ), 'properties' => array( 'content' => array( 'Hi' ) ) ) ) ) ), 'application/mf2+json' );
		$this->assertSame( 'mf2+json', $this->fetch_and_parse( 'https://example.com/mf2.json' )['_source_format'] );

		// A page can declare its status, as a deleted post's stub does (XRay's MetaEquivDeleted).
		$this->respond( 'https://example.com/deleted', '<html><head><meta http-equiv="STATUS" content="410 Gone"></head><body><div class="h-entry"><p class="e-content">This post has been deleted.</p></div></body></html>' );
		$jf2 = $this->fetch_and_parse( 'https://example.com/deleted' );
		$this->assertSame( 410, $jf2['_code'] );
		$this->assertSame( 'This post has been deleted.', $jf2['content']['text'] );
	}

	/**
	 * After a redirect, the document is read from where it ended up (X-4).
	 */
	public function test_effective_url_after_redirect() {
		$final          = new WpOrg\Requests\Response();
		$final->url     = 'https://example.org/posts/1/';
		$final->success = true;
		$redirected     = function ( $pre, $args, $url ) use ( $final ) {
			if ( 'https://example.com/old/' !== $url ) {
				return $pre;
			}
			return array(
				'headers'       => array( 'content-type' => 'text/html' ),
				'body'          => '<div class="h-entry"><p class="e-content">Moved</p><a class="u-photo" href="photo.jpg">p</a></div>',
				'response'      => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'       => array(),
				'filename'      => null,
				'http_response' => new WP_HTTP_Requests_Response( $final ),
			);
		};
		add_filter( 'pre_http_request', $redirected, 5, 3 );
		$parser = new ParseThis\Parser( 'https://example.com/old/' );
		$parser->fetch();
		$parser->parse();
		remove_filter( 'pre_http_request', $redirected, 5 );
		$jf2 = $parser->get();

		$this->assertSame( 'https://example.org/posts/1/', $jf2['url'] );
		$this->assertSame( 'https://example.org/posts/1/photo.jpg', $jf2['photo'] );

		// A fragment on the requested URL is kept.
		$response = array( 'http_response' => new WP_HTTP_Requests_Response( $final ) );
		$this->assertSame( 'https://example.org/posts/1/#comment-5', ParseThis\pt_effective_url( 'https://example.com/old/#comment-5', $response ) );
		$this->assertSame( 'https://example.com/x', ParseThis\pt_effective_url( 'https://example.com/x', array() ) );
	}

	/**
	 * A URL fragment picks out part of the page (X-5).
	 */
	public function test_fragment_selects_element() {
		$page = '<html><head><meta property="og:title" content="The Post Title"><meta property="og:description" content="About the post"></head><body>'
			. '<article class="h-entry"><h1 class="p-name">The Post Title</h1><div class="e-content">This page has comments.</div>'
			. '<div class="h-cite" id="comment-1000"><div class="p-author h-card"><span class="p-name">Commenter</span></div><p class="e-content">Comment text</p></div>'
			. '</article></body></html>';
		$this->respond( 'https://example.com/fragment-id', $page );

		// XRay's EntryAtFragmentID: the comment, with no title borrowed from the page.
		$jf2 = $this->fetch_and_parse( 'https://example.com/fragment-id#comment-1000' );
		$this->assertSame( 'Comment text', $jf2['content']['text'] );
		$this->assertSame( 'Commenter', $jf2['author']['name'] );
		$this->assertArrayNotHasKey( 'name', $jf2 );
		$this->assertSame( 'cite', $jf2['type'] );
		$this->assertSame( 'https://example.com/fragment-id#comment-1000', $jf2['url'] );

		// XRay's EntryAtNonExistentFragmentID: the whole page, without the fragment.
		$jf2 = $this->fetch_and_parse( 'https://example.com/fragment-id#comment-404' );
		$this->assertSame( 'The Post Title', $jf2['name'] );
		$this->assertSame( 'https://example.com/fragment-id', $jf2['url'] );
	}
}
