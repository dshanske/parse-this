<?php
/**
 * Tests for the microformats2 parser.
 *
 * @package Parse_This
 */

/**
 * MF2 parser tests.
 */
class Parser_MF2_Test extends Parse_This_TestCase {

	/**
	 * An h-entry with an author h-card, content, a like and a category.
	 */
	public function test_entry() {
		$jf2 = $this->parse_fixture( 'mf2-note.html', 'https://example.com/2026/09/a-like/' );

		$this->assertSame( 'entry', $jf2['type'] );
		$this->assertSame( 'https://example.com/2026/09/a-like/', $jf2['url'] );
		$this->assertSame( '2026-09-03T08:15:00+00:00', $jf2['published'] );
		$this->assertSame( 'https://example.org/great-post/', $jf2['like-of'] );
		$this->assertSame( 'like', $jf2['post-type'] );
		$this->assertSame( 'indieweb', $jf2['category'] );
		$this->assertSame(
			array(
				'name'  => 'Jane Doe',
				'url'   => 'https://example.com/',
				'photo' => 'https://example.com/jane.jpg',
				'type'  => 'card',
			),
			$jf2['author']
		);
		$this->assertSame( 'Great read about microformats!', $jf2['content']['text'] );
		// Scripts are removed from content HTML.
		$this->assertSame( '<p>Great read about <em>microformats</em>!</p>', $jf2['content']['html'] );
	}

	/**
	 * get( 'mf2' ) converts the result back to microformats2.
	 */
	public function test_entry_as_mf2() {
		$parser = new ParseThis\Parser();
		$parser->set( $this->fixture( 'mf2-note.html' ), 'https://example.com/2026/09/a-like/' );
		$parser->parse();
		$mf2 = $parser->get( 'mf2' );

		$this->assertSame( array( 'h-entry' ), $mf2['type'] );
		$this->assertSame( array( 'https://example.com/2026/09/a-like/' ), $mf2['properties']['url'] );
		$this->assertSame( array( 'h-card' ), $mf2['properties']['author'][0]['type'] );
	}

	/**
	 * A reply whose in-reply-to is a nested h-cite is stored as a reference.
	 */
	public function test_reply_with_nested_cite() {
		$jf2 = $this->parse_fixture( 'mf2-entry.html', 'https://example.com/2026/09/testing-parse-this/' );
		$this->assertSame( 'reply', $jf2['post-type'] );
		$this->assertSame( array( 'https://example.org/original-post/' ), $jf2['in-reply-to'] );
		$this->assertSame( 'The original post', $jf2['refs']['https://example.org/original-post/']['name'] );
		$this->assertSame( array( 'testing', 'indieweb' ), $jf2['category'] );
	}

	/**
	 * Every value of a multi-valued property is kept.
	 */
	public function test_multiple_categories() {
		$jf2 = ParseThis\MF2::parse(
			'<div class="h-entry"><a class="u-url" href="https://example.com/1/">1</a><span class="p-category">a</span><span class="p-category">b</span></div>',
			'https://example.com/1/',
			array( 'references' => true )
		);
		$this->assertSame( array( 'a', 'b' ), $jf2['category'] );
	}

	/**
	 * An h-feed parsed as a feed yields its items, newest date and author.
	 */
	public function test_feed_items() {
		$result = ParseThis\MF2::parse(
			$this->fixture( 'mf2-feed.html' ),
			'https://example.com/notes/',
			array(
				'return'     => 'feed',
				'references' => true,
			)
		);
		// C-42: the feed currently comes back inside a one-item list.
		$feed = isset( $result['type'] ) ? $result : $result[0];

		$this->assertSame( 'feed', $feed['type'] );
		$this->assertSame( "Jane's Notes", $feed['name'] );
		$this->assertSame( 'Jane Doe', $feed['author']['name'] );
		$this->assertCount( 2, $feed['items'] );
		$this->assertSame( 'note', $feed['items'][0]['post-type'] );
		$this->assertSame( 'like', $feed['items'][1]['post-type'] );
		$this->assertSame( '2026-09-02T10:00:00+00:00', $feed['_last_published'] );
	}

	/**
	 * Parser::parse() with return=feed returns the feed itself, with its url.
	 */
	public function test_feed_via_parser() {
		$jf2 = $this->parse_fixture( 'mf2-feed.html', 'https://example.com/notes/', array( 'return' => 'feed' ) );
		$this->assertSame( 'feed', $jf2['type'] );
		$this->assertSame( 'https://example.com/notes/', $jf2['url'] );
		$this->assertCount( 2, $jf2['items'] );
	}

