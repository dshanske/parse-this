<?php
/**
 * Helpers for processing microformats2 array structures.
 * Derived from https://github.com/barnabywalters/php-mf-cleaner
 * and https://github.com/aaronpk/XRay/blob/master/lib/Formats/Mf2.php
 * and https://github.com/pfefferle/wordpress-semantic-linkbacks/blob/master/includes/class-linkbacks-mf2-handler.php
 **/

class Parse_This_MF2_Utils {

	/**
	 * is this what type
	 *
	 * @param array  $mf Parsed Microformats Array
	 * @param string $type Type
	 * @return bool
	 */
	public static function is_type( $mf, $type ) {
		return is_array( $mf ) && ! empty( $mf['type'] ) && is_array( $mf['type'] ) && in_array( $type, $mf['type'], true );
	}

	/**
	 * Parse Content
	 *
	 * @param array $mf Parsed Microformats Array.
	 * @return array $data Content array consisting of text and html properties.
	 */
	public static function parse_html_value( $mf, $property ) {
		if ( ! array_key_exists( $property, $mf['properties'] ) ) {
			return null;
		}
		$textcontent = false;
		$htmlcontent = false;
		$content     = $mf['properties'][ $property ][0];
		if ( is_string( $content ) ) {
			$textcontent = $content;
		} elseif ( ! is_string( $content ) && is_array( $content ) && array_key_exists( 'value', $content ) ) {
			if ( array_key_exists( 'html', $content ) ) {
				$htmlcontent = trim( Parse_This::clean_content( $content['html'] ) );
				$textcontent = wp_strip_all_tags( $content['value'] );
			} else {
				$textcontent = trim( $content['value'] );
			}
		}
		$data = array(
			'text' => $textcontent,
		);
		if ( $htmlcontent && $textcontent !== $htmlcontent ) {
			$data['html'] = $htmlcontent;
		}
		return $data;
	}

	/**
	 * Verifies if $mf is an array without numeric keys, and has a 'properties' key.
	 *
	 * @param $mf
	 * @return bool
	 */
	public static function is_microformat( $mf ) {
		return ( is_array( $mf ) && ! wp_is_numeric_array( $mf ) && ! empty( $mf['type'] ) && isset( $mf['properties'] ) );
	}


	/**
	 * Verifies if $mf has an 'items' key which is also an array, returns true.
	 *
	 * @param $mf
	 * @return bool
	 */
	public static function is_microformat_collection( $mf ) {
		return ( is_array( $mf ) && isset( $mf['items'] ) && is_array( $mf['items'] ) );
	}

	/**
	 * Verifies if $p is an array without numeric keys and has key 'value' and 'html' set.
	 *
	 * @param $p
	 * @return bool
	 */
	public static function is_embedded_html( $p ) {
		return is_array( $p ) && ! wp_is_numeric_array( $p ) && isset( $p['value'] ) && isset( $p['html'] );
	}

	/**
	 * Verifies if property named $propname is in array $mf.
	 *
	 * @param array    $mf
	 * @param $propname
	 * @return bool
	 */
	public static function has_prop( array $mf, $propname ) {
		return ! empty( $mf['properties'][ $propname ] ) && is_array( $mf['properties'][ $propname ] );
	}


	/**
	 * Verifies if rel named $relname is in array $mf.
	 *
	 * @param array   $mf
	 * @param $relname
	 * @return bool
	 */
	public static function has_rel( array $mf, $relname ) {
		return ! empty( $mf['rels'][ $relname ] ) && is_array( $mf['rels'][ $relname ] );
	}

	/**
	 * shortcut for getPlaintext.
	 *
	 * @deprecated use getPlaintext from now on
	 * @param array       $mf
	 * @param $propname
	 * @param null|string $fallback
	 * @return mixed|null
	 */
	public static function get_prop( array $mf, $propname, $fallback = null ) {
		return self::get_plaintext( $mf, $propname, $fallback );
	}

	/**
	 * If $v is a microformat or embedded html, return $v['value']. Else return v.
	 *
	 * @param $v
	 * @return mixed
	 */
	public static function to_plaintext( $v ) {
		if ( self::is_microformat( $v ) || self::is_embedded_html( $v ) ) {
			return $v['value'];
		} elseif ( is_array( $v ) && isset( $v['text'] ) ) {
			return $v['text'];
		}
		return $v;
	}

