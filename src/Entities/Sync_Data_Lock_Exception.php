<?php
/**
 * Represents an exception that is thrown when a synchronization lock for data
 * cannot be acquired. This exception is typically used in scenarios where
 * concurrent processes attempt to access a shared resource that is already locked.
 *
 * @package juvo/as_processor
 */

namespace juvo\AS_Processor\Entities;

/**
 * Exception thrown when a data synchronization lock cannot be acquired,
 * indicating that the resource is currently locked or unavailable for processing.
 */
class Sync_Data_Lock_Exception extends \Exception {
}