	/**
	 * Feed authors without a url don't break author matching (C-37).
	 */
	public function test_feed_author_without_url() {
		$result = ParseThis\MF2::parse(
			'<div class="h-feed"><div class="p-author h-card"><span class="p-name">Jane</span></div>'
			. '<div class="h-entry"><span class="p-name">A</span><span class="p-author">Bob</span></div></div>',
			'https://example.com/',
			array( 'return' => 'feed' )
		);
		$feed = isset( $result['type'] ) ? $result : $result[0];
		$this->assertSame( 'Jane', $feed['author']['name'] );
		$this->assertCount( 1, $feed['items'] );
	}

	/**
	 * Feed item authors have a string url.
	 */
	public function test_feed_item_author_url_is_string() {
		$result = ParseThis\MF2::parse( $this->fixture( 'mf2-feed.html' ), 'https://example.com/notes/', array( 'return' => 'feed' ) );
		$feed   = isset( $result['type'] ) ? $result : $result[0];
		$this->assertSame( 'https://example.com/', $feed['items'][0]['author']['url'] );
	}

	/**
	 * Microformat types with special handling parse without errors.
	 *
	 * @dataProvider special_types_provider
	 *
	 * @param string $html     Markup.
	 * @param string $expected Expected jf2 type.
	 */
	public function test_special_types( $html, $expected ) {
		$result = ParseThis\MF2::parse( $html, 'https://example.com/', array( 'references' => true ) );
		$item   = isset( $result['type'] ) ? $result : $result[0];
		$this->assertSame( $expected, $item['type'] );
	}

	/**
	 * Markup for test_special_types().
	 *
	 * @return array[]
	 */
	public function special_types_provider() {
		return array(
			'h-geo (C-11)'  => array( '<div class="h-geo"><span class="p-latitude">1</span><span class="p-longitude">2</span></div>', 'geo' ),
			'h-event'       => array( '<div class="h-event"><span class="p-name">Meetup</span><time class="dt-start" datetime="2026-10-01T18:00:00Z">x</time></div>', 'event' ),
			'h-adr'         => array( '<div class="h-adr"><span class="p-locality">Town</span></div>', 'adr' ),
			'h-measure'     => array( '<div class="h-measure"><span class="p-num">3</span><span class="p-unit">km</span></div>', 'measure' ),
			'h-card'        => array( '<div class="h-card"><span class="p-name">Jane</span></div>', 'card' ),
		);
	}

	/**
	 * Types whose parsers used to be fatal parse without errors (C-8, C-9).
	 */
	public function test_resume_and_leg_parse_without_errors() {
		foreach ( array( '<div class="h-resume"><span class="p-name">CV</span></div>', '<div class="h-leg"><span class="p-name">Leg</span><span class="p-origin">A</span></div>' ) as $html ) {
			$this->assertNotEmpty( ParseThis\MF2::parse( $html, 'https://example.com/', array() ) );
		}
	}

	/**
	 * Every supported type keeps its jf2 type.
	 *
	 * @dataProvider untyped_provider
	 *
	 * @param string $class    Root class name.
	 * @param string $expected Expected jf2 type.
	 */
	public function test_type_is_kept( $class, $expected ) {
		$result = ParseThis\MF2::parse( '<div class="' . $class . '"><span class="p-name">N</span></div>', 'https://example.com/', array() );
		$item   = isset( $result['type'] ) ? $result : $result[0];
		$this->assertSame( $expected, $item['type'] );
	}

	/**
	 * Types for test_type_is_kept().
	 *
	 * @return array[]
	 */
	public function untyped_provider() {
		return array(
			array( 'h-review', 'review' ),
			array( 'h-product', 'product' ),
			array( 'h-resume', 'resume' ),
			array( 'h-listing', 'listing' ),
			array( 'h-recipe', 'recipe' ),
			array( 'h-item', 'item' ),
			array( 'h-leg', 'leg' ),
		);
	}

	/**
	 * A generated summary never splits a multibyte character (C-14).
	 */
	public function test_multibyte_summary() {
		$result = ParseThis\MF2::parse( '<div class="h-entry"><div class="e-content">' . str_repeat( 'é', 400 ) . '</div></div>', 'https://example.com/', array( 'references' => true ) );
		$item   = isset( $result['type'] ) ? $result : $result[0];
		$this->assertSame( 300, mb_strlen( rtrim( $item['summary'], '.' ) ) );
		$this->assertNotFalse( wp_json_encode( $item ) );
	}

	/**
	 * Unknown microformat types keep their generic properties.
	 */
	public function test_unknown_type() {
		$result = ParseThis\MF2::parse( '<div class="h-org"><span class="p-name">Acme</span></div>', 'https://example.com/', array() );
		$item   = isset( $result['type'] ) ? $result : $result[0];
		$this->assertSame( 'Acme', $item['name'] );
		$this->assertSame( 'org', $item['type'] );
	}

