<?php

namespace juvo\AS_Processor;

use ActionScheduler;
use ActionScheduler_Action;
use ActionScheduler_Store;
use Exception;

abstract class Sync implements Syncable, Stats_Saver
{

    use Sync_Data;
    use Chunker;

    const SERIALIZED_DELIMITER = "\n--END--\n";

    private string $sync_group_name;

    public function __construct()
    {
        $this->set_hooks();
    }

    /**
     * Used to add callbacks to hooks
     *
     * @return void
     */
    public function set_hooks(): void
    {
        add_action('action_scheduler_begin_execute', [$this, 'track_action_start'], 10, 2);
        add_action('action_scheduler_completed_action', [$this, 'maybe_trigger_last_in_group']);
        add_action($this->get_sync_name() . '/process_chunk', [$this, 'process_chunk']);

        // If the child, has the callback, we hook it up
        if (method_exists($this, 'after_sync_complete')) {
            add_action($this->get_sync_name() . '/complete', [$this, 'after_sync_complete']);
        }

        // Hookup to error handling if on_fail is present in child
        add_action('action_scheduler_failed_execution', [$this, 'handle_exception'], 10, 2);
        if (method_exists($this, 'on_fail')) {
            add_action($this->get_sync_name() . '/fail', [$this, 'on_fail'], 10, 3);
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
            $this->sync_group_name = $this->get_sync_name() . '_' . time();
        }
        return $this->sync_group_name;
    }

    /**
     * Query actions belonging to this specific group.
     *
     * @param array $status array of Stati to query. By default, queries all.
     * @param int $per_page number of action ids to receive.
     * @return int[]|false
     */
    protected function get_actions(array $status = [], int $per_page = 5): array|false
    {

        if (!ActionScheduler::is_initialized(__FUNCTION__)) {
            return false;
        }

        $store = ActionScheduler::store();
        $action_ids = $store->query_actions([
            'group'    => $this->get_sync_group_name(),
            'claimed'  => null,
            'status'   => $status,
            'per_page' => $per_page
        ]);

        return array_map(function($action_id) {
            return intval($action_id);
        }, $action_ids);
    }

    /**
     * Runs when an action is completed.
     *
     * Checks if there are more remaining jobs in the queue or if this is the last one.
     * This can be used to add additional cleanup jobs
     *
     * @param int $action_id
     * @return void
     * @throws Exception
     */
    public function maybe_trigger_last_in_group(int $action_id): void
    {

        $action = $this->action_belongs_to_sync($action_id);
        if (!$action || empty($action->get_group())) {
            return;
        }

        // avoid recoursion by not hooking a complete action while
        // in complete context
        if ($action->get_hook() == $this->get_sync_name() . '/complete') {
            return;
        }

        // Mark action as complete
        $this->get_stats()->end_action($action_id);

        // Check if action of the same group is running or pending
        $actions = $this->get_actions(status: [ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING], per_page: 1);
        if (count($actions) === 0) {

            // Mark sync as complete
            $this->get_stats()->end_sync();

            as_enqueue_async_action(
                $this->get_sync_name() . '/complete',
                [], // empty arguments array
                $this->get_sync_group_name()
            );
        }
    }

    /**
     * Runs when an action is started.
     *
     * Callback for "action_scheduler_before_execute" hook. It gets the current action and (re)sets the group name.
     * This is needed to have a consistent group name through all executions of a group
     *
     * @param int $action_id
     * @param mixed $context
     * @return void
     * @throws Exception
     */
    public function track_action_start(int $action_id, mixed $context)
    {
        $action = $this->action_belongs_to_sync($action_id);
        if (!$action || empty($action->get_group())) {
            return;
        }

        $this->sync_group_name = $action->get_group();

        // Track action start if it is not the complete action
        if (!str_contains($action->get_hook(), "/complete")) {
            $this->get_stats()->add_action($action_id);
        }
    }

    /**
     * Checks if the passed action belongs to the sync. If so returns the action object else false.
     *
     * @param int $action_id
     * @return false|ActionScheduler_Action
     */
    private function action_belongs_to_sync(int $action_id): false|ActionScheduler_Action
    {
        $action = ActionScheduler_Store::instance()->fetch_action((string)$action_id);

        // Action must contain the sync name as hook. Else it does not belong to sync
        if (!str_contains($action->get_hook(), $this->get_sync_name())) {
            return false;
        }

        return $action;
    }

    /**
     * Wraps the execution of the on_fail method, if it exists, for error handling.
     * Checks if the errored action belongs to the sync. If so passes the action instead of the action_id to be more flexible
     *
     * @param int $action_id The ID of the action
     * @param Exception $e The exception that was thrown
     * @return void
     * @throws Exception
     */
    public function handle_exception(int $action_id, Exception $e): void
    {

        // Check if action belongs to sync
        $action = $this->action_belongs_to_sync($action_id);
        if (!$action || empty($action->get_group())) {
            return;
        }

        // Update stats
        $this->get_stats()->mark_action_as_failed($action_id, $e->getMessage());

        do_action($this->get_sync_name() . '/fail', $action, $e, $action_id);
    }

    /**
     * Returns the stats object and pass this instance as saver.
     *
     * @throws Exception
     */
    public function get_stats(): Stats
    {
        return $this->get_sync_data('stats') ?: new Stats($this);
    }

    /**
     * @throws Exception
     */
    public function save_stats(Stats $stats): void
    {
        $this->update_sync_data(['stats' => $stats], true, true);
    }

}
