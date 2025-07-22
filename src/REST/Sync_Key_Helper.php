<?php
/**
 * Helper class for encoding/decoding sync keys in REST API.
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor\REST;

/**
 * Class Sync_Key_Helper
 *
 * Handles encoding and decoding of sync keys that contain forward slashes
 * for safe use in REST API URLs and parameters.
 */
class Sync_Key_Helper {

	/**
	 * Encode a sync key for REST API usage.
	 *
	 * Converts forward slashes to "__" to make the key URL-safe.
	 *
	 * @param string $key The sync key to encode.
	 * @return string The encoded sync key.
	 */
	public static function encode( string $key ): string {
		return str_replace( '/', '__', $key );
	}

	/**
	 * Decode a sync key from REST API format.
	 *
	 * Converts "__" back to forward slashes.
	 *
	 * @param string $encoded_key The encoded sync key.
	 * @return string The decoded sync key.
	 */
	public static function decode( string $encoded_key ): string {
		return str_replace( '__', '/', $encoded_key );
	}
}