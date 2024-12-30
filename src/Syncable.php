<?php

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
	function schedule(): void;
}
