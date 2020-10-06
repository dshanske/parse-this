<?php

class JF2_to_MF2_Test extends WP_UnitTestCase {
	public function test_single_hentry() {
		$mf2    = array(
			'type'       => array( 'h-entry' ),
			'properties' => array(
				'url' => array( 'http://www.example.org' ),
			),
		);
		$jf2    = array(
			'type' => 'entry',
			'url'  => 'http://www.example.org',
		);
		$return = jf2_to_mf2( $jf2 );
		$this->assertEquals( $mf2, $return, wp_json_encode( $return ) );
	}
	public function test_nested_property() {
		$mf2    = array(
			'type'       => array( 'h-entry' ),
			'properties' => array(
				'url'    => array( 'http://www.example.org' ),
				'author' => array(
					array(
						'type'       => array( 'h-card' ),
						'properties' => array(
							'name' => array( 'John Smith' ),
							'url'  => array( 'http://www.example.org/author/smith' ),
						),
					),
				),
			),
		);
		$jf2    = array(
			'type'   => 'entry',
			'url'    => 'http://www.example.org',
			'author' => array(
				'type' => 'card',
				'name' => 'John Smith',
				'url'  => 'http://www.example.org/author/smith',
			),
		);
		$return = jf2_to_mf2( $jf2 );
		$this->assertEqualsCanonicalizing( $mf2, $return, wp_json_encode( $return, JSON_PRETTY_PRINT ) . wp_json_encode( $mf2, JSON_PRETTY_PRINT ) );
	}

	public function test_double_nested_property() {
		$mf2    = array(
			'type'       => array( 'h-entry' ),
			'properties' => array(
				'url'      => array( 'http://www.example.org' ),
				'location' => array(
					array(
						'type'       => array( 'h-adr' ),
						'properties' => array(
							'label' => array( 'Sunshine Street' ),
							'geo'   => array(
									array(
										'type'       => array( 'h-geo' ),
										'properties' => array(
											'latitude'  => array( '40.11' ),
											'longitude' => array( '41.11' ),
										),
									)
							),
						),
					),
				),
			),
		);
		$jf2    = array(
			'type'     => 'entry',
			'url'      => 'http://www.example.org',
			'location' => array(
				'type'  => 'adr',
				'label' => 'Sunshine Street',
				'geo'   => array(
					'type'      => 'geo',
					'latitude'  => '40.11',
					'longitude' => '41.11',
				),
			),
		);
		$return = jf2_to_mf2( $jf2 );
		$this->assertEqualsCanonicalizing( $mf2, $return, wp_json_encode( $return, JSON_PRETTY_PRINT ) . wp_json_encode( $mf2, JSON_PRETTY_PRINT ) );
	}

	public function test_items() {
		$mf2    = array(
			'items' => array(
				array(
					'type'       => array( 'h-entry' ),
					'properties' => array(
						'url' => array( 'https://example.org/#1' ),
					),
				),
				array(
					'type'       => array( 'h-entry' ),
					'properties' => array(
						'url' => array( 'https://example.org/#2' ),
					),
				),
			),
		);
		$jf2    = array(
			'items' => array(
				array(
					'type' => 'entry',
					'url'  => 'https://example.org/#1',
				),
				array(
					'type' => 'entry',
					'url'  => 'https://example.org/#2',
				),
			),
		);
		$return = jf2_to_mf2( $jf2 );
		$this->assertEquals( $mf2, $return, wp_json_encode( $return ) );
	}

	public function test_hfeed() {
		$mf2    = array(
			'type'       => array( 'h-feed' ),
			'properties' => array(
				'name'     => array( 'Example Feed' ),
				'author'   => array(
					array(
						'type'       => array( 'h-card' ),
						'properties' => array(
							'name' => array( 'Example Man' ),
							'url'  => array( 'https://example.org' ),
						),
					),
				),
			),
			'children' => array(
				array(
					'type'       => array( 'h-entry' ),
					'properties' => array(
						'url' => array( 'https://example.org/#1' ),
					),
				),
				array(
					'type'       => array( 'h-entry' ),
					'properties' => array(
						'url' => array( 'https://example.org/#2' ),
					),
				),
			),
		);
		$jf2    = array(
			'type'     => 'feed',
			'author'   => array(
				'type' => 'card',
				'name' => 'Example Man',
				'url'  => 'https://example.org',
			),
			'name'     => 'Example Feed',
			'children' => array(
				array(
					'type' => 'entry',
					'url'  => 'https://example.org/#1',
				),
				array(
					'type' => 'entry',
					'url'  => 'https://example.org/#2',
				),
			),
		);
		$return = jf2_to_mf2( $jf2 );
		$this->assertEquals( $mf2, $return, wp_json_encode( $return ) );
	}
}

