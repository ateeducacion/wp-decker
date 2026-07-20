<?php
/**
 * WordPress Abilities API integration for Decker.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers discoverable, permission-aware Decker abilities.
 */
class Decker_Abilities {

	/**
	 * Ability category.
	 *
	 * @var string
	 */
	private const CATEGORY = 'decker';

	/**
	 * Supported stacks.
	 *
	 * @var string[]
	 */
	private const STACKS = array( 'to-do', 'in-progress', 'done' );

	/**
	 * Ability execution service.
	 *
	 * @var Decker_Ability_Service
	 */
	private $service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->service = new Decker_Ability_Service();

		if ( ! self::is_available() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Check whether the Abilities API is available.
	 *
	 * @return bool True when registration functions exist.
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_register_ability_category' ) && function_exists( 'wp_register_ability' );
	}

	/**
	 * Register the Decker category.
	 *
	 * @return void
	 */
	public function register_category(): void {
		if ( ! self::is_available() ) {
			return;
		}
		$has_category = 'wp_has_ability_category';
		if ( function_exists( $has_category ) && $has_category( self::CATEGORY ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Decker',
				'description' => 'Permission-aware abilities for Decker task, board, and knowledge-base operations.',
			)
		);
	}

	/**
	 * Register verified Decker abilities.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! self::is_available() ) {
			return;
		}

		foreach ( $this->get_ability_definitions() as $name => $definition ) {
			$has_ability = 'wp_has_ability';
			if ( function_exists( $has_ability ) && $has_ability( $name ) ) {
				continue;
			}
			wp_register_ability( $name, $definition );
		}
	}

	/**
	 * Get ability definitions.
	 *
	 * @return array<string, array<string, mixed>> Ability definitions.
	 */
	public function get_ability_definitions(): array {
		return array(
			'decker/list-tasks'            => $this->definition( 'List Decker tasks', 'Lists visible Decker tasks with optional search, board, stack, status, and pagination filters.', $this->list_tasks_input_schema(), $this->task_collection_schema(), 'list_tasks', 'can_list_tasks', true, true ),
			'decker/get-task'              => $this->definition( 'Get a Decker task', 'Retrieves one visible Decker task by ID.', $this->task_id_schema(), $this->task_schema(), 'get_task', 'can_read_task', true, true ),
			'decker/create-task'           => $this->definition( 'Create a Decker task', 'Creates a task through the existing Decker task domain method.', $this->task_write_schema( false ), $this->task_schema(), 'create_task', 'can_create_task', false, false ),
			'decker/update-task'           => $this->definition( 'Update a Decker task', 'Updates selected fields while preserving unspecified values and edit locks.', $this->task_write_schema( true ), $this->task_schema(), 'update_task', 'can_edit_task', false, true ),
			'decker/move-task'             => $this->definition( 'Move a Decker task', 'Moves a task to another stack or board through existing domain behavior.', $this->move_task_schema(), $this->task_schema(), 'move_task', 'can_edit_task', false, true ),
			'decker/archive-task'          => $this->definition( 'Archive or restore a Decker task', 'Archives or restores a task using an explicit, reversible archived state.', $this->archive_task_schema(), $this->task_schema(), 'archive_task', 'can_edit_task', false, true ),
			'decker/list-boards'           => $this->definition( 'List Decker boards', 'Lists boards that can be used to filter or create tasks.', null, $this->boards_schema(), 'list_boards', 'can_list_tasks', true, true ),
			'decker/search-knowledge-base' => $this->definition( 'Search the Decker knowledge base', 'Searches accessible knowledge-base articles by text and optional board.', $this->knowledge_base_input_schema(), $this->knowledge_base_output_schema(), 'search_knowledge_base', 'can_list_tasks', true, true ),
		);
	}

	/**
	 * Build an ability definition.
	 *
	 * @param string     $label             Label.
	 * @param string     $description       Description.
	 * @param array|null $input_schema      Input schema.
	 * @param array      $output_schema     Output schema.
	 * @param string     $execute_method    Execution method.
	 * @param string     $permission_method Permission method.
	 * @param bool       $readonly          Whether the ability is read-only.
	 * @param bool       $idempotent        Whether repeated execution is idempotent.
	 * @return array<string, mixed> Ability definition.
	 */
	private function definition( string $label, string $description, $input_schema, array $output_schema, string $execute_method, string $permission_method, bool $readonly, bool $idempotent ): array {
		$definition = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'output_schema'       => $output_schema,
			'execute_callback'    => array( $this->service, $execute_method ),
			'permission_callback' => array( $this->service, $permission_method ),
			'meta'                => array(
				'annotations' => array(
					'readonly'    => $readonly,
					'destructive' => false,
					'idempotent'  => $idempotent,
				),
			),
		);

		if ( null !== $input_schema ) {
			$definition['input_schema'] = $input_schema;
		}

