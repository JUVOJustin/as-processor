<?php

namespace juvo\AS_Processor\Tests;

use juvo\AS_Processor\Helper;
use PHPUnit\Framework\TestCase;

class HelperTest extends TestCase
{
    /**
     * @dataProvider mergeArraysProvider
     */
    public function test_merge_arrays(array $array1, array $array2, bool $deepMerge, bool $concatArrays, array $expected): void
    {
        $result = Helper::merge_arrays($array1, $array2, $deepMerge, $concatArrays);
        $this->assertEquals($expected, $result);
    }

    public static function mergeArraysProvider(): array
    {
        return [
            'Deep merge with concatenation' => [
                ['indexed' => [1, 2], 'associative' => ['a' => 1, 'b' => [2, 3]]],
                ['indexed' => [3, 4], 'associative' => ['b' => [4, 5], 'c' => 6]],
                true,
                true,
                ['indexed' => [1, 2, 3, 4], 'associative' => ['a' => 1, 'b' => [2, 3, 4, 5], 'c' => 6]]
            ],
            'Deep merge without concatenation' => [
                ['indexed' => [1, 2], 'associative' => ['a' => 1, 'b' => [2, 3]]],
                ['indexed' => [3, 4], 'associative' => ['b' => [4, 5], 'c' => 6]],
                true,
                false,
                ['indexed' => [3, 4], 'associative' => ['a' => 1, 'b' => [4, 5], 'c' => 6]]
            ],
            'Shallow merge with concatenation' => [
                ['indexed' => [1, 2], 'associative' => ['a' => 1, 'b' => [2, 3]]],
                ['indexed' => [3, 4], 'associative' => ['b' => [4, 5], 'c' => 6]],
                false,
                true,
                ['indexed' => [1, 2, 3, 4], 'associative' => ['a' => 1, 'b' => [4, 5], 'c' => 6]]
            ],
            'Shallow merge without concatenation' => [
                ['indexed' => [1, 2], 'associative' => ['a' => 1, 'b' => [2, 3]]],
                ['indexed' => [3, 4], 'associative' => ['b' => [4, 5], 'c' => 6]],
                false,
                false,
                ['indexed' => [3, 4], 'associative' => ['a' => 1, 'b' => [4, 5], 'c' => 6]]
            ],
            'Merge with empty arrays' => [
                ['indexed' => [], 'associative' => []],
                ['indexed' => [1, 2], 'associative' => ['a' => 1]],
                true,
                true,
                ['indexed' => [1, 2], 'associative' => ['a' => 1]]
            ],
            'Merge with null values' => [
                ['a' => null, 'b' => [1, 2]],
                ['a' => [3, 4], 'b' => null],
                true,
                true,
                ['a' => [3, 4], 'b' => null]
            ],
            'Merge with mixed types' => [
                ['a' => 1, 'b' => [1, 2]],
                ['a' => [3, 4], 'b' => 2],
                true,
                true,
                ['a' => [3, 4], 'b' => 2]
            ],
        ];
    }

    public function test_is_indexed_array(): void
    {
        $indexedArray = [1, 2, 3];
        $associativeArray = ['a' => 1, 'b' => 2];
        $emptyArray = [];
        $mixedArray = [0 => 'a', 2 => 'b', 1 => 'c'];

        $this->assertTrue(Helper::is_indexed_array($indexedArray));
        $this->assertFalse(Helper::is_indexed_array($associativeArray));
        $this->assertTrue(Helper::is_indexed_array($emptyArray));
        $this->assertFalse(Helper::is_indexed_array($mixedArray));
    }
}