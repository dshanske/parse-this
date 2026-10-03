<?php
/**
 * OPML class.
 *
 * @package Parse_This
 */

namespace ParseThis;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and converts OPML subscription lists.
 *
 * Not currently used anywhere in the plugin.
 *
 * @since 1.0.0
 */
class OPML {

	/**
	 * Downloads an OPML document.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL of the OPML file.
	 * @return string|WP_Error The response body, or WP_Error if the URL is invalid
	 *                         or the request fails.
	 */
	public function fetch( $url ) {
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid-url', __( 'A valid URL was not provided.', 'parse-this' ) );
		}

		$response = pt_remote_get( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		// Strip any character set off the content type.
		$ct = explode( ';', $content_type );
		if ( is_array( $ct ) ) {
			$content_type = array_shift( $ct );
		}
		$content_type = trim( $content_type );

		$content = wp_remote_retrieve_body( $response );
		return $content;
	}

	/**
	 * Converts OPML into a list of outline groups and their feeds.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content OPML XML.
	 * @return array[] List of groups, each with 'title' and 'children', where each
	 *                 child has 'name' and 'url' (as SimpleXMLElement values).
	 */
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
