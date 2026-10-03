<?php
/**
 * YouTube class.
 *
 * @package Parse_This
 */

namespace ParseThis;

/**
 * Extracts video metadata from YouTube watch pages.
 *
 * Reads the ytInitialPlayerResponse JSON that YouTube embeds in the page.
 * Used by Parser::parse() as the alternate parser for youtube.com and
 * youtu.be URLs when the page has no usable microformats or JSON-LD.
 *
 * @since 1.0.0
 */
class YouTube extends Base {
	/**
	 * Parses a YouTube watch page into jf2.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Raw HTML of the watch page.
	 * @param string $url     URL of the page.
	 * @param array  $args    Parse arguments (see Parser::parse()). Unused.
	 * @return array jf2 properties for the video (name, summary, author, published,
	 *               duration, category, featured, video), or an empty array if the
	 *               player data could not be found.
	 */
	public static function parse( $content, $url, $args ) {
		if ( ! $content ) {
			return array();
		}

		if ( ! is_string( $content ) ) {
			return array();
		}

		if ( ! preg_match( '#ytInitialPlayerResponse = (\{.+\});#U', $content, $match ) ) {
			return array();
		}
		$decode = json_decode( $match[1], true );
		if ( empty( $decode ) ) {
			return array();
		}
		if ( ! isset( $decode['videoDetails'] ) ) {
			return array();
		}
		$details       = $decode['videoDetails'];
		$microformat   = isset( $decode['microformat']['playerMicroformatRenderer'] ) ? $decode['microformat']['playerMicroformatRenderer'] : array();
		$jf2           = array(
			'uid'       => $details['videoId'] ?? null,
			'name'      => $details['title'] ?? null,
			'duration'  => seconds_to_iso8601( $details['lengthSeconds'] ?? null ),
			'category'  => $details['keywords'] ?? null,
			'summary'   => $details['shortDescription'] ?? null,
			'published' => normalize_iso8601( $microformat['publishDate'] ?? null ),
		);
		$author        = array(
			'type' => 'card',
			'url'  => $microformat['ownerProfileUrl'] ?? null,
			'name' => $details['author'] ?? null,
		);
		$jf2['author'] = array_filter( $author );

		if ( isset( $details['thumbnail'] ) ) {
			$thumbnail       = end( $details['thumbnail']['thumbnails'] );
			$jf2['featured'] = $thumbnail['url'];
		}
		if ( isset( $microformat['embed'] ) ) {
			$jf2['video'] = $microformat['embed']['iframeUrl'] ?? null;
		}
		if ( WP_DEBUG ) {
			$jf2['_yt'] = $decode;
		}
		return array_filter( $jf2 );
	}
}
