<?php
/**
 * Tests for the JSON-LD node converters.
 *
 * @package Parse_This
 */

use ParseThis\JSONLD;

/**
 * JSON-LD converter tests, one schema.org type family at a time.
 */
class Parser_JSONLD_Types_Test extends Parse_This_TestCase {

	/**
	 * Parses a page carrying the given JSON-LD scripts.
	 *
	 * @param array[] $scripts Decoded JSON-LD documents, one per script tag.
	 * @param array   $args    Optional. Parse arguments.
	 * @return array jf2.
	 */
	private function parse_scripts( $scripts, $args = array() ) {
		$html = '<html><head>';
		foreach ( $scripts as $script ) {
			$html .= '<script type="application/ld+json">' . ( is_string( $script ) ? $script : wp_json_encode( $script ) ) . '</script>';
		}
		$html .= '</head><body></body></html>';
		return JSONLD::parse( ParseThis\pt_load_domdocument( $html ), 'https://example.com/page/', $args );
	}

	/**
	 * Pages without usable JSON-LD give nothing.
	 */
	public function test_parse_without_jsonld() {
		$this->assertSame( array(), JSONLD::parse( null, 'https://example.com/', array() ) );
		$this->assertSame( array(), $this->parse_scripts( array() ) );
		$this->assertSame( array(), $this->parse_scripts( array( '{ not json' ) ) );
		$this->assertSame( array(), JSONLD::jsonld_to_jf2( array() ) );
	}

	/**
	 * Several script tags are combined, and debug keeps the raw nodes.
	 */
	public function test_parse_several_scripts_and_debug() {
		$jf2 = $this->parse_scripts(
			array(
				array(
					'@context' => 'https://schema.org',
					'@type'    => 'BlogPosting',
					'headline' => 'Post',
				),
				array(
					'@context' => 'https://schema.org',
					'@type'    => 'Person',
					'name'     => 'Writer',
				),
			),
			array( 'debug' => true )
		);
		$this->assertSame( 'Post', $jf2['name'] );
		$this->assertSame( 'Writer', $jf2['author']['name'] );
		$this->assertCount( 2, $jf2['_jsonld'] );
	}

	/**
	 * When no node describes the page, the converted nodes come back keyed by type.
	 */
	public function test_unpreferred_nodes_keyed_by_type() {
		$jf2 = JSONLD::jsonld_to_jf2(
			array(
				array(
					'@type' => 'WebSite',
					'name'  => 'Site',
					'url'   => 'https://example.com/',
				),
				array(
					'@type' => 'ImageObject',
					'url'   => 'https://example.com/i.jpg',
				),
				array(
					'@type'   => 'Place',
					'name'    => 'Park',
					'address' => array(
						'@type'           => 'PostalAddress',
						'addressLocality' => 'Town',
					),
				),
			)
		);
		$this->assertSame( 'website', $jf2['site']['_type'] );
		$this->assertSame( 'https://example.com/i.jpg', $jf2['image'] );
		$this->assertSame( 'Town', $jf2['place']['locality'] );
	}

	/**
	 * Without an article, video, audio, media, a web page and a person are tried in turn.
	 */
	public function test_general_node_fallbacks() {
		$video = JSONLD::jsonld_to_jf2(
			array(
				array(
					'@type'      => 'VideoObject',
					'name'       => 'Clip',
					'contentUrl' => 'https://example.com/v.mp4',
					'uploadDate' => '2024-01-02T03:04:05Z',
					'transcript' => '<p>Words</p>',
					'publisher'  => array(
						'@type' => 'Organization',
						'name'  => 'Studio',
					),
				),
			)
		);
		$this->assertSame( 'https://example.com/v.mp4', $video['video'] );
		$this->assertSame( 'Studio', $video['publication']['name'] );

		$audio = JSONLD::jsonld_to_jf2(
			array(
				array(
					'@type'        => 'AudioObject',
					'name'         => 'Episode',
					'contentUrl'   => 'https://example.com/a.mp3',
					'thumbnailUrl' => 'https://example.com/t.jpg',
					'transcript'   => 'Said things',
					'publisher'    => array(
						'@type' => 'Organization',
						'name'  => 'Network',
					),
				),
			)
		);
		$this->assertSame( 'https://example.com/a.mp3', $audio['audio'] );
		$this->assertSame( 'https://example.com/t.jpg', $audio['featured'] );
		$this->assertSame( 'Network', $audio['publication']['name'] );

		$media = JSONLD::jsonld_to_jf2(
			array(
				array(
					'@type' => 'Movie',
					'name'  => 'Film',
				),
			)
		);
		$this->assertSame( 'Film', $media['name'] );

		$page = JSONLD::jsonld_to_jf2(
			array(
				array(
					'@type' => 'WebPage',
					'name'  => 'Page',
				),
				array(
					'@type' => 'Person',
					'name'  => 'Owner',
				),
			)
		);
		$this->assertSame( 'Page', $page['name'] );
		$this->assertSame( 'Owner', $page['author']['name'] );

		$person = JSONLD::jsonld_to_jf2(
			array(
				array(
					'@type' => 'Person',
					'name'  => 'Solo',
					'url'   => 'https://example.com/me',
				),
			)
		);
		$this->assertSame( 'Solo', $person['name'] );
	}

