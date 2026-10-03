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
}
