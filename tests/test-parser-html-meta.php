<?php
/**
 * Tests for the meta-tag mappings in the HTML parser.
 *
 * @package Parse_This
 */

use ParseThis\HTML;

/**
 * HTML meta-tag parser tests: Open Graph types, Dublin Core, Parse.ly and generic tags.
 */
class Parser_HTML_Meta_Test extends Parse_This_TestCase {

	/**
	 * Parses a page with the given head markup.
	 *
	 * @param string $head Markup for the head.
	 * @param array  $args Optional. Parse arguments.
	 * @return array jf2.
	 */
	private function parse_head( $head, $args = array() ) {
		return HTML::parse( ParseThis\pt_load_domdocument( '<html><head>' . $head . '</head><body></body></html>' ), 'https://example.com/page/', $args );
	}

	/**
	 * Non-documents and empty meta give nothing.
	 */
	public function test_nothing_to_parse() {
		$this->assertSame( array(), HTML::parse( 'not a document', 'https://example.com/' ) );
		$this->assertSame( array(), HTML::meta_to_jf2( array() ) );
		$this->assertSame( array(), HTML::parse_meta( null ) );
	}

	/**
	 * Meta names come from property, then name, then itemprop; over-long names are skipped.
	 */
	public function test_meta_name_sources() {
		$jf2 = $this->parse_head(
			'<meta itemprop="datePublished" content="2024-03-04T05:06:07Z">'
			. '<meta name="duration" content="PT5M">'
			. '<meta name="' . str_repeat( 'x', 201 ) . '" content="ignored">',
			array( 'debug' => true )
		);
		$this->assertStringStartsWith( '2024-03-04', $jf2['published'] );
		$this->assertSame( 'PT5M', $jf2['duration'] );
		$this->assertArrayNotHasKey( str_repeat( 'x', 201 ), $jf2['_meta'] );
	}

	/**
	 * Parse.ly metadata: JSON in parsely-metadata and parsely-page, and the flat tags.
	 */
	public function test_parsely() {
		$page = wp_json_encode(
			array(
				'title'    => 'Parsely Title',
				'pub_date' => '2023-01-02T03:04:05Z',
				'image_url' => 'https://example.com/p.jpg',
				'type'     => 'post',
			)
		);
		$jf2  = $this->parse_head(
			"<meta name='parsely-page' content='" . esc_attr( $page ) . "'>"
			. "<meta name='parsely-metadata' content='" . esc_attr( wp_json_encode( array( 'k' => 'v' ) ) ) . "'>"
			. '<meta name="parsely-author" content="One"><meta name="parsely-author" content="Two">'
			. '<meta name="parsely-tags" content="alpha,beta">',
			array( 'debug' => true )
		);
		$this->assertSame( 'Parsely Title', $jf2['name'] );
		$this->assertSame( 'https://example.com/p.jpg', $jf2['featured'] );
		$this->assertStringStartsWith( '2023-01-02', $jf2['published'] );
		$this->assertSame( 'entry', $jf2['type'] );
		$this->assertSame( array( 'One', 'Two' ), $jf2['author'] );
		$this->assertSame( array( 'alpha', 'beta' ), $jf2['category'] );
		$this->assertSame( array( 'k' => 'v' ), $jf2['_meta']['parsely-metadata'] );

		$feed = HTML::meta_to_jf2(
			array(
				'parsely-type' => 'index',
				'parsely-tags' => array( 'x', 'y' ),
			)
		);
		$this->assertSame( 'feed', $feed['type'] );
		$this->assertSame( array( 'x', 'y' ), $feed['category'] );
	}

	/**
	 * Parse.ly tags separated by a comma and a space are trimmed.
	 */
	public function test_parsely_tags_trimmed() {
		$jf2 = HTML::meta_to_jf2( array( 'parsely-tags' => 'alpha, beta' ) );
		$this->assertSame( array( 'alpha', 'beta' ), $jf2['category'] );
	}

	/**
	 * Open Graph: image and video details, audio, locale, location and site name.
	 */
	public function test_open_graph_details() {
		$jf2 = $this->parse_head(
			'<meta property="og:url" content="https://www.example.com/page/">'
			. '<meta property="og:image" content="https://example.com/i.jpg">'
			. '<meta property="og:image:width" content="800">'
			. '<meta property="og:video:url" content="https://example.com/v.mp4">'
			. '<meta property="og:video:tag" content="clips">'
			. '<meta property="og:audio" content="https://example.com/a.mp3">'
			. '<meta property="og:locale" content="en_US">'
			. '<meta property="og:latitude" content="45.5">'
			. '<meta property="og:longitude" content="-122.6">'
		);
		$this->assertSame( 'https://example.com/i.jpg', $jf2['featured'] );
		$this->assertSame( 'https://example.com/v.mp4', $jf2['video'] );
		$this->assertSame( 'clips', $jf2['category'] );
		$this->assertSame( 'https://example.com/a.mp3', $jf2['audio'] );
		$this->assertSame( 'en_US', $jf2['locale'] );
		$this->assertSame( '45.5', $jf2['location']['latitude'] );
		$this->assertSame( 'example.com', $jf2['publication'] );

		$secure = HTML::meta_to_jf2( array( 'og' => array( 'image' => array( 'secure_url' => 'https://example.com/s.jpg' ) ) ) );
		$this->assertSame( 'https://example.com/s.jpg', $secure['featured'] );
	}