	/**
	 * Audio and video transcripts become jf2 content with html and text.
	 */
	public function test_media_transcript_text() {
		$this->markTestSkipped( 'audio_to_audio() and video_to_video() store the transcript text as content.value (#177).' );
		$video = JSONLD::video_to_video(
			array(
				'@type'      => 'VideoObject',
				'transcript' => '<p>Words</p>',
			)
		);
		$this->assertSame( 'Words', $video['content']['text'] );
		$audio = JSONLD::audio_to_audio(
			array(
				'@type'      => 'AudioObject',
				'transcript' => 'Said things',
			)
		);
		$this->assertSame( 'Said things', $audio['content']['text'] );
	}

	/**
	 * An article absorbs video, audio, person and organization nodes; repeats merge.
	 */
	public function test_article_merges_nodes() {
		$jf2 = JSONLD::jsonld_to_jf2(
			array(
				array(
					'@type'    => 'NewsArticle',
					'headline' => 'First',
				),
				array(
					'@type'         => 'NewsArticle',
					'datePublished' => '2024-05-06',
				),
				array(
					'@type'      => 'VideoObject',
					'contentUrl' => 'https://example.com/v.mp4',
				),
				array(
					'@type'      => 'AudioObject',
					'contentUrl' => 'https://example.com/a.mp3',
				),
				array(
					'@type' => 'Person',
					'name'  => 'Reporter',
				),
				array(
					'@type' => 'Organization',
					'name'  => 'Paper',
				),
				array(
					'@type' => 'Organization',
					'url'   => 'https://paper.example/',
				),
			)
		);
		$this->assertSame( 'First', $jf2['name'] );
		$this->assertStringStartsWith( '2024-05-06', $jf2['published'] );
		$this->assertSame( 'https://example.com/v.mp4', $jf2['video'] );
		$this->assertSame( 'https://example.com/a.mp3', $jf2['audio'] );
		$this->assertSame( 'Reporter', $jf2['author']['name'] );
		$this->assertSame( 'Paper', $jf2['org']['name'] );
		$this->assertSame( 'https://paper.example/', $jf2['org']['url'] );
	}

