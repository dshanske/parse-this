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
	private function parse_html( $html ) {
		$parser = new ParseThis\Parser();
		$parser->set( $html, 'https://example.com/a/' );
		$parser->parse();
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
}
