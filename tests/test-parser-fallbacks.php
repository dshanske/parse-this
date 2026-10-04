<?php
/**
 * Tests for how microformats and the fallback parsers combine (C-47).
 *
 * @package Parse_This
 */

/**
 * Fallback tests.
 */
class Parser_Fallbacks_Test extends Parse_This_TestCase {

	/**
	 * Open Graph tags used by several tests.
	 *
	 * @var string
	 */
	const OG = '<meta property="og:type" content="article"><meta property="og:title" content="OG title"><meta property="og:description" content="OG description"><meta property="og:image" content="https://example.com/og.jpg">';

	/**
	 * Parses markup as the page https://example.com/a/.
	 *
	 * @param string $html Markup.
	 * @return array jf2.
	 */
	private function parse_html( $html, $args = array() ) {
		$parser = new ParseThis\Parser();
		$parser->set( $html, 'https://example.com/a/' );
		$parser->parse( $args );
		return $parser->get();
	}

	/**
	 * Partial microformats: MF2 values win, meta tags fill the gaps.
	 */
	public function test_partial_mf2_filled_from_meta_tags() {
		$jf2 = $this->parse_html( '<html><head>' . self::OG . '</head><body><article class="h-entry"><h1 class="p-name">Article title</h1><a class="u-url" href="https://example.com/a/">link</a></article></body></html>' );

		$this->assertArrayNotHasKey( '_jf2', $jf2 );
		$this->assertSame( 'Article title', $jf2['name'] ); // Not the OG title.
		$this->assertSame( 'OG description', $jf2['summary'] );
		$this->assertSame( 'https://example.com/og.jpg', $jf2['featured'] );
		$this->assertSame( 'article', $jf2['post-type'] ); // Derived again after filling.
	}

	/**
	 * A content-less like keeps its response property and type, plus filled gaps.
	 */
	public function test_like_without_content() {
		$jf2 = $this->parse_html( '<html><head>' . self::OG . '</head><body><div class="h-entry"><a class="u-url" href="https://example.com/a/">permalink</a><a class="u-like-of" href="https://example.org/liked/">Liked</a><div class="p-author h-card"><a class="u-url p-name" href="https://example.com/">Jane</a></div></div></body></html>' );

		$this->assertSame( 'https://example.org/liked/', $jf2['like-of'] );
		$this->assertSame( 'like', $jf2['post-type'] );
		$this->assertSame( 'Jane', $jf2['author']['name'] );
		$this->assertSame( 'OG description', $jf2['summary'] );
	}

	/**
	 * Without any meta tags, the microformats result is still the result.
	 */
	public function test_like_without_meta_tags() {
		$jf2 = $this->parse_html( '<html><head><title>Like</title></head><body><div class="h-entry"><a class="u-url" href="https://example.com/a/">permalink</a><a class="u-like-of" href="https://example.org/liked/">Liked</a></div></body></html>' );

		$this->assertSame( 'entry', $jf2['type'] );
		$this->assertSame( 'https://example.org/liked/', $jf2['like-of'] );
		$this->assertSame( 'like', $jf2['post-type'] );
	}

	/**
	 * A fallback's author name fills in a nameless author card.
	 */
	public function test_author_name_filled() {
		$jf2 = $this->parse_html( '<html><head><meta property="og:type" content="article"><meta property="article:author" content="Alex Writer"></head><body><div class="h-entry"><div class="e-content">Text</div><a class="p-author h-card" href="https://example.com/alex/"><img class="u-photo" src="https://example.com/alex.jpg" alt=""></a></div></body></html>' );

		$this->assertSame( 'https://example.com/alex.jpg', $jf2['author']['photo'] );
		$this->assertSame( 'Alex Writer', $jf2['author']['name'] );
	}

	/**
	 * Registers a page that advertises its WordPress REST API alternate.
	 *
	 * @param string $head Extra markup for the page's head.
	 */
	private function page_with_rest_alternate( $head ) {
		$this->respond(
			'https://example.com/a/',
			'<html><head>' . $head . '</head><body><p>No microformats.</p></body></html>',
			'text/html',
			array( 'link' => '<https://example.com/wp-json/>; rel="https://api.w.org/", <https://example.com/wp-json/wp/v2/posts/5>; rel="alternate"; type="application/json"' )
		);
		$this->respond(
			'https://example.com/wp-json/wp/v2/posts/5?_embed=1',
			wp_json_encode(
				array(
					'id'      => 5,
					'link'    => 'https://example.com/a/',
					'title'   => array( 'rendered' => 'From REST' ),
					'content' => array( 'rendered' => '<p>Full content from REST.</p>' ),
				)
			),
			'application/json'
		);
		$this->respond_site_data( 'https://example.com/wp-json/', wp_json_encode( array( 'name' => 'Example' ) ) );
	}

