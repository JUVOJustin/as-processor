<?php
/**
 * Handles the database.
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor\DB;

/**
 * Class Data_DB
 *
 * This class provides an interface for interacting with a database table that stores data entries.
 * It supports operations such as table creation, data insertion, retrieval, deletion, and expiration management.
 */
class Data_DB extends Base_DB {


	/**
	 * The name of the table.
	 *
	 * @var string
	 */
	protected string $table_name = 'asp_data';

	/**
	 * Define the table schema and create the table if it doesn't exist.
	 *
	 * @return void
	 */
	protected function maybe_create_table(): void {
		$charset_collate = $this->db->get_charset_collate();
		$table_name      = $this->get_table_name();

		$sql = "CREATE TABLE {$table_name} (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `data` longtext NOT NULL,
            `expires` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, 
    		`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
        	UNIQUE KEY `unique_name` (`name`)
        ) {$charset_collate}";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Delete expired data entries.
	 *
	 * @return void
	 */
	public function delete_expired_data(): void {
		$table_name = $this->get_table_name();
		$this->db->query(
			$this->db->prepare( "DELETE FROM {$table_name} WHERE expires < %s", current_time( 'mysql' ) )
		);
	}

	/**
	 * Replace a record in the database with the provided name, value, and expiration timestamp.
	 *
	 * @param string $name The name of the record to replace.
	 * @param mixed  $value The value to associate with the record, which will be serialized before storing.
	 * @param int    $timestamp The number of seconds from now when the record should expire.
	 *
	 * @return \mysqli_result|bool|int|null The result of the database operation, which may vary depending on the database driver and context.
	 */
	public function replace( string $name, mixed $value, int $timestamp ): \mysqli_result|bool|int|null {
		// Serialize the data for the database
		$serialized_data = maybe_serialize( $value );

		// Expiration time
		$expires = gmdate( 'Y-m-d H:i:s', time() + $timestamp );

		// Insert or update the data in the database
		return $this->db->query(
			$this->db->prepare(
				"INSERT INTO {$this->get_table_name()} (`name`, `data`, `expires`) 
				 VALUES (%s, %s, %s)
				 ON DUPLICATE KEY UPDATE 
					`data` = VALUES(`data`), 
					`expires` = VALUES(`expires`)",
				$name,
				$serialized_data,
				$expires
			)
		);
	}

	/**
	 * Retrieve data from the database by name.
	 *
	 * @param string $name The name of the data to retrieve.
	 * @param string $column Optional. A specific column to return from the data row. Defaults to an empty string.
	 *
	 * @return mixed|false The data row as an associative array, a specific column's value if specified, or false if not found or expired.
	 */
	public function get( string $name, string $column = '' ): mixed {
		// Try to get the data from the database
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->get_table_name()} WHERE `name` = %s LIMIT 1",
				$name
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return false; // No data found
		}

		// Check expiration
		if ( strtotime( $row['expires'] ) < time() ) {
			$this->delete( $name );
			return false; // Data is expired
		}

		// Unserialize the data before returning
		$row['data'] = maybe_unserialize( $row['data'] );

		// Maybe only return the one key
		if ( $column ) {
			if ( ! empty( $row[ $column ] ) ) {
				return $row[ $column ];
			}
			return false;
		}

		return $row;
	}

	/**
	 * Delete a data entry based on the provided name.
	 *
	 * @param string $name The name of the entry to be deleted.
	 * @return \mysqli_result|bool|int|null The result of the delete operation, which may vary depending on the database implementation.
	 */
	public function delete( string $name ): \mysqli_result|bool|int|null {
		return $this->db->delete( $this->get_table_name(), array( 'name' => $name ) );
	}
}
