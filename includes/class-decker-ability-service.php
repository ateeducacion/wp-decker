<?php
/**
 * Ability execution facade for Decker.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'class-decker-ability-task-store.php';
require_once plugin_dir_path( __FILE__ ) . 'class-decker-ability-input-validator.php';
require_once plugin_dir_path( __FILE__ ) . 'class-decker-ability-task-access.php';
require_once plugin_dir_path( __FILE__ ) . 'class-decker-ability-query-service.php';
require_once plugin_dir_path( __FILE__ ) . 'class-decker-ability-command-service.php';

/**
 * Dispatches ability callbacks to focused query, command, and access services.
 */
class Decker_Ability_Service {

	/**
	 * Registered callbacks keyed by legacy method name.
	 *
	 * @var array<string, callable>
	 */
	private $callbacks;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$store     = new Decker_Ability_Task_Store();
		$validator = new Decker_Ability_Input_Validator();
		$access    = new Decker_Ability_Task_Access( $store );
		$queries   = new Decker_Ability_Query_Service( $store, $validator );
		$commands  = new Decker_Ability_Command_Service( $store, $validator );

		$this->callbacks = array(
			'can_list_tasks'        => array( $access, 'can_list_tasks' ),
			'can_create_task'       => array( $access, 'can_create_task' ),
			'can_read_task'         => array( $access, 'can_read_task' ),
			'can_edit_task'         => array( $access, 'can_edit_task' ),
			'list_tasks'            => array( $queries, 'list_tasks' ),
			'get_task'              => array( $queries, 'get_task' ),
			'list_boards'           => array( $queries, 'list_boards' ),
			'search_knowledge_base' => array( $queries, 'search_knowledge_base' ),
			'create_task'           => array( $commands, 'create_task' ),
			'update_task'           => array( $commands, 'update_task' ),
			'move_task'             => array( $commands, 'move_task' ),
			'archive_task'          => array( $commands, 'archive_task' ),
		);
	}

	/**
	 * Preserve the original callback surface while delegating implementation.
	 *
	 * @param string $name      Requested callback name.
	 * @param array  $arguments Callback arguments.
	 * @return mixed Callback result.
	 * @throws BadMethodCallException When the callback name is unknown.
	 */
	public function __call( string $name, array $arguments ) {
		if ( ! isset( $this->callbacks[ $name ] ) ) {
			throw new BadMethodCallException( 'Unknown Decker ability callback: ' . esc_html( $name ) );
		}

		return call_user_func_array( $this->callbacks[ $name ], $arguments );
	}
}
