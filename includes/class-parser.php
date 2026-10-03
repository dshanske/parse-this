<?php
/**
 * Parser class.
 *
 * @package Parse_This
 */

namespace ParseThis;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches a URL and converts it into jf2.
 *
 * Typical use:
 *
 *     $parse = new Parser( $url );
 *     $parse->fetch();
 *     $parse->parse( array( 'return' => 'feed' ) );
 *     $jf2 = $parse->get();
 *
 * Microformats2 are tried first. If they don't yield content, a WordPress
 * REST API alternate, JSON-LD, a site-specific parser (YouTube, X/Twitter)
 * and finally meta tags are tried in turn. RSS, Atom, JSON Feed,
 * jf2 and mf2 JSON responses are handled directly. Originally derived from
 * the Press This code removed from WordPress core.
 *
 * @since 1.0.0
 */
class Parser {
	/**
	 * URL being parsed.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $url = '';
	/**
	 * Parsed DOM of the fetched HTML, if any.
	 *
	 * @since 1.0.0
	 * @var DOMDocument|null
	 */
	private $doc;
	/**
	 * Links from the HTTP Link header, as parsed by pt_parse_header_links().
	 *
	 * @since 1.0.0
	 * @var array[]
	 */
	private $links = array();
	/**
	 * Parsed result.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $jf2 = array();

	/**
	 * Host name of the URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $domain = '';

	/**
	 * Fetched content: an HTML string, decoded JSON, or a SimplePie object.
	 *
	 * @since 1.0.0
	 * @var string|array|SimplePie
	 */
	private $content = '';

	/**
	 * MIME type of the fetched content, without parameters.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $content_type = '';

	/**
	 * Sets up a parser for a URL.
	 *
	 * URLs on a list of hosts known to support HTTPS are upgraded to https://
	 * (see pt_secure_rewrite()).
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $url Optional. URL to parse. Invalid URLs are ignored.
	 */
	public function __construct( $url = null ) {
		if ( wp_http_validate_url( $url ) ) {
			$this->url = pt_secure_rewrite( $url );
		}
	}

	/**
	 * Returns the parsed result or another stored property.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Optional. 'jf2' (default), 'mf2' for the result converted
	 *                    to mf2, or the name of another property such as 'content',
	 *                    'doc', 'links' or 'content_type'. Unknown names return jf2.
	 * @return mixed The requested value.
	 */
	public function get( $key = 'jf2' ) {
		if ( 'mf2' === $key ) {
			return jf2_to_mf2( $this->jf2 );
		}
		if ( ! property_exists( $this, $key ) ) {
			$key = 'jf2';
		}
		return $this->$key;
	}

	/**
	 * Sanitizes HTML content for display.
	 *
	 * Decodes entities, removes comments and <script> elements, then filters
	 * the result through wp_kses() with an allow-list of text, media and
	 * structural tags.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content HTML to clean. Non-strings are returned unchanged.
	 * @param array  $strip   Optional. Tags to remove from the allow-list, as keys
	 *                        (for example array( 'blockquote' => array() )).
	 * @return string|mixed The cleaned HTML.
	 */
	public static function clean_content( $content, $strip = array() ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}
		// Decode escaped entities so that they can be stripped.
		$content     = html_entity_decode( $content, ENT_COMPAT | ENT_HTML401, 'UTF-8' );
		$content     = preg_replace( '/<!--(.|\s)*?-->/', '', $content );
		$domdocument = pt_load_domdocument( $content );
		$scripts     = $domdocument->getElementsByTagName( 'script' );
		foreach ( $scripts as $item ) {
			$item->parentNode->removeChild( $item ); // phpcs:ignore
		}

		$content = $domdocument->saveHTML();

