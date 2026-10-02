<?php

class Parse_This_OPML {
	private static function ifset( $key, $array ) {
		return isset( $array[ $key ] ) ? $array[ $key ] : null;
	}


	/**
	 * Downloads the $url and returns the feeds it finds
	 *
	 * @param string $url URL to scan.
	 * @return WP_Error|boolean WP_Error if invalid and true if successful
	 */
	public function fetch( $url ) {
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'invalid-url', __( 'A valid URL was not provided.', 'parse-this' ) );
		}

		$response = pt_remote_get( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		// Strip any character set off the content type
		$ct = explode( ';', $content_type );
		if ( is_array( $ct ) ) {
			$content_type = array_shift( $ct );
		}
		$content_type = trim( $content_type );

		$content = wp_remote_retrieve_body( $response );
		return $content;
	}

	public function convert( $content ) {
		$xml    = simplexml_load_string( $content );
		$xml    = $xml->body;
		$return = array();
		foreach ( $xml->outline as $outline ) {
			$top = array(
				'title'    => $outline['title'],
				'children' => array(),
			);
			foreach ( $outline as $feed ) {
				$top['children'][] = array(
					'name' => $feed['title'],
					'url'  => $feed['xmlUrl'],
				);
			}
			$return[] = $top;
		}
		return $return;
	}
}
