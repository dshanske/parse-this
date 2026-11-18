<?php
/**
 * Tests for the less common paths through the MF2 parser and MF2_Utils.
 *
 * @package Parse_This
 */

use ParseThis\MF2;
use ParseThis\MF2_Utils;

/**
 * MF2 parser path tests: feeds, alternates, authorship and item types.
 */
class Parser_MF2_Paths_Test extends Parse_This_TestCase {

	/**
	 * Parse arguments that avoid network requests.
	 *
	 * @param array $args Optional. Arguments to add.
	 * @return array Parse arguments.
	 */
	private function offline( $args = array() ) {
		return array_merge(
			array(
				'alternate' => false,
				'follow'    => false,
			),
			$args
		);
	}

	/**
	 * Feeds are found inside an h-card, implied when there's none, and given a url.
	 */
	public function test_find_hfeed() {
		$nested = MF2::find_hfeed( '<div class="h-card"><span class="p-name">Me</span><div class="h-feed" id="posts"><div class="h-entry"><span class="p-name">A</span></div></div></div>', 'https://example.com/' );
		$this->assertCount( 1, $nested );
		$this->assertSame( array( 'https://example.com/#posts' ), $nested[0]['properties']['url'] );

		$implied = MF2::find_hfeed( '<div class="h-entry"><span class="p-name">A</span></div>', 'https://example.com/list' );
		$this->assertSame( 'h-feed', $implied[0]['type'] );
		$this->assertSame( array( 'https://example.com/list' ), $implied[0]['properties']['url'] );

		$unnamed = MF2::find_hfeed(
			array(
				'items' => array(
					array(
						'type'       => array( 'h-feed' ),
						'properties' => array(),
					),
				),
			),
			'https://example.com/feed'
		);
		$this->assertSame( array( 'https://example.com/feed' ), $unnamed[0]['properties']['url'] );
	}

	/**
	 * Input that isn't a document, documents without items, and invalid return values.
	 */
	public function test_parse_degenerate_input() {
		$this->assertSame( array(), MF2::parse( null, 'https://example.com/' ) );
		$this->assertSame( array(), MF2::parse( array( 'rels' => array() ), 'https://example.com/' ) );
		$jf2 = MF2::parse( '<div class="h-entry"><p class="p-name">Hi</p></div>', 'https://example.com/', $this->offline( array( 'return' => 'everything' ) ) );
		$this->assertSame( 'Hi', $jf2['name'] );
	}

	/**
	 * A page with no items whose rel=author is the page itself is that author's card.
	 */
	public function test_rel_author_is_page() {
		$jf2 = MF2::parse( '<a rel="author" href="https://example.com/about">Me Myself</a>', 'https://example.com/about', $this->offline() );
		$this->assertSame( 'card', $jf2['type'] );
		$this->assertSame( 'Me Myself', $jf2['name'] );
	}

	/**
	 * A single item keeps the page's rel=alternate links for debugging.
	 */
	public function test_single_item_alternates() {
		$jf2 = MF2::parse( '<link rel="alternate" type="application/rss+xml" href="https://example.com/feed"><div class="h-entry"><p class="p-name">Hi</p></div>', 'https://example.com/', $this->offline() );
		$this->assertSame( array( 'https://example.com/feed' ), $jf2['_alternate'] );
	}