	/**
	 * When local data has no content, the REST alternate is fetched to fill gaps.
	 */
	public function test_rest_alternate_when_local_is_not_enough() {
		$this->page_with_rest_alternate( '<title>Untitled</title>' );

		$parser = new ParseThis\Parser( 'https://example.com/a/' );
		$parser->fetch();
		$parser->parse();
		$jf2 = $parser->get();

		$this->assertSame( '<p>Full content from REST.</p>', $jf2['content']['html'] );
		$this->assertContains( 'https://example.com/wp-json/wp/v2/posts/5?_embed=1', wp_list_pluck( $this->requests, 'url' ) );
	}

	/**
	 * When local data has content, no remote request is made.
	 */
	public function test_no_remote_request_when_local_is_enough() {
		$this->page_with_rest_alternate( self::OG );

		$parser = new ParseThis\Parser( 'https://example.com/a/' );
		$parser->fetch();
		$parser->parse();
		$jf2 = $parser->get();

		$this->assertSame( 'OG description', $jf2['summary'] );
		$this->assertSame( array( 'https://example.com/a/' ), wp_list_pluck( $this->requests, 'url' ) );
	}

	/**
	 * With require_content, a summary isn't enough and the REST alternate is fetched.
	 */
	public function test_require_content_fetches_rest_alternate() {
		$this->page_with_rest_alternate( self::OG );

		$parser = new ParseThis\Parser( 'https://example.com/a/' );
		$parser->fetch();
		$parser->parse( array( 'require_content' => true ) );
		$jf2 = $parser->get();

		$this->assertSame( 'OG description', $jf2['summary'] ); // Meta tags filled it first.
		$this->assertSame( '<p>Full content from REST.</p>', $jf2['content']['html'] );
	}

	/**
	 * The site-specific X parser runs for x.com even when the page has meta tags.
	 */
	public function test_site_parser_always_runs_for_its_host() {
		$url = 'https://x.com/jack/status/20';
		$this->respond( $url, '<html><head><meta property="og:title" content="jack on X"><meta property="og:description" content="Short text"></head><body></body></html>' );
		$this->respond( add_query_arg( 'url', $url, 'https://publish.x.com/oembed' ), $this->fixture( 'twitter-oembed.json' ), 'application/json' );

		$parser = new ParseThis\Parser( $url );
		$parser->fetch();
		$parser->parse();
		$jf2 = $parser->get();

		$this->assertSame( 'jack', $jf2['author']['name'] );
		$this->assertSame( '2006-03-21T00:00:00+00:00', $jf2['published'] );
		$this->assertSame( 'just setting up my #twttr with @biz', $jf2['content']['value'] );
	}

	/**
	 * An author found only as a string (here an Open Graph tag) comes back as a card.
	 */
	public function test_string_author_becomes_card() {
		$jf2 = $this->parse_html( '<html><head>' . self::OG . '<meta property="article:author" content="Alex Writer"></head><body><p>Plain page</p></body></html>' );

		$this->assertSame(
			array(
				'type' => 'card',
				'name' => 'Alex Writer',
			),
			$jf2['author']
		);
	}

	/**
	 * By default single values follow jf2; always_arrays gives Microsub-style arrays.
	 */
	public function test_always_arrays() {
		$html = '<div class="h-entry"><a class="u-url" href="https://example.com/a/">a</a><img class="u-photo" src="https://example.com/p.jpg"><span class="p-category">one</span><a class="u-like-of" href="https://example.org/">l</a></div>';

		$parser = new ParseThis\Parser();
		$parser->set( $html, 'https://example.com/a/' );
		$parser->parse();
		$jf2 = $parser->get();
		$this->assertSame( 'https://example.com/p.jpg', $jf2['photo'] );
		$this->assertSame( 'one', $jf2['category'] );

		$parser->parse( array( 'always_arrays' => true ) );
		$jf2 = $parser->get();
		$this->assertSame( array( 'https://example.com/p.jpg' ), $jf2['photo'] );
		$this->assertSame( array( 'one' ), $jf2['category'] );
		$this->assertSame( array( 'https://example.org/' ), $jf2['like-of'] );
	}

