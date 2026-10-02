<?php
/**
 * Parser class.
 *
 * @package Parse_This
 */

namespace ParseThis;

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
 * REST API alternate, JSON-LD, a site-specific parser (YouTube, Instagram,
 * Twitter) and finally meta tags are tried in turn. RSS, Atom, JSON Feed,
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
	private $jf2   = array();

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
	 * A variant of core's fetch_feed() with caching disabled and HTML tags
	 * kept, so that content can be sanitized by clean_content() instead.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Feed URL.
	 * @return SimplePie|WP_Error The initialized feed, or WP_Error if SimplePie
	 *                            reports an error.
	 */
	public static function fetch_feed( $url ) {
		$url = pt_secure_rewrite( $url );
		if ( ! class_exists( 'SimplePie', false ) ) {
			require_once ABSPATH . WPINC . '/class-simplepie.php';
		}
		require_once ABSPATH . WPINC . '/class-wp-feed-cache-transient.php';
		require_once ABSPATH . WPINC . '/class-wp-simplepie-file.php';
		require_once ABSPATH . WPINC . '/class-wp-simplepie-sanitize-kses.php';
		$feed = new \SimplePie();

		// Register the cache handler using the recommended method for SimplePie 1.3 or later.
		if ( method_exists( 'SimplePie_Cache', 'register' ) ) {
			\SimplePie_Cache::register( 'wp_transient', 'WP_Feed_Cache_Transient' );
			$feed->set_cache_location( 'wp_transient' );
		} else {
			// Back-compat for SimplePie 1.2.x. Not reached on WordPress 6.2+, which bundles 1.5 or later.
			require_once ABSPATH . WPINC . '/class-wp-feed-cache.php';
			$feed->set_cache_class( 'WP_Feed_Cache' );
		}

		$feed->set_file_class( 'WP_SimplePie_File' );
		$feed->enable_cache( false );
		$feed->set_feed_url( $url );
		$feed->strip_htmltags( false );
		/** This action is documented in wp-includes/feed.php */
		do_action_ref_array( 'wp_feed_options', array( &$feed, $url ) );
		$feed->init();
		$feed->set_output_encoding( get_option( 'blog_charset' ) );

		if ( $feed->error() ) {
			return new \WP_Error( 'simplepie-error', $feed->error() );
		}

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
	 * Used to expand short links in summaries.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url       URL to check.
	 * @param bool   $allowlist Optional. When false, only URLs on known
	 *                          link-shortener hosts are checked; when true
	 *                          (the default), any URL is. The name is the reverse
	 *                          of what it does (review finding P-2).
	 * @return string|false|WP_Error The redirect target, false if there is no
	 *                               redirect (or $url is not a shortener when
	 *                               $allowlist is false), or WP_Error if $url is
	 *                               invalid.
	 */
	public static function redirect( $url, $allowlist = true ) {
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid-url', __( 'A valid URL was not provided.', 'parse-this' ) );
		}
		$url        = pt_secure_rewrite( $url );
		$domain     = wp_parse_url( $url, PHP_URL_HOST );
		$shorteners = array( 'fb.me', 't.co', 'youtu.be', 'ow.ly', 'bit.ly', 'tinyurl.com' );
		if ( ! $allowlist && ! in_array( $domain, $shorteners, true ) ) {
			return false;
		}
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
		$response = pt_remote_get( $url );
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
			$content = self::fetch_feed( $url );
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
				$content           = RESTAPI::posts_to_feed( array( 'items' => $content ), $url );
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
	 * }
	 * @return WP_Error|void WP_Error if there is no content to parse.
	 */
	public function parse( $args = array() ) {
		$defaults = array(
			'alternate'  => false,
			'return'     => 'single',
			'follow'     => false,
			'limit'      => 150,
			'jsonld'     => true,
			'html'       => true,
			'references' => true,
			'location'   => false,
		);
		$args     = wp_parse_args( $args, $defaults );
		// If not an option then revert to single.
		if ( ! in_array( $args['return'], array( 'single', 'feed' ), true ) ) {
			$args['return'] = 'single';
		}
		if ( class_exists( RSS::class ) && $this->content instanceof \SimplePie ) {
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

		if ( 'application/json' === $this->content_type ) {
			$this->jf2 = RESTAPI::parse( $content, $this->url, $args );
			if ( ! empty( $this->jf2 ) ) {
				$this->jf2['_rest'] = $content;
				return;
			}
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

		$more = array();

		// If No MF2 or if the parsed jf2 is missing any sort of content then try to find it in the HTML.
		if ( isset( $this->jf2['type'] ) && 'card' === $this->jf2['type'] ) {
			$more = array_intersect( array_keys( $this->jf2 ), array( 'name', 'url', 'photo' ) );
		} else {
			$more = array_intersect( array_keys( $this->jf2 ), array( 'summary', 'content', 'refs', 'items' ) );
			if ( empty( $more ) ) {
				$this->set( array( '_jf2' => $this->jf2 ), $this->url, true );
			}
		}
		if ( ! isset( $this->jf2['url'] ) ) {
			$this->jf2['url'] = $this->url;
		}

		if ( empty( $more ) ) {
			$alt = null;
			$jf2 = isset( $this->jf2['_jf2'] ) ? $this->jf2['_jf2'] : array();

			$empty = true;

			if ( ! empty( $this->links ) ) {
				$endpoint = pt_find_rest_endpoint( $this->links );
				$rest     = pt_find_rest_alternate( $this->links );
				if ( $endpoint && $rest ) {
					$empty        = false;
					$path         = RESTAPI::get_rest_path( $endpoint, $rest );
					$fetch        = RESTAPI::fetch( $endpoint, $path );
					$alt          = RESTAPI::parse( $fetch, $endpoint, $args );
					if ( is_array( $alt ) ) {
						$alt['_rest'] = $fetch;
					}
				}
			}

			if ( $empty && $args['jsonld'] ) {
				$alt = JSONLD::parse( $this->doc, $this->url, $args );
			}

			if ( empty( $alt ) || ! is_array( $alt ) ) {
				$alt   = array();
				$empty = true;
			} elseif ( is_countable( $alt ) && 1 === count( $alt ) && array_key_exists( '_jsonld', $alt ) ) {
				$empty = true;
			} else {
				$empty = false;
			}
			if ( $empty && $args['html'] ) {
				$args['alternate'] = true;
				if ( in_array( wp_parse_url( $this->url, PHP_URL_HOST ), array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be' ), true ) ) {
					$alt = YouTube::parse( $this->content, $this->url, $args );
				} elseif ( in_array( wp_parse_url( $this->url, PHP_URL_HOST ), array( 'www.instagram.com', 'instagram.com' ), true ) ) {
					$alt = Instagram::parse( $this->doc, $this->url, $args );
				} elseif ( in_array( wp_parse_url( $this->url, PHP_URL_HOST ), array( 'twitter.com', 'mobile.twitter.com' ), true ) ) {
					$alt = Twitter::parse( $this->url, $args );
				}
				if ( ! $alt ) {
					$alt = HTML::parse( $content, $this->url, $args );
				}
			}
			$json = JSON::parse( $this->doc, $this->url, $args );
			if ( is_array( $json ) ) {
				$this->jf2 = array_merge( $this->jf2, $json );
			}
			if ( is_array( $alt ) ) {
				$this->jf2 = array_merge( $this->jf2, $alt );
			}
			if ( ! empty( $jf2 ) ) {
				if ( isset( $jf2['author'] ) ) {
					if ( isset( $this->jf2['author'] ) && is_string( $this->jf2['author'] ) && is_array( $jf2['author'] ) ) {
						$jf2['author']['name'] = $this->jf2['author'];
					}
					$this->jf2['author']   = $jf2['author'];
				}
			}
			if ( isset( $alt['author'] ) && isset( $this->jf2['author'] ) && is_array( $this->jf2['author'] ) && ! wp_is_numeric_array( $this->jf2['author'] ) && ! isset( $this->jf2['author']['name'] ) ) {
				$this->jf2['author']['name'] = $alt['author'];
			}
		}
		if ( ! isset( $this->jf2['url'] ) ) {
			$this->jf2['url'] = $this->url;
		}
			// Expand Short URLs in summary.
		if ( isset( $this->jf2['summary'] ) ) {
			$urls = wp_extract_urls( $this->jf2['summary'] );
			foreach ( $urls as $url ) {
				$redirect = self::redirect( $url );
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
}
