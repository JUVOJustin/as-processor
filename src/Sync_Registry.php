<?php
/**
 * Registry for managing all Sync implementations.
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor;

use ActionScheduler_Store;

/**
 * Class Sync_Registry
 *
 * Singleton registry for managing all Sync implementations.
 * Provides methods to register, retrieve, and create sync instances.
 */
class Sync_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var Sync_Registry|null
	 */
	private static ?Sync_Registry $instance = null;

	/**
	 * Registered sync classes.
	 *
	 * @var array<string, string>
	 */
	private array $syncs = array();

	/**
	 * Get singleton instance.
	 *
	 * @return Sync_Registry
	 */
	public static function instance(): Sync_Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a sync instance.
	 *
	 * @param Sync $sync Sync instance to register.
	 * @return void
	 */
	public function register( Sync $sync ): void {
		$key                 = $sync->get_sync_name();
		$this->syncs[ $key ] = get_class( $sync );
	}

	/**
	 * Get all registered syncs.
	 *
	 * @return array<string, string> Array of sync key => class name.
	 */
	public function get_all(): array {
		return $this->syncs;
	}

	/**
	 * Get sync class name by key.
	 *
	 * @param string $key Sync key.
	 * @return string|null Class name or null if not found.
	 */
	public function get_sync_class( string $key ): ?string {
		return $this->syncs[ $key ] ?? null;
	}

	/**
	 * Create sync instance by key.
	 *
	 * @param string $key Sync key.
	 * @return Sync|null Sync instance or null if not found.
	 */
	public function create_sync( string $key ): ?Sync {
		$class_name = $this->get_sync_class( $key );
		return $class_name ? new $class_name() : null;
	}

	/**
	 * Get sync information by key.
	 *
	 * @param string $key Sync key.
	 * @return array<string, mixed>|null Sync information or null if not found.
	 */
	public function get_sync_info( string $key ): ?array {
		$sync = $this->create_sync( $key );
		if ( ! $sync ) {
			return null;
		}

		return array(
			'key'         => $key,
			'name'        => $sync->get_display_name(),
			'description' => $sync->get_description(),
			'type'        => $this->get_sync_type( $sync ),
			'group'       => $sync->get_sync_group_name(),
		);
	}

	/**
	 * Determine sync type.
	 *
	 * @param Sync $sync Sync instance.
	 * @return string Sync type.
	 */
	private function get_sync_type( Sync $sync ): string {
		if ( $sync instanceof Import ) {
			return 'import';
		}
		// Add other types as needed (Export, etc.)
		return 'sync';
	}
}
