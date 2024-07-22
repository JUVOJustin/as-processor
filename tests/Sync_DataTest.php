<?php

namespace juvo\AS_Processor\Tests;

use juvo\AS_Processor\Sync_Data;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

class Sync_DataTest extends TestCase
{
    private $syncData;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->syncData = $this->getMockForTrait(Sync_Data::class);
    }

    /**
     * @dataProvider mergeArraysProvider
     */
    public function testMergeArrays(array $array1, array $array2, bool $deepMerge, bool $concatArrays, array $expected): void
    {
        $result = $this->invokeMethod($this->syncData, 'mergeArrays', [$array1, $array2, $deepMerge, $concatArrays]);
        $this->assertEquals($expected, $result);
    }

    public static function mergeArraysProvider(): array
    {
        return [
            'Deep merge with concatenation'       => [
                ['indexed' => [1, 2], 'associative' => ['a' => 1, 'b' => [2, 3]]],
                ['indexed' => [3, 4], 'associative' => ['b' => [4, 5], 'c' => 6]],
                true,
                true,
                ['indexed' => [1, 2, 3, 4], 'associative' => ['a' => 1, 'b' => [2, 3, 4, 5], 'c' => 6]]
            ],
            'Deep merge without concatenation'    => [
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
            'Merge with empty arrays'             => [
                ['indexed' => [], 'associative' => []],
                ['indexed' => [1, 2], 'associative' => ['a' => 1]],
                true,
                true,
                ['indexed' => [1, 2], 'associative' => ['a' => 1]]
            ],
            'Merge with null values'              => [
                ['a' => null, 'b' => [1, 2]],
                ['a' => [3, 4], 'b' => null],
                true,
                true,
                ['a' => [3, 4], 'b' => null]
            ],
            'Merge with mixed types'              => [
                ['a' => 1, 'b' => [1, 2]],
                ['a' => [3, 4], 'b' => 2],
                true,
                true,
                ['a' => [3, 4], 'b' => 2]
            ],
        ];
    }

    public function testIsIndexedArray(): void
    {
        $indexedArray = [1, 2, 3];
        $associativeArray = ['a' => 1, 'b' => 2];
        $emptyArray = [];
        $mixedArray = [0 => 'a', 2 => 'b', 1 => 'c'];

        $this->assertTrue($this->invokeMethod($this->syncData, 'isIndexedArray', [$indexedArray]));
        $this->assertFalse($this->invokeMethod($this->syncData, 'isIndexedArray', [$associativeArray]));
        $this->assertTrue($this->invokeMethod($this->syncData, 'isIndexedArray', [$emptyArray]));
        $this->assertFalse($this->invokeMethod($this->syncData, 'isIndexedArray', [$mixedArray]));
    }

    /**
     * @throws \ReflectionException
     */
    private function invokeMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}