		return $definition;
	}

	/**
	 * Get the list-tasks input schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function list_tasks_input_schema(): array {
		return $this->object_schema(
			array(
				'search'         => array(
					'type' => 'string',
					'maxLength' => 200,
				),
				'board_id'       => $this->positive_integer_schema(),
				'stack'          => array(
					'type' => 'string',
					'enum' => self::STACKS,
				),
				'status'         => array(
					'type' => 'string',
					'enum' => array( 'publish', 'archived' ),
					'default' => 'publish',
				),
				'page'           => array(
					'type' => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'       => array(
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
				'include_hidden' => array(
					'type' => 'boolean',
					'default' => false,
				),
			)
		);
	}

	/**
	 * Get a task ID input schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function task_id_schema(): array {
		return $this->object_schema( array( 'task_id' => $this->positive_integer_schema() ), array( 'task_id' ) );
	}

	/**
	 * Get create or update input schema.
	 *
	 * @param bool $update Whether the schema is for an update.
	 * @return array<string, mixed> Schema.
	 */
	private function task_write_schema( bool $update ): array {
		$properties = array(
			'task_id'             => $this->positive_integer_schema(),
			'title'               => array(
				'type' => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'description'         => array(
				'type' => 'string',
				'maxLength' => 100000,
			),
			'stack'               => array(
				'type' => 'string',
				'enum' => self::STACKS,
				'default' => 'to-do',
			),
			'board_id'            => $this->positive_integer_schema(),
			'max_priority'        => array( 'type' => 'boolean' ),
			'due_date'            => array(
				'type' => 'string',
				'pattern' => '^$|^\\d{4}-\\d{2}-\\d{2}$',
				'maxLength' => 10,
			),
			'author_id'           => $this->positive_integer_schema(),
			'responsible_user_id' => $this->positive_integer_schema(),
			'hidden'              => array( 'type' => 'boolean' ),
			'assignee_ids'        => $this->id_array_schema(),
			'label_ids'           => $this->id_array_schema(),
		);

		if ( $update ) {
			$schema                  = $this->object_schema( $properties, array( 'task_id' ) );
			$schema['minProperties'] = 2;
			return $schema;
		}

		unset( $properties['task_id'] );
		return $this->object_schema( $properties, array( 'title', 'board_id' ) );
	}

	/**
	 * Get move-task input schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function move_task_schema(): array {
		$schema = $this->object_schema(
			array(
				'task_id'  => $this->positive_integer_schema(),
				'stack'    => array(
					'type' => 'string',
					'enum' => self::STACKS,
				),
				'board_id' => $this->positive_integer_schema(),
			),
			array( 'task_id' )
		);
		$schema['minProperties'] = 2;
		return $schema;
	}

	/**
	 * Get archive-task input schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function archive_task_schema(): array {
		return $this->object_schema(
			array(
				'task_id'  => $this->positive_integer_schema(),
				'archived' => array( 'type' => 'boolean' ),
			),
			array( 'task_id', 'archived' )
		);
	}

	/**
	 * Get knowledge-base input schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function knowledge_base_input_schema(): array {
		return $this->object_schema(
			array(
				'search'   => array(
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'board_id' => $this->positive_integer_schema(),
				'limit'    => array(
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 10,
				),
			),
			array( 'search' )
		);
	}

	/**
	 * Get task collection output schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function task_collection_schema(): array {
		return $this->object_schema(
			array(
				'tasks'       => array(
					'type' => 'array',
					'items' => $this->task_schema(),
				),
				'page'        => $this->positive_integer_schema(),
				'per_page'    => $this->positive_integer_schema(),
				'total'       => array(
					'type' => 'integer',
					'minimum' => 0,
				),
				'total_pages' => array(
					'type' => 'integer',
					'minimum' => 0,
				),
			),
			array( 'tasks', 'page', 'per_page', 'total', 'total_pages' )
		);
	}

	/**
	 * Get task output schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function task_schema(): array {
		$properties = array(
			'id'                  => $this->positive_integer_schema(),
			'title'               => array( 'type' => 'string' ),
			'description'         => array( 'type' => 'string' ),
			'status'              => array(
				'type' => 'string',
				'enum' => array( 'publish', 'archived' ),
			),
			'stack'               => array(
				'type' => 'string',
				'enum' => self::STACKS,
			),
			'board_id'            => array( 'type' => 'integer' ),
			'label_ids'           => $this->id_array_schema(),
			'assignee_ids'        => $this->id_array_schema(),
			'author_id'           => $this->positive_integer_schema(),
			'responsible_user_id' => $this->positive_integer_schema(),
			'due_date'            => array( 'type' => 'string' ),
			'max_priority'        => array( 'type' => 'boolean' ),
			'hidden'              => array( 'type' => 'boolean' ),
			'order'               => array( 'type' => 'integer' ),
			'modified'            => array( 'type' => 'string' ),
		);

		return $this->object_schema( $properties, array_keys( $properties ) );
	}

	/**
	 * Get boards output schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function boards_schema(): array {
		$board = $this->object_schema(
			array(
				'id'          => $this->positive_integer_schema(),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
			),
			array( 'id', 'name', 'slug', 'description' )
		);

		return $this->object_schema(
			array(
				'boards' => array(
					'type' => 'array',
					'items' => $board,
				),
			),
			array( 'boards' )
		);
	}

	/**
	 * Get knowledge-base output schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function knowledge_base_output_schema(): array {
		$article = $this->object_schema(
			array(
				'id'       => $this->positive_integer_schema(),
				'title'    => array( 'type' => 'string' ),
				'content'  => array( 'type' => 'string' ),
				'board_id' => array( 'type' => 'integer' ),
				'modified' => array( 'type' => 'string' ),
			),
			array( 'id', 'title', 'content', 'board_id', 'modified' )
		);

		return $this->object_schema(
			array(
				'articles' => array(
					'type' => 'array',
					'items' => $article,
				),
			),
			array( 'articles' )
		);
	}

	/**
	 * Build a strict object schema.
	 *
	 * @param array $properties Properties.
	 * @param array $required   Required property names.
	 * @return array<string, mixed> Schema.
	 */
	private function object_schema( array $properties, array $required = array() ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);

		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Get a positive integer schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function positive_integer_schema(): array {
		return array(
			'type' => 'integer',
			'minimum' => 1,
		);
	}

	/**
	 * Get an ID array schema.
	 *
	 * @return array<string, mixed> Schema.
	 */
	private function id_array_schema(): array {
		return array(
			'type'        => 'array',
			'items'       => $this->positive_integer_schema(),
			'maxItems'    => 100,
			'uniqueItems' => true,
		);
	}
}

new Decker_Abilities();