	/**
	 * always_arrays also applies to every feed item.
	 */
	public function test_always_arrays_feed_items() {
		$jf2 = ParseThis\Parser::format_output(
			array(
				'type'  => 'feed',
				'items' => array(
					array(
						'type'  => 'entry',
						'photo' => 'https://example.com/1.jpg',
					),
					array(
						'type'  => 'entry',
						'photo' => array( 'https://example.com/2.jpg', 'https://example.com/3.jpg' ),
					),
				),
			),
			array( 'always_arrays' => true )
		);
		$this->assertSame( array( 'https://example.com/1.jpg' ), $jf2['items'][0]['photo'] );
		$this->assertCount( 2, $jf2['items'][1]['photo'] );
	}

	/**
	 * Only link-shortener URLs in a summary are expanded (P-2).
	 */
	public function test_only_shortener_links_are_expanded() {
		$this->respond( 'https://bit.ly/abc', '', 'text/html', array( 'location' => 'https://example.org/full-article/' ), 301 );
		$html = '<html><head><meta property="og:type" content="article"><meta property="og:description" content="Read https://bit.ly/abc and https://example.net/page"></head><body></body></html>';

		$jf2 = $this->parse_html( $html );

		$this->assertSame( 'Read https://example.org/full-article/ and https://example.net/page', $jf2['summary'] );
		$this->assertSame( array( 'https://bit.ly/abc' ), wp_list_pluck( $this->requests, 'url' ) );
	}

	/**
	 * Raw source data is included only with the debug argument, not because of WP_DEBUG (P-8).
	 */
	public function test_source_data_only_with_debug() {
		$this->assertTrue( WP_DEBUG );
		$html = '<html><head><meta property="og:title" content="Hello"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"Hello"}</script></head><body></body></html>';

		$jf2 = $this->parse_html( $html );
		$this->assertArrayNotHasKey( '_meta', $jf2 );
		$this->assertArrayNotHasKey( '_jsonld', $jf2 );

		$jf2 = $this->parse_html( $html, array( 'debug' => true ) );
		$this->assertArrayHasKey( '_meta', $jf2 );
		$this->assertArrayHasKey( '_jsonld', $jf2 );
	}

