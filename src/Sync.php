<?php

namespace juvo\AS_Processor;

use ActionScheduler_Action;
use ActionScheduler_Store;
use Exception;

abstract class Sync implements Syncable
{

    use Sync_Data;
    use Chunker;

    const SERIALIZED_DELIMITER = "\n--END--\n";

    private string $sync_group_name;

    public function __construct() {
        $this->set_hooks();
    }

    /**
     * Used to add callbacks to hooks
     *
     * @return void
     */
    public function set_hooks(): void {
        add_action('action_scheduler_begin_execute', function(int $action_id) {
            $this->maybe_trigger_last_in_group($action_id);
        }, 10, 1);
        add_action( 'action_scheduler_before_execute', [$this, 'track_action_group'], 10, 2 );
        add_action('action_scheduler_completed_action', [$this, 'maybe_trigger_last_in_group']);
        add_action($this->get_sync_name() . '/process_chunk', [$this, 'process_chunk']);

        // If the child, has the callback, we hook it up
        if (method_exists($this, 'after_sync_complete')) {
            add_action($this->get_sync_name() . '/complete', [$this, 'after_sync_complete']);
        }

        // Hookup to error handling if on_fail is present in child
        add_action('action_scheduler_failed_execution', [$this, 'handle_exception'], 10, 2);
        if (method_exists($this, 'on_fail')) {
            add_action($this->get_sync_name() . '/fail', [$this, 'on_fail'], 10, 2);
        }
    }

    /**
     * Returns the name of the sync. The name must always be deterministic.
     *
     * @return string
     */
    abstract function get_sync_name(): string;

    /**
     * Callback for the Chunk jobs. The child implementation either dispatches to an import or an export
     *
     * @param string $chunk_file_path
     * @return void
     */
    abstract function process_chunk(string $chunk_file_path): void;

    /**
     * Returns the sync group name. If none set it will generate one from the sync name and the current time
     *
     * @return string
     */
    public function get_sync_group_name(): string
    {
        if (empty($this->sync_group_name)) {
            $this->sync_group_name = $this->get_sync_name() . '_'. time();
        }
        return $this->sync_group_name;
    }

    /**
     * Checks if there are more remaining jobs in the queue or if this is the last one.
     * This can be used to add additional cleanup jobs
     *
     * @param int $action_id
     * @return void
     */
    public function maybe_trigger_last_in_group(int $action_id): void
    {

        $action = $this->action_belongs_to_sync($action_id);
        if (!$action || empty($action->get_group())) {
            return;
        }

        $actions = as_get_scheduled_actions([
            'group' => $this->get_sync_group_name(),
            'status' => ActionScheduler_Store::STATUS_PENDING,
        ]);

        if (count($actions) === 0) {
            do_action($this->get_sync_name() . '/complete');
        }
    }

    /**
     * Callback for "action_scheduler_before_execute" hook. It gets the current action and (re)sets the group name.
     * This is needed to have a consistent group name through all executions of a group
     *
     * @param int $action_id
     * @param mixed $context
     * @return void
     */
    public function track_action_group(int $action_id, mixed $context) {
        $action = $this->action_belongs_to_sync($action_id);
        if (!$action || empty($action->get_group())) {
            return;
        }

        $this->sync_group_name = $action->get_group();
    }

    /**
     * Checks if the passed action belongs to the sync. If so returns the action object else false.
     *
     * @param int $action_id
     * @return false|ActionScheduler_Action
     */
    private function action_belongs_to_sync(int $action_id): false|ActionScheduler_Action
    {
        $action = ActionScheduler_Store::instance()->fetch_action((string) $action_id);

        // Action must contain the sync name as hook. Else it does not belong to sync
        if (!str_contains($action->get_hook(), $this->get_sync_name())) {
            return false;
        }

        // Set group name
        $this->sync_group_name = $action->get_group();

        return $action;
    }

    /**
     * Wraps the execution of the on_fail method, if it exists, for error handling.
     * Checks if the errored action belongs to the sync. If so passes the action instead of the action_id to be more flexible
     *
     * @param int $action_id The ID of the action
     * @param Exception $e The exception that was thrown
     * @return void
     */
    public function handle_exception(int $action_id, Exception $e): void
    {

        // Check if action belongs to sync
        $action = $this->action_belongs_to_sync($action_id);
        if (!$action || empty($action->get_group())) {
            return;
        }

        do_action($this->get_sync_name() . '/fail', $action, $e );
    }

}
