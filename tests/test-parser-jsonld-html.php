<?php
/**
 * Tests for the JSON-LD and meta-tag parsers.
 *
 * @package Parse_This
 */

/**
 * JSON-LD and HTML parser tests.
 */
class Parser_JSONLD_HTML_Test extends Parse_This_TestCase {

	/**
	 * Parses a JSON-LD fixture.
	 *
	 * @return array jf2.
	 */
	private function jsonld_fixture() {
		return ParseThis\JSONLD::parse( ParseThis\pt_load_domdocument( $this->fixture( 'jsonld-article.html' ) ), 'https://example.com/news/', array() );
	}

	/**
	 * An article in an @graph, with an array @type, becomes an entry.
	 */
	public function test_jsonld_article() {
		$jf2 = $this->jsonld_fixture();

		$this->assertSame( 'entry', $jf2['type'] );
		$this->assertSame( 'Breaking News Headline', $jf2['name'] );
		$this->assertSame( 'A short summary of the news.', $jf2['summary'] );
		$this->assertSame( '2026-08-15T09:30:00+00:00', $jf2['published'] );
		$this->assertSame( '2026-08-15T12:00:00+00:00', $jf2['updated'] );
		$this->assertSame( 'https://example.com/news.jpg', $jf2['featured'] );
		$this->assertSame( array( 'news', 'testing', 'parse this' ), $jf2['category'] );
		$this->assertSame( '<p>The full article body.</p>', $jf2['content']['html'] );
		$this->assertSame( 'https://example.com/news.mp4', $jf2['video'] );
	}

	/**
	 * People and organizations listed as authors both become cards.
	 */
	public function test_jsonld_authors_and_publisher() {
		$jf2 = $this->jsonld_fixture();

		$this->assertCount( 2, $jf2['author'] );
		$this->assertSame( 'John Reporter', $jf2['author'][0]['name'] );
		$this->assertSame( 'https://example.com/john/', $jf2['author'][0]['url'] );
		$this->assertSame( 'Example Daily Staff', $jf2['author'][1]['name'] );
		$this->assertSame( 'Example Daily', $jf2['publication']['name'] );
		$this->assertSame( 'https://example.com/logo.png', $jf2['publication']['photo'] );
	}

	/**
	 * Node shapes that used to cause fatal errors on PHP 8.
	 */
	public function test_jsonld_edge_cases() {
		$org = ParseThis\JSONLD::organization_to_hcard(
			array(
				'@type'  => 'Organization',
				'name'   => 'Org',
				'member' => array( array( '@type' => 'Person', 'name' => 'A' ), array( '@type' => 'Person', 'name' => 'B' ) ),
			)
		);
		$this->assertCount( 2, $org['member'] ); // C-19.

		$place = ParseThis\JSONLD::place_to_hcard( array( '@type' => 'Place', 'name' => 'Cafe', 'address' => '1 Main Street' ) );
		$this->assertSame( 'Cafe', $place['name'] ); // C-20.

		$entry = ParseThis\JSONLD::article_to_hentry( array( '@type' => 'Article', 'headline' => 'H', 'video' => array( '@id' => 'https://example.com/v.mp4' ), 'datePublished' => 'not a date' ) );
		$this->assertSame( 'https://example.com/v.mp4', $entry['video'] ); // C-21.
		$this->assertSame( 'not a date', $entry['published'] ); // C-24.
	}

	/**
	 * Open Graph and article meta tags become an entry.
	 */
	public function test_open_graph() {
		$jf2 = ParseThis\HTML::parse( ParseThis\pt_load_domdocument( $this->fixture( 'ogp-article.html' ) ), 'https://example.com/og-article/' );

		$this->assertSame( 'entry', $jf2['type'] );
		$this->assertSame( 'An Open Graph Article', $jf2['name'] );
		$this->assertSame( 'Described with Open Graph.', $jf2['summary'] );
		$this->assertSame( 'https://example.com/og-article/', $jf2['url'] );
		$this->assertSame( 'https://example.com/og.jpg', $jf2['featured'] );
		$this->assertSame( 'Example OG Site', $jf2['publication'] );
		$this->assertSame( 'Alex Writer', $jf2['author'] );
		$this->assertSame( 'opengraph', $jf2['category'] );
		$this->assertSame( '2026-07-04T08:00:00+00:00', $jf2['published'] );
		$this->assertSame(
			array(
				'longitude' => '-74.0',
				'latitude'  => '40.7',
			),
			$jf2['location']
		); // C-23.
		$this->assertSame( array( 'https://example.com/clip.mp4' ), $jf2['video'] ); // C-22.
	}

	/**
	 * A page without a title parses without errors.
	 */
	public function test_page_without_title() {
		$jf2 = ParseThis\HTML::parse( ParseThis\pt_load_domdocument( '<html><body><p>No title</p></body></html>' ), 'https://example.com/' );
		$this->assertIsArray( $jf2 );
	}

	/**
	 * Parses a page carrying the given JSON-LD graph, without other markup.
	 *
	 * @param array $graph JSON-LD nodes.
	 * @return array jf2.
	 */
	private function parse_jsonld_graph( $graph ) {
		$html   = '<html><head><script type="application/ld+json">' . wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			)
		) . '</script></head><body></body></html>';
		$parser = new ParseThis\Parser();
		$parser->set( $html, 'https://example.com/page/' );
		$parser->parse( array( 'html' => false ) );
		return $parser->get();
	}

	/**
	 * A specific type wins over the WebPage node SEO plugins add, and subtypes are known (#60).
	 */
	public function test_jsonld_specific_types_and_subtypes() {
		$page = array(
			'@type'         => 'WebPage',
			'name'          => 'Page title',
			'datePublished' => '2026-10-01T10:00:00+00:00',
		);

		$jf2 = $this->parse_jsonld_graph(
			array(
				$page,
				array(
					'@type'     => 'MusicEvent',
					'name'      => 'Concert',
					'startDate' => '2026-11-01T20:00:00+00:00',
					'location'  => array(
						'@type' => 'Place',
						'name'  => 'The Hall',
					),
				),
			)
		);
		$this->assertSame( 'event', $jf2['type'] );
		$this->assertSame( 'Concert', $jf2['name'] );
		$this->assertSame( '2026-10-01T10:00:00+00:00', $jf2['published'] );

		$jf2 = $this->parse_jsonld_graph(
			array(
				$page,
				array(
					'@type'       => 'SocialMediaPosting',
					'headline'    => 'A post',
					'articleBody' => '<p>Body <b>text</b></p>',
				),
			)
		);
		$this->assertSame( 'entry', $jf2['type'] );
		$this->assertSame( 'A post', $jf2['name'] );
		$this->assertSame( 'Body text', $jf2['content']['text'] );
		$this->assertSame( '<p>Body <b>text</b></p>', $jf2['content']['html'] );

		// A WebPage alone is still an entry.
		$jf2 = $this->parse_jsonld_graph( array( $page ) );
		$this->assertSame( 'entry', $jf2['type'] );
		$this->assertSame( 'Page title', $jf2['name'] );
	}
}
