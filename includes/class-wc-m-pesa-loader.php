<?php
/**
 * Registers and stores all action and filter hooks for the plugin.
 *
 * By maintaining a register of all hooks, this class allows the plugin to
 * defer all hooks until they are needed at run-time, and to defer loading
 * of class files until they are needed.
 *
 * @package WC_MPesa
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects and fires all WordPress hooks for the plugin.
 */
class WC_MPesa_Loader {

	/** @var array<int, array<string, mixed>> Registered actions. */
	private array $actions = array();

	/** @var array<int, array<string, mixed>> Registered filters. */
	private array $filters = array();

	/**
	 * Add a new action to the collection to be registered with WordPress.
	 *
	 * @param string   $hook          The WordPress action hook name.
	 * @param object   $component     A reference to the object on which the action is defined.
	 * @param string   $callback      The method name on the component.
	 * @param int      $priority      Optional. Default 10.
	 * @param int      $accepted_args Optional. Default 1.
	 */
	public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Add a new filter to the collection to be registered with WordPress.
	 *
	 * @param string   $hook
	 * @param object   $component
	 * @param string   $callback
	 * @param int      $priority
	 * @param int      $accepted_args
	 */
	public function add_filter( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Utility method to add a hook to the given collection.
	 *
	 * @param array<int, array<string, mixed>> $hooks
	 * @param string $hook
	 * @param object $component
	 * @param string $callback
	 * @param int    $priority
	 * @param int    $accepted_args
	 * @return array<int, array<string, mixed>>
	 */
	private function add( array $hooks, string $hook, object $component, string $callback, int $priority, int $accepted_args ): array {
		$hooks[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return $hooks;
	}

	/**
	 * Register all collected actions and filters with WordPress.
	 */
	public function run(): void {
		foreach ( $this->filters as $hook ) {
			add_filter(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}

		foreach ( $this->actions as $hook ) {
			add_action(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}
	}
}
