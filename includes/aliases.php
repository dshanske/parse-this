<?php
/**
 * Deprecated global names for code that predates the ParseThis namespace.
 *
 * Post Kinds and Yarns call these names directly. Because the standalone
 * plugin loads before the copies they bundle, defining the names here makes
 * those plugins use the current code rather than their own older copy.
 *
 * Only names used by those plugins are aliased. No deprecation notices are
 * raised, so as not to flood their users' logs.
 *
 * @package Parse_This
 */

/*
 * Maps the old global class names to their namespaced classes on demand.
 * class_exists( 'REST_Parse_This' ), which Post Kinds uses to decide whether
 * to load its own endpoint, therefore finds the current one.
 */
spl_autoload_register(
	function ( $class ) {
		$aliases = array(
			'Parse_This'           => 'ParseThis\\Parser',
			'Parse_This_Discovery' => 'ParseThis\\Discovery',
			'Parse_This_MF2'       => 'ParseThis\\MF2',
			'Parse_This_MF2_Utils' => 'ParseThis\\MF2_Utils',
			'REST_Parse_This'      => 'ParseThis\\REST_Endpoint',
		);
		if ( isset( $aliases[ $class ] ) ) {
			class_alias( $aliases[ $class ], $class );
		}
	}
);
