<?php
/**
 * Parse_This_Instagram class.
 *
 * @package Parse_This
 */

/**
 * Extracts posts and locations from Instagram pages.
 *
 * Reads the window._sharedData JSON that Instagram used to embed in its pages.
 * Instagram no longer serves it, so this parser currently returns nothing
 * (review finding CMP-7).
 *
 * @since 1.0.0
 */
class Parse_This_Instagram extends Parse_This_Base {
	/**
	 * Parses an Instagram post or location page into jf2.
	 *
	 * @since 1.0.0
	 *
	 * @param DOMDocument|null $doc  Parsed HTML document.
	 * @param string           $url  URL of the page.
	 * @param array            $args Parse arguments (see Parse_This::parse()). Unused.
	 * @return array jf2 properties, or an empty array if no shared data was found or
	 *               the page is a login wall.
	 */
	public static function parse( $doc, $url, $args ) {
		if ( ! $doc ) {
			return array();
		}
		$xpath = new DOMXPath( $doc );
		foreach ( $xpath->query( '//script' ) as $script ) {
			if ( preg_match( '/window\._sharedData = ({.+});/', $script->textContent, $match ) ) { // phpcs:ignore
				$data = json_decode( $match[1], true );
			}
		}
		if ( empty( $data ) ) {
			return array();
		}

		$jf2 = array();
		if ( $data && is_array( $data ) && array_key_exists( 'entry_data', $data ) ) {
			if ( is_array( $data['entry_data'] ) ) {
				if ( array_key_exists( 'PostPage', $data['entry_data'] ) ) {
					// Photo Page.
					$jf2 = self::html_photo( $data, $url );
				} elseif ( array_key_exists( 'LocationsPage', $data['entry_data'] ) ) {
					// Locations Page.
					$jf2 = self::html_location( $data, $url );
				} elseif ( array_key_exists( 'LoginAndSignupPage', $data['entry_data'] ) ) {
					return array();
				}
			}
		}
		if ( WP_DEBUG ) {
			$jf2['_ig'] = $data;
		}
		return array_filter( $jf2 );
	}

	/**
	 * Extracts a location from a locations-page data structure.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data Decoded window._sharedData.
	 * @param string $url  URL of the page.
	 * @return array jf2 location properties, or an empty array.
	 */
	private static function html_location( $data, $url ) {
		$post = $data['entry_data']['LocationsPage'];
		if ( isset( $post[0]['graphql']['location'] ) ) {
			$data = $post[0]['graphql']['location'];
		} else {
			return array();
		}
		return self::json_location( $data, $url );
	}

	/**
	 * Converts Instagram location data into jf2 properties.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data Instagram location object.
	 * @param string $url  URL of the page. Unused.
	 * @return array Location properties: name, latitude, longitude, url and the
	 *               decoded address parts.
	 */
	private static function json_location( $data, $url ) {
		$address = isset( $data['address_json'] ) ? json_decode( $data['address_json'], true ) : array();
		$jf2     = array(
			'address'        => $address,
			'name'           => ifset( $data['name'] ),
			'latitude'       => ifset( $data['lat'] ),
			'longitude'      => ifset( $data['lng'] ),
			'url'            => ifset( $data['website'] ),
			'street_address' => ifset( $address['street_address'] ),
			'postal_code'    => ifset( $address['zip_code'] ),
			'region'         => ifset( $address['region_name'] ),
			'country'        => ifset( $address['country_code'] ),
		);
		return array_filter( $jf2 );
	}

	/**
	 * Returns profile data for a profile page.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data Decoded window._sharedData.
	 * @param string $url  URL of the page. Unused.
	 * @return array The profile's user object, or an empty array.
	 */
	private static function feed( $data, $url ) {
		return self::profile( $data );
	}

	/**
	 * Extracts a post from a post-page data structure.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data Decoded window._sharedData.
	 * @param string $url  URL of the page.
	 * @return array jf2 entry for the post.
	 */
	private static function html_photo( $data, $url ) {
		$post = $data['entry_data']['PostPage'];
		if ( isset( $post[0]['graphql']['shortcode_media'] ) ) {
			$data = $post[0]['graphql']['shortcode_media'];
		} elseif ( isset( $post[0]['graphql']['media'] ) ) {
			$data = $post[0]['graphql']['media'];
		} elseif ( isset( $post[0]['media'] ) ) {
			$data = $post[0]['media'];
		}
		return self::json_photo( $data, $url );
	}