	/**
	 * Returns plaintext of $propname with optional $fallback
	 *
	 * @param array       $mf
	 * @param $propname
	 * @param null|string $fallback
	 * @return mixed|null
	 * @link http://php.net/manual/en/function.current.php
	 */
	public static function get_plaintext( array $mf, $propname, $fallback = null ) {
		if ( ! empty( $mf['properties'][ $propname ] ) && is_array( $mf['properties'][ $propname ] ) ) {
			return self::to_plaintext( current( $mf['properties'][ $propname ] ) );
		}
		return $fallback;
	}

	/**
	 * Converts $propname in $mf into array_map plaintext, or $fallback if not valid.
	 *
	 * @param array       $mf
	 * @param $propname
	 * @param null|string $fallback
	 * @return null
	 */
	public static function get_plaintext_array( array $mf, $propname, $fallback = null ) {
		if ( ! empty( $mf['properties'][ $propname ] ) && is_array( $mf['properties'][ $propname ] ) ) {
			return array_map( array( 'Parse_Mf2', 'to_plaintext' ), $mf['properties'][ $propname ] ); }
		return $fallback;
	}

	/**
	 * Returns ['html'] element of $v, or ['value'] or just $v, in order of availablility.
	 *
	 * @param $v
	 * @return mixed
	 */
	public static function to_html( $v ) {
		if ( self::is_embedded_html( $v ) ) {
			return $v['html']; } elseif ( self::is_microformat( $v ) ) {
			return htmlspecialchars( $v['value'] ); }
			return htmlspecialchars( $v );
	}

	/**
	 * Gets HTML of $propname or if not, $fallback
	 *
	 * @param array       $mf
	 * @param $propname
	 * @param null|string $fallback
	 * @return mixed|null
	 */
	public static function get_html( array $mf, $propname, $fallback = null ) {
		if ( ! empty( $mf['properties'][ $propname ] ) && is_array( $mf['properties'][ $propname ] ) ) {
			return self::to_html( current( $mf['properties'][ $propname ] ) ); }
		return $fallback;
	}



	/**
	 * Returns 'summary' element of $mf or a truncated Plaintext of $mf['properties']['content'] with 19 chars and ellipsis.
	 *
	 * @deprecated as not often used
	 * @param array $mf
	 * @param array $content
	 * @return mixed|null|string
	 */
	public static function get_summary( array $mf, $content = null ) {
		if ( self::has_prop( $mf, 'summary' ) ) {
			return self::get_prop( $mf, 'summary' );
		}
		if ( ! $content ) {
			$content = self::parse_html_value( $mf, 'content' );
		}
		$summary = substr( $content['text'], 0, 300 );
		if ( 300 < strlen( $content['text'] ) ) {
			$summary .= '...';
		}
		return $summary;
	}


	/**
	 * Gets the date published of $mf array.
	 *
	 * @param array       $mf
	 * @param bool        $ensurevalid
	 * @param null|string $fallback optional result if date not available
	 * @return mixed|null
	 */
	public static function get_published( array $mf, $ensurevalid = false, $fallback = null ) {
		$date = self::get_datetime_property( 'published', $mf, $ensurevalid, $fallback );
		if ( $date instanceof DateTime ) {
			return $date->format( DATE_W3C );
		}
		return null;
	}

	/**
	 * Gets the date updated of $mf array.
	 *
	 * @param array $mf
	 * @param bool  $ensurevalid
	 * @param null  $fallback
	 * @return mixed|null
	 */
	public static function get_updated( array $mf, $ensurevalid = false, $fallback = null ) {
		$date = self::get_datetime_property( 'updated', $mf, $ensurevalid, $fallback );
		if ( $date instanceof DateTime ) {
			return $date->format( DATE_W3C );
		}
		return null;
	}

	/**
	 * Gets the DateTime properties including published or updated, depending on params.
	 *
	 * @param $name string updated or published
	 * @param array                            $mf
	 * @param bool                             $ensurevalid
	 * @param null|string                      $fallback
	 * @return DateTime|null
	 */
	public static function get_datetime_property( $name, array $mf, $ensurevalid = false, $fallback = null ) {
		$compliment = 'published' === $name ? 'updated' : 'published';
		if ( self::has_prop( $mf, $name ) ) {
			$return = self::get_prop( $mf, $name );
		} elseif ( self::has_prop( $mf, $compliment ) ) {
			$return = self::get_prop( $mf, $compliment );
		} else {
			return $fallback;
		}
		if ( ! $ensurevalid ) {
			return $return;
		} else {
			try {
				return new DateTime( $return );
			} catch ( Exception $e ) {
				return $fallback;
			}
		}
	}

