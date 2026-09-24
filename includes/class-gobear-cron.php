<?php
/**
 * Safety-net polling: if a dispatched job hasn't received a webhook update in a while (missed
 * delivery, webhook not yet configured on Gobear's side, etc.), periodically poll Gobear directly
 * for its current status so the fulfillment queue doesn't get stuck silently.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Cron {

	const CRON_HOOK = 'gobear_poll_stale_orders';

	public function init() {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::CRON_HOOK, array( $this, 'poll_stale_orders' ) );
	}

	public function add_schedule( $schedules ) {
		$schedules['gobear_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes (Gobear)', 'gobear-personalization' ),
		);
		return $schedules;
	}

	public function poll_stale_orders() {
		global $wpdb;
		$table    = $wpdb->prefix . 'gobear_fulfillments';
		$settings = Gobear_Settings::get_settings();
		$hours    = ! empty( $settings['stale_job_hours'] ) ? (int) $settings['stale_job_hours'] : 6;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE status IN ('sent','in_production')
				AND gobear_order_id IS NOT NULL
				AND updated_at < %s
				LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$threshold
			)
		);

		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			$driver = Gobear_Driver_Factory::create( $row->machine_type );
			if ( is_wp_error( $driver ) ) {
				continue;
			}

			$detail = $driver->get_order_detail( $row->gobear_order_id );
			if ( is_wp_error( $detail ) ) {
				// Touch updated_at so a persistently-erroring lookup doesn't get re-queried every
				// single cron tick; it will be retried again after the next stale threshold.
				$wpdb->update( $table, array( 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
				continue;
			}

			$mapped = Gobear_Fulfillment_Sync::map_order_detail_to_status( $detail );
			Gobear_Fulfillment_Sync::apply_status(
				$row,
				$mapped['status'],
				array(
					'error'     => $mapped['error'],
					'snapshots' => isset( $detail['snapshots'] ) ? $detail['snapshots'] : array(),
				)
			);
		}
	}
}
