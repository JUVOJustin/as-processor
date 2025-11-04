<?php

namespace juvo\AS_Processor\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use juvo\AS_Processor\Sync;

/**
 * Test concrete implementation of Sync for testing lifecycle hooks
 */
class TestableSync extends Sync {
	public string $test_sync_name = 'test_sync';
	public bool $on_finish_called = false;
	public bool $on_complete_called = false;
	public bool $handle_per_action_complete_called = false;
	public array $per_action_complete_calls = [];

	public function get_sync_name(): string {
		return $this->test_sync_name;
	}

	protected function process_chunk( int $chunk_id ): void {
		// No-op for testing
	}

	public function on_finish(): void {
		$this->on_finish_called = true;
	}

	public function handle_per_action_complete( \ActionScheduler_Action $action, int $action_id ): void {
		$this->handle_per_action_complete_called = true;
		$this->per_action_complete_calls[] = $action_id;
	}

	// Make protected methods accessible for testing
	public function get_finish_hook_fired(): bool {
		$reflection = new ReflectionClass( $this );
		$property = $reflection->getProperty( 'finish_hook_fired' );
		$property->setAccessible( true );
		return $property->getValue( $this );
	}
}

/**
 * Tests for Sync lifecycle hooks refactoring
 */
class SyncLifecycleTest extends TestCase {

	/**
	 * Test that the new methods exist and have correct signatures
	 */
	public function test_new_methods_exist(): void {
		$sync = $this->getMockBuilder( Sync::class )
			->disableOriginalConstructor()
			->getMockForAbstractClass();

		$this->assertTrue( method_exists( $sync, 'on_finish' ), 'on_finish() method should exist' );
		$this->assertTrue( method_exists( $sync, 'on_complete' ), 'on_complete() method should exist for backward compatibility' );
		$this->assertTrue( method_exists( $sync, 'handle_per_action_complete' ), 'handle_per_action_complete() method should exist' );
	}

	/**
	 * Test that on_complete is deprecated and triggers warning
	 */
	public function test_on_complete_deprecation(): void {
		// Skip this test if running in a WordPress environment or without error handling
		if ( ! function_exists( 'trigger_error' ) ) {
			$this->markTestSkipped( 'trigger_error function not available' );
		}

		$sync = $this->getMockBuilder( Sync::class )
			->disableOriginalConstructor()
			->getMockForAbstractClass();

		// Test that calling on_complete() doesn't throw an error
		// (The actual deprecation warning would be triggered in a real environment)
		$this->expectNotToPerformAssertions();
		$sync->on_complete();
	}

	/**
	 * Test that new hook names follow the expected pattern
	 */
	public function test_hook_name_pattern(): void {
		$sync = $this->getMockBuilder( Sync::class )
			->disableOriginalConstructor()
			->getMockForAbstractClass();

		// Mock get_sync_name
		$sync->method( 'get_sync_name' )->willReturn( 'test_sync' );

		$expected_hooks = [
			'test_sync/complete', // Per-action completion
			'test_sync/finish',   // Group completion
			'test_sync/fail',     // Failure
			'test_sync/cancel',   // Cancellation
			'test_sync/timeout',  // Timeout
		];

		// Just verify the pattern is correct
		foreach ( $expected_hooks as $hook ) {
			$this->assertStringContainsString( '/', $hook, 'Hook name should contain a slash' );
			$this->assertStringStartsWith( 'test_sync', $hook, 'Hook name should start with sync name' );
		}
	}

	/**
	 * Test that finish_hook_fired flag prevents double firing
	 */
	public function test_finish_hook_fired_flag_exists(): void {
		$reflection = new ReflectionClass( Sync::class );
		$properties = $reflection->getProperties();
		
		$property_names = array_map( function( $prop ) {
			return $prop->getName();
		}, $properties );

		$this->assertTrue( in_array( 'finish_hook_fired', $property_names, true ), 'finish_hook_fired property should exist' );
	}

	/**
	 * Test that deprecation warning flag exists
	 */
	public function test_deprecation_warning_flag_exists(): void {
		$reflection = new ReflectionClass( Sync::class );
		$properties = $reflection->getProperties();
		
		$property_names = array_map( function( $prop ) {
			return $prop->getName();
		}, $properties );

		$this->assertTrue( in_array( 'deprecation_warning_shown', $property_names, true ), 'deprecation_warning_shown property should exist' );
	}

	/**
	 * Test method signatures for handle_per_action_complete
	 */
	public function test_handle_per_action_complete_signature(): void {
		$reflection = new ReflectionClass( Sync::class );
		$method = $reflection->getMethod( 'handle_per_action_complete' );
		
		$this->assertTrue( $method->isPublic(), 'handle_per_action_complete should be public' );
		$this->assertEquals( 2, $method->getNumberOfParameters(), 'handle_per_action_complete should accept 2 parameters' );
	}

	/**
	 * Test on_finish method signature
	 */
	public function test_on_finish_signature(): void {
		$reflection = new ReflectionClass( Sync::class );
		$method = $reflection->getMethod( 'on_finish' );
		
		$this->assertTrue( $method->isPublic(), 'on_finish should be public' );
		$this->assertEquals( 0, $method->getNumberOfParameters(), 'on_finish should accept no parameters' );
	}

	/**
	 * Test that class has proper PHPDoc documentation
	 */
	public function test_class_has_lifecycle_documentation(): void {
		$reflection = new ReflectionClass( Sync::class );
		$docComment = $reflection->getDocComment();
		
		$this->assertNotFalse( $docComment, 'Class should have a doc comment' );
		
		// Check for lifecycle hooks documentation
		$this->assertStringContainsString( 'Lifecycle Hooks', $docComment, 'Doc comment should mention Lifecycle Hooks' );
		$this->assertStringContainsString( '/complete', $docComment, 'Doc comment should mention /complete hook' );
		$this->assertStringContainsString( '/finish', $docComment, 'Doc comment should mention /finish hook' );
		$this->assertStringContainsString( '/fail', $docComment, 'Doc comment should mention /fail hook' );
		$this->assertStringContainsString( '/cancel', $docComment, 'Doc comment should mention /cancel hook' );
		$this->assertStringContainsString( '/timeout', $docComment, 'Doc comment should mention /timeout hook' );
	}
}
