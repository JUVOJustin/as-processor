<?php
/**
 * Base layer for custom DB tables.
 *
 * @package juvo/as-processor
 */

namespace juvo\AS_Processor\DB;

use wpdb;

/**
 * Abstract class Base_DB.
 * Serves as a base for database-related functionality,
 * providing a structure for interacting with custom database tables.
 */
abstract class Base_DB {

	/**
	 * WordPress database wrapper instance.
	 *
	 * @var \wpdb
	 */
	protected wpdb $db;

	/**
	 * Table name (defined by subclasses).
	 *
	 * @var string
	 */
	protected string $table_name;

	/**
	 * Whether the table is initialized.
	 *
	 * @var bool
	 */
	private bool $table_checked = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * Get the table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		return $this->db->prefix . $this->table_name;
	}

	/**
	 * Ensure the table exists.
	 *
	 * @return void
	 */
	public function ensure_table(): void {
		if ( ! $this->table_checked ) {
			$this->maybe_create_table();
			$this->table_checked = true;
		}
	}

	/**
	 * Create the database table if it does not exist.
	 * Must be implemented by subclasses.
	 *
	 * @return void
	 */
	abstract protected function maybe_create_table(): void;
}