	/**
	 * True if same hostname is parsed on both
	 *
	 * @param $u1 string url
	 * @param $u2 string url
	 * @return bool
	 * @link http://php.net/manual/en/function.parse-url.php
	 */
	public static function same_hostname( $u1, $u2 ) {
		return wp_parse_url( $u1, PHP_URL_HOST ) === wp_parse_url( $u2, PHP_URL_HOST );
	}

	/**
	 * Returns array per parse_url standard with pathname key added.
	 *
	 * @param $url
	 * @return mixed
	 * @link http://php.net/manual/en/function.parse-url.php
	 */
	public static function parse_url( $url ) {
		$r             = wp_parse_url( $url );
		$r['pathname'] = empty( $r['path'] ) ? '/' : $r['path'];
		return $r;
	}


	/**
	 * See if urls match for each component of parsed urls. Return true if so.
	 *
	 * @param $url1
	 * @param $url2
	 * @return bool
	 * @see parseUrl()
	 */
	public static function urls_match( $url1, $url2 ) {
		return ( normalize_url( $url1 ) === normalize_url( $url2 ) );
	}

	/**
	 * Flattens microformats. Can intake multiple Microformats including possible MicroformatCollection.
	 *
	 * @param array $mfs
	 * @return array
	 */
	public static function flatten_microformat_properties( array $mf ) {
		$items = array();

		if ( ! self::is_microformat( $mf ) ) {
			return $items; }

		foreach ( $mf['properties'] as $proparray ) {
			foreach ( $proparray as $prop ) {
				if ( self::is_microformat( $prop ) ) {
					$items[] = $prop;
					$items   = array_merge( $items, self::flatten_microformat_properties( $prop ) );
				}
			}
		}

		return $items;
	}

	/**
	 * Flattens microformats. Can intake multiple Microformats including possible MicroformatCollection.
	 *
	 * @param array $mfs
	 * @return array
	 */
	public static function flatten_microformats( array $mfs ) {
		if ( self::is_microformat_collection( $mfs ) ) {
			$mfs = $mfs['items']; } elseif ( self::is_microformat( $mfs ) ) {
			$mfs = array( $mfs ); }

			$items = array();

			foreach ( $mfs as $mf ) {
				$items[] = $mf;

				$items = array_merge( $items, self::flatten_microformat_properties( $mf ) );

				if ( empty( $mf['children'] ) ) {
					continue; }

				foreach ( $mf['children'] as $child ) {
					$items[] = $child;
					$items   = array_merge( $items, self::flatten_microformat_properties( $child ) );
				}
			}

			return $items;
	}

	/**
	 *
	 * @param array $mfs
	 * @param $name
	 * @param bool  $flatten
	 * @return mixed
	 */
	public static function find_microformats_by_type( array $mfs, $name, $flatten = true ) {
		return self::find_microformats_by_callable(
			$mfs,
			function ( $mf ) use ( $name ) {
				return in_array( $name, $mf['type'], true );
			},
			$flatten
		);
	}


	/**
	 * Can determine if a microformat key with value exists in $mf. Returns true if so.
	 *
	 * @param array     $mfs
	 * @param $propname
	 * @param $propvalue
	 * @param bool      $flatten
	 * @return mixed
	 * @see findMicroformatsByCallable()
	 */
	public static function find_microformats_by_property( array $mfs, $propname, $propvalue, $flatten = true ) {
		return find_microformats_by_callable(
			$mfs,
			function ( $mf ) use ( $propname, $propvalue ) {
				if ( ! hasProp( $mf, $propname ) ) {
					return false; }

				if ( in_array( $propvalue, $mf['properties'][ $propname ], true ) ) {
					return true; }

				return false;
			},
			$flatten
		);
	}

	/**
	 * $callable should be a function or an exception will be thrown. $mfs can accept microformat collections.
	 * If $flatten is true then the result will be flattened.
	 *
	 * @param array    $mfs
	 * @param $callable
	 * @param bool     $flatten
	 * @return mixed
	 * @link http://php.net/manual/en/function.is-callable.php
	 * @see flattenMicroformats()
	 */
	public static function find_microformats_by_callable( array $mfs, $callable, $flatten = true ) {
		if ( ! is_callable( $callable ) ) {
			throw new \InvalidArgumentException( '$callable must be callable' ); }

		if ( $flatten && ( self::is_microformat( $mfs ) || self::is_microformat_collection( $mfs ) ) ) {
			$mfs = self::flatten_microformats( $mfs ); }

		return array_values( array_filter( $mfs, $callable ) );
	}

}