	/**
	 * jf2, jf2 feed and mf2 JSON alternates are fetched and used instead of the HTML.
	 */
	public function test_json_alternates() {
		$this->respond( 'https://example.com/alt/post.jf2', wp_json_encode( array( 'type' => 'entry', 'name' => 'From jf2' ) ), 'application/jf2+json' );
		$jf2 = MF2::parse( '<link rel="alternate" type="application/jf2+json" href="https://example.com/alt/post.jf2"><div class="h-entry"><p class="p-name">From HTML</p></div>', 'https://example.com/alt/post', array( 'follow' => false ) );
		$this->assertSame( 'From jf2', $jf2['name'] );

		$this->respond(
			'https://example.com/alt/feed.jf2',
			wp_json_encode(
				array(
					'type'     => 'feed',
					'name'     => 'Feed',
					'children' => array(
						array(
							'type' => 'entry',
							'name' => 'One',
						),
					),
				)
			),
			'application/jf2feed+json'
		);
		$feed = MF2::parse( '<link rel="alternate" type="application/jf2feed+json" href="https://example.com/alt/feed.jf2"><div class="h-entry"><p class="p-name">HTML</p></div>', 'https://example.com/alt/', array( 'follow' => false ) );
		$this->assertSame( 'Feed', $feed['name'] );

		$this->respond(
			'https://example.com/alt/post.mf2',
			wp_json_encode(
				array(
					'items' => array(
						array(
							'type'       => array( 'h-entry' ),
							'properties' => array( 'name' => array( 'From mf2' ) ),
						),
					),
				)
			),
			'application/mf2+json'
		);
		$mf2 = MF2::parse( '<link rel="alternate" type="application/mf2+json" href="https://example.com/alt/post.mf2"><div class="h-entry"><p class="p-name">From HTML</p></div>', 'https://example.com/alt/post2', array( 'follow' => false ) );
		$this->assertSame( 'From mf2', $mf2['name'] );
	}

	/**
	 * Several items that can't be told apart come back as a list.
	 */
	public function test_several_unrelated_items() {
		$jf2 = MF2::parse( '<div class="h-card"><a class="p-name u-url" href="https://a.example/">A</a></div><div class="h-card"><a class="p-name u-url" href="https://b.example/">B</a></div>', 'https://example.com/', $this->offline() );
		$this->assertSame( array( 'A', 'B' ), wp_list_pluck( $jf2, 'name' ) );
	}

	/**
	 * A feed request for a page with an h-card and one other item gives that item.
	 */
	public function test_feed_with_card_and_one_item() {
		$this->markTestSkipped( 'normalize_feed() leaves the remaining item at index 1, so nothing is returned (#180).' );
		$jf2 = MF2::parse( '<div class="h-card"><a class="p-name u-url" href="https://example.com/">Owner</a></div><div class="h-entry"><p class="p-name">Post</p></div>', 'https://example.com/', $this->offline( array( 'return' => 'feed' ) ) );
		$this->assertSame( 'Post', $jf2['name'] );
	}

	/**
	 * When a feed request's lone item has no author, the page's h-card becomes it.
	 */
	public function test_feed_card_becomes_author() {
		$this->markTestSkipped( 'normalize_feed() only sets the h-card as author when the item already has one, overwriting it (#180).' );
		$jf2 = MF2::parse( '<div class="h-card"><a class="p-name u-url" href="https://example.com/">Owner</a></div><div class="h-entry"><p class="p-name">Post</p></div>', 'https://example.com/', $this->offline( array( 'return' => 'feed' ) ) );
		$this->assertSame( 'Owner', $jf2['author']['name'] );
		$own = MF2::parse( '<div class="h-card"><a class="p-name u-url" href="https://example.com/">Owner</a></div><div class="h-entry"><p class="p-name">Post</p><span class="p-author">Guest</span></div>', 'https://example.com/', $this->offline( array( 'return' => 'feed' ) ) );
		$this->assertSame( 'Guest', $own['author']['name'] );
	}

	/**
	 * A feed with an id and no uid gets the page URL plus the id.
	 */
	public function test_uid_from_id() {
		$jf2 = MF2::parse( '<div class="h-feed" id="posts"><p class="p-name">Posts</p></div>', 'https://example.com/page', $this->offline() );
		$this->assertSame( 'https://example.com/page#posts', $jf2['uid'] );
	}

	/**
	 * Tracking parameters don't stop an item from matching the page.
	 */
	public function test_main_item_ignores_utm() {
		$html = '<div class="h-entry"><a class="u-url p-name" href="https://example.com/a?utm_source=x">A</a></div>'
			. '<div class="h-entry"><a class="u-url p-name" href="https://example.com/b?id=2&utm_medium=y">B</a></div>';
		$jf2  = MF2::parse( $html, 'https://example.com/b?id=2', $this->offline() );
		$this->assertSame( 'B', $jf2['name'] );
	}

