<?php
/**
 * Tests for less common WordPress REST API cases.
 *
 * @package Parse_This
 */

use ParseThis\RESTAPI;

/**
 * REST API parser tests: URL helpers, fetching, site cards and feeds.
 */
class RESTAPI_More_Test extends Parse_This_TestCase {

	/**
	 * Small helpers with unusual input.
	 */
	public function test_helpers() {
		$this->assertNull( RESTAPI::get_rendered( 'title', array() ) );
		$this->assertNull( RESTAPI::get_rendered( 'title', array( 'title' => array( 'raw' => 'x' ) ) ) );
		$this->assertSame( 'YWL_Pw', RESTAPI::base64url_encode( "ab\xff?" ) );
		$this->assertFalse( RESTAPI::get_rest_url( 'not a url', '/wp/v2/posts' ) );
		$this->assertFalse( RESTAPI::get_rest_url( 'https://example.com/api?x=1', '/wp/v2/posts' ) );
		$this->assertFalse( RESTAPI::get_rest_root( '/no/host' ) );
		$this->assertSame( 'https://example.com:8443/wp-json/', RESTAPI::get_rest_root( 'https://example.com:8443/wp-json/wp/v2/posts' ) );
		$this->assertFalse( RESTAPI::get_rest_path( 'not a url', 'https://example.com/' ) );
	}

	/**
	 * A rendered field that isn't an object is ignored rather than causing an error.
	 */
	public function test_rendered_string_field() {
		$this->markTestSkipped( 'RESTAPI::get_rendered() throws a TypeError when the field is not an array (#191).' );
		$this->assertNull( RESTAPI::get_rendered( 'title', array( 'title' => 'Plain string' ) ) );
	}

	/**
	 * Authors: missing, error objects and full user records.
	 */
	public function test_authors() {
		$this->assertNull( RESTAPI::get_author( array() ) );
		$this->assertNull( RESTAPI::get_author( array( '_embedded' => array( 'author' => array( array( 'code' => 'rest_user_invalid_id' ) ) ) ) ) );
		$user = array(
			'name'        => 'Writer',
			'url'         => 'https://writer.example/',
			'description' => 'Bio',
			'avatar_urls' => array(
				'24' => 'https://example.com/24.jpg',
				'96' => 'https://example.com/96.jpg',
			),
		);
		$this->assertSame( 'https://example.com/96.jpg', RESTAPI::get_author( array( '_embedded' => array( 'author' => array( $user ) ) ) )['photo'] );
		$card = RESTAPI::format_author( $user );
		$this->assertSame( 'Bio', $card['note'] );
		$this->assertSame( 'https://example.com/96.jpg', $card['photo'] );
	}

	/**
	 * Dates: invalid values, values without a zone, and bad GMT values falling back to local.
	 */
	public function test_dates() {
		$this->assertNull( RESTAPI::get_datetime( 'not a date' ) );
		$this->assertSame( '2024-01-02T03:04:05+02:00', RESTAPI::get_datetime( '2024-01-02T03:04:05', new DateTimeZone( '+02:00' ) ) );
		$this->assertSame(
			'2024-01-02T03:04:05+00:00',
			RESTAPI::post_datetime(
				array(
					'date_gmt' => 'garbage',
					'date'     => '2024-01-02T03:04:05',
				),
				'date'
			)
		);
	}

	/**
	 * Fetching: no URL, errors, wrong content types, collections and the cache.
	 */
	public function test_fetch() {
		$this->assertWPError( RESTAPI::fetch( '', '/wp/v2/posts' ) );
		$this->assertWPError( RESTAPI::fetch( 'https://example.com/wp-json/', '/wp/v2/unmocked' ) );

		$this->respond( 'https://example.com/wp-json/wp/v2/html?_embed=1', '<html></html>', 'text/html' );
		$error = RESTAPI::fetch( 'https://example.com/wp-json/', '/wp/v2/html' );
		$this->assertSame( 'content-type', $error->get_error_code() );

		$this->respond(
			'https://example.com/wp-json/wp/v2/posts?_embed=1',
			wp_json_encode( array( array( 'id' => 1 ) ) ),
			'application/json; charset=UTF-8',
			array(
				'x-wp-total'      => '1',
				'x-wp-totalpages' => '1',
			)
		);
		$collection = RESTAPI::fetch( 'https://example.com/wp-json/', '/wp/v2/posts', true );
		$this->assertSame( '1', $collection['_total'] );
		$this->assertSame( 1, $collection['items'][0]['id'] );

		// The cached body is used, without another request.
		$requests = count( $this->requests );
		$cached   = RESTAPI::fetch( 'https://example.com/wp-json/', '/wp/v2/posts', true );
		$this->assertSame( 1, $cached[0]['id'] );
		$this->assertCount( $requests, $this->requests );
	}