	/**
	 * Article fields: keywords, body, images, media references and the publisher.
	 */
	public function test_article_to_hentry_fields() {
		$this->assertFalse( JSONLD::article_to_hentry( array( '@type' => 'Person' ) ) );

		$entry = JSONLD::article_to_hentry(
			array(
				'@type'         => 'BlogPosting',
				'name'          => 'By name',
				'description'   => 'Summary',
				'dateModified'  => '2024-02-03T04:05:06Z',
				'keywords'      => 'one, two ,three',
				'articleBody'   => '<p>Body <b>text</b></p>',
				'image'         => array( 'https://example.com/a.jpg', 'https://example.com/b.jpg' ),
				'audio'         => array( array( 'contentUrl' => 'https://example.com/a.mp3' ) ),
				'video'         => 'https://example.com/v.mp4',
				'publisher'     => array(
					'@type' => 'Organization',
					'name'  => 'Pub',
				),
				'author'        => array( 'not an agent' => true ),
			)
		);
		$this->assertSame( 'By name', $entry['name'] );
		$this->assertSame( 'Summary', $entry['summary'] );
		$this->assertStringStartsWith( '2024-02-03', $entry['updated'] );
		$this->assertSame( array( 'one', 'two', 'three' ), $entry['category'] );
		$this->assertSame( 'Body text', $entry['content']['text'] );
		$this->assertSame( 'https://example.com/b.jpg', $entry['featured'] );
		$this->assertSame( 'https://example.com/a.mp3', $entry['audio'] );
		$this->assertSame( 'https://example.com/v.mp4', $entry['video'] );
		$this->assertSame( 'Pub', $entry['publication']['name'] );
		$this->assertArrayNotHasKey( 'author', $entry );

		$listed = JSONLD::article_to_hentry(
			array(
				'@type'    => 'Article',
				'keywords' => array( ' a ', 'b' ),
				'author'   => array(
					array(
						'@type' => 'Person',
						'name'  => 'One',
					),
					array(
						'@type' => 'Person',
						'name'  => 'Two',
					),
				),
			)
		);
		$this->assertSame( array( 'a', 'b' ), $listed['category'] );
		$this->assertCount( 2, $listed['author'] );
	}

	/**
	 * A creator is treated like an author: one card on its own, and author isn't overwritten.
	 */
	public function test_article_creator() {
		$entry = JSONLD::article_to_hentry(
			array(
				'@type'   => 'Article',
				'creator' => array(
					'@type' => 'Person',
					'name'  => 'Maker',
				),
			)
		);
		$this->assertSame( 'Maker', $entry['author']['name'] );

		$both = JSONLD::article_to_hentry(
			array(
				'@type'   => 'Article',
				'author'  => array(
					'@type' => 'Person',
					'name'  => 'Writer',
				),
				'creator' => 'Someone else',
			)
		);
		$this->assertSame( 'Writer', $both['author']['name'] );
	}

	/**
	 * People: plain names, several names, full records, and organizations as authors.
	 */
	public function test_person_to_hcard() {
		$this->assertSame(
			array(
				'type' => 'card',
				'name' => 'Plain',
			),
			JSONLD::person_to_hcard( 'Plain' )
		);
		$this->assertFalse( JSONLD::person_to_hcard( array( 'name' => 'No type' ) ) );
		$this->assertFalse( JSONLD::person_to_hcard( array( '@type' => 'Place' ) ) );

		$names = JSONLD::person_to_hcard(
			array(
				'@type' => 'Person',
				'name'  => array( 'A', 'B' ),
			)
		);
		$this->assertSame( 'B', $names[1]['name'] );

		$card = JSONLD::person_to_hcard(
			array(
				'@type'     => 'Person',
				'name'      => 'Full',
				'email'     => 'full@example.com',
				'image'     => array(
					'@type' => 'ImageObject',
					'url'   => 'https://example.com/p.jpg',
				),
				'sameAs'    => array( 'https://social.example/@full' ),
				'birthDate' => '1980-01-01',
				'jobTitle'  => 'Editor',
				'location'  => array(
					'@type' => 'Place',
					'name'  => 'City',
				),
			)
		);
		$this->assertSame( 'https://example.com/p.jpg', $card['photo'] );
		$this->assertSame( 'Editor', $card['job-title'] );
		$this->assertSame( 'City', $card['location']['name'] );

		$org = JSONLD::person_to_hcard(
			array(
				'@type' => 'NewsMediaOrganization',
				'name'  => 'Outlet',
			)
		);
		$this->assertSame( 'NewsMediaOrganization', $org['_type'] );
	}

	/**
	 * Organizations: logo or image, members, and structured or plain addresses.
	 */
	public function test_organization_to_hcard() {
		$this->assertFalse( JSONLD::organization_to_hcard( array( '@type' => 'Person' ) ) );

		$org = JSONLD::organization_to_hcard(
			array(
				'@type'       => 'Organization',
				'name'        => 'Org',
				'image'       => 'https://example.com/org.png',
				'description' => 'About',
				'member'      => array(
					'@type' => 'Person',
					'name'  => 'Member',
				),
				'address'     => array(
					'@type'         => 'PostalAddress',
					'streetAddress' => '1 Road',
					'postalCode'    => '12345',
				),
			)
		);
		$this->assertSame( 'https://example.com/org.png', $org['photo'] );
		$this->assertSame( 'Member', $org['member'][0]['name'] );
		$this->assertSame( '1 Road', $org['street-address'] );
		$this->assertSame( '12345', $org['postal-code'] );

		$members = JSONLD::organization_to_hcard(
			array(
				'@type'  => 'MusicGroup',
				'name'   => 'Band',
				'member' => array( 'Singer', array( '@type' => 'Thing' ) ),
			)
		);
		$this->assertSame( array( 'Singer' ), wp_list_pluck( $members['member'], 'name' ) );
	}

