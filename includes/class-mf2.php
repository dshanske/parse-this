<?php
/**
 * MF2 class.
 *
 * @package Parse_This
 */

namespace ParseThis;

/**
 * Converts microformats2 into jf2.
 *
 * Parses HTML with the bundled php-mf2 parser (or accepts already-parsed
 * mf2), then converts each supported h-* type into its jf2 form. Derived from
 * php-mf-cleaner, XRay's Mf2 format and Semantic Linkbacks' mf2 handler.
 *
 * @since 1.0.0
 *
 * @link https://github.com/barnabywalters/php-mf-cleaner
 * @link https://github.com/aaronpk/XRay/blob/master/lib/Formats/Mf2.php
 * @link https://github.com/pfefferle/wordpress-semantic-linkbacks/blob/master/includes/class-linkbacks-mf2-handler.php
 */
class MF2 extends MF2_Utils {

	/**
	 * Finds the h-feeds in a document.
	 *
	 * Top-level h-feeds and h-feeds nested one level inside another item are
	 * returned. If the document has items but no h-feed, an implied h-feed for
	 * $url is returned. Feeds without a url get $url, plus #id if they have one.
	 *
	 * @since 1.0.0
	 *
	 * @param string|DOMDocument|array $input HTML, a parsed DOM document, or parsed mf2.
	 * @param string                   $url   URL of the document.
	 * @return array[] The h-feed microformats found.
	 */
	public static function find_hfeed( $input, $url ) {
		if ( ! class_exists( 'Mf2\Parser' ) ) {
					require_once plugin_dir_path( __DIR__ ) . 'lib/mf2/Parser.php';
		}
		if ( is_string( $input ) || is_a( $input, 'DOMDocument' ) ) {
			$parser = new \Mf2\Parser( $input, $url );
			$input  = $parser->parse();
		}

		$feeds = array();

		if ( array_key_exists( 'items', $input ) ) {
			foreach ( $input['items'] as $item ) {
				if ( self::is_type( $item, 'h-feed' ) ) {
					$feeds[] = $item;
				} elseif ( self::has_children( $item ) ) {
					foreach ( $item['children'] as $child ) {
						if ( self::is_type( $child, 'h-feed' ) ) {
							$feeds[] = $child;
						}
					}
				}
			}
			if ( empty( $feeds ) && 1 <= count( $input['items'] ) ) {
				$feeds[] = array(
					'type'       => 'h-feed',
					'properties' => array(
						'url' => array( $url ),
					),
				);
			}
		}
		foreach ( $feeds as $key => $feed ) {
			if ( ! array_key_exists( 'url', $feed['properties'] ) ) {
				if ( array_key_exists( 'id', $feed ) ) {
					$feeds[ $key ]['properties']['url'] = array( $url . '#' . $feed['id'] );
				} else {
					$feeds[ $key ]['properties']['url'] = array( $url );
				}
			}
		}
		return $feeds;
	}

