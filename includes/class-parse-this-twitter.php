<?php
/**
 * Parse This Twitter class.
 */
class Parse_This_Twitter extends Parse_This_Base {
	/**
	 *
	 * @access public
	 */
	public static function parse( $url, $args ) {
		if ( false === strpos( $url, 'status' ) ) {
			return array();
		}

		$url      = add_query_arg( 'url', $url, 'https://publish.twitter.com/oembed' );
		$response = pt_remote_get( $url );
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$oembed = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $oembed ) ) {
			return array();
		}
		$jf2 = array();
		if ( array_key_exists( 'url', $oembed ) ) {
			$jf2['url'] = $oembed['url'];
		}
		if ( array_key_exists( 'html', $oembed ) ) {
			$html = $oembed['html'];
			$dom  = pt_load_domdocument( $html );
			$html = explode( '&mdash;', $html );
			$html = $html[0];
			$text = wp_strip_all_tags( $html );
			$text = explode( '&mdash;', $text );
			$text = $text[0];

			$links    = $dom->getElementsByTagName( 'a' );
			$names    = array();
			$category = array();
			foreach ( $links as $link ) {
					$key   = wp_strip_all_tags( $link->nodeValue ); // phpcs:ignore
					$value = $link->getAttribute( 'href' );
					$parse = wp_parse_url( $value );
				if ( '' === $key || ! is_array( $parse ) ) {
					continue;
				}
					unset( $parse['query'] );
					$value = build_url( $parse );
				if ( '#' === $key[0] ) {
					$category[] = str_replace( '#', '', $key );
				} elseif ( '@' === $key[0] ) {
					$category[] = $value;
				} elseif ( isset( $jf2['url'] ) && $jf2['url'] === $value ) {
					$jf2['published'] = normalize_iso8601( $key );
				} else {
					$names[ wp_strip_all_tags( $key ) ] = normalize_url( $value ); // phpcs:ignore
				}
			}
			$jf2['links']    = $names;
			$jf2['category'] = $category;
			$jf2['content']  = array(
				'html'  => Parse_This::clean_content( $html, array( 'blockquote' => array() ) ),
				'value' => $text,
			);
			$jf2['summary']  = $jf2['content']['html'];
		}
		$jf2['author']      = array_filter(
			array(
				'type' => 'card',
				'name' => ifset( $oembed['author_name'] ),
				'url'  => ifset( $oembed['author_url'] ),
			)
		);
		$jf2['publication'] = 'Twitter';
		if ( WP_DEBUG ) {
			$jf2['_ombed'] = $oembed;
		}

		return array_filter( $jf2 );
	}

}
