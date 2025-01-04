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
	 * Singelton Instance of Database Class
	 *
	 * @var ?Base_DB
	 */
	protected static ?Base_DB $instance = null;

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
	 * Retrieve the singleton instance of the class, creating it if necessary.
	 *
	 * @return static The singleton instance of the class.
	 */
	public static function db(): static {
		if (!static::$instance instanceof static) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * Constructor.
	 */
	final private function __construct() {
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