	/**
	 * Finds the author of an item using the IndieWeb authorship algorithm.
	 *
	 * Uses the item's author h-card if it has one; otherwise an author URL, the
	 * author name, or the document's rel=author link. When $follow is true and
	 * the author page is on another URL, that page is fetched and parsed.
	 *
	 * @since 1.0.0
	 *
	 * @param array      $item   Microformat to find the author of.
	 * @param array|bool $mf2    Parsed mf2 document the item came from.
	 * @param bool       $follow Optional. Whether to fetch the author page.
	 *                            Default false.
	 * @return array|null An h-card microformat, jf2 from the fetched author page, or null
	 *                     if no author was found.
	 */
	public static function find_author( $item, $mf2, $follow = false ) {
		// Follows the authorship algorithm at https://indieweb.org/authorship (steps numbered below).
		$authorpage = false;
		if ( self::has_prop( $item, 'author' ) ) {
			// Check if any of the values of the author property are an h-card.
			foreach ( $item['properties']['author'] as $a ) {
				if ( self::is_type( $a, 'h-card' ) ) {
					// 5.1 "if it has an h-card, use it, exit."
					return $a;
				} elseif ( is_string( $a ) ) {
					if ( wp_http_validate_url( $a ) ) {
						// 5.2 "otherwise if author property is an http(s) URL, let the author-page have that URL"
						$authorpage = $a;
					} else {
						// 5.3 "otherwise use the author property as the author name, exit"
						// We can only set the name, no h-card or URL was found
						$author = self::get_plaintext( $item, 'author' );
					}
				} else {
					// This case is only hit when the author property is an mf2 object that is not an h-card.
					$author = self::get_plaintext( $item, 'author' );
				}
				if ( ! $authorpage ) {
					return array(
						'type'       => array( 'h-card' ),
						'properties' => array(
							'name' => array( $author ),
						),
					);
				}
			}
		}
			// 6. "if no author page was found" ... check for rel-author link
		if ( ! $authorpage ) {
			if ( isset( $mf2['rels'] ) && isset( $mf2['rels']['author'] ) ) {
				$authorpage = $mf2['rels']['author'][0];
			}
		}
		// 7. "if there is an author-page URL" ...
		if ( $authorpage ) {
			if ( $follow && ! self::urls_match( $authorpage, self::get_plaintext( $mf2, 'url' ) ) ) {
				$parse = new Parser( $authorpage );
				$parse->fetch();
				$parse->parse();
				return $parse->get();
			} else {
				$rel = self::get_rel_urls( $mf2, $authorpage );
				if ( $rel ) {
					return array(
						'type' => array( 'h-card' ),
						'properties' => $rel,
					);
				} else {
					return array(
						'type'       => array( 'h-card' ),
						'properties' => array(
							'url' => array( $authorpage ),
						),
					);
				}
			}
		}
	}

	/**
	 * Returns the values of several properties.
	 *
	 * Nested microformats are converted to jf2 with parse_item(). Only the last
	 * value of each property is kept.
	 *
	 * @since 1.0.0
	 *
	 * @param array      $mf         Microformat.
	 * @param string[]   $properties Property names to read.
	 * @param array|null $args       Optional. Parse arguments for nested items.
	 * @return array Values keyed by property name. Empty if $mf is not a microformat.
	 */
	public static function get_prop_array( array $mf, $properties, $args = null ) {
		if ( ! self::is_microformat( $mf ) ) {
			return array();
		}

		$data = array();
		foreach ( $properties as $p ) {
			if ( array_key_exists( $p, $mf['properties'] ) ) {
				foreach ( $mf['properties'][ $p ] as $v ) {
					if ( self::is_microformat( $v ) ) {
						$v = self::parse_item( $v, $mf, $args );
					}
					$data[ $p ] = $v;
				}
			}
		}
		return $data;
	}

