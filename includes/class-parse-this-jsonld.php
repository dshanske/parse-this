<?php
/**
 * Parse This JSON-LD class.
 *
 */
class Parse_This_JSONLD extends Parse_This_Base {
	/**
	 * Parses _meta, _images, and _links data from the content.
	 *
	 * @access public
	 */
	public static function parse( $doc, $url, $args ) {
		if ( ! $doc ) {
			return array();
		}
		$xpath = new DOMXPath( $doc );

		$jsonld  = array();
		$content = '';
		foreach ( $xpath->query( "//script[@type='application/ld+json']" ) as $script ) {
			$jsonld[] = json_decode( $script->textContent, true ); // phpcs:ignore
		}
		$jf2 = self::jsonld_to_jf2( $jsonld );
		if ( WP_DEBUG ) {
			$jf2['_jsonld'] = $jsonld;
		}
		return array_filter( $jf2 );
	}

	public static function jsonld_to_jf2( $jsonld ) {
		if ( empty( $jsonld ) ) {
			return array();
		}
		$jf2 = array();
		if ( 1 === count( $jsonld ) && wp_is_numeric_array( $jsonld[0] ) ) {
			$jsonld = $jsonld[0];
		}
		foreach ( $jsonld as $json ) {
			if ( self::is_jsonld( $json ) ) {
				switch ( $json['@type'] ) {
					case 'WebPage':
					case 'Article':
					case 'NewsArticle':
						$jf2['entry'] = self::article_to_hentry( $json );
						break;
					case 'Person':
						$jf2['person'] = self::person_to_hcard( $json );
						break;
					case 'Organization':
					case 'NGO':
						$jf2['org'] = self::organization_to_hcard( $json );
						break;
					case 'WebSite':
						$jf2['site'] = self::website_to_hcard( $json );
						break;
					case 'ImageObject':
						$jf2['image'] = self::image_to_photo( $json );
						break;
					case 'VideoObject':
						$jf2['video'] = self::video_to_video( $json );
						break;
					case 'Place':
						$jf2['place'] = self::place_to_hcard( $json );
						break;
				}
			}
		}
		$return = null;
		if ( array_key_exists( 'entry', $jf2 ) ) {
			$return = $jf2['entry'];
		} elseif ( array_key_exists( 'video', $jf2 ) ) {
			$return = $jf2['video'];
		} else {
			return $jf2;
		}

		if ( ! array_key_exists( 'author', $return ) && array_key_exists( 'person', $jf2 ) ) {
			$return['author'] = $jf2['person'];
		}
		if ( ! array_key_exists( 'publication', $return ) && array_key_exists( 'publisher', $jf2 ) ) {
			$return['publication'] = $jf2['publisher'];
		}
		return array_filter( $return );
	}

	public static function image_to_photo( $image ) {
		if ( is_string( $image ) ) {
			return $image;
		}
		if ( ! self::is_jsonld( $image ) ) {
			return false;
		}
		if ( self::is_jsonld_type( $image, 'ImageObject' ) ) {
			return $image['url'];
		}
		return false;
	}

	public static function video_to_video( $video ) {
		if ( is_string( $video ) ) {
			return $video;
		}
		if ( ! self::is_jsonld( $video ) ) {
			return false;
		}
		if ( self::is_jsonld_type( $video, 'VideoObject' ) ) {
			$return = array(
				'name'     => ifset( $video['name'] ),
				'summary'  => ifset( $video['description'] ),
				'featured' => ifset( $video['thumbnailUrl'] ),
				'video'    => ifset( $video['contentUrl'] ),
			);
			if ( isset( $video['publisher'] ) ) {
				$return['publication'] = self::organization_to_hcard( $video['publisher'] );
			}
			return array_filter( $return );
		}
		return false;
	}

	public static function geocoordinates_to_geo( $geo ) {
		if ( ! self::is_jsonld( $geo ) ) {
			return false;
		}
		if ( ! self::is_jsonld_type( $geo, 'GeoCoordinates' ) ) {
			return false;
		}
		$return = array(
			'type'      => 'geo',
			'latitude'  => ifset( $geo['latitude'] ),
			'longitude' => ifset( $geo['longitude'] ),
		);
		return array_filter( $return );
	}

	public static function postaladdress_to_address( $address ) {
		if ( ! self::is_jsonld( $address ) ) {
			return false;
		}
		if ( ! self::is_jsonld_type( $address, 'PostalAddress' ) ) {
			return false;
		}
		$return = array(
			'locality'       => ifset( $address['addressLocality'] ),
			'region'         => ifset( $address['addressRegion'] ),
			'country-name'   => ifset( $address['addressCountry'] ),
			'postal-code'    => ifset( $address['postalCode'] ),
			'street-address' => ifset( $address['streetAddress'] ),
		);
		return array_filter( $return );
	}

	public static function place_to_hcard( $place ) {
		if ( ! self::is_jsonld( $place ) ) {
			return false;
		}
		if ( ! self::is_jsonld_type( $place, 'Place' ) ) {
			return false;
		}
		$hcard = array(
			'type'  => 'card',
			'_type' => 'place',
			'name'  => ifset( $place['name'] ),
			'note'  => ifset( $place['description'] ),
			'tel'   => ifset( $place['telephone'] ),
			'photo' => self::image_to_photo( $place['image'] ),
			'me'    => ifset( $place['sameAs'] ),
			'geo'   => self::geocoordinates_to_geo( $place['geo'] ),
		);

		if ( isset( $place['address'] ) ) {
			$hcard = array_merge( $hcard, self::postaladdress_to_address( $place['address'] ) );
		}
		return array_filter( $hcard );
	}