	/**
	 * The limit stops parsing further children, also when given as a string (P-7).
	 */
	public function test_feed_limit() {
		$html = '<div class="h-feed">';
		for ( $i = 1; $i <= 4; $i++ ) {
			$html .= '<div class="h-entry"><a class="u-url" href="https://example.com/' . $i . '/">' . $i . '</a></div>';
		}
		$html .= '</div>';
		foreach ( array( 2, '2' ) as $limit ) {
			$result = ParseThis\MF2::parse(
				$html,
				'https://example.com/',
				array(
					'return' => 'feed',
					'limit'  => $limit,
				)
			);
			$this->assertCount( 2, $result['items'] );
		}
	}

	/**
	 * With follow, an author page shared by several items is fetched once (P-4).
	 */
	public function test_follow_fetches_each_author_page_once() {
		$this->respond( 'https://example.org/jane/', '<div class="h-card"><a class="u-url p-name" href="https://example.org/jane/">Jane Doe</a></div>' );
		$html = '<div class="h-feed">';
		for ( $i = 1; $i <= 3; $i++ ) {
			$html .= '<div class="h-entry"><a class="u-url" href="https://example.com/' . $i . '/">' . $i . '</a><a class="u-author" href="https://example.org/jane/">a</a></div>';
		}
		$html .= '</div>';

		$result = ParseThis\MF2::parse(
			$html,
			'https://example.com/',
			array(
				'return' => 'feed',
				'follow' => true,
			)
		);

		$this->assertSame( 'Jane Doe', $result['items'][2]['author']['name'] );
		$this->assertSame( array( 'https://example.org/jane/' ), array_values( array_unique( wp_list_pluck( $this->requests, 'url' ) ) ) );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Followed author pages count against the per-parse request budget (S-5).
	 */
	public function test_follow_respects_request_budget() {
		$html = '<div class="h-feed">';
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->respond( 'https://example.org/author' . $i . '/', '<div class="h-card"><a class="u-url p-name" href="https://example.org/author' . $i . '/">Author ' . $i . '</a></div>' );
			$html .= '<div class="h-entry"><a class="u-url" href="https://example.com/' . $i . '/">' . $i . '</a><a class="u-author" href="https://example.org/author' . $i . '/">a</a></div>';
		}
		$html .= '</div>';
		$limit = function () {
			return 2;
		};
		add_filter( 'parse_this_max_requests', $limit );

		$parser = new ParseThis\Parser();
		$parser->set( $html, 'https://example.com/' );
		$parser->parse(
			array(
				'return' => 'feed',
				'follow' => true,
			)
		);
		remove_filter( 'parse_this_max_requests', $limit );
		$jf2 = $parser->get();

		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'Author 1', $jf2['items'][0]['author']['name'] );
		$this->assertSame( 'Author 2', $jf2['items'][1]['author']['name'] );
		// Over budget: left as the author's URL.
		$this->assertSame( 'https://example.org/author3/', $jf2['items'][2]['author']['url'] );
	}

	/**
	 * A rel=author link on a page without microformats gives a jf2 card (C-49).
	 */
	public function test_rel_author_without_microformats_is_a_jf2_card() {
		$result = ParseThis\MF2::parse(
			'<html><head><title>T</title></head><body><a rel="author" href="https://example.org/jane">Jane Doe</a></body></html>',
			'https://example.com/post/',
			array()
		);
		$this->assertSame(
			array(
				'type' => 'card',
				'url'  => 'https://example.org/jane',
				'name' => 'Jane Doe',
			),
			$result['author']
		);

		// Without link text, only the URL.
		$result = ParseThis\MF2::parse( '<html><head><link rel="author" href="https://example.org/jane"></head><body></body></html>', 'https://example.com/post/', array() );
		$this->assertSame(
			array(
				'type' => 'card',
				'url'  => 'https://example.org/jane',
			),
			$result['author']
		);
	}

	/**
	 * A followed author page that returns an error leaves the author as its URL (C-51).
	 */
	public function test_follow_author_page_error() {
		$this->respond( 'https://example.org/missing-author/', 'Not found', 'text/html', array(), 404 );
		$parser = new ParseThis\Parser();
		$parser->set( '<div class="h-entry"><a class="u-author" href="https://example.org/missing-author/">a</a><p class="e-content">Hi</p></div>', 'https://example.com/post/' );
		$parser->parse( array( 'follow' => true ) );
		$jf2 = $parser->get();
		$this->assertSame(
			array(
				'url'  => 'https://example.org/missing-author/',
				'type' => 'card',
			),
			$jf2['author']
		);
	}

	/**
	 * A name or rating of "0" is a real value (C-52).
	 */
	public function test_zero_values_are_kept() {
		$result = ParseThis\MF2::parse( '<div class="h-card"><span class="p-name">0</span><img class="u-photo" src="https://example.com/photo.jpg"></div>', 'https://example.com/', array() );
		$this->assertSame( '0', $result['name'] );

		$result = ParseThis\MF2::parse( '<div class="h-entry"><p class="e-content">Hi</p><div class="p-author h-card"><span class="p-name">0</span></div></div>', 'https://example.com/', array() );
		$this->assertSame( '0', $result['author']['name'] );

		$result = ParseThis\MF2::parse( '<div class="h-review"><span class="p-name">Meh</span><data class="p-rating" value="0">0</data></div>', 'https://example.com/', array() );
		$this->assertSame( '0', $result['rating'] );
	}

	/**
	 * u-follow-of is read, as a URL or a nested h-card, and makes a follow (C-55).
	 */
	public function test_follow_of() {
		$result = ParseThis\MF2::parse( '<div class="h-entry"><a class="u-follow-of" href="https://realize.be/">Swentel</a></div>', 'https://example.com/f/', array( 'references' => false ) );
		$this->assertSame( 'https://realize.be/', $result['follow-of'] );
		$this->assertSame( 'follow', $result['post-type'] );

		$result = ParseThis\MF2::parse( '<div class="h-entry"><div class="u-follow-of h-card"><a class="u-url p-name" href="https://realize.be/">Swentel</a></div></div>', 'https://example.com/f/', array( 'references' => false ) );
		$this->assertSame( 'https://realize.be/', $result['follow-of']['url'] );
		$this->assertSame( 'follow', $result['post-type'] );
	}

	/**
	 * h-event, h-review and h-recipe keep their properties and get a post-type (C-56).
	 */
	public function test_event_review_recipe() {
		$args = array( 'references' => false );

		$event = ParseThis\MF2::parse(
			'<div class="h-event"><a class="u-url p-name" href="https://example.com/e">HWC</a><time class="dt-start">2016-03-09T18:30</time><time class="dt-end">2016-03-09T19:30</time><img class="u-featured" src="https://example.com/featured.jpg"><div class="e-description"><p>Come <b>by</b>.</p></div></div>',
			'https://example.com/e',
			$args
		);
		$this->assertSame( 'event', $event['post-type'] );
		$this->assertSame( '2016-03-09T19:30', $event['end'] );
		$this->assertSame( 'https://example.com/featured.jpg', $event['featured'] );
		$this->assertSame( 'Come by.', $event['content']['text'] );

		$review = ParseThis\MF2::parse(
			'<div class="h-review"><span class="p-name">Review</span><a class="u-in-reply-to u-like-of" href="https://target.example/product">p</a><data class="p-rating" value="3"></data><div class="e-content">Full text</div></div>',
			'https://example.com/r',
			$args
		);
		$this->assertSame( 'review', $review['post-type'] );
		$this->assertSame( 'https://target.example/product', $review['in-reply-to'] );
		$this->assertSame( 'https://target.example/product', $review['like-of'] );
		$this->assertSame( '3', $review['rating'] );

		$hreview = ParseThis\MF2::parse( '<div class="h-review"><span class="p-name">Old</span><div class="e-description">Described</div></div>', 'https://example.com/r2', $args );
		$this->assertSame( 'Described', $hreview['content']['text'] );

		$recipe = ParseThis\MF2::parse(
			'<div class="h-recipe"><span class="p-name">Cookies</span><span class="p-yield">12 Cookies</span><time class="dt-duration" datetime="PT30M">30 min</time><span class="p-ingredient">3 cups flour</span><span class="p-ingredient">chocolate chips</span><div class="e-instructions"><p>Mix <b>well</b>.</p></div><span class="p-nutrition">Lots</span></div>',
			'https://example.com/c',
			$args
		);
		$this->assertSame( 'recipe', $recipe['post-type'] );
		$this->assertSame( '12 Cookies', $recipe['yield'] );
		$this->assertSame( 'PT30M', $recipe['duration'] );
		$this->assertSame( array( '3 cups flour', 'chocolate chips' ), $recipe['ingredient'] );
		$this->assertSame( 'Mix well.', $recipe['instructions']['text'] );
		$this->assertSame( '<p>Mix <b>well</b>.</p>', $recipe['instructions']['html'] );
		$this->assertSame( 'Lots', $recipe['nutrition'] );
	}
}