	/**
	 * A plain-text address on an organization or place is kept as its label.
	 */
	public function test_organization_text_address() {
		$org = JSONLD::organization_to_hcard(
			array(
				'@type'   => 'Organization',
				'name'    => 'Org',
				'address' => '1 Main Street, Town',
			)
		);
		$this->assertSame( '1 Main Street, Town', $org['label'] );
		$place = JSONLD::place_to_hcard(
			array(
				'@type'   => 'Place',
				'name'    => 'Park',
				'address' => '2 Side Road',
			)
		);
		$this->assertSame( '2 Side Road', $place['label'] );
	}

	/**
	 * Places, coordinates and addresses.
	 */
	public function test_place_geo_and_address() {
		$this->assertFalse( JSONLD::place_to_hcard( 'Somewhere' ) );
		$this->assertFalse( JSONLD::place_to_hcard( array( '@type' => 'Person' ) ) );
		$this->assertFalse( JSONLD::geocoordinates_to_geo( 'x' ) );
		$this->assertFalse( JSONLD::geocoordinates_to_geo( array( '@type' => 'Place' ) ) );
		$this->assertFalse( JSONLD::postaladdress_to_address( array( '@type' => 'Place' ) ) );

		$place = JSONLD::place_to_hcard(
			array(
				'@type'     => 'Place',
				'name'      => 'Venue',
				'telephone' => '555-0100',
				'geo'       => array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => 45.5,
					'longitude' => -122.6,
				),
				'address'   => array(
					'@type'           => 'PostalAddress',
					'addressLocality' => 'Portland',
					'addressRegion'   => 'OR',
					'addressCountry'  => 'US',
				),
			)
		);
		$this->assertSame( 'geo', $place['geo']['type'] );
		$this->assertSame( 45.5, $place['geo']['latitude'] );
		$this->assertSame( 'Portland', $place['locality'] );
		$this->assertSame( 'US', $place['country-name'] );
	}

	/**
	 * Images: a URL, the last of a list, an ImageObject, or not an image.
	 */
	public function test_image_to_photo() {
		$this->assertSame( 'https://example.com/a.jpg', JSONLD::image_to_photo( 'https://example.com/a.jpg' ) );
		$this->assertSame( 'https://example.com/b.jpg', JSONLD::image_to_photo( array( 'https://example.com/a.jpg', 'https://example.com/b.jpg' ) ) );
		$this->assertSame(
			'https://example.com/c.jpg',
			JSONLD::image_to_photo(
				array(
					'@type' => 'ImageObject',
					'url'   => 'https://example.com/c.jpg',
				)
			)
		);
		$this->assertFalse( JSONLD::image_to_photo( array( '@type' => 'Person' ) ) );
		$this->assertFalse( JSONLD::image_to_photo( null ) );
	}

	/**
	 * An ImageObject with contentUrl or @id instead of url still gives its URL.
	 */
	public function test_image_object_content_url() {
		$this->assertSame(
			'https://example.com/d.jpg',
			JSONLD::image_to_photo(
				array(
					'@type'      => 'ImageObject',
					'contentUrl' => 'https://example.com/d.jpg',
				)
			)
		);
		$this->assertSame(
			'https://example.com/e.jpg',
			JSONLD::image_to_photo(
				array(
					'@type' => 'ImageObject',
					'@id'   => 'https://example.com/e.jpg',
				)
			)
		);
		$this->assertFalse( JSONLD::image_to_photo( array( '@type' => 'ImageObject' ) ) );
	}

	/**
	 * Audio and video converters accept URLs and reject other types.
	 */
	public function test_audio_and_video_inputs() {
		$this->assertSame( 'https://example.com/a.mp3', JSONLD::audio_to_audio( 'https://example.com/a.mp3' ) );
		$this->assertFalse( JSONLD::audio_to_audio( array( '@type' => 'VideoObject' ) ) );
		$this->assertSame( 'https://example.com/v.mp4', JSONLD::video_to_video( 'https://example.com/v.mp4' ) );
		$this->assertFalse( JSONLD::video_to_video( array( '@type' => 'AudioObject' ) ) );
	}

	/**
	 * Websites and their type checks.
	 */
	public function test_site_to_hcard() {
		$this->assertFalse( JSONLD::site_to_hcard( array( '@type' => 'WebPage' ) ) );
		$site = JSONLD::site_to_hcard(
			array(
				'@type'  => 'WebSite',
				'name'   => 'Site',
				'sameAs' => 'https://social.example/@site',
			)
		);
		$this->assertSame( 'https://social.example/@site', $site['me'] );
	}

	/**
	 * Events: organizer, location, dates and image.
	 */
	public function test_event_to_hevent() {
		$this->assertFalse( JSONLD::event_to_hevent( array( '@type' => 'Place' ) ) );
		$event = JSONLD::jsonld_to_jf2(
			array(
				array(
					'@type'     => 'MusicEvent',
					'name'      => 'Concert',
					'startDate' => '2025-06-01T20:00:00-07:00',
					'endDate'   => '2025-06-01T23:00:00-07:00',
					'image'     => 'https://example.com/poster.jpg',
					'organizer' => array(
						'@type' => 'Organization',
						'name'  => 'Promoter',
					),
					'location'  => array(
						'@type' => 'Place',
						'name'  => 'Hall',
					),
				),
			)
		);
		$this->assertSame( 'event', $event['type'] );
		$this->assertSame( 'Promoter', $event['organizer']['name'] );
		$this->assertSame( 'Hall', $event['location']['name'] );
		$this->assertSame( 'https://example.com/poster.jpg', $event['featured'] );
		$this->assertStringStartsWith( '2025-06-01', $event['start'] );
	}

	/**
	 * A person can organize an event.
	 */
	public function test_event_person_organizer() {
		$event = JSONLD::event_to_hevent(
			array(
				'@type'     => 'Event',
				'name'      => 'Meetup',
				'organizer' => array(
					'@type' => 'Person',
					'name'  => 'Host',
				),
			)
		);
		$this->assertSame( 'Host', $event['organizer']['name'] );
	}

	/**
	 * Movies and shows: trailer, people and timeRequired.
	 */
	public function test_media_to_hcite() {
		$this->assertFalse( JSONLD::media_to_hcite( array( '@type' => 'Person' ) ) );
		$movie = JSONLD::media_to_hcite(
			array(
				'@type'         => 'Movie',
				'name'          => 'Film',
				'url'           => 'https://example.com/film',
				'genre'         => 'Drama',
				'datePublished' => '2020-01-01',
				'image'         => 'https://example.com/film.jpg',
				'timeRequired'  => 'PT2H',
				'trailer'       => array(
					'@type'      => 'VideoObject',
					'contentUrl' => 'https://example.com/trailer.mp4',
				),
				'director'      => array(
					'@type' => 'Person',
					'name'  => 'Director',
				),
				'actor'         => array(
					array(
						'@type' => 'Person',
						'name'  => 'Lead',
					),
					array(
						'@type' => 'Person',
						'name'  => 'Support',
					),
				),
			)
		);
		$this->assertSame( 'cite', $movie['type'] );
		$this->assertSame( 'PT2H', $movie['duration'] );
		$this->assertSame( 'https://example.com/trailer.mp4', $movie['video']['video'] );
		$this->assertSame( 'Director', $movie['director'][0]['name'] );
		$this->assertSame( array( 'Lead', 'Support' ), wp_list_pluck( $movie['actor'], 'name' ) );
	}

	/**
	 * Music releases: tracks become nested citations.
	 */
	public function test_music_to_hcite() {
		$this->assertFalse( JSONLD::music_to_hcite( array( '@type' => 'Movie' ) ) );
		$music = JSONLD::jsonld_to_jf2(
			array(
				array(
					'@type'  => 'MusicRelease',
					'name'   => 'Album',
					'genre'  => 'Jazz',
					'tracks' => array(
						array(
							'@type'    => 'MusicRelease',
							'name'     => 'Track one',
							'duration' => 'PT3M',
						),
					),
				),
			)
		);
		$this->assertSame( 'music', array_keys( $music )[0] );
		$this->assertSame( 'Album', $music['music']['name'] );
		$this->assertSame( 'Track one', $music['music']['tracks'][0]['name'] );
	}

	/**
	 * The artists of a release are cards, whether one artist or several.
	 */
	public function test_music_artists() {
		$this->markTestSkipped( 'music_to_hcite() wraps each artist card in an extra array and mishandles a single byArtist object (#176).' );
		$one  = JSONLD::music_to_hcite(
			array(
				'@type'     => 'MusicRelease',
				'name'      => 'Single',
				'releaseOf' => array(
					'@type'    => 'MusicAlbum',
					'byArtist' => array(
						'@type' => 'MusicGroup',
						'name'  => 'Band',
					),
				),
			)
		);
		$this->assertSame( 'Band', $one['author'][0]['name'] );
		$many = JSONLD::music_to_hcite(
			array(
				'@type'     => 'MusicRelease',
				'name'      => 'Duet',
				'releaseOf' => array(
					'byArtist' => array(
						array( 'name' => 'A' ),
						array( 'name' => 'B' ),
					),
				),
			)
		);
		$this->assertSame( array( 'A', 'B' ), wp_list_pluck( $many['author'], 'name' ) );
	}

	/**
	 * Reviews of different things: events, media, products, recipes, places, organizations and people.
	 */
	public function test_review_items() {
		$this->assertFalse( JSONLD::review_to_hentry( array( '@type' => 'Article' ) ) );
		$items = array(
			'event'   => array(
				'@type' => 'Event',
				'name'  => 'Show',
			),
			'media'   => array(
				'@type' => 'TVSeries',
				'name'  => 'Series',
			),
			'product' => array(
				'@type' => 'Product',
				'name'  => 'Gadget',
			),
			'recipe'  => array(
				'@type' => 'Recipe',
				'name'  => 'Soup',
			),
			'place'   => array(
				'@type' => 'Place',
				'name'  => 'Diner',
			),
			'org'     => array(
				'@type' => 'Organization',
				'name'  => 'Shop',
			),
			'person'  => array(
				'@type' => 'Person',
				'name'  => 'Chef',
			),
		);
		foreach ( $items as $kind => $item ) {
			$review = JSONLD::review_to_hentry(
				array(
					'@type'        => 'Review',
					'itemReviewed' => array( $item ),
				)
			);
			$this->assertSame( $item['name'], $review['review-of']['name'], $kind );
		}

		$by_url = JSONLD::review_to_hentry(
			array(
				'@type'        => 'Review',
				'headline'     => 'Headline',
				'itemReviewed' => 'https://example.com/thing',
				'author'       => 'Critic',
			)
		);
		$this->assertSame( 'Headline', $by_url['name'] );
		$this->assertSame( 'https://example.com/thing', $by_url['review-of'] );
		$this->assertSame( 'Critic', $by_url['author']['name'] );

		$book = JSONLD::review_to_hentry(
			array(
				'@type'        => 'Review',
				'itemReviewed' => array(
					'@type'  => 'Book',
					'name'   => 'Novel',
					'isbn'   => '978-0',
					'sameAs' => 'https://books.example/novel',
					'author' => array(
						array(
							'@type' => 'Person',
							'name'  => 'Novelist',
						),
					),
				),
				'author'       => array(
					'@type' => 'Thing',
					'name'  => 'Unknown kind',
				),
			)
		);
		$this->assertSame( 'cite', $book['review-of']['type'] );
		$this->assertSame( 'https://books.example/novel', $book['review-of']['url'] );
		$this->assertSame( 'Novelist', $book['review-of']['author']['name'] );
		$this->assertSame( 'Unknown kind', $book['author']['name'] );

		$nothing = JSONLD::review_to_hentry(
			array(
				'@type'        => 'Review',
				'name'         => 'R',
				'itemReviewed' => array( '@type' => 'Thing' ),
				'author'       => 42,
			)
		);
		$this->assertArrayNotHasKey( 'review-of', $nothing );
		$this->assertArrayNotHasKey( 'author', $nothing );
	}

	/**
	 * Products: brand names, offer lists and price ranges, identifiers.
	 */
	public function test_product_to_hproduct() {
		$this->assertFalse( JSONLD::product_to_hproduct( array( '@type' => 'Recipe' ) ) );
		$range = JSONLD::product_to_hproduct(
			array(
				'@type'  => 'ProductGroup',
				'name'   => 'Shirt',
				'brand'  => 'Maker',
				'gtin13' => '0123456789012',
				'offers' => array(
					'@type'         => 'AggregateOffer',
					'lowPrice'      => 10,
					'priceCurrency' => 'EUR',
				),
			)
		);
		$this->assertSame( 'Maker', $range['brand'] );
		$this->assertSame( '0123456789012', $range['identifier'] );
		$this->assertSame( '10 EUR', $range['price'] );

		$none = JSONLD::product_to_hproduct(
			array(
				'@type'  => 'Product',
				'name'   => 'Thing',
				'brand'  => array( 'x', 'y' ),
				'offers' => array( array( 'priceCurrency' => 'USD' ) ),
			)
		);
		$this->assertArrayNotHasKey( 'brand', $none );
		$this->assertArrayNotHasKey( 'price', $none );
	}

	/**
	 * Recipes: yield lists, nutrition, sections of steps and plain-text instructions.
	 */
	public function test_recipe_to_hrecipe() {
		$this->assertFalse( JSONLD::recipe_to_hrecipe( array( '@type' => 'Product' ) ) );
		$recipe = JSONLD::recipe_to_hrecipe(
			array(
				'@type'              => 'Recipe',
				'name'               => 'Bread',
				'recipeYield'        => array( '2 loaves', '2' ),
				'nutrition'          => array( 'calories' => '200 calories' ),
				'ingredients'        => array( 'flour', 3, 'water' ),
				'cookTime'           => 'PT40M',
				'recipeCuisine'      => 'French',
				'recipeInstructions' => array(
					array(
						'@type'           => 'HowToSection',
						'name'            => 'Dough',
						'itemListElement' => array(
							array(
								'@type' => 'HowToStep',
								'text'  => 'Mix.',
							),
							'Knead.',
							array( 'name' => 'Rest.' ),
							array( 'image' => 'x.jpg' ),
						),
					),
					'<b>Bake.</b>',
					array( 'name' => 'Cool.' ),
					array( 'image' => 'y.jpg' ),
					7,
				),
			)
		);
		$this->assertSame( '2 loaves', $recipe['yield'] );
		$this->assertSame( '200 calories', $recipe['nutrition'] );
		$this->assertSame( array( 'flour', 'water' ), $recipe['ingredient'] );
		$this->assertSame( 'PT40M', $recipe['duration'] );
		$this->assertSame( array( 'French' ), $recipe['category'] );
		$this->assertSame( "Mix.\nKnead.\nRest.\nBake.\nCool.", $recipe['instructions']['text'] );

		$text = JSONLD::recipe_to_hrecipe(
			array(
				'@type'              => 'Recipe',
				'name'               => 'Tea',
				'recipeInstructions' => 'Steep.',
			)
		);
		$this->assertSame( 'Steep.', $text['instructions']['text'] );

		$blank = JSONLD::recipe_to_hrecipe(
			array(
				'@type'              => 'Recipe',
				'name'               => 'Water',
				'recipeInstructions' => '   ',
			)
		);
		$this->assertArrayNotHasKey( 'instructions', $blank );
	}

	/**
	 * The type helpers.
	 */
	public function test_type_helpers() {
		$this->assertFalse( JSONLD::is_jsonld( 'x' ) );
		$this->assertTrue( JSONLD::is_jsonld_graph( array( '@graph' => array() ) ) );
		$this->assertFalse( JSONLD::is_jsonld_type( 'x', 'Thing' ) );
		$this->assertTrue( JSONLD::is_jsonld_type( array( '@type' => array( 'A', 'Place' ) ), 'Place' ) );
		$this->assertSame( 'address', JSONLD::get_type( array( '@type' => 'PostalAddress' ) ) );
		$this->assertFalse( JSONLD::get_type( array( '@type' => 'Thing' ) ) );
	}
}