	/**
	 * A page author with an h-card here points at the first other item as the content.
	 */
	public function test_main_item_after_author_card() {
		$html = '<a rel="author" href="https://example.com/me">me</a>'
			. '<div class="h-card"><a class="u-url p-name" href="https://example.com/me">Me</a></div>'
			. '<div class="h-event"><span class="p-name">Party</span></div>'
			. '<div class="h-card"><a class="u-url p-name" href="https://other.example/">Other</a></div>';
		$jf2  = MF2::parse( $html, 'https://example.com/party', $this->offline() );
		$this->assertSame( 'Party', $jf2['name'] );
	}

	/**
	 * The author falls back to the page's rel=author link text.
	 */
	public function test_author_from_rel_link() {
		$jf2 = MF2::parse( '<a rel="author" href="https://writer.example/">Writer Name</a><div class="h-entry"><p class="e-content">Hi</p></div>', 'https://example.com/post', $this->offline() );
		$this->assertSame( 'Writer Name', $jf2['author']['name'] );
		$this->assertSame( 'https://writer.example/', $jf2['author']['url'] );
	}

	/**
	 * An author URL is the author page even when its host doesn't resolve.
	 */
	public function test_author_url_without_dns() {
		$this->markTestSkipped( 'find_author() checks author URLs with a DNS lookup and treats unresolvable ones as names (#182).' );
		$jf2 = MF2::parse( '<div class="h-entry"><p class="e-content">Hi</p><a class="u-author" href="https://writer.invalid/">a</a></div>', 'https://example.com/post', $this->offline() );
		$this->assertSame( 'https://writer.invalid/', $jf2['author']['url'] );
		$this->assertArrayNotHasKey( 'name', $jf2['author'] );
	}

	/**
	 * Following author pages: mf2 JSON, pages with no cards, and pages that aren't profiles.
	 */
	public function test_author_page_variants() {
		$parse = function ( $author_url ) {
			return MF2::parse( '<div class="h-entry"><p class="e-content">Hi</p><a class="u-author" href="' . $author_url . '">a</a></div>', 'https://example.com/' . md5( $author_url ), array( 'follow' => true ) );
		};

		$this->respond(
			'https://example.org/json-author/',
			wp_json_encode(
				array(
					'items' => array(
						array(
							'type'       => array( 'h-card' ),
							'properties' => array(
								'name' => array( 'From JSON' ),
								'url'  => array( 'https://example.org/json-author/' ),
								'uid'  => array( 'https://example.org/json-author/' ),
							),
						),
					),
				)
			),
			'application/mf2+json'
		);
		$this->assertSame( 'From JSON', $parse( 'https://example.org/json-author/' )['author']['name'] );

		$this->respond( 'https://example.org/empty-author/', '<p>No microformats</p>' );
		$this->assertSame( 'https://example.org/empty-author/', $parse( 'https://example.org/empty-author/' )['author']['url'] );

		$this->respond( 'https://example.org/blog-author/', '<div class="h-entry"><p class="p-name">Not a card</p></div>' );
		$this->assertSame( 'https://example.org/blog-author/', $parse( 'https://example.org/blog-author/' )['author']['url'] );

		// The card's only URL is not a web URL, so the author page comes first.
		$this->respond( 'https://example.org/xmpp-author/', '<a rel="me" href="xmpp:me@example.org">x</a><div class="h-card"><a class="p-name u-url" href="xmpp:me@example.org">Chat</a></div>' );
		$card = $parse( 'https://example.org/xmpp-author/' )['author'];
		$this->assertSame( 'Chat', $card['name'] );
		$this->assertSame( 'https://example.org/xmpp-author/', is_array( $card['url'] ) ? $card['url'][0] : $card['url'] );
	}

	/**
	 * The entry page's own card for the author page is used, whether or not the page is followed.
	 */
	public function test_author_card_on_entry_page() {
		$html = '<div class="h-entry"><p class="e-content">Hi</p><a class="u-author" href="https://example.org/local-author/">a</a></div>'
			. '<div class="h-card"><a class="u-url p-name" href="https://example.org/local-author/">Local Card</a></div>';
		$this->respond( 'https://example.org/local-author/', '<p>Nothing here</p>' );
		$followed = MF2::parse( $html, 'https://example.com/local', array( 'follow' => true ) );
		$this->assertSame( 'Local Card', $followed['author']['name'] );
	}

