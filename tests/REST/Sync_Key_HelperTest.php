<?php
/**
 * Tests for Sync_Key_Helper class.
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor\Tests\REST;

use juvo\AS_Processor\REST\Sync_Key_Helper;
use PHPUnit\Framework\TestCase;

/**
 * Class Sync_Key_HelperTest
 *
 * Tests the encoding and decoding of sync keys.
 */
class Sync_Key_HelperTest extends TestCase {

	/**
	 * Test encoding sync keys.
	 *
	 * @return void
	 */
	public function test_encode() {
		// Test simple key without slashes.
		$this->assertEquals( 'simple-key', Sync_Key_Helper::encode( 'simple-key' ) );

		// Test key with one slash.
		$this->assertEquals( 'prefix__suffix', Sync_Key_Helper::encode( 'prefix/suffix' ) );

		// Test key with multiple slashes.
		$this->assertEquals( 'level1__level2__level3', Sync_Key_Helper::encode( 'level1/level2/level3' ) );

		// Test key with consecutive slashes.
		$this->assertEquals( 'double____slash', Sync_Key_Helper::encode( 'double//slash' ) );
	}

	/**
	 * Test decoding sync keys.
	 *
	 * @return void
	 */
	public function test_decode() {
		// Test simple key without encoded parts.
		$this->assertEquals( 'simple-key', Sync_Key_Helper::decode( 'simple-key' ) );

		// Test key with one encoded slash.
		$this->assertEquals( 'prefix/suffix', Sync_Key_Helper::decode( 'prefix__suffix' ) );

		// Test key with multiple encoded slashes.
		$this->assertEquals( 'level1/level2/level3', Sync_Key_Helper::decode( 'level1__level2__level3' ) );

		// Test key with consecutive encoded slashes.
		$this->assertEquals( 'double//slash', Sync_Key_Helper::decode( 'double____slash' ) );
	}

	/**
	 * Test round-trip encoding and decoding.
	 *
	 * @return void
	 */
	public function test_round_trip() {
		$test_keys = array(
			'simple-key',
			'prefix/suffix',
			'level1/level2/level3',
			'mixed-key/with/slashes-and-dashes',
			'special/chars/@#$%',
		);

		foreach ( $test_keys as $key ) {
			$encoded = Sync_Key_Helper::encode( $key );
			$decoded = Sync_Key_Helper::decode( $encoded );
			$this->assertEquals( $key, $decoded, "Round-trip failed for key: $key" );
		}
	}
}