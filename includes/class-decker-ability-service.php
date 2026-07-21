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
	 * Task access rules.
	 *
	 * @var Decker_Ability_Task_Access
	 */
	private $access;

	/**
	 * Read operations.
	 *
	 * @var Decker_Ability_Query_Service
	 */
	private $queries;

	/**
	 * Write operations.
	 *
	 * @var Decker_Ability_Command_Service
	 */
	private $commands;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$store     = new Decker_Ability_Task_Store();
		$validator = new Decker_Ability_Input_Validator();

		$this->access   = new Decker_Ability_Task_Access( $store );
		$this->queries  = new Decker_Ability_Query_Service( $store, $validator, $this->access );
		$this->commands = new Decker_Ability_Command_Service( $store, $validator );
	}

	/**
	 * Check permission to read task collections.
	 *
	 * @param array|null $input Optional ability input.
	 * @return bool|WP_Error True when permitted, otherwise an error.
	 */
	public function can_list_tasks( $input = null ) {
		return $this->access->can_list_tasks( $input );
	}

	/**
	 * Check permission to create tasks.
	 *
	 * @param array|null $input Optional ability input.
	 * @return bool|WP_Error True when permitted, otherwise an error.
	 */
	public function can_create_task( $input = null ) {
		return $this->access->can_create_task( $input );
	}

	/**
	 * Check permission to read one task.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error True when permitted, otherwise an error.
	 */
	public function can_read_task( $input ) {
		return $this->access->can_read_task( $input );
	}

	/**
	 * Check permission to edit one task.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error True when permitted, otherwise an error.
	 */
	public function can_edit_task( $input ) {
		return $this->access->can_edit_task( $input );
	}

	/**
	 * List visible tasks.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Task collection or error.
	 */
	public function list_tasks( $input ) {
		return $this->queries->list_tasks( $input );
	}

	/**
	 * Retrieve one task.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Task data or error.
	 */
	public function get_task( $input ) {
		return $this->queries->get_task( $input );
	}

	/**
	 * List available boards.
	 *
	 * @return array<string, mixed>|WP_Error Boards or error.
	 */
	public function list_boards() {
		return $this->queries->list_boards();
	}

	/**
	 * Search knowledge-base articles.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Articles or error.
	 */
	public function search_knowledge_base( $input ) {
		return $this->queries->search_knowledge_base( $input );
	}

	/**
	 * Create a task.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Created task or error.
	 */
	public function create_task( $input ) {
		return $this->commands->create_task( $input );
	}

	/**
	 * Update a task.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Updated task or error.
	 */
	public function update_task( $input ) {
		return $this->commands->update_task( $input );
	}

	/**
	 * Move a task.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Updated task or error.
	 */
	public function move_task( $input ) {
		return $this->commands->move_task( $input );
	}

	/**
	 * Archive or restore a task.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Updated task or error.
	 */
	public function archive_task( $input ) {
		return $this->commands->archive_task( $input );
	}
}