	/**
	 * Converts Instagram media data into a jf2 entry.
	 *
	 * Captions become content, hashtags become categories, and carousel posts
	 * list every image under photo.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data Instagram media object.
	 * @param string $url  URL of the post.
	 * @return array jf2 entry with url, content, category, photo, video, published,
	 *               location and author where available.
	 */
	public static function json_photo( $data, $url ) {
		// Start building the h-entry.
		$entry = array(
			'type' => 'entry',
			'url'  => $url,
		);

		// Content and hashtags.
		$caption = false;

		if ( isset( $data['caption'] ) ) {
			  $caption = $data['caption'];
		} elseif ( isset( $data['edge_media_to_caption']['edges'][0]['node']['text'] ) ) {
			  $caption = $data['edge_media_to_caption']['edges'][0]['node']['text'];
		}

		if ( $caption ) {
			if ( preg_match_all( '/#([a-z0-9_-]+)/i', $caption, $matches ) ) {
				$entry['category'] = array();
				foreach ( $matches[1] as $match ) {
					$entry['category'][] = $match;
				}
			}

			$entry['content'] = array(
				'text' => $caption,
			);
		}

		// Include the photo/video media URLs
		// (Always return arrays, even for single images).
		if ( array_key_exists( 'edge_sidecar_to_children', $data ) ) {
			$entry['photo'] = array();
			foreach ( $data['edge_sidecar_to_children']['edges'] as $edge ) {
				$entry['photo'][] = $edge['node']['display_url'];
			}
		} else {
			 // Single photo or video.
			if ( array_key_exists( 'display_src', $data ) ) {
				$entry['photo'] = array( $data['display_src'] );
			} elseif ( array_key_exists( 'display_url', $data ) ) {
				$entry['photo'] = array( $data['display_url'] );
			}

			if ( isset( $data['is_video'] ) && $data['is_video'] && isset( $data['video_url'] ) ) {
				$entry['video'] = array( $data['video_url'] );
			}
		}

		// Published date.
		if ( isset( $data['taken_at_timestamp'] ) ) {
			$published = new DateTime();
			$published->setTimestamp( (int) $data['taken_at_timestamp'] );
			$entry['published'] = $published->format( DATE_W3C );
		} elseif ( isset( $data['date'] ) ) {
			$entry['published'] = normalize_iso8601( $data['date'] );
		}
		if ( isset( $data['location'] ) ) {
			$entry['location'] = array();
			if ( isset( $data['location']['address_json'] ) ) {
				$address           = json_decode( $data['location']['address_json'], true );
				$entry['location'] = array(
					'street_address' => $address['street_address'],
					'postal_code'    => $address['zip_code'],
					'region'         => $address['region_name'],
					'country'        => $address['country_code'],
				);
			}
			$entry['location']['name'] = $data['location']['name'];
			$entry['location']['url']  = sprintf( 'https://www.instagram.com/explore/locations/%1$s', $data['location']['id'] );
			$entry['location']         = array_filter( $entry['location'] );
		}
		if ( isset( $data['owner'] ) ) {
			$entry['author'] = array(
				'type'     => 'card',
				'name'     => ifset( $data['owner']['full_name'] ),
				'nickname' => ifset( $data['owner']['username'] ),
				'url'      => sprintf( 'https://www.instagram.com/%1$s/', $data['owner']['username'] ),
				'photo'    => ifset( $data['owner']['profile_pic_url'] ),
			);
		}
		return $entry;
	}

	/**
	 * Extracts the user object from profile-page data.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Decoded window._sharedData.
	 * @return array The user object, or an empty array.
	 */
	private static function profile( $data ) {
		if ( isset( $data['entry_data']['ProfilePage'][0] ) ) {
			$profile = $data['entry_data']['ProfilePage'][0];
			if ( $profile && isset( $profile['graphql']['user'] ) ) {
				$user = $profile['graphql']['user'];
				return $user;
			}
		}
		return array();
	}
}
