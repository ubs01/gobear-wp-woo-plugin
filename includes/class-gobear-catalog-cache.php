<?php
/**
 * Caches the (fairly large, slow-changing) phone/case catalog — doc 3.15 / 6.3 "Get all phone
 * configs and template files" — so the storefront design tool and admin screens don't hit the
 * Gobear API on every page load.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Catalog_Cache {

	const TRANSIENT_PREFIX = 'gobear_catalog_';

	/**
	 * Maps a specific machine type to the cache "family" its catalog is shared under, since
	 * GBK77 and GBK114 both go through the printer driver's endpoint.
	 */
	private static function get_family_key( $machine_type ) {
		$machine_type = strtoupper( $machine_type );
		if ( in_array( $machine_type, array( 'GBK77', 'GBK114' ), true ) ) {
			return 'printer';
		}
		if ( 'K20' === $machine_type ) {
			return 'deskprinter';
		}
		return null;
	}

	/**
	 * Returns the cached catalog for a machine type, fetching and caching it if needed.
	 *
	 * @param string $machine_type
	 * @param bool   $force_refresh
	 * @return array|WP_Error Raw data as returned by phone/getList (brand -> series -> models -> materials -> skus).
	 */
	public static function get_catalog( $machine_type, $force_refresh = false ) {
		$family = self::get_family_key( $machine_type );
		if ( ! $family ) {
			return new WP_Error( 'gobear_unsupported_machine_type', __( 'Unsupported machine type.', 'gobear-personalization' ) );
		}

		$transient_key = self::TRANSIENT_PREFIX . $family;

		if ( ! $force_refresh ) {
			$cached = get_transient( $transient_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$driver = Gobear_Driver_Factory::create( $machine_type );
		if ( is_wp_error( $driver ) ) {
			return $driver;
		}

		$settings = Gobear_Settings::get_settings();
		$merch_id = ( 'AGENT' === $settings['app_type'] && ! empty( $settings['merch_id'] ) ) ? $settings['merch_id'] : null;

		$catalog = $driver->get_phone_configs( $merch_id );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		$ttl = ! empty( $settings['catalog_cache_ttl'] ) ? (int) $settings['catalog_cache_ttl'] : HOUR_IN_SECONDS;
		set_transient( $transient_key, $catalog, $ttl );

		return $catalog;
	}

	/**
	 * Clears the cached catalog. Pass a machine type to clear just that family, or omit to clear
	 * both.
	 */
	public static function clear_cache( $machine_type = null ) {
		if ( $machine_type ) {
			$family = self::get_family_key( $machine_type );
			if ( $family ) {
				delete_transient( self::TRANSIENT_PREFIX . $family );
			}
			return;
		}
		delete_transient( self::TRANSIENT_PREFIX . 'printer' );
		delete_transient( self::TRANSIENT_PREFIX . 'deskprinter' );
	}

	/**
	 * Flattens the nested brand/series/model tree into a simple list of models, each carrying
	 * its brand/series context — convenient for populating a "choose your phone" dropdown.
	 *
	 * @param array $catalog Raw catalog as returned by get_catalog().
	 * @return array[] List of ['brand_id','brand_name','series_name','model_id','model_name','full_name','phone_image','materials'].
	 */
	public static function flatten_models( $catalog ) {
		$models = array();

		if ( ! is_array( $catalog ) ) {
			return $models;
		}

		foreach ( $catalog as $brand ) {
			if ( empty( $brand['series'] ) || ! is_array( $brand['series'] ) ) {
				continue;
			}
			foreach ( $brand['series'] as $series ) {
				if ( empty( $series['models'] ) || ! is_array( $series['models'] ) ) {
					continue;
				}
				foreach ( $series['models'] as $model ) {
					$models[] = array(
						'brand_id'    => isset( $brand['brand_id'] ) ? $brand['brand_id'] : null,
						'brand_name'  => isset( $brand['brand_name'] ) ? $brand['brand_name'] : '',
						'series_name' => isset( $series['series_name'] ) ? $series['series_name'] : '',
						'model_id'    => isset( $model['model_id'] ) ? $model['model_id'] : null,
						'model_name'  => isset( $model['model_name'] ) ? $model['model_name'] : '',
						'full_name'   => isset( $model['full_name'] ) ? $model['full_name'] : '',
						'phone_image' => isset( $model['phone_image'] ) ? $model['phone_image'] : '',
						'materials'   => isset( $model['materials'] ) ? $model['materials'] : array(),
					);
				}
			}
		}

		return $models;
	}

	/**
	 * Finds a single flattened model entry by model_id.
	 *
	 * @param array $catalog
	 * @param int   $model_id
	 * @return array|null
	 */
	public static function find_model( $catalog, $model_id ) {
		foreach ( self::flatten_models( $catalog ) as $model ) {
			if ( (int) $model['model_id'] === (int) $model_id ) {
				return $model;
			}
		}
		return null;
	}

	/**
	 * Finds a single sku entry (with its base_map) within a model's materials by sku_id.
	 *
	 * @param array $model Flattened model entry (see flatten_models()).
	 * @param int   $sku_id
	 * @return array|null
	 */
	public static function find_sku_in_model( $model, $sku_id ) {
		if ( empty( $model['materials'] ) ) {
			return null;
		}
		foreach ( $model['materials'] as $material ) {
			if ( empty( $material['skus'] ) ) {
				continue;
			}
			foreach ( $material['skus'] as $sku ) {
				if ( isset( $sku['sku_id'] ) && (int) $sku['sku_id'] === (int) $sku_id ) {
					$sku['material_id']   = isset( $material['material_id'] ) ? $material['material_id'] : null;
					$sku['material_name'] = isset( $material['material_name'] ) ? $material['material_name'] : '';
					return $sku;
				}
			}
		}
		return null;
	}
}