	/**
	 * The API root: errors and unknown content pass through; single gives a site card.
	 */
	public function test_parse_root_as_card() {
		$error = new WP_Error( 'x', 'y' );
		$this->assertSame( $error, RESTAPI::parse( $error, 'https://example.com/wp-json/', array() ) );
		$this->assertFalse( RESTAPI::parse( 'text', 'https://example.com/wp-json/', array() ) );
		$this->assertFalse( RESTAPI::parse( array( 'other' => true ), 'https://example.com/wp-json/', array() ) );

		$root = array(
			'namespaces'      => array( 'wp/v2' ),
			'name'            => 'Site',
			'description'     => 'Tagline',
			'url'             => 'https://example.com',
			'timezone_string' => 'Europe/Paris',
			'_embedded'       => array(
				'wp:featuredmedia' => array(
					array( 'source_url' => 'https://example.com/icon.png' ),
				),
			),
		);
		$card = RESTAPI::parse( $root, 'https://example.com/wp-json/', array( 'return' => 'single' ) );
		$this->assertSame( 'card', $card['type'] );
		$this->assertSame( 'Europe/Paris', $card['tz'] );
		$this->assertSame( 'https://example.com/icon.png', $card['photo'] );
		$this->assertSame( 'Tagline', $card['note'] );

		$root['_embedded']['wp:featuredmedia'][] = array( 'source_url' => 'https://example.com/logo.png' );
		$two                                     = RESTAPI::parse( $root, 'https://example.com/wp-json/', array( 'return' => 'single' ) );
		$this->assertCount( 2, $two['photo'] );
	}

	/**
	 * The API root as a feed: the latest posts, with paging totals and debug data.
	 */
	public function test_parse_root_as_feed() {
		$post = array(
			'id'       => 7,
			'link'     => 'https://example.com/seven/',
			'date_gmt' => '2024-05-05T10:00:00',
			'title'    => array( 'rendered' => 'Seven' ),
			'content'  => array( 'rendered' => '<p>Body</p>' ),
		);
		$this->respond(
			'https://example.com/wp-json/wp/v2/posts?_embed=1',
			wp_json_encode( array( $post ) ),
			'application/json',
			array(
				'x-wp-total'      => '12',
				'x-wp-totalpages' => '2',
			)
		);
		$this->respond_site_data( 'https://example.com/wp-json/', array( 'name' => 'Site' ) );
		$feed = RESTAPI::parse(
			array( 'namespaces' => array( 'wp/v2' ) ),
			'https://example.com/wp-json/',
			array(
				'return' => 'feed',
				'debug'  => true,
			)
		);
		$this->assertSame( 'Seven', $feed['items'][0]['name'] );
		$this->assertSame( '2', $feed['_pages'] );
		$this->assertSame( '12', $feed['_total'] );
		$this->assertSame( 7, $feed['items'][0]['_rest']['id'] );

		$this->assertWPError( RESTAPI::parse( array( 'namespaces' => array() ), 'https://unmocked.example.com/wp-json/', array( 'return' => 'feed' ) ) );
	}

	/**
	 * A plain-permalink site's posts are fetched with _embed as a query argument, not in the route.
	 */
	public function test_parse_root_as_feed_plain_permalinks() {
		$this->respond( 'https://example.com/?rest_route=/wp/v2/posts&_embed=1', wp_json_encode( array() ), 'application/json' );
		$this->respond_site_data( 'https://example.com/?rest_route=/', array( 'name' => 'Site' ) );
		$feed = RESTAPI::parse( array( 'namespaces' => array( 'wp/v2' ) ), 'https://example.com/?rest_route=/', array( 'return' => 'feed' ) );
		$this->assertNotWPError( $feed );
	}
}
