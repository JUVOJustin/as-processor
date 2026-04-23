<?php

namespace juvo\AS_Processor\Tests;

use PHPUnit\Framework\TestCase;
use juvo\AS_Processor\Sync_Data;

class SyncDataTest extends TestCase
{
	use Sync_Data;

	/**
	 * Test that short lock keys are returned unchanged
	 */
	public function test_normalize_lock_key_short_name(): void
	{
		$short_key = 'test_sync_post_ids_lock';
		$normalized = $this->normalize_lock_key($short_key);
		
		$this->assertEquals($short_key, $normalized);
		$this->assertLessThanOrEqual(64, strlen($normalized));
	}

	/**
	 * Test that lock keys exactly at 64 characters are returned unchanged
	 */
	public function test_normalize_lock_key_exactly_64_chars(): void
	{
		$key_64 = str_repeat('a', 64);
		$normalized = $this->normalize_lock_key($key_64);
		
		$this->assertEquals($key_64, $normalized);
		$this->assertEquals(64, strlen($normalized));
	}

	/**
	 * Test that lock keys over 64 characters are hashed
	 */
	public function test_normalize_lock_key_long_name(): void
	{
		$long_key = 'autoscout_ch_switzerland_dealer_network/vehicles_import_post_ids_lock';
		$normalized = $this->normalize_lock_key($long_key);
		
		$this->assertNotEquals($long_key, $normalized);
		$this->assertLessThanOrEqual(64, strlen($normalized));
		$this->assertStringStartsWith('asp_lock_', $normalized);
		$this->assertEquals(41, strlen($normalized)); // 'asp_lock_' (9) + MD5 hash (32) = 41
	}

	/**
	 * Test that the hash is consistent for the same input
	 */
	public function test_normalize_lock_key_consistent_hashing(): void
	{
		$long_key = 'very_long_sync_name_that_will_definitely_exceed_the_limit_with_underscores';
		$normalized1 = $this->normalize_lock_key($long_key);
		$normalized2 = $this->normalize_lock_key($long_key);
		
		$this->assertEquals($normalized1, $normalized2);
	}

	/**
	 * Test that different long keys produce different hashes
	 */
	public function test_normalize_lock_key_different_hashes(): void
	{
		$long_key1 = 'autoscout_ch_switzerland_dealer_network/vehicles_import_post_ids_lock';
		$long_key2 = 'autoscout_de_germany_dealer_network/vehicles_import_post_ids_lock';
		
		$normalized1 = $this->normalize_lock_key($long_key1);
		$normalized2 = $this->normalize_lock_key($long_key2);
		
		$this->assertNotEquals($normalized1, $normalized2);
	}

	/**
	 * Test that keys at 65 characters (just over the limit) are hashed
	 */
	public function test_normalize_lock_key_65_chars(): void
	{
		$key_65 = str_repeat('a', 65);
		$normalized = $this->normalize_lock_key($key_65);
		
		$this->assertNotEquals($key_65, $normalized);
		$this->assertLessThanOrEqual(64, strlen($normalized));
		$this->assertEquals(41, strlen($normalized));
	}

	/**
	 * Test real-world sync name patterns
	 */
	public function test_normalize_lock_key_real_world_patterns(): void
	{
		$test_cases = [
			'autoscout_ch/vehicles_post_ids_lock' => false, // Should not be hashed (35 chars)
			'autoscout_ch/vehicles_seller_1234_lock' => false, // Should not be hashed (42 chars)
			'my_very_long_sync_process_name_that_exceeds_the_mysql_limits_post_ids_lock' => true, // Should be hashed (77 chars)
		];

		foreach ($test_cases as $lock_key => $should_be_hashed) {
			$normalized = $this->normalize_lock_key($lock_key);
			
			if ($should_be_hashed) {
				$this->assertNotEquals($lock_key, $normalized, "Key '$lock_key' should be hashed");
				$this->assertStringStartsWith('asp_lock_', $normalized);
			} else {
				$this->assertEquals($lock_key, $normalized, "Key '$lock_key' should NOT be hashed");
			}
			
			$this->assertLessThanOrEqual(64, strlen($normalized));
		}
	}
}
