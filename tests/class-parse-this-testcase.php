<?php
/**
 * Base class for Parse This tests: fixtures, HTTP mocking and SimplePie.
 *
 * @package Parse_This
 */

/**
 * Base test case.
 */
abstract class Parse_This_TestCase extends WP_UnitTestCase {

	/**
	 * Mocked HTTP responses, keyed by URL.
	 *
	 * @var array[]
	 */
	protected $responses = array();

	/**
	 * Requests made during the test, in order: url and args.
	 *
	 * @var array[]
	 */
	protected $requests = array();

	/**
	 * Intercepts all HTTP requests.
	 */
	public function set_up() {
		parent::set_up();
		$this->responses = array();
		$this->requests  = array();
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	/**
	 * Removes the HTTP mock.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		parent::tear_down();
	}

	/**
	 * Returns the contents of a fixture file.
	 *
	 * @param string $name File name in tests/fixtures.
	 * @return string File contents.
	 */
	protected function fixture( $name ) {
		return file_get_contents( __DIR__ . '/fixtures/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Registers a mocked response for a URL.
	 *
	 * @param string $url          URL that will be requested.
	 * @param string $body         Response body.
	 * @param string $content_type Optional. Content-Type header. Default text/html.
	 * @param array  $headers      Optional. Extra headers, lowercase names.
	 * @param int    $code         Optional. HTTP status. Default 200.
	 */
	protected function respond( $url, $body, $content_type = 'text/html; charset=utf-8', $headers = array(), $code = 200 ) {
		$this->responses[ $url ] = array(
			'headers'  => array_merge( array( 'content-type' => $content_type ), $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Serves mocked responses. Unmocked URLs fail, so no test touches the network.
	 *
	 * @param false|array $pre  Short-circuit value.
	 * @param array       $args Request arguments.
	 * @param string      $url  Request URL.
	 * @return array|WP_Error Mocked response, or WP_Error for an unmocked URL.
	 */
	public function mock_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( isset( $this->responses[ $url ] ) ) {
			return $this->responses[ $url ];
		}
		return new WP_Error( 'http_request_not_mocked', 'No mocked response for ' . $url );
	}

	/**
	 * Builds an initialized SimplePie feed from XML, whichever SimplePie core ships.
	 *
	 * @param string $xml Feed XML.
	 * @return object SimplePie feed.
	 */
	protected function simplepie( $xml ) {
		if ( ! class_exists( 'SimplePie\SimplePie' ) && ! class_exists( 'SimplePie' ) ) {
			require_once ABSPATH . WPINC . '/class-simplepie.php';
		}
		$class = class_exists( 'SimplePie\SimplePie' ) ? 'SimplePie\SimplePie' : 'SimplePie';
		$feed  = new $class();
		$feed->set_raw_data( $xml );
		$feed->enable_cache( false );
		$feed->init();
		return $feed;
	}

	/**
	 * Parses a fixture as the page at $url.
	 *
	 * @param string $fixture Fixture file name.
	 * @param string $url     URL the content is from.
	 * @param array  $args    Optional. Arguments for Parser::parse().
	 * @return array jf2.
	 */
	protected function parse_fixture( $fixture, $url, $args = array() ) {
		$parser = new ParseThis\Parser();
		$parser->set( $this->fixture( $fixture ), $url );
		$parser->parse( $args );
		return $parser->get();
	}
}