	/**
	 * rel=syndication links are merged with the item's own syndication.
	 */
	public function test_syndication_rels() {
		$jf2 = MF2::parse( '<a rel="syndication" href="https://silo.example/1">s</a><div class="h-entry"><p class="p-name">Hi</p><a class="u-syndication" href="https://silo.example/2">t</a></div>', 'https://example.com/', $this->offline() );
		$this->assertEqualsCanonicalizing( array( 'https://silo.example/1', 'https://silo.example/2' ), array_values( $jf2['syndication'] ) );

		$only = MF2::parse( '<a rel="syndication" href="https://silo.example/3">s</a><div class="h-entry"><p class="p-name">Hi</p></div>', 'https://example.com/', $this->offline() );
		$this->assertSame( array( 'https://silo.example/3' ), $only['syndication'] );
	}

	/**
	 * Unknown types: invalid names and empty items give nothing.
	 */
	public function test_parse_hunknown_edges() {
		$this->assertSame( array(), MF2::parse_hunknown( array( 'type' => array( 'h-' ), 'properties' => array() ), array(), MF2::default_args() ) );
		$this->assertSame( array(), MF2::parse_hunknown( array( 'type' => array( 'h-x-empty' ), 'properties' => array() ), array(), MF2::default_args() ) );
	}

	/**
	 * Every parse_h* function ignores input that isn't a microformat.
	 */
	public function test_type_parsers_reject_non_microformats() {
		foreach ( array( 'parse_hcard', 'parse_hevent', 'parse_hreview', 'parse_hproduct', 'parse_hresume', 'parse_hlisting', 'parse_hrecipe', 'parse_hitem', 'parse_hadr', 'parse_hgeo' ) as $method ) {
			$this->assertNull( MF2::$method( 'not mf2', array(), array() ), $method );
		}
		$this->assertSame( array(), MF2::get_prop_array( array( 'x' => 1 ), array( 'name' ) ) );
	}

	/**
	 * Products, resumes, listings, items and legs.
	 */
	public function test_other_types() {
		$product = MF2::parse( '<div class="h-product"><span class="p-name">Widget</span><data class="p-price" value="9.99">$9.99</data><span class="p-identifier">W-1</span><p class="p-description">Useful</p></div>', 'https://example.com/', $this->offline() );
		$this->assertSame( 'product', $product['type'] );
		$this->assertSame( '9.99', $product['price'] );
		$this->assertSame( 'W-1', $product['identifier'] );

		$resume = MF2::parse( '<div class="h-resume"><span class="p-name">CV</span><p class="p-summary">Engineer</p></div>', 'https://example.com/', $this->offline() );
		$this->assertSame( 'resume', $resume['type'] );

		$listing = MF2::parse( '<div class="h-listing"><span class="p-name">For sale</span></div>', 'https://example.com/', $this->offline() );
		$this->assertSame( 'listing', $listing['type'] );

		$item = MF2::parse( '<div class="h-item"><span class="p-name">Thing</span></div>', 'https://example.com/', $this->offline() );
		$this->assertSame( 'item', $item['type'] );

		$leg = MF2::parse( '<div class="h-leg"><span class="p-origin">PDX</span><span class="p-destination">SFO</span><time class="dt-departure" datetime="2025-01-02T08:00:00-08:00">8am</time><time class="dt-arrival" datetime="2025-01-02T10:00:00-08:00">10am</time></div>', 'https://example.com/', $this->offline() );
		$this->assertSame( 'PDX', $leg['origin'] );
		$this->assertSame( '2025-01-02T08:00:00-08:00', $leg['departure'] );
		$this->assertSame( '2025-01-02T10:00:00-08:00', $leg['arrival'] );
	}

	/**
	 * An h-card holding entries lists them as items when one item is wanted.
	 */
	public function test_card_with_child_entries() {
		$jf2 = MF2::parse( '<div class="h-card"><a class="p-name u-url" href="https://example.com/">Owner</a><div class="h-entry"><p class="p-name">One</p></div></div>', 'https://example.com/', $this->offline() );
		$this->assertSame( 'card', $jf2['type'] );
		$this->assertSame( 'One', $jf2['items'][0]['name'] );
	}

