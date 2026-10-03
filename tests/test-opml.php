<?php
/**
 * Tests for ParseThis\OPML.
 *
 * @package Parse_This
 */

/**
 * OPML tests.
 */
class OPML_Test extends Parse_This_TestCase {

	/**
	 * Groups and loose feeds are converted to sanitized strings (S-7).
	 */
	public function test_convert() {
		$opml = '<?xml version="1.0"?><opml version="2.0"><head><title>Subs</title></head><body>'
			. '<outline text="News" title="News">'
			. '<outline type="rss" text="Example" title="Example &lt;b&gt;Feed&lt;/b&gt;" xmlUrl="https://example.com/feed/"/>'
			. '<outline type="rss" title="Bad" xmlUrl="javascript:alert(1)"/>'
			. '</outline>'
			. '<outline type="rss" text="Loose" xmlUrl="https://example.org/feed/"/>'
			. '</body></opml>';

		$result = ( new ParseThis\OPML() )->convert( $opml );

		$this->assertSame(
			array(
				array(
					'title'    => 'News',
					'children' => array(
						array(
							'name' => 'Example Feed',
							'url'  => 'https://example.com/feed/',
						),
					),
				),
				array(
					'title'    => '',
					'children' => array(
						array(
							'name' => 'Loose',
							'url'  => 'https://example.org/feed/',
						),
					),
				),
			),
			$result
		);
	}

	/**
	 * Invalid or empty input returns an empty array instead of a fatal error (S-7).
	 */
	public function test_convert_invalid() {
		$opml = new ParseThis\OPML();
		$this->assertSame( array(), $opml->convert( 'not xml <' ) );
		$this->assertSame( array(), $opml->convert( '' ) );
		$this->assertSame( array(), $opml->convert( '<rss version="2.0"></rss>' ) );
	}

	/**
	 * An error response is returned as WP_Error, not as content (S-7).
	 */
	public function test_fetch_error_response() {
		$this->respond( 'https://example.com/subs.opml', 'Not found', 'text/html', array(), 404 );
		$this->assertWPError( ( new ParseThis\OPML() )->fetch( 'https://example.com/subs.opml' ) );
	}
}
