<?php
/**
 * Helper functions.
 *
 * All functions are in the ParseThis namespace. Each is wrapped in
 * function_exists() because several plugins bundle their own copy of Parse
 * This; the first copy loaded wins. Deprecated global names for some of them
 * are defined in aliases.php.
 *
 * @package Parse_This
 */

namespace ParseThis;

if ( ! function_exists( __NAMESPACE__ . '\\jf2_to_mf2' ) ) {
	/**
	 * Converts jf2 into microformats2 JSON.
	 *
	 * The inverse of mf2_to_jf2(). Empty values and the _raw key are dropped.
	 *
	 * @since 1.0.0
	 *
	 * @link https://jf2.spec.indieweb.org/
	 *
	 * @param array|mixed $jf2 jf2 object, or array( 'items' => ... ) for a list.
	 * @return array|mixed mf2 object with type and properties. Values that are not
	 *                     jf2 objects are returned unchanged.
	 */
	function jf2_to_mf2( $jf2 ) {
		if ( ! $jf2 || ! is_array( $jf2 ) ) {
			return $jf2;
		}
		if ( 1 === count( $jf2 ) && array_key_exists( 'items', $jf2 ) ) {
			return array(
				'items' => array_map( __NAMESPACE__ . '\\jf2_to_mf2', $jf2['items'] ),
			);
		}

		if ( array_key_exists( 'properties', $jf2 ) || ! array_key_exists( 'type', $jf2 ) ) {
			return $jf2;
		}

		$mf2 = array();
		if ( array_key_exists( 'type', $jf2 ) ) {
			$mf2['type'] = array( 'h-' . $jf2['type'] );
			unset( $jf2['type'] );
		}
		if ( array_key_exists( 'children', $jf2 ) ) {
			$mf2['children'] = array_map( __NAMESPACE__ . '\\jf2_to_mf2', $jf2['children'] );
			unset( $jf2['children'] );
		}

		$mf2['properties'] = array();

		foreach ( $jf2 as $key => $value ) {
			// Exclude values.
			if ( empty( $value ) || ( '_raw' === $key ) ) {
				continue;
			}
			if ( ! wp_is_numeric_array( $value ) && is_array( $value ) && array_key_exists( 'type', $value ) ) {
				$value = array( jf2_to_mf2( $value ) );
			} elseif ( wp_is_numeric_array( $value ) ) {
				$value = array_map( __NAMESPACE__ . '\\jf2_to_mf2', $value );
			} elseif ( ! wp_is_numeric_array( $value ) ) {
				$value = array( $value );
			}
			$mf2['properties'][ $key ] = $value;
		}
		return $mf2;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\mf2_to_jf2' ) ) {

	/**
	 * Converts microformats2 JSON into jf2.
	 *
	 * Single-value property arrays are collapsed to the value, the h- prefix is
	 * removed from types, and nested microformats are converted recursively.
	 *
	 * @since 1.0.0
	 *
	 * @link https://jf2.spec.indieweb.org/
	 *
	 * @param array|mixed $mf2 mf2 object, a parsed mf2 document with items, or a
	 *                         list of mf2 objects.
	 * @return array|mixed jf2. A list of one is returned as the single item.
	 *                     Strings and objects are returned unchanged.
	 */
	function mf2_to_jf2( $mf2 ) {
		if ( empty( $mf2 ) || is_string( $mf2 ) || is_object( $mf2 ) ) {
			return $mf2;
		}

		$jf2 = array();

		// If it is a numeric array, run this function through each item.
		if ( wp_is_numeric_array( $mf2 ) ) {
			$jf2 = array_map( __NAMESPACE__ . '\\mf2_to_jf2', $mf2 );
			if ( 1 === count( $jf2 ) ) {
				return array_pop( $jf2 );
			}
			return $jf2;
		}

		if ( isset( $mf2['items'] ) ) {
			$jf2['items'] = array_map( __NAMESPACE__ . '\\mf2_to_jf2', $mf2['items'] );
		}

		if ( isset( $mf2['children'] ) ) {
			$jf2['children'] = array_map( __NAMESPACE__ . '\\mf2_to_jf2', $mf2['children'] );
		}

		if ( isset( $mf2['type'] ) ) {
			$type        = is_array( $mf2['type'] ) ? array_pop( $mf2['type'] ) : $mf2['type'];
			$jf2['type'] = str_replace( 'h-', '', $type );
		}
		if ( isset( $mf2['properties'] ) ) {
			foreach ( $mf2['properties'] as $key => $value ) {
				if ( is_array( $value ) ) {
					if ( wp_is_numeric_array( $value ) ) {
						$value = array_map( __NAMESPACE__ . '\\mf2_to_jf2', $value );
						if ( is_countable( $value ) && 1 === count( $value ) ) {
							$value = array_pop( $value );
						}
					} elseif ( isset( $value['type'] ) ) {
						$value = mf2_to_jf2( $value );
					}
				}
				$jf2[ $key ] = $value;
			}
		}
		return $jf2;
	}
}


if ( ! function_exists( __NAMESPACE__ . '\\jf2_location' ) ) {
	/**
	 * Flattens a nested jf2 location.
	 *
	 * The latitude, longitude and altitude are copied onto the object, and location
	 * becomes the place's label or name (or is removed). Any check-in on the
	 * object also receives the location's properties.
	 *
	 * @since 1.0.1
	 *
	 * @param array $data jf2 object.
	 * @return array The updated object.
	 */
	function jf2_location( $data ) {
		if ( ! array_key_exists( 'location', $data ) ) {
			return $data;
		}
		$location = $data['location'];
		if ( is_string( $location ) ) {
			return $data;
		}
		foreach ( array( 'latitude', 'longitude', 'altitude' ) as $prop ) {
			if ( array_key_exists( $prop, $location ) ) {
				$data[ $prop ] = $location[ $prop ];
			}
		}
		if ( array_key_exists( 'label', $location ) ) {
			$data['location'] = $location['label'];
		} elseif ( array_key_exists( 'name', $location ) ) {
			$data['location'] = $location['name'];
		} else {
			unset( $data['location'] );
		}
		if ( array_key_exists( 'checkin', $data ) && is_array( $data['checkin'] ) ) {
			foreach ( $location as $key => $value ) {
				if ( ! array_key_exists( $key, $data['checkin'] ) ) {
					$data['checkin'][ $key ] = $value;
				}
			}
		}
		return $data;
	}
}


if ( ! function_exists( __NAMESPACE__ . '\\jf2_references' ) ) {
	/**
	 * Moves nested citations into refs, per the jf2 spec.
	 *
	 * Properties holding h-cite objects are replaced by their URLs, and the
	 * citations are stored in refs keyed by URL. Typed category values are
	 * moved the same way.
	 *
	 * @since 1.0.0
	 *
	 * @link https://jf2.spec.indieweb.org/#references
	 *
	 * @param array $data jf2 object.
	 * @return array The updated object.
	 */
	function jf2_references( $data ) {
		foreach ( $data as $key => $val ) {
			if ( 'refs' === $key || ! is_array( $val ) ) {
				continue;
			}
			$values  = wp_is_numeric_array( $val ) ? $val : array( $val );
			$changed = false;
			foreach ( $values as $i => $value ) {
				if ( ! is_array( $value ) || ! isset( $value['url'] ) || ! is_string( $value['url'] ) || ! isset( $value['type'] ) ) {
					continue;
				}
				// Any typed category (a person tag, say) is a reference; elsewhere only citations are.
				if ( 'category' !== $key && 'cite' !== $value['type'] ) {
					continue;
				}
				if ( ! isset( $data['refs'] ) ) {
					$data['refs'] = array();
				}
				$data['refs'][ $value['url'] ] = $value;
				$values[ $i ]                  = $value['url'];
				$changed                       = true;
			}
			if ( $changed ) {
				$data[ $key ] = $values;
			}
		}
		return $data;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\url_to_author' ) ) {
	/**
	 * Returns the local user that an author archive URL belongs to.
	 *
	 * Handles ?author=N URLs and pretty author permalinks.
	 *
	 * @since 1.0.0
	 *
	 * @global WP_Rewrite $wp_rewrite WordPress rewrite component.
	 *
	 * @param string $url URL to check.
	 * @return WP_User|false|null The user, false if no user has that ID or slug, or
	 *                            null if the URL is not on this site or not an
	 *                            author URL.
	 */
	function url_to_author( $url ) {
		global $wp_rewrite;
		// check if url hase the same host.
		if ( wp_parse_url( site_url(), PHP_URL_HOST ) !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return null;
		}
		// first, check to see if there is a 'author=N' to match against.
		if ( preg_match( '/[?&]author=(\d+)/i', $url, $values ) ) {
			$id = absint( $values[1] );
			if ( $id ) {
				return get_user_by( 'id', $id );
			}
		}
		// check to see if we are using rewrite rules.
		$rewrite = $wp_rewrite->wp_rewrite_rules();
		// not using rewrite rules, and 'author=N' method failed, so we're out of options.
		if ( empty( $rewrite ) ) {
			return null;
		}
		// generate rewrite rule for the author url.
		$author_rewrite = $wp_rewrite->get_author_permastruct();
		$author_regexp  = str_replace( '%author%', '', $author_rewrite );
		// match the rewrite rule with the passed url.
		if ( preg_match( '/https?:\/\/(.+)' . preg_quote( $author_regexp, '/' ) . '([^\/]+)/i', $url, $match ) ) {
			$user = get_user_by( 'slug', $match[2] );
			if ( $user ) {
				return $user;
			}
		}
		return null;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\url_to_user' ) ) {
	/**
	 * Returns the local user associated with a URL.
	 *
	 * The site's home URL maps to the IndieWeb plugin's default author when the
	 * IndieWeb plugin is active, otherwise to the only author if there is just
	 * one. Author archive URLs map to their author, and other URLs are matched
	 * against users' website field.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL to check.
	 * @return WP_User|false|null The user, or null (or false) if none matches.
	 */
	function url_to_user( $url ) {
		if ( empty( $url ) ) {
			return null;
		}
		// Ensure has trailing slash.
		$url = trailingslashit( $url );
		if ( ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) && ( wp_parse_url( home_url(), PHP_URL_HOST ) === wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			$url = set_url_scheme( $url, 'https' );
		}
		// Try to save the expense of a search query if the URL is the site URL.
		if ( home_url( '/' ) === $url ) {
			// Use the Indieweb settings to set the default author.
			if ( class_exists( 'Indieweb_Plugin' ) && ( get_option( 'iw_single_author' ) || ! is_multi_author() ) ) {
				return get_user_by( 'id', get_option( 'iw_default_author' ) );
			}
			$users = get_users( array( 'capability' => array( 'edit_posts' ) ) );
			if ( 1 === count( $users ) ) {
				return $users[0];
			}
			return null;
		}
		// Check if this is a author post URL.
		$user = url_to_author( $url );
		if ( $user instanceof \WP_User ) {
			return $user;
		}
		$args  = array(
			'search'         => $url,
			'search_columns' => array( 'user_url' ),
		);
		$users = get_users( $args );
		// check result.
		if ( ! empty( $users ) ) {
			return $users[0];
		}
		return null;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\build_url' ) ) {
	/**
	 * Builds a URL from its parts; the inverse of wp_parse_url().
	 *
	 * Slightly modified from p3k-utils. Copyright 2017 Aaron Parecki, used with
	 * permission under the MIT License.
	 *
	 * @since 1.0.0
	 *
	 * @link https://github.com/aaronpk/p3k-utils
	 * @link https://www.php.net/parse_url
	 *
	 * @param array $parsed_url URL components as returned by wp_parse_url().
	 * @return string The URL.
	 */
	function build_url( $parsed_url ) {
			$scheme   = ! empty( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
			$host     = ! empty( $parsed_url['host'] ) ? $parsed_url['host'] : '';
			$port     = ! empty( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
			$user     = ! empty( $parsed_url['user'] ) ? $parsed_url['user'] : '';
			$pass     = ! empty( $parsed_url['pass'] ) ? ':' . $parsed_url['pass'] : '';
			$pass     = ( $user || $pass ) ? "$pass@" : '';
			$path     = ! empty( $parsed_url['path'] ) ? $parsed_url['path'] : '';
			$query    = ! empty( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';
			$fragment = ! empty( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';

			return "$scheme$user$pass$host$port$path$query$fragment";
	}
}


if ( ! function_exists( __NAMESPACE__ . '\\normalize_url' ) ) {
	/**
	 * Normalizes a URL for comparison.
	 *
	 * Lowercases the host and adds a / path if there is none.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url   URL to normalize.
	 * @param bool   $strip Optional. Whether to remove the query string. Default false.
	 * @return string|null The normalized URL, or null if it has no host.
	 */
	function normalize_url( $url, $strip = false ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) ) {
				$parts['path'] = '/';
		}
		if ( $strip ) {
			$parts['query'] = '';
		}
		if ( isset( $parts['host'] ) ) {
				$parts['host'] = strtolower( $parts['host'] );
				return build_url( $parts );
		}
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\normalize_iso8601' ) ) {
	/**
	 * Normalizes a date string to W3C (ISO 8601) format.
	 *
	 * @since 1.0.0
	 *
	 * @param string|mixed $string Date string.
	 * @return string|null The date in W3C format, the original string if it cannot
	 *                     be parsed, or null if it is empty or not a string.
	 */
	function normalize_iso8601( $string ) {
		if ( empty( $string ) || ! is_string( $string ) ) {
			return null;
		}
		try {
			$date = new \DateTime( $string );
		} catch ( \Exception $e ) {
			return $string;
		}
		return $date->format( DATE_W3C );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\post_type_discovery' ) ) {
	/**
	 * Determines the IndieWeb post type of a jf2 or mf2 entry.
	 *
	 * Response properties decide the type first (rsvp, checkin, like-of,
	 * in-reply-to, ...), then media (video, photo, audio). An entry with a name
	 * that is not just the start of its content is an article; anything else is
	 * a note.
	 *
	 * @since 1.0.0
	 *
	 * @link https://indieweb.org/post-type-discovery
	 *
	 * @param array|mixed $jf2 jf2 object, or an mf2 object (converted first).
	 * @return string The post type, 'event' for events, or an empty string.
	 */
	function post_type_discovery( $jf2 ) {
		if ( ! is_array( $jf2 ) ) {
			return '';
		}
		if ( array_key_exists( 'properties', $jf2 ) ) {
			$jf2 = mf2_to_jf2( $jf2 );
		}
		if ( ! array_key_exists( 'type', $jf2 ) ) {
			return '';
		}
		if ( 'event' === $jf2['type'] ) {
			return 'event';
		}
		if ( 'entry' === $jf2['type'] ) {
			$map = array(
				'rsvp'      => array( 'rsvp' ),
				'checkin'   => array( 'checkin' ),
				'itinerary' => array( 'itinerary' ),
				'repost'    => array( 'repost-of' ),
				'like'      => array( 'like-of' ),
				'follow'    => array( 'follow-of' ),
				'tag'       => array( 'tag-of' ),
				'favorite'  => array( 'favorite-of' ),
				'bookmark'  => array( 'bookmark-of' ),
				'watch'     => array( 'watch-of' ),
				'jam'       => array( 'jam-of' ),
				'listen'    => array( 'listen-of' ),
				'read'      => array( 'read-of' ),
				'play'      => array( 'play-of' ),
				'eat'       => array( 'ate', 'pk-ate' ),
				'drink'     => array( 'drank', 'pk-drank' ),
				'reply'     => array( 'in-reply-to' ),
				'video'     => array( 'video' ),
				'photo'     => array( 'photo' ),
				'audio'     => array( 'audio' ),
			);
			foreach ( $map as $key => $value ) {
				$diff = array_intersect( array_keys( $jf2 ), $value );
				if ( ! empty( $diff ) ) {
					return $key;
				}
			}
			if ( isset( $jf2['name'] ) && ! empty( $jf2['name'] ) ) {
				$jf2['name'] = $jf2['name'];
				$content     = $jf2['content'] ?? null;
				if ( ! $content ) {
					$content = $jf2['summary'] ?? null;
				}
				if ( is_array( $content ) && array_key_exists( 'text', $content ) ) {
					$content = $content['text'];
				}
				if ( is_string( $content ) ) {
					$content = trim( $content );
					if ( 0 !== strpos( $content, $jf2['name'] ) ) {
						return 'article';
					}
				}
			}
				return 'note';
		}
		return '';
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\seconds_to_iso8601' ) ) {
	/**
	 * Converts a number of seconds into an ISO 8601 duration.
	 *
	 * For example 3725 becomes PT1H2M5S.
	 *
	 * @since 1.0.0
	 *
	 * @param int|float $second Duration in seconds.
	 * @return string ISO 8601 duration.
	 */
	function seconds_to_iso8601( $second ) {
		$h   = intval( $second / 3600 );
		$m   = intval( ( $second - $h * 3600 ) / 60 );
		$s   = $second - ( $h * 3600 + $m * 60 );
		$ret = 'PT';
		if ( $h ) {
			$ret .= $h . 'H';
		}
		if ( $m ) {
			$ret .= $m . 'M';
		}
		if ( ( ! $h && ! $m ) || $s ) {
			$ret .= $s . 'S';
		}
		return $ret;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\pt_load_domdocument' ) ) {
	/**
	 * Parses HTML into a DOMDocument.
	 *
	 * Uses the bundled masterminds/html5 parser when available, otherwise
	 * PHP's DOMDocument with errors suppressed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content HTML.
	 * @return DOMDocument The parsed document.
	 */
	function pt_load_domdocument( $content ) {
		if ( ! class_exists( '\Masterminds\HTML5', false ) ) {
			$file = plugin_dir_path( __DIR__ ) . 'lib/html5/autoloader.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
		if ( class_exists( 'Masterminds\\HTML5' ) ) {
			$doc = new \Masterminds\HTML5( array( 'disable_html_ns' => true ) );
			$doc = $doc->loadHTML( $content );
		} else {
			$doc = new \DOMDocument();
			libxml_use_internal_errors( true );
			if ( function_exists( 'mb_encode_numericentity' ) ) {
				// DOMDocument assumes ISO-8859-1, so convert to UTF-8 and encode non-ASCII characters as entities.
				$encoding = mb_detect_encoding( $content, array( 'UTF-8', 'ISO-8859-1' ), true );
				if ( $encoding && 'UTF-8' !== $encoding ) {
					$content = mb_convert_encoding( $content, 'UTF-8', $encoding );
				}
				$content = mb_encode_numericentity( $content, array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' );
			}
			$doc->loadHTML( $content );
			libxml_use_internal_errors( false );
		}
		return $doc;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\\pt_remote_get' ) ) {
	/**
	 * Retrieves a remote URL, retrying once with a browser user agent if the site rejects the request.
	 *
	 * @param string $url         URL to retrieve.
	 * @param array  $args        Optional. Arguments passed to wp_safe_remote_get().
	 * @param array  $retry_codes Optional. Response codes that trigger the retry.
	 * @return array|WP_Error The response, or WP_Error on failure.
	 */
	function pt_remote_get( $url, $args = array(), $retry_codes = array( 403, 415 ) ) {
		$args = wp_parse_args(
			$args,
			array(
				'timeout'             => 15,
				'limit_response_size' => 1048576,
				'redirection'         => 5,
			)
		);

		$response = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! in_array( (int) wp_remote_retrieve_response_code( $response ), $retry_codes, true ) ) {
			return $response;
		}

		$args['user-agent'] = 'Mozilla/5.0 (X11; Fedora; Linux x86_64; rv:57.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/57.0.2987.133 Safari/537.36 Parse This/WP';
		$response           = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( in_array( (int) wp_remote_retrieve_response_code( $response ), $retry_codes, true ) ) {
			return new \WP_Error( 'source_error', 'Unable to Retrieve' );
		}
		return $response;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\pt_secure_rewrite' ) ) {
	/**
	 * Upgrades http:// URLs to https:// for hosts known to support HTTPS.
	 *
	 * @since 1.0.1
	 *
	 * @param string $url URL.
	 * @return string The URL, with https:// if its domain is on the list.
	 */
	function pt_secure_rewrite( $url ) {
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$host   = preg_replace( '/^([a-zA-Z0-9].*\.)?([a-zA-Z0-9][a-zA-Z0-9-]{1,61}[a-zA-Z0-9]\.[a-zA-Z.]{2,})$/', '$2', $host );
		$secure = array(
			'blogger.com',
			'creativecommons.org',
			'dailymotion.com',
			'debian.org',
			'facebook.com',
			'foursquare.com',
			'feedburner.com',
			'fsf.org',
			'fsfe.org',
			'github.com',
			'gitlab.com',
			'gnu.org',
			'google.com',
			'gravatar.com',
			'gstatic.com',
			'kernel.org',
			'lwn.net',
			'tumblr.com',
			'twitter.com',
			'x.com',
			'vimeo.com',
			'wikipedia.org',
			'wordpress.com',
			'youtube.com',
		);
		/**
		 * Filters the domains whose http:// URLs are upgraded to https://.
		 *
		 * Matching is on the registrable domain, so listing example.com also
		 * covers www.example.com.
		 *
		 * @since 1.0.1
		 *
		 * @param string[] $secure Domains known to support HTTPS.
		 */
		$secure = apply_filters( 'pt_rewrite_secure', $secure );
		if ( in_array( $host, $secure, true ) ) {
			$url = preg_replace( '/^http:/i', 'https:', $url );
		}
		return $url;
	}
}


if ( ! function_exists( __NAMESPACE__ . '\\pt_parse_header_links' ) ) {
	/**
	 * Parses HTTP Link headers.
	 *
	 * @since 2.0.0
	 *
	 * @link https://www.rfc-editor.org/rfc/rfc8288
	 *
	 * @param string|string[] $links One Link header value, or several. A value may
	 *                               hold multiple comma-separated links.
	 * @return array[] One entry per link and rel value, with 'uri', 'rel' and any
	 *                 other parameters (type, title, ...).
	 */
	function pt_parse_header_links( $links ) {
		$items = array();

		if ( is_string( $links ) ) {
			$links = array( $links );
		}

		if ( is_array( $links ) && 1 <= count( $links ) ) {
			// A header may hold several comma-separated links. Only split on commas that start a new <uri>, as URIs may contain commas.
			$split = array();
			foreach ( $links as $link ) {
				$split = array_merge( $split, preg_split( '/,(?=\s*<)/', $link ) );
			}
			foreach ( $split as $link ) {
				$item = array();
				if ( preg_match( '/^\s*<([^>]*)>(.*)$/s', $link, $match ) ) {
					$uri    = $match[1];
					$pieces = explode( ';', $match[2] );
				} else {
					$pieces = explode( ';', $link );
					$uri    = array_shift( $pieces );
				}
				foreach ( $pieces as $p ) {
					$elements = explode( '=', $p, 2 );
					$name     = trim( $elements[0] );
					if ( '' === $name ) {
						continue;
					}

					$item[ $name ] = isset( $elements[1] ) ? trim( $elements[1], " \t\"'" ) : '';
				}

				$item['uri'] = trim( trim( $uri ), '<>' );

				if ( isset( $item['rel'] ) ) {
					$rels = explode( ' ', $item['rel'] );
					foreach ( $rels as $rel ) {
						$item['rel'] = $rel;
						$items[]     = $item;
					}
				} else {
					$items[] = array_filter( $item );
				}
			}
		}

		return array_filter( $items );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\pt_find_rest_alternate' ) ) {
	/**
	 * Finds the application/json alternate link among parsed Link headers.
	 *
	 * WordPress sends this for each post, pointing at its REST API URL.
	 *
	 * @since 2.0.0
	 *
	 * @param array[] $links Links from pt_parse_header_links().
	 * @return string|false The URL, or false if there is none.
	 */
	function pt_find_rest_alternate( $links ) {
		foreach ( $links as $link ) {
			if ( 'alternate' === ( $link['rel'] ?? null ) && 'application/json' === ( $link['type'] ?? null ) ) {
				return $link['uri'];
			}
		}

		return false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\pt_find_rest_endpoint' ) ) {
	/**
	 * Finds the WordPress REST API root among parsed Link headers.
	 *
	 * @since 2.0.0
	 *
	 * @param array[] $links Links from pt_parse_header_links().
	 * @return string|false The REST API root URL, or false if there is none.
	 */
	function pt_find_rest_endpoint( $links ) {
		foreach ( $links as $link ) {
			if ( 'https://api.w.org/' === ( $link['rel'] ?? null ) ) {
				return $link['uri'];
			}
		}
		return false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\pt_make_absolute_url' ) ) {
	/**
	 * Resolves a relative URL against a base URL.
	 *
	 * @since 2.0.0
	 *
	 * @param string $maybe_relative_path URL or path to resolve.
	 * @param string $url                 Base URL.
	 * @return string The absolute URL, or $maybe_relative_path unchanged if it is
	 *                already absolute or either value cannot be parsed.
	 */
	function pt_make_absolute_url( $maybe_relative_path, $url ) {
		if ( empty( $url ) ) {
			return $maybe_relative_path;
		}

		$url_parts = wp_parse_url( $url );
		if ( ! $url_parts ) {
			return $maybe_relative_path;
		}

		$relative_url_parts = wp_parse_url( $maybe_relative_path );
		if ( ! $relative_url_parts ) {
			return $maybe_relative_path;
		}

		// Check for a scheme on the 'relative' URL.
		if ( ! empty( $relative_url_parts['scheme'] ) ) {
			return $maybe_relative_path;
		}

		$absolute_path = $url_parts['scheme'] . '://';

		// Schemeless URLs will make it this far, so we check for a host in the relative URL
		// and convert it to a protocol-URL.
		if ( isset( $relative_url_parts['host'] ) ) {
			$absolute_path .= $relative_url_parts['host'];
			if ( isset( $relative_url_parts['port'] ) ) {
				$absolute_path .= ':' . $relative_url_parts['port'];
			}
		} else {
			$absolute_path .= $url_parts['host'];
			if ( isset( $url_parts['port'] ) ) {
				$absolute_path .= ':' . $url_parts['port'];
			}
		}

		// Start off with the absolute URL path.
		$path = ! empty( $url_parts['path'] ) ? $url_parts['path'] : '/';

		// If it's a root-relative path, then great.
		if ( ! empty( $relative_url_parts['path'] ) && '/' === $relative_url_parts['path'][0] ) {
			$path = $relative_url_parts['path'];

			// Else it's a relative path.
		} elseif ( ! empty( $relative_url_parts['path'] ) ) {
			// Strip off any file components from the absolute path.
			$path = substr( $path, 0, strrpos( $path, '/' ) + 1 );

			// Build the new path.
			$path .= $relative_url_parts['path'];

			// Strip all /path/../ out of the path.
			while ( strpos( $path, '../' ) > 1 ) {
				$path = preg_replace( '![^/]+/\.\./!', '', $path );
			}

			// Strip any final leading ../ from the path.
			$path = preg_replace( '!^/(\.\./)+!', '', $path );
		}

		// Add the query string.
		if ( ! empty( $relative_url_parts['query'] ) ) {
				$path .= '?' . $relative_url_parts['query'];
		}

		// Add the fragment string.
		if ( ! empty( $relative_url_parts['fragment'] ) ) {
				$path .= '#' . $relative_url_parts['fragment'];
		}

		return $absolute_path . '/' . ltrim( $path, '/' );
	}
}