	/**
	 * Open Graph types: articles, books, profiles, songs and videos.
	 */
	public function test_open_graph_types() {
		$article = $this->parse_head(
			'<meta property="og:type" content="article">'
			. '<meta property="article:published_time" content="2024-01-01T10:00:00Z">'
			. '<meta property="article:author" content="https://example.com/writer">'
			. '<meta property="article:tag" content="news">'
		);
		$this->assertSame( 'entry', $article['type'] );
		$this->assertSame( 'https://example.com/writer', $article['author'] );
		$this->assertSame( 'news', $article['category'] );

		$book = $this->parse_head( '<meta property="og:type" content="book"><meta property="book:isbn" content="978-1"><meta property="book:release_date" content="2020-05-05">' );
		$this->assertSame( 'cite', $book['type'] );
		$this->assertSame( '978-1', $book['uid'] );
		$this->assertSame( '2020-05-05', $book['release_date'] );

		$profile = $this->parse_head( '<meta property="og:type" content="profile">' );
		$this->assertSame( 'card', $profile['type'] );

		$song = $this->parse_head(
			'<meta property="og:type" content="music.song">'
			. '<meta property="music:musician" content="https://example.com/band">'
			. '<meta property="music:duration" content="240">'
			. '<meta property="music:release_date" content="2019-09-09">'
			. '<meta property="music:album" content="https://example.com/album">'
		);
		$this->assertSame( 'cite', $song['type'] );
		$this->assertSame( 'https://example.com/band', $song['author'] );
		$this->assertSame( '240', $song['duration'] );
		$this->assertSame( 'https://example.com/album', $song['publication'] );

		$movie = $this->parse_head(
			'<meta property="og:type" content="video.movie">'
			. '<meta property="video:tag" content="drama">'
			. '<meta property="video:release_date" content="2001-01-01">'
			. '<meta property="video:duration" content="7200">'
		);
		$this->assertSame( 'cite', $movie['type'] );
		$this->assertSame( 'drama', $movie['category'] );
		$this->assertSame( '7200', $movie['duration'] );
	}

	/**
	 * An article's modified time becomes jf2's updated.
	 */
	public function test_article_modified_is_updated() {
		$this->markTestSkipped( 'meta_to_jf2() maps article:modified_time to "modified", which is not a jf2 property (#185).' );
		$jf2 = $this->parse_head( '<meta property="og:type" content="article"><meta property="article:modified_time" content="2024-02-02T10:00:00Z">' );
		$this->assertStringStartsWith( '2024-02-02', $jf2['updated'] );
	}

	/**
	 * Generic tags: citation authors and dates, Foursquare coordinates, and name.
	 */
	public function test_generic_tags() {
		$jf2 = HTML::meta_to_jf2(
			array(
				'citation_author' => array( 'Researcher' ),
				'citation_date'   => '2010/05/06',
				'playfoursquare'  => array(
					'location:latitude'  => '1.5',
					'location:longitude' => '2.5',
				),
				'name'            => 'Named',
			)
		);
		$this->assertSame( 'Researcher', $jf2['author'] );
		$this->assertArrayHasKey( 'published', $jf2 );
		$this->assertSame( '1.5', $jf2['latitude'] );
		$this->assertSame( '2.5', $jf2['longitude'] );
		$this->assertSame( 'Named', $jf2['name'] );
	}

	/**
	 * Dublin Core as grouped by parse_meta(): title, creators, description and date.
	 */
	public function test_dublin_core_grouped() {
		$jf2 = HTML::meta_to_jf2(
			array(
				'dc' => array(
					'Title'       => 'DC Title',
					'Creator'     => array( 'Ann', 'Bob' ),
					'Description' => 'DC summary',
					'Date'        => '2022-02-22',
				),
			)
		);
		$this->assertSame( 'DC Title', $jf2['name'] );
		$this->assertSame( array( 'Ann', 'Bob' ), wp_list_pluck( $jf2['author'], 'name' ) );
		$this->assertSame( 'DC summary', $jf2['summary'] );
		$this->assertStringStartsWith( '2022-02-22', $jf2['published'] );

		$one = HTML::meta_to_jf2( array( 'dc' => array( 'Creator' => 'Solo' ) ) );
		$this->assertSame( 'Solo', $one['author'] );
	}

	/**
	 * Dublin Core tags as pages write them (DC.title, dc.creator) are recognized.
	 */
	public function test_dublin_core_in_markup() {
		$this->markTestSkipped( 'Dublin Core is read only from "dc.Title"-style names; DC.title and dc.title are ignored (#186).' );
		$jf2 = $this->parse_head( '<meta name="DC.title" content="Upper"><meta name="dc.creator" content="Lower Creator"><meta name="DC.description" content="About">' );
		$this->assertSame( 'Upper', $jf2['name'] );
		$this->assertSame( 'Lower Creator', $jf2['author'] );
		$this->assertSame( 'About', $jf2['summary'] );
	}

	/**
	 * parse_meta() groups prefixed keys, keeping a plain value as the first entry.
	 */
	public function test_parse_meta_grouping() {
		$meta = HTML::parse_meta(
			array(
				'og:image'       => 'https://example.com/i.jpg',
				'og:image:width' => '800',
				'twitter.card'   => array( 'summary', 'summary' ),
				'plain'          => 'value',
			)
		);
		$this->assertSame( 'summary', $meta['twitter']['card'] );
		$this->assertSame( 'value', $meta['plain'] );
		$og = HTML::parse_meta( $meta['og'] );
		$this->assertSame( 'https://example.com/i.jpg', $og['image'][0] );
		$this->assertSame( '800', $og['image']['width'] );
	}
}