	public static function person_to_hcard( $person ) {
		if ( ! self::is_jsonld( $person ) ) {
			return false;
		}
		if ( ! self::is_jsonld_type( $person, 'Person' ) ) {
			return false;
		}
		if ( isset( $person['name'] ) && is_array( $person['name'] ) ) {
			$author = array();
			foreach ( $person['name'] as $a ) {
				$author[] = array(
					'type' => 'card',
					'name' => $a,
				);
			}
		} else {

			$author = array(
				'type'      => 'card',
				'name'      => ifset( $person['name'] ),
				'email'     => ifset( $person['email'] ),
				'photo'     => ifset( $person['image'] ),
				'url'       => ifset( $person['url'] ),
				'me'        => ifset( $person['sameAs'] ),
				'email'     => ifset( $person['email'] ),
				'dt-bday'   => ifset( $person['birthDate'] ),
				'job-title' => ifset( $person['jobTitle'] ),
				'location'  => self::place_to_hcard( ifset( $person['location'] ) ),
			);
		}
		return array_filter( $author );
	}

	public static function website_to_hcard( $website ) {
		if ( ! self::is_jsonld( $website ) ) {
			return false;
		}
		if ( ! self::is_jsonld_type( $website, 'WebSite' ) ) {
			return false;
		}

		$publication = array(
			'type'  => 'card',
			'_type' => 'website',
			'name'  => ifset( $website['name'] ),
			'url'   => ifset( $website['url'] ),
			'me'    => ifset( $website['sameAs'] ),
		);
		return array_filter( $publication );
	}

	public static function organization_to_hcard( $organization ) {
		if ( ! self::is_jsonld( $organization ) ) {
			return false;
		}
		if ( ! self::is_jsonld_type( $organization, 'Organization' ) && ! self::is_jsonld_type( $organization, 'NGO' ) ) {
			return false;
		}

		$publication = array(
			'type'     => 'card',
			'_type'    => 'organization',
			'name'     => ifset( $organization['name'] ),
			'photo'    => self::image_to_photo( ifset( $organization['logo'] ) ),
			'url'      => ifset( $organization['url'] ),
			'me'       => ifset( $organization['sameAs'] ),
			'email'    => ifset( $organization['email'] ),
			'location' => self::place_to_hcard( ifset( $organization['location'] ) ),
		);
		if ( isset( $organization['address'] ) ) {
			$address = self::postaladdress_to_address( $organization['address'] );
			if ( is_array( $address ) ) {
				$publication = array_merge( $publication, $address );
			}
		}
		return array_filter( $publication );
	}

	public static function is_jsonld( $jsonld ) {
		return ( is_array( $jsonld ) && array_key_exists( '@type', $jsonld ) );
	}

	public static function is_jsonld_type( $jsonld, $type ) {
		return ( array_key_exists( '@type', $jsonld ) && $type === $jsonld['@type'] );
	}

	public static function article_to_hentry( $newsarticle ) {
		if ( ! self::is_jsonld( $newsarticle ) ) {
			return false;
		}
		$jf2          = array();
		$jf2['type']  = 'entry';
		$jf2['_type'] = $newsarticle['@type'];
		if ( isset( $newsarticle['datePublished'] ) ) {
			$jf2['published'] = normalize_iso8601( $newsarticle['datePublished'] );
		}

		if ( isset( $newsarticle['dateModified'] ) ) {
			$jf2['updated'] = normalize_iso8601( $newsarticle['dateModified'] );
		}

		if ( isset( $newsarticle['headline'] ) ) {
			$jf2['name'] = $newsarticle['headline'];
		} elseif ( isset( $newsarticle['name'] ) ) {
			$jf2['name'] = $newsarticle['name'];
		}
		if ( isset( $newsarticle['description'] ) ) {
			$jf2['summary'] = $newsarticle['description'];
		}
		if ( isset( $newsarticle['image'] ) ) {
			$jf2['featured'] = self::image_to_photo( $newsarticle['image'] );
		}
		if ( isset( $newsarticle['keywords'] ) ) {
			$jf2['category'] = $newsarticle['keywords'];
		}

		if ( isset( $newsarticle['articleBody'] ) ) {
			$jf2['content'] = array(
				'html'  => Parse_This::clean_content( $newsarticle['articleBody'] ),
				'value' => wp_strip_all_tags( $newsarticle['articleBody'] ),
			);
		}
		if ( isset( $newsarticle['author'] ) ) {
			if ( ! wp_is_numeric_array( $newsarticle['author'] ) ) {
				$newsarticle['author'] = array( $newsarticle['author'] );
			}
			$jf2['author'] = array();
			foreach ( $newsarticle['author'] as $author ) {
				$jf2['author'][] = self::person_to_hcard( $author );
			}
		} elseif ( isset( $newsarticle['creator'] ) ) {
			if ( ! wp_is_numeric_array( $newsarticle['creator'] ) ) {
				$newsarticle['creator'] = array( $newsarticle['creator'] );
			}
			$jf2['author'] = array();
			foreach ( $newsarticle['creator'] as $creator ) {
				$jf2['author'][] = self::person_to_hcard( $creator );
			}
		}

		if ( isset( $jf2['author'] ) && wp_is_numeric_array( $jf2['author'] ) && 1 === count( $jf2['author'] ) ) {
			$jf2['author'] = $jf2['author'][0];
		}

		if ( isset( $newsarticle['publisher'] ) ) {
			$jf2['publication'] = self::organization_to_hcard( $newsarticle['publisher'] );
		}
		return array_filter( $jf2 );
	}
}
