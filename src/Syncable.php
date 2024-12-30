<?php
/**
 * Interface Syncable
 *
 * Defines the contract for objects that are capable of being synchronized.
 * Provides methods for retrieving the name of the sync operation and for scheduling
 * synchronization tasks.
 *
 * @package juvo/as-processor
 */

namespace juvo\AS_Processor;

interface Syncable {


	/**
	 * Returns the name of the sync
	 *
	 * @return string
	 */
	public function get_sync_name(): string;

	/**
	 * Contains the actual logic for the main task that should break the data into chunks.
	 * This function can ideally be hooked on "init"
	 *
	 * @return void
	 */
	public function schedule(): void;
}
