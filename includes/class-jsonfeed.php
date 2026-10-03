<?php
/**
 * JSONFeed class.
 *
 * @package Parse_This
 */

namespace ParseThis;

/**
 * Converts JSON Feed (versions 1 and 1.1) documents into jf2 feeds.
 *
 * @since 1.0.0
 *
 * @link https://www.jsonfeed.org/version/1.1/
 */
class JSONFeed extends Base {
	/**
	 * Returns an array value if the key is set.
	 *
	 * @since 1.0.0
	 *
	 * @param string|int $key   Key to look up.
	 * @param array      $array Array to look in.
	 * @return mixed The value, or null if the key is not set.
	 */
	private static function ifset( $key, $array ) {
		return isset( $array[ $key ] ) ? $array[ $key ] : null;
	}

	/**
	 * Converts JSON Feed author data into jf2 cards.
	 *
	 * Accepts both the version 1 'author' object and the version 1.1 'authors'
	 * list. Entries that are not objects are skipped.
	 *
	 * @since 1.0.0
	 *
	 * @param array $array A feed or item object that may contain author/authors.
	 * @return array|null A single author (name, url, photo), a list of authors, or
	 *                    null if none are present.
	 */
	private static function get_author( $array ) {
		if ( isset( $array['author'] ) && ! isset( $array['authors'] ) ) {
			$array['authors'] = $array['author'];
		}
		if ( ! isset( $array['authors'] ) ) {
			return null;
		}
		$author = $array['authors'];
		$return = array();
		if ( ! is_array( $author ) ) {
			return null;
		}
		if ( ! wp_is_numeric_array( $author ) ) {
			$author = array( $author );
		}
		foreach ( $author as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$card = array_filter(
				array(
					'name'  => self::ifset( 'name', $element ),
					'url'   => self::ifset( 'url', $element ),
					'photo' => self::ifset( 'avatar', $element ),
				)
			);
			if ( $card ) {
				$return[] = array( 'type' => 'card' ) + $card;
			}
		}
		$return = array_values( array_filter( $return ) );
		if ( 1 === count( $return ) ) {
			return $return[0];
		}
		return $return;
	}

	/**
	 * Converts a decoded JSON Feed into a jf2 feed.
	 *
	 * Item attachments are mapped by MIME type to audio, photo or video, and
	 * duration_in_seconds becomes an ISO 8601 duration.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $content Decoded JSON Feed document.
	 * @param string $url     URL the feed was fetched from.
	 * @return array jf2 feed with type 'feed', '_feed_type' => 'jsonfeed', feed-level
	 *               properties, 'items', and the '_last_published'/'_last_updated'
	 *               dates of its items.
	 */
	public static function to_jf2( $content, $url ) {
		$return          = array_filter(
			array(
				'type'       => 'feed',
				'_feed_type' => 'jsonfeed',
				'name'       => self::ifset( 'title', $content ),
				'url'        => $url,
				'summary'    => self::ifset( 'description', $content ),
				'photo'      => self::ifset( 'icon', $content ),
				'author'     => self::get_author( $content ),
				'language'   => self::ifset( 'language', $content ),
			)
		);
		$return['items'] = array();
		$items = ( isset( $content['items'] ) && is_array( $content['items'] ) ) ? $content['items'] : array();
		foreach ( $items as $item ) {
			$newitem = array_filter(
				array(
					'type'        => 'entry',
					'uid'         => self::ifset( 'id', $item ),
					'url'         => self::ifset( 'url', $item ),
					'in-reply-to' => self::ifset( 'external_url', $item ),
					'name'        => self::ifset( 'title', $item ),
					'content'     => array_filter(
						array(
							'html' => Parser::clean_content( self::ifset( 'content_html', $item ) ),
							'text' => self::ifset( 'content_text', $item ),
						)
					),
					'summary'     => self::ifset( 'summary', $item ),
					'featured'    => self::ifset( 'image', $item ),
					'published'   => normalize_iso8601( self::ifset( 'date_published', $item ) ),
					'updated'     => normalize_iso8601( self::ifset( 'date_modified', $item ) ),
					'author'      => self::get_author( $item ),
					'category'    => self::ifset( 'tags', $item ),
					'language'    => self::ifset( 'language', $item ),
				)
			);
			if ( array_key_exists( 'attachments', $item ) ) {
				foreach ( $item['attachments'] as $attachment ) {
					if ( ! isset( $attachment['mime_type'] ) || ! isset( $attachment['url'] ) ) {
						continue;
					}
					$type = explode( '/', $attachment['mime_type'] );
					$type = array_shift( $type );
					switch ( $type ) {
						case 'audio':
							$newitem['audio'] = $attachment['url'];
							if ( isset( $attachment['duration_in_seconds'] ) ) {
								$newitem['duration'] = seconds_to_iso8601( $attachment['duration_in_seconds'] );
							}
							break;
						case 'image':
							$newitem['photo'] = $attachment['url'];
							break;
						case 'video':
							$newitem['video'] = $attachment['url'];
							if ( isset( $attachment['duration_in_seconds'] ) ) {
								$newitem['duration'] = seconds_to_iso8601( $attachment['duration_in_seconds'] );
							}
							break;
					}
				}
			}
			$return['items'][] = $newitem;
		}
		$return['_last_published'] = self::find_last_published( $return['items'] );
		$return['_last_updated']   = self::find_last_updated( $return['items'] );
		return $return;
	}
}