	/**
	 * Parses microformats2 into jf2.
	 *
	 * With 'alternate' set, a rel=alternate jf2feed, jf2 or mf2 JSON version
	 * of the page is fetched and used instead. Documents with no items but a
	 * rel=author link return that author. With 'return' => 'feed', several
	 * top-level items are combined into one h-feed. Otherwise the item whose URL
	 * matches $url is returned, or the list of all items.
	 *
	 * @since 1.0.0
	 *
	 * @param string|DOMDocument|array $input HTML, a parsed DOM document, or parsed mf2.
	 * @param string                   $url   URL of the document.
	 * @param array                    $args {
	 *     Optional. Parse arguments; see Parser::parse() for the full set.
	 *
	 *     @type bool   $alternate Whether to use a rel=alternate jf2/mf2 version.
	 *                             Default true.
	 *     @type string $return    'single' or 'feed'. Default 'single'.
	 *     @type bool   $follow    Whether to fetch author pages. Default false.
	 * }
	 * @return array jf2 for one item, a list of jf2 items, or an empty array.
	 */
	public static function parse( $input, $url, $args = array() ) {
		$defaults    = array(
			'alternate' => true, // Use rel-alternate if set for jf2 or mf2.
			'return'    => 'single',
			'follow'    => false, // Follow author links and return parsed data.
		);
		$args        = wp_parse_args( $args, $defaults );
		$args['url'] = $url;
		if ( ! in_array( $args['return'], array( 'single', 'feed' ), true ) ) {
			$args['return'] = 'single';
		}
		// Normalize all urls to ensure comparisons.
		$url = normalize_url( $url );
		if ( ! class_exists( 'Mf2\Parser' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'lib/mf2/Parser.php';
		}
		if ( is_string( $input ) || is_a( $input, 'DOMDocument' ) ) {
			$parser = new \Mf2\Parser( $input, $url );
			$input  = $parser->parse();
			if ( $args['alternate'] ) {
				// Check for rel-alternate jf2 or mf2 feed.
				if ( isset( $input['rel-urls'] ) ) {
					foreach ( $input['rel-urls'] as $rel => $info ) {
						if ( isset( $info['rels'] ) && in_array( 'alternate', $info['rels'], true ) ) {
							if ( isset( $info['type'] ) ) {
								if ( 'application/jf2feed+json' === $info['type'] ) {
									$parse = new Parser( $rel );
									$parse->fetch();
									return $parse->get();
								}
								if ( 'application/jf2+json' === $info['type'] ) {
									$parse = new Parser( $rel );
									$parse->fetch();
									return $parse->get();
								}
								if ( 'application/mf2+json' === $info['type'] ) {
									$parse = new Parser( $rel );
									$parse->fetch();
									$input = $parse->get( 'content' );
									break;
								}
							}
						}
					}
				}
			}
		}
		if ( ! is_array( $input ) ) {
			return array();
		}

		if ( ! isset( $input['items'] ) || ! is_array( $input['items'] ) ) {
			$input['items'] = array();
		}
		$count = count( $input['items'] );
		if ( 0 === $count ) {
			if ( self::has_rel( $input, 'author' ) ) {
				$author = self::get_rel( $input, 'author' );
				if ( is_array( $author ) ) {
					$author = array_pop( $author );
				}
				$author_url = $author;
				$author     = self::get_rel_urls( $input, $author_url );
				if ( ! is_array( $author ) ) {
					$author = array( 'url' => array( $author_url ) );
				}
				$author['type'] = 'card';
				if ( ! self::urls_match( $url, $author_url ) ) {
					return array(
						'author' => $author,
					);
				} else {
					return $author;
				}
			}
			return array();
		}

		if ( 'feed' === $args['return'] && $count > 1 ) {
			$input = self::normalize_feed( $input );
			$count = count( $input['items'] );
		}

		if ( 1 === $count ) {
			$return = self::parse_item( $input['items'][0], $input, $args );
			if ( self::has_rel( $input, 'alternate' ) ) {
				$return['_alternate'] = self::get_rel( $input, 'alternate' );
				return $return;
			}
		}

		$return = array();
		$card   = null;
		foreach ( $input['items'] as $key => $item ) {
			$parsed = self::parse_item( $item, $input, $args );
			$check  = false;
			if ( isset( $parsed['url'] ) ) {
				if ( is_array( $parsed['url'] ) ) {
					$check = in_array( $url, $parsed['url'], true );
				} elseif ( is_string( $parsed['url'] ) ) {
					$check = self::urls_match( $url, $parsed['url'] );
				}
				if ( $check ) {
					if ( 'feed' !== $args['return'] ) {
						return $parsed;
					}
				}
			}
			$return[] = $parsed;
		}

		return array_filter( $return );
	}

	/**
	 * Combines a document's top-level items into a single h-feed.
	 *
	 * The first h-card is removed from the items and used as the feed's author.
	 * If only one item remains, the document is returned with that h-card as the
	 * item's author instead.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Parsed mf2 document.
	 * @return array Parsed mf2 document with one h-feed item.
	 */
	public static function normalize_feed( $input ) {
		$hcard = array();
		foreach ( $input['items'] as $key => $item ) {
			if ( self::is_type( $item, 'h-card' ) ) {
				$hcard = $item;
				unset( $input['items'][ $key ] );
				break;
			}
		}
		if ( 1 === count( $input['items'] ) ) {
			if ( self::has_prop( $input['items'][0], 'author' ) ) {
				$input['items'][0]['properties']['author'] = array( $hcard );
			}
			return $input;
		}
		return array(
			'items' => array(
				array(
					'type'       => array( 'h-feed' ),
					'properties' => array(
						'author' => array( $hcard ),
					),
					'children'   => $input['items'],
				),
			),
		);
	}

	/**
	 * Converts an h-feed into a jf2 feed.
	 *
	 * Children are only parsed when $args['return'] is 'feed'. Items whose
	 * author URL matches the feed author get the full feed author card.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry h-feed microformat.
	 * @param array $mf    Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array jf2 feed with name, author, uid, items and the items'
	 *               '_last_published'/'_last_updated' dates.
	 */
	public static function parse_hfeed( $entry, $mf, $args ) {
		$data         = array(
			'type'  => 'feed',
			'items' => array(),
		);
		$data['name'] = self::get_plaintext( $entry, 'name' );
		$author       = self::find_author( $entry, $mf, $args['follow'] );
		if ( self::is_microformat( $author ) ) {
			$data['author'] = self::parse_hcard( $author, $mf, $args );
		} else {
			$data['author'] = $author;
		}
		$data['uid'] = self::get_plaintext( $entry, 'uid' );
		if ( isset( $entry['id'] ) && isset( $args['url'] ) && ! $data['uid'] ) {
			$data['uid'] = $args['url'] . '#' . $entry['id'];
		}

		if ( isset( $entry['children'] ) && 'feed' === $args['return'] ) {
			$data['items'] = self::parse_children( $entry['children'], $mf, $args );
		}
		$data    = array_filter( $data );
		$authors = array();
		if ( isset( $data['author'] ) ) {
			$authors[] = $data['author'];
		}
		if ( isset( $data['items'] ) ) {
			foreach ( $data['items'] as $key => $item ) {
				foreach ( $authors as $author ) {
					if ( is_string( $author['url'] ) ) {
						$author['url'] = array( $author['url'] );
					}
					if ( array_key_exists( 'author', $item ) && in_array( $item['author']['url'], $author['url'], true ) ) {
						$item['author'] = $author;
						break;
					}
				}
				$data['items'][ $key ] = $item;
			}
			$data['_last_published'] = self::find_last_published( $data['items'] );
			$data['_last_updated']   = self::find_last_updated( $data['items'] );
		}
		return $data;
	}

	/**
	 * Converts a list of child microformats into jf2, up to $args['limit'].
	 *
	 * @since 1.0.0
	 *
	 * @param array $children Child microformats.
	 * @param array $mf       Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array jf2 items that have a type.
	 */
	public static function parse_children( $children, $mf, $args ) {
		$items = array();
		$index = 0;
		foreach ( $children as $child ) {
			if ( isset( $args['limit'] ) && $args['limit'] === $index ) {
				continue;
			}
			$item = self::parse_item( $child, $mf, $args );
			if ( isset( $item['type'] ) ) {
				$items[] = $item;
			}
			$index++;
		}
		return array_filter( $items );
	}

	/**
	 * Converts a microformat into jf2 according to its type.
	 *
	 * Handles h-feed, h-card, h-entry, h-cite, h-event, h-review, h-recipe,
	 * h-listing, h-product, h-resume, h-item, h-leg, h-adr, h-geo and
	 * h-measure. Anything else goes to parse_hunknown().
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Microformat.
	 * @param array $mf   Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array|null jf2 for the item.
	 */
	public static function parse_item( $item, $mf, $args ) {
		if ( self::is_type( $item, 'h-feed' ) ) {
			return self::parse_hfeed( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-card' ) ) {
			return self::parse_hcard( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-entry' ) || self::is_type( $item, 'h-cite' ) ) {
			return self::parse_hentry( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-event' ) ) {
			return self::parse_hevent( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-review' ) ) {
			return self::parse_hreview( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-recipe' ) ) {
			return self::parse_hrecipe( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-listing' ) ) {
			return self::parse_hlisting( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-product' ) ) {
			return self::parse_hproduct( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-resume' ) ) {
			return self::parse_hresume( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-item' ) ) {
			return self::parse_hitem( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-leg' ) ) {
			return self::parse_hleg( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-adr' ) ) {
			return self::parse_hadr( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-geo' ) ) {
			return self::parse_hgeo( $item, $mf, $args );
		} elseif ( self::is_type( $item, 'h-measure' ) ) {
			return self::parse_hmeasure( $item, $mf, $args );
		}
		return self::parse_hunknown( $item, $mf, $args );
	}

	/**
	 * Checks whether one string starts with another, ignoring surrounding whitespace.
	 *
	 * @since 1.0.0
	 *
	 * @param string $string1 String to check.
	 * @param string $string2 Prefix to look for.
	 * @return bool True if $string1 starts with $string2. False if either is empty.
	 */
	public static function compare( $string1, $string2 ) {
		if ( empty( $string1 ) || empty( $string2 ) ) {
			return false;
		}
		$string1 = trim( $string1 );
		$string2 = trim( $string2 );
		return ( 0 === strpos( $string1, $string2 ) );
	}

	/**
	 * Converts a microformat of an unrecognized type into jf2.
	 *
	 * Note: only types without a hyphen pass the check below, so in practice
	 * every h-* type returns an empty array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $unknown Microformat.
	 * @param array $mf      Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array jf2 with the generic properties from parse_h() and the original type,
	 *               or an empty array.
	 */
	public static function parse_hunknown( $unknown, $mf, $args ) {
		$type = $unknown['type'][0];
		$type = explode( '-', $type );
		if ( 1 !== count( $type ) ) {
			return array();
		}
		// Parse unknown h property.
		$data = self::parse_h( $unknown, $mf, $args );
		if ( empty( $data ) ) {
			return array();
		}
		$data['type'] = $unknown['type'][0];

		return $data;
	}

	/**
	 * Returns the properties common to most microformat types.
	 *
	 * Reads name, published, updated, url, author, content and summary, drops
	 * the name when it just repeats the content, and adds the document's
	 * rel=syndication links.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry Microformat.
	 * @param array $mf    Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array jf2 properties.
	 */
	public static function parse_h( $entry, $mf, $args ) {
		$data              = array();
		$data['name']      = self::get_plaintext( $entry, 'name' );
		$data['published'] = self::get_published( $entry, true, null );
		$data['updated']   = self::get_updated( $entry, true, null );
		$data['url']       = normalize_url( self::get_plaintext( $entry, 'url' ) );
		$author            = self::find_author( $entry, $mf, $args['follow'] );
		if ( self::is_microformat( $author ) ) {
			$data['author'] = self::parse_hcard( $author, $mf, $args, $data['url'] );
		} else {
			$data['author'] = $author;
		}
		$data['content'] = self::parse_html_value( $entry, 'content' );
		$data['summary'] = self::get_summary( $entry, $data['content'] );

		// If name and content are equal remove name.
		if ( is_array( $data['content'] ) && array_key_exists( 'text', $data['content'] ) ) {
			if ( self::compare( $data['name'], $data['content']['text'] ) ) {
				unset( $data['name'] );
			}
		}

		if ( isset( $mf['rels']['syndication'] ) ) {
			if ( isset( $data['syndication'] ) ) {
				if ( is_string( $data['syndication'] ) ) {
					$data['syndication'] = array( $data['syndication'] );
				}
				$data['syndication'] = array_unique( array_merge( $data['syndication'], $mf['rels']['syndication'] ) );
			} else {
				$data['syndication'] = $mf['rels']['syndication'];
			}
		}
		return array_filter( $data );
	}

	/**
	 * Converts an h-measure into jf2.
	 *
	 * @since 1.0.0
	 *
	 * @param array $measure h-measure microformat.
	 * @param array $mf      Parsed mf2 document. Unused.
	 * @param array $args    Parse arguments. Unused.
	 * @return array jf2 with type 'measure', num and unit.
	 */
	public static function parse_hmeasure( $measure, $mf, $args ) {
		$data       = array(
			'type' => 'measure',
		);
		$properties = array(
			'num',
			'unit',
		);
		foreach ( $properties as $property ) {
			$data[ $property ] = self::get_plaintext( $measure, $property );
		}
		return array_filter( $data );
	}

	/**
	 * Converts an h-leg (a leg of a trip) into jf2.
	 *
	 * @since 1.0.0
	 *
	 * @param array $leg  h-leg microformat.
	 * @param array $mf   Parsed mf2 document. Unused.
	 * @param array $args Parse arguments. Unused.
	 * @return array jf2 with url, name, origin, destination, operator, transit-type,
	 *               number, departure and arrival where present.
	 */
	public static function parse_hleg( $leg, $mf, $args ) {
		// The aaronpk special.
		$data       = array();
		$properties = array(
			'url',
			'name',
			'origin',
			'destination',
			'operator',
			'transit-type',
			'number',
		);
		foreach ( $properties as $property ) {
			$data[ $property ] = self::get_plaintext( $leg, $property );
		}

		foreach ( array( 'departure', 'arrival' ) as $property ) {
			$datetime = self::get_datetime_property( $property, $leg, true, null );
			if ( $datetime instanceof \DateTimeInterface ) {
				$data[ $property ] = $datetime->format( DATE_W3C );
			}
		}
		$data              = array_filter( $data );
		return $data;
	}

	/**
	 * Converts an h-entry or h-cite into jf2.
	 *
	 * Reads the response properties (in-reply-to, like-of, repost-of and so on),
	 * media, location and check-in data, then the common properties from
	 * parse_h(). With $args['references'], nested citations are moved to refs.
	 * Adds the post type from post_type_discovery() as 'post-type'.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry h-entry or h-cite microformat.
	 * @param array $mf    Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array jf2 with type 'entry' or 'cite'.
	 */
	public static function parse_hentry( $entry, $mf, $args ) {
		// Array Values.
		$properties   = array(
			'checkin',
			'category',
			'invitee',
			'photo',
			'video',
			'audio',
			'syndication',
			'in-reply-to',
			'like-of',
			'repost-of',
			'bookmark-of',
			'favorite-of',
			'listen-of',
			'quotation-of',
			'watch-of',
			'read-of',
			'play-of',
			'jam-of',
			'itinerary',
			'tag-of',
			'location',
			'checked-in-by',
			'pk-ate',
			'pk-drank',
			'item',
		);
		$data         = self::get_prop_array( $entry, $properties );
		$data['type'] = self::is_type( $entry, 'h-entry' ) ? 'entry' : 'cite';
		$properties   = array( 'url', 'weather', 'temperature', 'rsvp', 'featured', 'swarm-coins', 'latitude', 'longitude' );
		foreach ( $properties as $property ) {
			$data[ $property ] = self::get_plaintext( $entry, $property );
		}
		$data = array_filter( $data );
		$data = array_merge( $data, self::parse_h( $entry, $mf, $args ) );
		if ( $args['references'] ) {
			$data = jf2_references( $data );
		}
		$data['post-type'] = post_type_discovery( $data );
		return array_filter( $data );
	}

	/**
	 * Converts an h-card into jf2.
	 *
	 * When $args['return'] is 'feed' and the card's first child is an h-feed
	 * (as on sites that nest their feed inside their h-card), that feed is
	 * returned with the card as its author.
	 *
	 * @since 1.0.0
	 *
	 * @param array       $hcard h-card microformat.
	 * @param array       $mf    Parsed mf2 document.
	 * @param array       $args  Parse arguments (see Parser::parse()).
	 * @param string|bool $url   Optional. Unused.
	 * @return array|null jf2 card (or feed, see above), or null if $hcard is not a
	 *                     microformat.
	 */
	public static function parse_hcard( $hcard, $mf, $args, $url = false ) {
		if ( ! self::is_microformat( $hcard ) ) {
			return;
		}
		$data       = array();
		$properties = array(
			'url',
			'uid',
			'name',
			'note',
			'photo',
			'bday',
			'callsign',
			'latitude',
			'longitude',
			'street-address',
			'extended-address',
			'locality',
			'region',
			'country-name',
			'label',
			'post-office-box',
			'given-name',
			'honorific-prefix',
			'additional-name',
			'family-name',
			'honorific-suffix',
			'email',
			'postal-code',
			'altitude',
			'location',
		);
		foreach ( $properties as $property ) {
			$data[ $property ] = self::get_plaintext( $hcard, $property );
		}
		$data = array_filter( $data );
		$data = array_merge( self::get_prop_array( $hcard, array_keys( $hcard['properties'] ) ), $data );

		$data['type'] = 'card';
		if ( isset( $hcard['children'] ) ) {
			// In the case of sites like tantek.com where multiple feeds are nested inside h-card if it is a feed request return only the first feed.
			if ( 'feed' === $args['return'] && self::is_type( $hcard['children'][0], 'h-feed' ) ) {
				$feed = self::parse_hfeed( $hcard['children'][0], $mf, $args );
				unset( $data['children'] );
				$feed['author'] = $data;
				return array_filter( $feed );
			} else {
				$data['items'] = self::parse_children( $hcard['children'], $mf, $args );
			}
		}
		return array_filter( $data );
	}

	/**
	 * Converts an h-event into jf2.
	 *
	 * Reads category, attendee, organizer, location, start, end, photo, uid and url, plus the common properties from parse_h().
	 *
	 * @since 1.0.0
	 *
	 * @param array $event h-event microformat.
	 * @param array $mf    Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array|null jf2 properties, or null if the input is not a microformat.
	 */
	public static function parse_hevent( $event, $mf, $args ) {
		if ( ! self::is_microformat( $event ) ) {
			return;
		}
		$data       = array(
			'type' => 'event',
		);
		$data       = array_merge( $data, self::parse_h( $event, $mf, $args ) );
		$properties = array( 'category', 'attendee', 'organizer', 'location', 'start', 'end', 'photo', 'uid', 'url' );
		$data       = array_merge( $data, self::get_prop_array( $event, $properties ) );
		return array_filter( $data );
	}

	/**
	 * Converts an h-review into jf2.
	 *
	 * Reads category, item, summary, published, rating, best and worst, plus the common properties from parse_h().
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry h-review microformat.
	 * @param array $mf    Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array|null jf2 properties, or null if the input is not a microformat.
	 */
	public static function parse_hreview( $entry, $mf, $args ) {
		if ( ! self::is_microformat( $entry ) ) {
			return;
		}
		$data       = array(
			'type' => 'review',
			'name' => null,
			'url'  => null,
		);
		$properties = array( 'category', 'item' );
		$data       = self::get_prop_array( $entry, $properties );
		$properties = array( 'summary', 'published', 'rating', 'best', 'worst' );
		foreach ( $properties as $p ) {
			$v = self::get_plaintext( $entry, $p );
			if ( null !== $v ) {
				$data[ $p ] = $v;
			}
		}
		$data = array_merge( $data, self::parse_h( $entry, $mf, $args ) );
		return array_filter( $data );
	}


	/**
	 * Converts an h-product into jf2.
	 *
	 * Reads category, brand, photo, audio, video, identifier, price and description, plus the common properties from parse_h().
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry h-product microformat.
	 * @param array $mf    Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array|null jf2 properties, or null if the input is not a microformat.
	 */
	public static function parse_hproduct( $entry, $mf, $args ) {
		if ( ! self::is_microformat( $entry ) ) {
			return;
		}
		$data       = array(
			'type' => 'product',
			'name' => null,
			'url'  => null,
		);
		$properties = array( 'category', 'brand', 'photo', 'audio', 'video' );
		$data       = self::get_prop_array( $entry, $properties );
		$properties = array( 'identifier', 'price', 'description' );
		foreach ( $properties as $p ) {
			$v = self::get_plaintext( $entry, $p );
			if ( null !== $v ) {
				$data[ $p ] = $v;
			}
		}
		$data = array_merge( $data, self::parse_h( $entry, $mf, $args ) );
		return array_filter( $data );
	}


	/**
	 * Converts an h-resume into jf2.
	 *
	 * Reads category and item, plus the common properties from parse_h().
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry h-resume microformat.
	 * @param array $mf    Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array|null jf2 properties, or null if the input is not a microformat.
	 */
	public static function parse_hresume( $entry, $mf, $args ) {
		if ( ! self::is_microformat( $entry ) ) {
			return;
		}
		$data       = array(
			'type' => 'resume',
			'name' => null,
			'url'  => null,
		);
		$properties = array( 'category', 'item' );
		$data       = self::get_prop_array( $entry, $properties );
		$properties = array();
		foreach ( $properties as $p ) {
			$v = self::get_plaintext( $entry, $p );
			if ( null !== $v ) {
				$data[ $p ] = $v;
			}
		}
		$data = array_merge( $data, self::parse_h( $entry, $mf, $args ) );
		return array_filter( $data );
	}

	/**
	 * Converts an h-listing into jf2.
	 *
	 * Reads category and item, plus the common properties from parse_h().
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry h-listing microformat.
	 * @param array $mf    Parsed mf2 document.
	 * @param array $args  Parse arguments (see Parser::parse()).
	 * @return array|null jf2 properties, or null if the input is not a microformat.
	 */
	public static function parse_hlisting( $entry, $mf, $args ) {
		if ( ! self::is_microformat( $entry ) ) {
			return;
		}
		$data       = array(
			'type' => 'listing',
			'name' => null,
			'url'  => null,
		);
		$properties = array( 'category', 'item' );
		$data       = self::get_prop_array( $entry, $properties );
		$properties = array();
		foreach ( $properties as $p ) {
			$v = self::get_plaintext( $entry, $p );
			if ( null !== $v ) {
				$data[ $p ] = $v;
			}
		}
		$data = array_merge( $data, self::parse_h( $entry, $mf, $args ) );
		return array_filter( $data );
	}

	/**
	 * Converts an h-recipe into jf2.
	 *
	 * Reads category and item, plus the common properties from parse_h().
	 *
	 * @since 1.0.0
	 *
	 * @param array $recipe h-recipe microformat.
	 * @param array $mf     Parsed mf2 document.
	 * @param array $args   Parse arguments (see Parser::parse()).
	 * @return array|null jf2 properties, or null if the input is not a microformat.
	 */
	public static function parse_hrecipe( $recipe, $mf, $args ) {
		if ( ! self::is_microformat( $recipe ) ) {
			return;
		}
		$data       = array(
			'type' => 'recipe',
			'name' => null,
			'url'  => null,
		);
		$properties = array( 'category', 'item' );
		$data       = self::get_prop_array( $recipe, $properties );
		$properties = array();
		foreach ( $properties as $p ) {
			$v = self::get_plaintext( $recipe, $p );
			if ( null !== $v ) {
				$data[ $p ] = $v;
			}
		}
		$data = array_merge( $data, self::parse_h( $recipe, $mf, $args ) );
		return array_filter( $data );
	}

	/**
	 * Converts an h-item into jf2.
	 *
	 * Reads category and item, plus the common properties from parse_h().
	 *
	 * @since 1.0.0
	 *
	 * @param array $item h-item microformat.
	 * @param array $mf   Parsed mf2 document.
	 * @param array $args Parse arguments (see Parser::parse()).
	 * @return array|null jf2 properties, or null if the input is not a microformat.
	 */
	public static function parse_hitem( $item, $mf, $args ) {
		if ( ! self::is_microformat( $item ) ) {
			return;
		}
		$data       = array(
			'type' => 'item',
			'name' => null,
			'url'  => null,
		);
		$properties = array( 'category', 'item' );
		$data       = self::get_prop_array( $item, $properties );
		$properties = array();
		foreach ( $properties as $p ) {
			$v = self::get_plaintext( $item, $p );
			if ( null !== $v ) {
				$data[ $p ] = $v;
			}
		}
		$data = array_merge( $data, self::parse_h( $item, $mf, $args ) );
		return array_filter( $data );
	}

	/**
	 * Converts an h-adr into jf2.
	 *
	 * @since 1.0.0
	 *
	 * @param array $hadr h-adr microformat.
	 * @param array $mf   Parsed mf2 document. Unused.
	 * @param array $args Parse arguments. Unused.
	 * @return array|null jf2 with type 'adr' and the address and geo properties present,
	 *                     or null if the input is not a microformat.
	 */
	public static function parse_hadr( $hadr, $mf, $args ) {
		if ( ! self::is_microformat( $hadr ) ) {
			return;
		}
		$data       = array(
			'type' => 'adr',
		);
		$properties = array( 'weather', 'latitude', 'longitude', 'altitude', 'label', 'street-address', 'extended-address', 'locality', 'region', 'country-name' );
		foreach ( $properties as $property ) {
			$data[ $property ] = self::get_plaintext( $hadr, $property );
		}
		$properties = array( 'temperature', 'geo' );
		$props      = self::get_prop_array( $hadr, $properties );
		$data       = array_merge( $data, $props );
		return array_filter( $data );
	}

	/**
	 * Converts an h-geo into jf2.
	 *
	 * @since 1.0.0
	 *
	 * @param array $hgeo h-geo microformat.
	 * @param array $mf   Parsed mf2 document. Unused.
	 * @param array $args Parse arguments. Unused.
	 * @return array|null jf2 with type 'geo', latitude, longitude and altitude, or null
	 *                     if the input is not a microformat.
	 */
	public static function parse_hgeo( $hgeo, $mf, $args ) {
		if ( ! self::is_microformat( $hgeo ) ) {
			return;
		}
		$data       = array(
			'type' => 'geo',
		);
		$properties = array( 'latitude', 'longitude', 'altitude' );
		foreach ( $properties as $p ) {
			$v = self::get_plaintext( $hgeo, $p );
			if ( null !== $v ) {
				$data[ $p ] = $v;
			}
		}
		return array_filter( $data );
	}
}
