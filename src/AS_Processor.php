<?php
/**
 * Registers shared AS Processor runtime hooks.
 *
 * @package juvo/as-processor
 */

namespace juvo\AS_Processor;

use juvo\AS_Processor\DB\Chunk_DB;
use juvo\AS_Processor\DB\Data_DB;

/**
 * Library bootstrap for hooks that must exist independently of sync instances.
 */
final class AS_Processor {

	/**
	 * Register shared runtime hooks.
	 *
	 * Implementing plugins should call this once during their bootstrap, after
	 * WordPress is loaded and before Action Scheduler runs queued actions.
	 * WordPress hook registrations are request-local, so this method must not
	 * skip registration based on a persisted option from a previous request.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( false === has_action( 'action_scheduler_ensure_recurring_actions', array( self::class, 'ensure_recurring_actions' ) ) ) {
			add_action( 'action_scheduler_ensure_recurring_actions', array( self::class, 'ensure_recurring_actions' ) );
		}

		if ( false === has_action( 'asp/cleanup', array( self::class, 'cleanup' ) ) ) {
			add_action( 'asp/cleanup', array( self::class, 'cleanup' ) );
		}
	}

	/**
	 * Ensure the library's recurring actions remain scheduled.
	 *
	 * @return void
	 */
	public static function ensure_recurring_actions(): void {
		if ( as_has_scheduled_action( 'asp/cleanup' ) ) {
			return;
		}

		// Run the cleanup at midnight every day.
		as_schedule_cron_action(
			time(),
			'0 0 * * *',
			'asp/cleanup'
		);
	}

	/**
	 * Remove expired chunk tracking rows and sync data.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		Chunk_DB::db()->cleanup();
		Data_DB::db()->delete_expired_data();
	}
}
