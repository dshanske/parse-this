<?php
/**
 * Class autoloader for Parse This.
 *
 * @package Parse_This
 */

/*
 * Maps classes prefixed Parse_This to files in this directory, for example
 * Parse_This_MF2_Utils to class-parse-this-mf2-utils.php.
 */
spl_autoload_register(
	function ( $class ) {
		$base_dir = trailingslashit( __DIR__ );
		$bases    = array( 'Parse_This' );
		foreach ( $bases as $base ) {
			if ( strncmp( $class, $base, strlen( $base ) ) === 0 ) {
				$filename = 'class-' . strtolower( str_replace( '_', '-', $class ) );
				$file     = $base_dir . $filename . '.php';
				if ( file_exists( $file ) ) {
					require $file;
				}
			}
		}
	}
);
