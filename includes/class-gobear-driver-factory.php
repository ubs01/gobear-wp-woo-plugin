<?php
/**
 * Resolves the correct device-family driver for a given machine type string, so the rest of the
 * plugin never has to know which URL prefix a machine type maps to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Driver_Factory {

	/** @var Gobear_Driver_Interface[] Cached instances keyed by class name. */
	private static $instances = array();

	/**
	 * @param string             $machine_type e.g. 'GBK77', 'GBK114', 'K20'.
	 * @param Gobear_Client|null $client
	 * @return Gobear_Driver_Interface|WP_Error
	 */
	public static function create( $machine_type, Gobear_Client $client = null ) {
		$machine_type = strtoupper( trim( (string) $machine_type ) );
		$client       = $client ? $client : new Gobear_Client();

		$class = null;
		if ( in_array( $machine_type, array( 'GBK77', 'GBK114' ), true ) ) {
			$class = 'Gobear_Printer_Driver';
		} elseif ( 'K20' === $machine_type ) {
			$class = 'Gobear_Deskprinter_Driver';
		}

		if ( ! $class ) {
			return new WP_Error(
				'gobear_unsupported_machine_type',
				sprintf(
					/* translators: %s: machine type string. */
					__( 'Unsupported Gobear machine type "%s".', 'gobear-personalization' ),
					$machine_type
				)
			);
		}

		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new $class( $client );
		}

		return self::$instances[ $class ];
	}

	/**
	 * All machine types the plugin knows how to talk to, grouped by driver, for use in select
	 * fields ("Printer (GBK77 / GBK114)" vs "Desk Printer (K20)").
	 *
	 * @return array<string,string[]> Friendly label => machine type codes.
	 */
	public static function get_supported_families() {
		return array(
			__( 'Printer (GBK77 / GBK114)', 'gobear-personalization' ) => array( 'GBK77', 'GBK114' ),
			__( 'Desk Printer (K20)', 'gobear-personalization' )       => array( 'K20' ),
		);
	}
}