	/**
	 * MF2::compare() checks for a prefix after trimming.
	 */
	public function test_compare() {
		$this->assertFalse( MF2::compare( '', 'a' ) );
		$this->assertTrue( MF2::compare( ' abc ', 'ab' ) );
		$this->assertFalse( MF2::compare( 'abc', 'b' ) );
	}

	/**
	 * The MF2_Utils helpers that Post Kinds and Yarns can call.
	 */
	public function test_mf2_utils_helpers() {
		$entry = array(
			'type'       => array( 'h-entry' ),
			'properties' => array(
				'name'      => array( 'Title' ),
				'summary'   => array( 'Short' ),
				'content'   => array(
					array(
						'value' => 'Body',
						'html'  => '<b>Body</b>',
					),
				),
				'category'  => array( 'a', array( 'text' => 'b' ) ),
				'published' => array( 'not a date' ),
				'updated'   => array( '2024-01-01T00:00:00Z' ),
			),
			'children'   => array(),
		);
		$this->assertTrue( MF2_Utils::has_children( $entry ) );
		$this->assertFalse( MF2_Utils::has_children( 'x' ) );
		$this->assertTrue( MF2_Utils::is_microformat_array( array( 'items' => array() ) ) );
		$this->assertFalse( MF2_Utils::is_microformat_array( array( 'items' => 'x' ) ) );
		$this->assertFalse( MF2_Utils::get_type( 'x' ) );
		$this->assertSame( array( 'a', 'b' ), MF2_Utils::get_plaintext_array( $entry, 'category' ) );
		$this->assertSame( 'none', MF2_Utils::get_plaintext_array( $entry, 'missing', 'none' ) );
		$this->assertSame( '<b>Body</b>', MF2_Utils::get_html( $entry, 'content' ) );
		$this->assertSame( 'Title', MF2_Utils::get_html( $entry, 'name' ) );
		$this->assertSame( 'none', MF2_Utils::get_html( $entry, 'missing', 'none' ) );
		$this->assertSame( 'Short', MF2_Utils::get_summary( $entry ) );
		$this->assertSame( 'Title', MF2_Utils::get_prop( $entry, 'name' ) );
		$this->assertSame( 'b', MF2_Utils::to_plaintext( array( 'text' => 'b' ) ) );
		$this->assertNull( MF2_Utils::get_published( $entry, true ) );
		$this->assertSame( 'not a date', MF2_Utils::get_datetime_property( 'published', $entry ) );
		$this->assertSame( 'fallback', MF2_Utils::get_datetime_property( 'published', $entry, true, 'fallback' ) );
		$this->assertSame( 'none', MF2_Utils::get_datetime_property( 'start', array( 'properties' => array() ), false, 'none' ) );
		$this->assertSame( '2024-01-01T00:00:00+00:00', MF2_Utils::get_updated( $entry, true ) );

		$this->assertTrue( MF2_Utils::same_hostname( 'https://example.com/a', 'http://example.com/b' ) );
		$this->assertFalse( MF2_Utils::same_hostname( 'https://example.com/', 'https://example.org/' ) );
		$this->assertSame( '/', MF2_Utils::parse_url( 'https://example.com' )['pathname'] );
		$this->assertSame( '/a', MF2_Utils::parse_url( 'https://example.com/a' )['pathname'] );

		$doc = array(
			'rels'     => array( 'me' => array( 'https://me.example/' ) ),
			'rel-urls' => array(
				'https://me.example/' => array(
					'rels'  => array( 'me' ),
					'title' => 'Titled',
				),
			),
		);
		$this->assertFalse( MF2_Utils::get_rel( $doc, 'author' ) );
		$this->assertSame( array( 'Titled' ), MF2_Utils::get_rel_urls( $doc, 'https://me.example/' )['name'] );
	}

	/**
	 * Plain-text content without HTML.
	 */
	public function test_plain_text_content() {
		$this->assertSame(
			'Plain',
			MF2_Utils::parse_html_value(
				array(
					'type'       => array( 'h-entry' ),
					'properties' => array( 'content' => array( array( 'value' => ' Plain ' ) ) ),
				),
				'content'
			)['text']
		);
	}
}