	/**
	 * Short-link expansion counts against the per-parse request budget (S-5).
	 */
	public function test_short_links_respect_request_budget() {
		foreach ( array( 'a', 'b', 'c' ) as $id ) {
			$this->respond( 'https://bit.ly/' . $id, '', 'text/html', array( 'location' => 'https://example.org/' . $id . '/' ), 301 );
		}
		$limit = function () {
			return 1;
		};
		add_filter( 'parse_this_max_requests', $limit );
		$jf2 = $this->parse_html( '<html><head><meta property="og:type" content="article"><meta property="og:description" content="https://bit.ly/a https://bit.ly/b https://bit.ly/c"></head><body></body></html>' );
		remove_filter( 'parse_this_max_requests', $limit );

		$this->assertSame( 'https://example.org/a/ https://bit.ly/b https://bit.ly/c', $jf2['summary'] );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Values from remote documents are sanitized in the output (S-4).
	 */
	public function test_output_is_sanitized() {
		$jf2 = ParseThis\Parser::format_output(
			array(
				'type'        => 'entry',
				'uid'         => 'tag:example.com,2026:1',
				'url'         => 'javascript:alert(1)',
				'name'        => 'Hello <b>world</b>',
				'summary'     => "Line one<script>x</script>\nLine two",
				'photo'       => array( 'https://example.com/a.jpg', 'data:image/png;base64,AAAA' ),
				'featured'    => 'https://example.com/f.jpg',
				'category'    => array( '<i>news</i>', 'plain' ),
				'in-reply-to' => array(
					'type' => 'cite',
					'url'  => 'vbscript:x',
					'name' => '<b>Cited</b>',
				),
				'like-of'     => 'https://example.com/liked/',
				'content'     => array(
					'html' => '<p>Kept <strong>HTML</strong></p>',
					'text' => '<p>Text</p>',
				),
				'author'      => array(
					'type'  => 'card',
					'name'  => '<em>Jane</em>',
					'url'   => 'javascript:void(0)',
					'photo' => 'https://example.com/jane.jpg',
				),
				'items'       => array(
					array(
						'type' => 'entry',
						'url'  => 'https://example.com/1/',
						'name' => '<b>One</b>',
					),
				),
				'_jsonld'     => array( 'url' => 'javascript:raw' ),
			),
			array( 'always_arrays' => false )
		);

		$this->assertSame( 'tag:example.com,2026:1', $jf2['uid'] );
		$this->assertArrayNotHasKey( 'url', $jf2 );
		$this->assertSame( 'Hello world', $jf2['name'] );
		$this->assertSame( "Line one\nLine two", $jf2['summary'] );
		$this->assertSame( array( 'https://example.com/a.jpg' ), $jf2['photo'] );
		$this->assertSame( array( 'news', 'plain' ), $jf2['category'] );
		$this->assertArrayNotHasKey( 'url', $jf2['in-reply-to'] );
		$this->assertSame( 'Cited', $jf2['in-reply-to']['name'] );
		$this->assertSame( 'https://example.com/liked/', $jf2['like-of'] );
		$this->assertSame( '<p>Kept <strong>HTML</strong></p>', $jf2['content']['html'] );
		$this->assertSame( 'Text', $jf2['content']['text'] );
		$this->assertSame( 'Jane', $jf2['author']['name'] );
		$this->assertArrayNotHasKey( 'url', $jf2['author'] );
		$this->assertSame( 'https://example.com/jane.jpg', $jf2['author']['photo'] );
		$this->assertSame( 'One', $jf2['items'][0]['name'] );
		// Debug data is left as it was.
		$this->assertSame( 'javascript:raw', $jf2['_jsonld']['url'] );
		// An author given as a URL string is sanitized before it becomes a card.
		$jf2 = ParseThis\Parser::format_output(
			array(
				'type'   => 'entry',
				'author' => 'javascript:alert(1)',
			),
			array()
		);
		$this->assertArrayNotHasKey( 'author', $jf2 );
	}

	/**
	 * Finished jf2 from a remote document has its HTML cleaned (S-4).
	 */
	public function test_remote_jf2_html_is_cleaned() {
		$this->respond(
			'https://example.com/post.jf2',
			wp_json_encode(
				array(
					'type'    => 'entry',
					'url'     => 'https://example.com/post/',
					'content' => array(
						'html' => '<p onclick="steal()">Hi</p><script>steal()</script>',
						'text' => 'Hi',
					),
				)
			),
			'application/jf2+json'
		);
		$parser = new ParseThis\Parser( 'https://example.com/post.jf2' );
		$parser->fetch();
		$parser->parse();
		$jf2 = $parser->get();

		$this->assertSame( '<p>Hi</p>', $jf2['content']['html'] );
	}

	/**
	 * Any microformats property can be u-, p- or e-, so values are sanitized by shape (C-48).
	 */
	public function test_sanitizing_follows_the_value_not_the_property() {
		$jf2 = $this->parse_html(
			'<div class="h-entry"><a class="u-url" href="/a/">a</a><span class="p-name">N</span>'
			. '<data class="p-rsvp" value="YES">Yes</data>'
			. '<span class="p-in-reply-to">A conversation at the pub</span>'
			. '<span class="p-photo">a photo of a cat</span>'
			. '<a class="u-category" href="https://tags.example/t">t</a><span class="p-category">plain <i>cat</i></span>'
			. '<div class="e-like-of">I <b>liked</b> <a href="javascript:x()">this</a></div>'
			. '<a class="u-syndication" href="javascript:alert(1)">s</a>'
			. '<div class="e-content">Text</div></div>'
		);

		$this->assertSame( 'yes', $jf2['rsvp'] );
		$this->assertSame( 'A conversation at the pub', $jf2['in-reply-to'] );
		$this->assertSame( 'a photo of a cat', $jf2['photo'] );
		$this->assertSame( array( 'plain cat', 'https://tags.example/t' ), $jf2['category'] );
		$this->assertSame( 'I liked this', $jf2['like-of']['value'] );
		$this->assertStringNotContainsString( 'javascript', $jf2['like-of']['html'] );
		$this->assertStringContainsString( '<b>liked</b>', $jf2['like-of']['html'] );
		$this->assertArrayNotHasKey( 'syndication', $jf2 );

		$jf2 = ParseThis\Parser::format_output(
			array(
				'type'        => 'entry',
				'in-reply-to' => array( 'Re: hello', "java\tscript:alert(1)", ' javascript:alert(2)', 'mailto:jane@example.com', 'ftp://example.com/file' ),
			),
			array()
		);
		$this->assertSame( array( 'Re: hello', 'mailto:jane@example.com' ), $jf2['in-reply-to'] );
	}

	/**
	 * Nested h-* objects in properties are sanitized, with and without references (C-48).
	 */
	public function test_nested_objects_are_sanitized() {
		$html = '<div class="h-entry"><a class="u-url" href="/a/">a</a><span class="p-name">N</span>'
			. '<div class="p-in-reply-to h-cite"><span class="p-name">A <b>chat</b></span><span class="p-author h-card"><span class="p-name">Bob</span><a class="u-url" href="javascript:bad()">x</a></span></div>'
			. '<div class="u-like-of h-cite"><a class="u-url" href="javascript:alert(1)">liked</a><span class="p-name">Liked</span></div>'
			. '<div class="u-bookmark-of h-cite"><a class="u-url" href="https://example.org/b">B</a><span class="p-name">Bookmarked</span></div>'
			. '<span class="p-category h-card"><a class="u-url p-name" href="javascript:c()">Alice</a></span>'
			. '<span class="p-category h-card"><a class="u-url p-name" href="https://alice.example/">Alice</a></span>'
			. '<div class="p-location h-card"><span class="p-name">Venue <i>x</i></span><a class="u-url" href="vbscript:v">v</a></div>'
			. '<div class="e-content">Text</div></div>';

		foreach ( array( false, true ) as $references ) {
			$jf2  = $this->parse_html( $html, array( 'references' => $references ) );
			$json = wp_json_encode( $jf2 );
			$this->assertStringNotContainsString( 'javascript', $json );
			$this->assertStringNotContainsString( 'vbscript', $json );
			$this->assertSame( 'A chat', $jf2['in-reply-to']['name'] );
			$this->assertSame( 'Bob', $jf2['in-reply-to']['author']['name'] );
			$this->assertSame( 'Venue x', $jf2['location']['name'] );
		}

		// With references, nested objects move to refs, keyed only by safe URLs.
		$keys = array_keys( $jf2['refs'] );
		sort( $keys );
		$this->assertSame( array( 'https://alice.example/', 'https://example.org/b' ), $keys );
		$this->assertSame( 'Bookmarked', $jf2['refs']['https://example.org/b']['name'] );
		// The tag with an unsafe URL stays inline, without it; the safe one is a reference.
		$this->assertSame(
			array(
				array(
					'name' => 'Alice',
					'type' => 'card',
				),
				'https://alice.example/',
			),
			$jf2['category']
		);
	}

	/**
	 * clean_content() keeps text before the first element (C-53).
	 */
	public function test_clean_content_keeps_leading_text() {
		$this->assertSame( 'Plain <i>x</i> and more', ParseThis\Parser::clean_content( 'Plain <i>x</i> and more' ) );
		$this->assertSame( 'Just text', ParseThis\Parser::clean_content( 'Just text' ) );
		$this->assertSame(
			'This page has a link to <a href="http://target.example.com">target.example.com</a> and some <b>formatted text</b>.',
			ParseThis\Parser::clean_content( 'This page has a link to <a href="http://target.example.com">target.example.com</a> and some <b>formatted text</b>.' )
		);
		$this->assertSame( '<p>One</p><p>Two</p>', ParseThis\Parser::clean_content( '<p>One</p><script>a()</script><script>b()</script><p>Two</p>' ) );
		$this->assertSame( 'Café ☕ <b>ok</b>', ParseThis\Parser::clean_content( 'Café ☕ <b>ok</b>' ) );

		$jf2 = $this->parse_html( '<div class="h-entry"><div class="e-content">Hello <a href="https://example.org/">there</a></div></div>' );
		$this->assertSame( 'Hello <a href="https://example.org/">there</a>', $jf2['content']['html'] );
	}

	/**
	 * A result filled only by meta tags still gets a type (C-50).
	 */
	public function test_result_from_meta_tags_has_a_type() {
		$jf2 = $this->parse_html( '<html><head><meta property="og:type" content="object"><meta property="og:title" content="A repository"><meta property="og:description" content="Some code"></head><body></body></html>' );
		$this->assertSame( 'entry', $jf2['type'] );
		$this->assertSame( 'A repository', $jf2['name'] );

		// An explicit type is kept.
		$jf2 = $this->parse_html( '<html><head><meta property="og:type" content="profile"><meta property="og:title" content="Jane"></head><body></body></html>' );
		$this->assertSame( 'card', $jf2['type'] );
	}
}
