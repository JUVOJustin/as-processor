<?php

namespace juvo\AS_Processor\Tests;

use juvo\AS_Processor\Helper;
use PHPUnit\Framework\TestCase;

class HelperTest extends TestCase {

	/**
	 * @dataProvider mergeArraysProvider
	 */
	public function test_merge_arrays( array $array1, array $array2, bool $deepMerge, bool $concatArrays, array $expected ): void {
		$result = Helper::merge_arrays( $array1, $array2, $deepMerge, $concatArrays );
		$this->assertEquals( $expected, $result );
	}

	public static function mergeArraysProvider(): array {
		return array(
			'Deep merge with concatenation'            => array(
				array(
					'indexed'     => array( 1, 2 ),
					'associative' => array(
						'a' => 1,
						'b' => array( 2, 3 ),
					),
				),
				array(
					'indexed'     => array( 3, 4 ),
					'associative' => array(
						'b' => array( 4, 5 ),
						'c' => 6,
					),
				),
				true,
				true,
				array(
					'indexed'     => array( 1, 2, 3, 4 ),
					'associative' => array(
						'a' => 1,
						'b' => array( 2, 3, 4, 5 ),
						'c' => 6,
					),
				),
			),
			'Deep merge without concatenation'         => array(
				array(
					'indexed'     => array( 1, 2 ),
					'associative' => array(
						'a' => 1,
						'b' => array( 2, 3 ),
					),
				),
				array(
					'indexed'     => array( 3, 4 ),
					'associative' => array(
						'b' => array( 4, 5 ),
						'c' => 6,
					),
				),
				true,
				false,
				array(
					'indexed'     => array( 3, 4 ),
					'associative' => array(
						'a' => 1,
						'b' => array( 4, 5 ),
						'c' => 6,
					),
				),
			),
			'Shallow merge with concatenation'         => array(
				array(
					'indexed'     => array( 1, 2 ),
					'associative' => array(
						'a' => 1,
						'b' => array( 2, 3 ),
					),
				),
				array(
					'indexed'     => array( 3, 4 ),
					'associative' => array(
						'b' => array( 4, 5 ),
						'c' => 6,
					),
				),
				false,
				true,
				array(
					'indexed'     => array( 1, 2, 3, 4 ),
					'associative' => array(
						'a' => 1,
						'b' => array( 4, 5 ),
						'c' => 6,
					),
				),
			),
			'Shallow merge without concatenation'      => array(
				array(
					'indexed'     => array( 1, 2 ),
					'associative' => array(
						'a' => 1,
						'b' => array( 2, 3 ),
					),
				),
				array(
					'indexed'     => array( 3, 4 ),
					'associative' => array(
						'b' => array( 4, 5 ),
						'c' => 6,
					),
				),
				false,
				false,
				array(
					'indexed'     => array( 3, 4 ),
					'associative' => array(
						'a' => 1,
						'b' => array( 4, 5 ),
						'c' => 6,
					),
				),
			),
			'Merge with empty arrays'                  => array(
				array(
					'indexed'     => array(),
					'associative' => array(),
				),
				array(
					'indexed'     => array( 1, 2 ),
					'associative' => array( 'a' => 1 ),
				),
				true,
				true,
				array(
					'indexed'     => array( 1, 2 ),
					'associative' => array( 'a' => 1 ),
				),
			),
			'Merge with null values'                   => array(
				array(
					'a' => null,
					'b' => array( 1, 2 ),
				),
				array(
					'a' => array( 3, 4 ),
					'b' => null,
				),
				true,
				true,
				array(
					'a' => array( 3, 4 ),
					'b' => null,
				),
			),
			'Merge with mixed types'                   => array(
				array(
					'a' => 1,
					'b' => array( 1, 2 ),
				),
				array(
					'a' => array( 3, 4 ),
					'b' => 2,
				),
				true,
				true,
				array(
					'a' => array( 3, 4 ),
					'b' => 2,
				),
			),
			'Flat array, merge'                        => array(
				array( 123, 1234, 12345 ),
				array( 456, 4567, 45678 ),
				true,
				false,
				array( 123, 1234, 12345, 456, 4567, 45678 ),
			),
			'Flat array, concat'                       => array(
				array( 123, 1234, 12345 ),
				array( 456, 4567, 45678 ),
				false,
				true,
				array( 123, 1234, 12345, 456, 4567, 45678 ),
			),
			'Flat array, merge and concat'             => array(
				array( 123, 1234, 12345 ),
				array( 456, 4567, 45678 ),
				true,
				true,
				array( 123, 1234, 12345, 456, 4567, 45678 ),
			),
			'Flat array none'                          => array(
				array( 123, 1234, 12345 ),
				array( 456, 4567, 45678 ),
				false,
				false,
				array( 123, 1234, 12345, 456, 4567, 45678 ),
			),
			'Flat array with custom numeric keys'      => array(
				array(
					100 => 'value1',
					200 => 'value2',
				),
				array(
					300 => 'value3',
					400 => 'value4',
				),
				false,
				false,
				array(
					100 => 'value1',
					200 => 'value2',
					300 => 'value3',
					400 => 'value4',
				),
			),
			'Flat array with overlapping custom numeric keys' => array(
				array(
					100 => 'value1',
					200 => 'value2',
				),
				array(
					200 => 'value2_updated',
					300 => 'value3',
				),
				true,
				false,
				array(
					100 => 'value1',
					200 => 'value2_updated',
					300 => 'value3',
				),
			),
			'Flat array mixed numeric and string keys' => array(
				array(
					100    => 'value1',
					'key1' => 'value2',
				),
				array(
					200    => 'value3',
					'key2' => 'value4',
				),
				false,
				false,
				array(
					100    => 'value1',
					'key1' => 'value2',
					200    => 'value3',
					'key2' => 'value4',
				),
			),
		);
	}

	public function test_is_indexed_array(): void {
		$indexedArray     = array( 1, 2, 3 );
		$associativeArray = array(
			'a' => 1,
			'b' => 2,
		);
		$emptyArray       = array();
		$mixedArray       = array(
			0 => 'a',
			2 => 'b',
			1 => 'c',
		);

		$this->assertTrue( Helper::is_indexed_array( $indexedArray ) );
		$this->assertFalse( Helper::is_indexed_array( $associativeArray ) );
		$this->assertTrue( Helper::is_indexed_array( $emptyArray ) );
		$this->assertFalse( Helper::is_indexed_array( $mixedArray ) );
	}
}
