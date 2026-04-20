<?php
/**
 * Creates runtime copies of import fixtures so source fixtures stay intact.
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo\Support;

use RuntimeException;

final class Demo_Fixture_Manager {

	private const RUNTIME_DIR = 'as-processor-demo-runtime';

	/**
	 * Create a writable runtime copy of a fixture file.
	 *
	 * @param string $filename Fixture filename.
	 * @return string
	 */
	public static function create_runtime_copy( string $filename ): string {
		$source_path = ASP_DEMO_DATA_DIR . $filename;

		if ( ! file_exists( $source_path ) ) {
			throw new RuntimeException( sprintf( 'Fixture not found: %s', $source_path ) );
		}

		$runtime_dir = self::get_runtime_dir();

		if ( ! wp_mkdir_p( $runtime_dir ) ) {
			throw new RuntimeException( sprintf( 'Could not create runtime directory: %s', $runtime_dir ) );
		}

		$target_path = trailingslashit( $runtime_dir ) . wp_generate_uuid4() . '-' . basename( $filename );

		if ( ! copy( $source_path, $target_path ) ) {
			throw new RuntimeException( sprintf( 'Could not create runtime copy: %s', $target_path ) );
		}

		return $target_path;
	}

	/**
	 * Remove any leftover runtime fixture copies from previous test runs.
	 */
	public static function cleanup_runtime_copies(): void {
		$runtime_dir = self::get_runtime_dir();

		if ( ! is_dir( $runtime_dir ) ) {
			return;
		}

		$entries = scandir( $runtime_dir );

		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = trailingslashit( $runtime_dir ) . $entry;

			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Return the upload-based runtime directory used for disposable fixtures.
	 */
	private static function get_runtime_dir(): string {
		$upload_dir = wp_get_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . self::RUNTIME_DIR;
	}
}