		$allowed = array(
			'a'          => array(
				'href' => array(),
				'name' => array(),
			),
			'abbr'       => array(),
			'b'          => array(),
			'br'         => array(),
			'code'       => array(),
			'ins'        => array(),
			'del'        => array(),
			'em'         => array(),
			'i'          => array(),
			'q'          => array(),
			'strike'     => array(),
			'strong'     => array(),
			'time'       => array(
				'datetime' => array(),
			),
			'blockquote' => array(),
			'pre'        => array(),
			'p'          => array(),
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'h5'         => array(),
			'h6'         => array(),
			'ul'         => array(),
			'li'         => array(),
			'ol'         => array(),
			'span'       => array(),
			'img'        => array(
				'src'    => array(),
				'alt'    => array(),
				'title'  => array(),
				'width'  => array(),
				'height' => array(),
				'srcset' => array(),
			),
			'figure'     => array(),
			'figcaption' => array(),
			'picture'    => array(
				'srcset' => array(),
				'type'   => array(),
			),
			'video'      => array(
				'poster' => array(),
				'src'    => array(),
			),
			'audio'      => array(
				'duration' => array(),
				'src'      => array(),
			),
			'track'      => array(
				'label'   => array(),
				'src'     => array(),
				'srclang' => array(),
				'kind'    => array(),
			),
			'source'     => array(
				'src'    => array(),
				'srcset' => array(),
				'type'   => array(),

			),
			'hr'         => array(),
		);
		if ( ! empty( $strip ) ) {
			$allowed = array_diff_key( $allowed, $strip );
		}
		return trim( wp_kses( $content, $allowed ) );
	}

	/**
	 * Sets the content to parse, skipping the fetch.
	 *
	 * @since 1.0.0
	 *
	 * @param string|array|SimplePie $source_content Content: an HTML string, decoded
	 *                                               JSON, a SimplePie feed, or jf2.
	 * @param string                 $url            URL of the content.
	 * @param bool                   $jf2            Optional. Whether
	 *                                               $source_content is already jf2
	 *                                               and should be stored as the
	 *                                               result. Default false.
	 */
	public function set( $source_content, $url, $jf2 = false ) {
		$this->content = $source_content;
		if ( wp_http_validate_url( $url ) ) {
			$this->url    = pt_secure_rewrite( $url );
			$this->domain = wp_parse_url( $url, PHP_URL_HOST );
		}
		if ( $jf2 ) {
			$this->jf2 = $source_content;
		} elseif ( is_string( $this->content ) ) {
			$this->doc = pt_load_domdocument( $this->content );
		}
	}

	/**
	 * Fetches an RSS or Atom feed with SimplePie.
	 *
	 * Uses core's fetch_feed(), with the feed cache turned off and HTML tags
	 * kept for the duration of the call, so that every fetch is fresh and
	 * content is sanitized by clean_content() rather than stripped. Core's
	 * KSES sanitizer still runs, as for any feed WordPress fetches.
	 *
	 * @since 1.0.0
	 * @since 2.0.0 Uses core's fetch_feed() instead of a copy of it. Added $response.
	 *
	 * @param string     $url      Feed URL.
	 * @param array|null $response Optional. A response already fetched for
	 *                             $url. It is handed to SimplePie instead of
	 *                             downloading the feed again.
	 * @return \SimplePie|\SimplePie\SimplePie|\WP_Error The initialized feed, or
	 *                                                   WP_Error if SimplePie
	 *                                                   reports an error.
	 */
	public static function fetch_feed( $url, $response = null ) {
		$url     = pt_secure_rewrite( $url );
		$options = static function ( $feed ) {
			$feed->enable_cache( false );
			$feed->strip_htmltags( false );
		};
		add_action( 'wp_feed_options', $options );

		// Serve the response we already have to SimplePie's request for this URL, once.
		$reuse = null;
		if ( is_array( $response ) && ! is_wp_error( $response ) ) {
			$reuse = static function ( $pre, $args, $request_url ) use ( &$response, $url ) {
				if ( null !== $response && normalize_url( $request_url ) === normalize_url( $url ) ) {
					$cached   = $response;
					$response = null;
					return $cached;
				}
				return $pre;
			};
			add_filter( 'pre_http_request', $reuse, 1, 3 );
		}

		$feed = \fetch_feed( $url );

		if ( $reuse ) {
			remove_filter( 'pre_http_request', $reuse, 1 );
		}
		remove_action( 'wp_feed_options', $options );
		return $feed;
	}

	/**
	 * Checks whether a content type can be parsed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content_type MIME type, without parameters.
	 * @return bool True if supported.
	 */
	public function supported_content( $content_type ) {
		$types = array(
			'application/mf2+json',
			'text/html',
			'application/json',
			'application/feed+json',
			'application/xml',
			'text/xml',
			'application/jf2+json',
			'application/jf2feed+json',
			'application/rss+xml',
			'application/atom+xml',
		);
		return in_array( $content_type, $types, true );
	}

	/**
	 * Returns where a URL redirects to, without following the redirect.
	 *
	 * Used to expand short links in summaries, where only known link
	 * shorteners are checked.
	 *
	 * @since 1.0.0
	 * @since 2.0.0 The second parameter is named $any_host; it was $allowlist,
	 *              which described the opposite of what it did.
	 *
	 * @param string $url      URL to check.
	 * @param bool   $any_host Optional. Whether to check a URL on any host (true,
	 *                         the default) or only on the hosts of known link
	 *                         shorteners (false; see the parse_this_url_shorteners
	 *                         filter).
	 * @return string|false|WP_Error The redirect target, false if there is no
	 *                               redirect (or $url is not a shortener when
	 *                               $any_host is false), or WP_Error if $url is
	 *                               invalid.
	 */
	public static function redirect( $url, $any_host = true ) {
		if ( ! $any_host ) {
			// Check the host first: it is free, while validating the URL costs a DNS lookup.
			$domain = is_string( $url ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;
			/**
			 * Filters the hosts treated as link shorteners, whose links in summaries are expanded.
			 *
			 * @since 2.0.0
			 *
			 * @param string[] $shorteners Host names.
			 */
			$shorteners = apply_filters( 'parse_this_url_shorteners', array( 'bit.ly', 'buff.ly', 'dlvr.it', 'fb.me', 'goo.gl', 'is.gd', 'lnkd.in', 'ow.ly', 't.co', 'tinyurl.com', 'trib.al', 'youtu.be' ) );
			if ( ! is_string( $domain ) || ! in_array( strtolower( $domain ), (array) $shorteners, true ) ) {
				return false;
			}
		}
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid-url', __( 'A valid URL was not provided.', 'parse-this' ) );
		}
		$url      = pt_secure_rewrite( $url );
		$response = pt_remote_get( $url, array( 'redirection' => 0 ), array() );
		$redirect = wp_remote_retrieve_header( $response, 'location' );
		if ( ! $redirect ) {
			return false;
		}
		return ( normalize_url( $redirect ) !== normalize_url( $url ) ) ? $redirect : false;
	}

	/**
	 * Downloads a URL and stores its content for parse().
	 *
	 * Feeds are loaded into SimplePie, JSON Feeds and WordPress REST
	 * collections are converted to jf2 immediately, jf2 JSON is stored as the
	 * result, and HTML is loaded into a DOM document.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $url Optional. URL to fetch. Defaults to the URL passed to
	 *                         the constructor.
	 * @return true|false|WP_Error True on success, false if a feed could not be
	 *                             parsed, or WP_Error if the URL is invalid, the
	 *                             request fails, or the content type is not
	 *                             supported.
	 */
	public function fetch( $url = null ) {
		if ( ! $url ) {
			$url = $this->url;
		}
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid-url', __( 'A valid URL was not provided.', 'parse-this' ) );
		}
		// YouTube watch pages exceed 1 MB, and the player data is part-way through them.
		$host     = wp_parse_url( $url, PHP_URL_HOST );
		$args     = in_array( $host, array( 'youtube.com', 'www.youtube.com', 'm.youtube.com' ), true ) ? array( 'limit_response_size' => 3 * MB_IN_BYTES ) : array();
		$response = pt_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw = wp_remote_retrieve_header( $response, 'link' );
		if ( ! empty( $raw ) ) {
			$this->links = pt_parse_header_links( $raw );
		}

		$this->content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( is_array( $this->content_type ) ) {
			$this->content_type = array_pop( $this->content_type );
		}
						// Strip any character set off the content type.
						$ct = explode( ';', $this->content_type );
		if ( is_array( $ct ) ) {
			$this->content_type = array_shift( $ct );
		}
						$this->content_type = trim( $this->content_type );
						// List of content types we know how to handle.
		if ( ! self::supported_content( $this->content_type ) ) {
			return new \WP_Error( 'content-type', 'Content Type is Not Supported', array( 'content-type' => $this->content_type ) );
		}

		$content = wp_remote_retrieve_body( $response );

		// This is an RSS or Atom Feed URL and if it is not we do not know how to deal with XML anyway.
		if ( class_exists( RSS::class ) && ( in_array( $this->content_type, array( 'application/rss+xml', 'application/atom+xml', 'text/xml', 'application/xml', 'text/xml' ), true ) ) ) {
			// Get a SimplePie feed object from the specified feed source.
			$content = self::fetch_feed( $url, $response );
			if ( is_wp_error( $content ) ) {
				return false;
			}

			$this->set( $content, $url, true );
			return true;
		}

		if ( in_array( $this->content_type, array( 'application/mf2+json', 'application/jf2+json', 'application/jf2feed+json' ), true ) ) {
			$content = json_decode( $content, true );
			// Parsed mf2 is passed to the MF2 parser as content; jf2 is already in its final form.
			$this->set( $content, $url, ( 'application/mf2+json' !== $this->content_type ) );
			return true;
		}

		if ( in_array( $this->content_type, array( 'application/feed+json', 'application/json' ), true ) ) {
			$content = json_decode( $content, true );

			if ( class_exists( JSONFeed::class ) && isset( $content['version'] ) && false !== strpos( $content['version'], 'https://jsonfeed.org/version/' ) ) {
				$content = JSONFeed::to_jf2( $content, $url );
				$this->set( $content, $url, true );
				// This means we are probing a specific REST Endpoint as they return this.
			} elseif ( wp_remote_retrieve_header( $response, 'x-wp-total' ) ) {
				// Site details come from the REST API root, not the collection URL.
				$root              = RESTAPI::get_rest_root( $url );
				$content           = RESTAPI::posts_to_feed( array( 'items' => $content ), $root ? $root : '' );
				$content['_total'] = wp_remote_retrieve_header( $response, 'x-wp-total' );
				$content['_pages'] = wp_remote_retrieve_header( $response, 'x-wp-totalpages' );

				$this->set( $content, $url, true );
			}
		}

		$this->set( $content, $url, ( 'application/jf2+json' === $this->content_type ) );
		return true;
	}

	/**
	 * Parses the fetched content into jf2.
	 *
	 * Retrieve the result with get().
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Optional. Parse arguments.
	 *
	 *     @type bool   $alternate  Whether to use a rel=alternate jf2 or mf2 version
	 *                              of the page. Default false.
	 *     @type string $return     'single' for one item or 'feed' for a list.
	 *                              Default 'single'.
	 *     @type bool   $follow     Whether to fetch and parse external author pages.
	 *                              Default false.
	 *     @type int    $limit      Maximum number of feed children. Default 150.
	 *     @type bool   $jsonld     Whether to try JSON-LD. Default true.
	 *     @type bool   $html       Whether to fall back to meta tags. Default true.
	 *     @type bool   $references Whether to move nested citations into refs, per
	 *                              the jf2 spec. Default true.
	 *     @type bool   $location   Whether to flatten location into latitude,
	 *                              longitude and altitude properties with a string
	 *                              location. Default false.
	 *     @type bool|null $require_content Whether a summary is not enough, so the
	 *                              page's REST API alternate is fetched for full
	 *                              content when the page has none of its own. Null
	 *                              (the default) means true when return is 'feed',
	 *                              false otherwise.
	 *     @type bool   $always_arrays Whether to always return category, photo,
	 *                              video, audio, syndication, like-of, repost-of,
	 *                              bookmark-of and in-reply-to as arrays, as Microsub
	 *                              does. Default false, which follows jf2: a single
	 *                              value is not wrapped in an array.
	 *     @type bool   $debug      Whether to include the raw source data each
	 *                              fallback read (_meta, _jsonld, _json, _yt,
	 *                              _ombed, _rest). Default false.
	 * }
	 * @return WP_Error|void WP_Error if there is no content to parse.
	 */
	public function parse( $args = array() ) {
		$defaults = array(
			'alternate'       => false,
			'return'          => 'single',
			'follow'          => false,
			'limit'           => 150,
			'jsonld'          => true,
			'html'            => true,
			'references'      => true,
			'location'        => false,
			'require_content' => null,
			'always_arrays'   => false,
			'debug'           => false,
		);
		$args     = wp_parse_args( $args, $defaults );
		// If not an option then revert to single.
		if ( ! in_array( $args['return'], array( 'single', 'feed' ), true ) ) {
			$args['return'] = 'single';
		}
		$result = $this->parse_sources( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_array( $this->jf2 ) ) {
			$this->jf2 = self::format_output( $this->jf2, $args );
		}
	}

	/**
	 * Normalizes the parse result and, for feeds, each item.
	 *
	 * Every author becomes a jf2 card (see jf2_author_to_card()). With
	 * $args['always_arrays'], the properties in ARRAY_PROPERTIES are always arrays.
	 *
	 * @since 2.0.0
	 *
	 * @param array $jf2  Parse result.
	 * @param array $args Parse arguments (see parse()).
	 * @return array The normalized result.
	 */
	public static function format_output( $jf2, $args ) {
		$jf2 = self::format_object( $jf2, $args );
		if ( isset( $jf2['items'] ) && is_array( $jf2['items'] ) ) {
			foreach ( $jf2['items'] as $key => $item ) {
				if ( is_array( $item ) ) {
					$jf2['items'][ $key ] = self::format_object( $item, $args );
				}
			}
		}
		return $jf2;
	}

	/**
	 * Normalizes one jf2 object; see format_output().
	 *
	 * @since 2.0.0
	 *
	 * @param array $jf2  jf2 object.
	 * @param array $args Parse arguments.
	 * @return array The normalized object.
	 */
	private static function format_object( $jf2, $args ) {
		if ( array_key_exists( 'author', $jf2 ) ) {
			$card = jf2_author_to_card( $jf2['author'] );
			if ( null === $card ) {
				unset( $jf2['author'] );
			} else {
				$jf2['author'] = $card;
			}
		}
		if ( ! empty( $args['always_arrays'] ) ) {
			foreach ( self::ARRAY_PROPERTIES as $property ) {
				if ( isset( $jf2[ $property ] ) && ! wp_is_numeric_array( $jf2[ $property ] ) ) {
					$jf2[ $property ] = array( $jf2[ $property ] );
				}
			}
		}
		return $jf2;
	}

	/**
	 * Runs the parsers for the fetched content and stores the result in $jf2.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Parse arguments, with defaults applied (see parse()).
	 * @return WP_Error|void WP_Error if there is no content to parse.
	 */
	private function parse_sources( $args ) {
		if ( class_exists( RSS::class ) && ( $this->content instanceof \SimplePie\SimplePie || $this->content instanceof \SimplePie ) ) {
			$this->jf2 = RSS::parse( $this->content, $this->url );

			return;
		} elseif ( $this->doc instanceof \DOMDocument ) {
			$content = $this->doc;
		} else {
			$content = $this->content;
		}
		if ( ! $content ) {
			return new \WP_Error( 'Missing Content' );
		}

		// JSON that fetch() didn't already convert (a JSON Feed or REST collection) may be a REST API object.
		if ( 'application/json' === $this->content_type && empty( $this->jf2 ) ) {
			$rest = RESTAPI::parse( $content, $this->url, $args );
			if ( is_array( $rest ) && ! empty( $rest ) ) {
				$this->jf2          = $rest;
				$this->jf2['_rest'] = $content;
				return;
			}
			// Unrecognized JSON: return it as is.
			$this->jf2 = array(
				'raw' => $content,
				'url' => $this->url,
			);
			return;
		}

		if ( ! is_array( $this->jf2 ) ) {
			$this->jf2 = array(
				'raw' => $this->jf2,
				'url' => $this->url,
			);
			return;
		}

		// Ensure not already preparsed.
		if ( empty( $this->jf2 ) ) {
			$this->jf2 = MF2::parse( $content, $this->url, $args );
		}

		// Microformats come first. A list means several top-level items, none of them this page.
		if ( empty( $this->jf2 ) ) {
			$this->jf2 = array();
		} elseif ( wp_is_numeric_array( $this->jf2 ) ) {
			$this->jf2 = array( '_jf2' => $this->jf2 );
		}

		/*
		 * Fallbacks only fill gaps, in this order: the site-specific parser for this host
		 * (generic data is poor on those sites, so it always runs, even when it costs a
		 * request), then the parsers that read the document already fetched.
		 */
		$host      = wp_parse_url( $this->url, PHP_URL_HOST );
		$fallbacks = array();
		if ( $args['html'] && in_array( $host, array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be' ), true ) ) {
			$fallbacks[] = YouTube::parse( $this->content, $this->url, $args );
		}
		if ( $args['html'] && in_array( $host, array( 'x.com', 'www.x.com', 'mobile.x.com', 'twitter.com', 'www.twitter.com', 'mobile.twitter.com' ), true ) ) {
			$fallbacks[] = Twitter::parse( $this->url, $args );
		}
		if ( $args['jsonld'] ) {
			$fallbacks[] = JSONLD::parse( $this->doc, $this->url, $args );
		}
		$fallbacks[] = JSON::parse( $this->doc, $this->url, $args );
		if ( $args['html'] ) {
			$fallbacks[] = HTML::parse( $content, $this->url, $args );
		}
		foreach ( $fallbacks as $alt ) {
			$this->jf2 = self::fill_gaps( $this->jf2, $alt );
		}

		// The REST alternate costs an HTTP request, so it only runs if there still isn't enough.
		$require_content = isset( $args['require_content'] ) ? (bool) $args['require_content'] : ( 'feed' === $args['return'] );
		if ( ! self::has_content( $this->jf2, $require_content ) ) {
			$remote = array();
			if ( ! empty( $this->links ) ) {
				$endpoint = pt_find_rest_endpoint( $this->links );
				$rest     = pt_find_rest_alternate( $this->links );
				if ( $endpoint && $rest ) {
					$fetch = RESTAPI::fetch( $endpoint, RESTAPI::get_rest_path( $endpoint, $rest ) );
					$alt   = RESTAPI::parse( $fetch, $endpoint, $args );
					if ( is_array( $alt ) ) {
						$alt['_rest'] = $fetch;
						$remote[]     = $alt;
					}
				}
			}
			foreach ( $remote as $alt ) {
				$this->jf2 = self::fill_gaps( $this->jf2, $alt );
			}
		}

		// Post type is derived, so derive it again now the gaps are filled.
		if ( isset( $this->jf2['post-type'] ) && isset( $this->jf2['type'] ) && 'entry' === $this->jf2['type'] ) {
			$this->jf2['post-type'] = post_type_discovery( $this->jf2 );
		}

		if ( ! isset( $this->jf2['url'] ) ) {
			$this->jf2['url'] = $this->url;
		}
			// Expand Short URLs in summary.
		if ( isset( $this->jf2['summary'] ) ) {
			$urls = wp_extract_urls( $this->jf2['summary'] );
			foreach ( $urls as $url ) {
				$redirect = self::redirect( $url, false );
				if ( $redirect && ! is_wp_error( $redirect ) ) {
					$this->jf2['_urls'][] = $redirect;
					$this->jf2['summary'] = str_replace( $url, $redirect, $this->jf2['summary'] );
				}
			}
		}
		if ( isset( $this->jf2['location'] ) && $args['location'] ) {
			$this->jf2 = jf2_location( $this->jf2 );
		}

		$this->jf2['_links'] = $this->links;
	}
	/**
	 * Properties that are always arrays with the always_arrays argument. These are
	 * the properties Microsub specifies as arrays of values.
	 *
	 * @since 2.0.0
	 * @var string[]
	 */
	const ARRAY_PROPERTIES = array(
		'category',
		'photo',
		'video',
		'audio',
		'syndication',
		'like-of',
		'repost-of',
		'bookmark-of',
		'in-reply-to',
	);

	/**
	 * Response properties. An entry with any of these was marked up on purpose,
	 * so it counts as having content even without a summary or content.
	 *
	 * @since 2.0.0
	 * @var string[]
	 */
	const RESPONSE_PROPERTIES = array(
		'in-reply-to',
		'like-of',
		'repost-of',
		'bookmark-of',
		'favorite-of',
		'quotation-of',
		'follow-of',
		'tag-of',
		'listen-of',
		'watch-of',
		'read-of',
		'play-of',
		'jam-of',
		'rsvp',
		'checkin',
		'itinerary',
		'ate',
		'pk-ate',
		'drank',
		'pk-drank',
	);

	/**
	 * Checks whether parsed jf2 has enough to stand on its own.
	 *
	 * A card needs a name, url or photo. Anything else needs content,
	 * references, items or a response property, or, unless $require_content
	 * is set, a summary.
	 *
	 * @since 2.0.0
	 *
	 * @param array $jf2             Parsed jf2.
	 * @param bool  $require_content Optional. Whether a summary alone is not
	 *                               enough. Default false.
	 * @return bool True if no remote fallback is needed.
	 */
	public static function has_content( $jf2, $require_content = false ) {
		if ( ! is_array( $jf2 ) ) {
			return false;
		}
		if ( isset( $jf2['type'] ) && 'card' === $jf2['type'] ) {
			$keys = array( 'name', 'url', 'photo' );
		} else {
			$keys = array_merge( array( 'content', 'refs', 'items' ), self::RESPONSE_PROPERTIES );
			if ( ! $require_content ) {
				$keys[] = 'summary';
			}
		}
		foreach ( $keys as $key ) {
			if ( ! empty( $jf2[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Adds a fallback's properties where the result doesn't have them yet.
	 *
	 * Existing values always win. As a special case, a fallback's author
	 * name (a string) is added to an author card that has no name.
	 *
	 * @since 2.0.0
	 *
	 * @param array      $jf2      Result so far.
	 * @param array|null $fallback Properties from a fallback parser.
	 * @return array The result with the gaps filled.
	 */
	public static function fill_gaps( $jf2, $fallback ) {
		if ( ! is_array( $fallback ) ) {
			return $jf2;
		}
		foreach ( $fallback as $key => $value ) {
			if ( ! isset( $jf2[ $key ] ) || '' === $jf2[ $key ] || array() === $jf2[ $key ] ) {
				$jf2[ $key ] = $value;
			}
		}
		if ( isset( $fallback['author'], $jf2['author'] ) && is_string( $fallback['author'] ) && is_array( $jf2['author'] ) && ! wp_is_numeric_array( $jf2['author'] ) && empty( $jf2['author']['name'] ) ) {
			$jf2['author']['name'] = $fallback['author'];
		}
		return $jf2;
	}
}
