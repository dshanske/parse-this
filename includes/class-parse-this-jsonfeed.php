<?php

class Parse_This_JSONFeed {
	private static function ifset( $key, $array ) {
		return isset( $array[ $key ] ) ? $array[ $key ] : null;
	}

	private static function get_author( $array ) {
		if ( isset( $array['author'] ) && ! isset( $array['authors'] ) ) {
			$array['authors'] = $array['author'];
		}
		if ( ! isset( $array['authors'] ) ) {
			return null;
		}
		$author = $array['authors'];
		if ( ! wp_is_numeric_array( $author ) ) {
			$author = array( $author );
		}
		foreach ( $author as $element ) {
			$return[] = array_filter(
				array(
					'name'  => self::ifset( 'name', $element ),
					'url'   => self::ifset( 'url', $element ),
					'photo' => self::ifset( 'avatar', $element ),
				)
			);
		}
		$return = array_filter( $return );
		if ( 1 === count( $return ) ) {
			return $return[0];
		}
		return $return;
	}

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
		foreach ( $content['items'] as $item ) {
			$newitem = array_filter(
				array(
					'uid'         => self::ifset( 'id', $item ),
					'url'         => self::ifset( 'url', $item ),
					'in-reply-to' => self::ifset( 'external_url', $item ),
					'name'        => self::ifset( 'title', $item ),
					'content'     => array_filter(
						array(
							'html' => Parse_This::clean_content( self::ifset( 'content_html', $item ) ),
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
		return $return;
	}
}



