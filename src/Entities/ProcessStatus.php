<?php
/**
 * Process Status Entity
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor\Entities;

/**
 * Represents the possible states of a process.
 */
enum ProcessStatus: string
{
    case ALL = 'all';
    case SCHEDULED = 'scheduled';
    case STARTED = 'started';
    case RUNNING = 'running';
    case FINISHED = 'finished';
    case FAILED = 'failed';
	case CANCELLED = 'cancelled';
	case TIMED_OUT = 'timed-out';
}