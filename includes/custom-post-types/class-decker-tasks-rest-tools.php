<?php
/**
 * REST transport for task search, clone and merge.
 *
 * Registers the search, clone and merge routes; clone and merge delegate to
 * Decker_Task_Clone and Decker_Task_Merge and reach the shared lock manager
 * through the injected Decker_Tasks instance to invalidate open sessions.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Tasks_Rest_Tools
 */
class Decker_Tasks_Rest_Tools {

	/**
	 * The task post type coordinator.
	 *
	 * @var Decker_Tasks
	 */
	private $tasks;

	/**
	 * Constructor.
	 *
	 * @param Decker_Tasks $tasks The task post type coordinator.
	 */
	public function __construct( Decker_Tasks $tasks ) {
		$this->tasks = $tasks;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the task search, clone and merge REST routes.
	 */
	public function register_routes() {
		$routes = array(
			array(
				'route'      => '/tasks/search',
				'methods'    => 'GET',
				'callback'   => 'search_tasks',
				'permission' => 'edit_posts',
				'args'       => array(
					'search' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/clone',
				'methods'    => 'POST',
				'callback'   => 'handle_clone_task',
				'permission' => 'edit_posts',
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/merge',
				'methods'    => 'POST',
				'callback'   => 'handle_merge_task',
				'permission' => 'edit_posts',
				'args'       => array(
					'destination_task_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		);

		foreach ( $routes as $definition ) {
			$route_args = array(
				'methods'             => $definition['methods'],
				'callback'            => array( $this, $definition['callback'] ),
				'permission_callback' => Decker_Tasks_Rest_Support::permission_callback( $definition['permission'] ),
			);

			if ( isset( $definition['args'] ) ) {
				$route_args['args'] = $definition['args'];
			}

			register_rest_route( 'decker/v1', $definition['route'], $route_args );
		}
	}

	/**
	 * Search tasks by title.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function search_tasks( WP_REST_Request $request ) {
		$search_term = $request->get_param( 'search' );

		if ( empty( $search_term ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Search term is required.',
				),
				400
			);
		}

		// Query tasks by title.
		$args = array(
			'post_type'      => 'decker_task',
			'post_status'    => array( 'publish' ),
			's'              => $search_term,
			'posts_per_page' => 20,
			'orderby'        => 'relevance',
		);

		$query = new WP_Query( $args );
		$tasks = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$task_id = get_the_ID();

				// Get board information.
				$boards      = wp_get_post_terms( $task_id, 'decker_board' );
				$board_name  = ! empty( $boards ) ? $boards[0]->name : __( 'No Board', 'decker' );
				$board_slug  = ! empty( $boards ) ? $boards[0]->slug : '';

				// Get stack information.
				$stack = get_post_meta( $task_id, 'stack', true );
				$stack_label = '';
				switch ( $stack ) {
					case 'to-do':
						$stack_label = __( 'To-Do', 'decker' );
						break;
					case 'in-progress':
						$stack_label = __( 'In Progress', 'decker' );
						break;
					case 'done':
						$stack_label = __( 'Done', 'decker' );
						break;
					default:
						$stack_label = __( 'Unknown', 'decker' );
						break;
				}

				$tasks[] = array(
					'id'          => $task_id,
					'title'       => get_the_title(),
					'board'       => $board_name,
					'board_slug'  => $board_slug,
					'stack'       => $stack,
					'stack_label' => $stack_label,
					'url'         => get_permalink( $task_id ),
				);
			}
			wp_reset_postdata();
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'tasks'   => $tasks,
			),
			200
		);
	}

	/**
	 * Handle the REST API request to clone a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function handle_clone_task( $request ) {
		$task_id = (int) $request['id'];
		$post    = get_post( $task_id );

		if ( ! $post || 'decker_task' !== $post->post_type ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Task not found.', 'decker' ),
				),
				404
			);
		}

		if ( ! current_user_can( 'edit_post', $task_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'You do not have permission to clone this task.', 'decker' ),
				),
				403
			);
		}

		$new_task_id = Decker_Task_Clone::clone_task( $task_id );

		if ( is_wp_error( $new_task_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $new_task_id->get_error_message(),
				),
				500
			);
		}

		$task_url = get_permalink( $new_task_id );
		if ( ! $task_url || is_wp_error( $task_url ) ) {
			$task_url = add_query_arg(
				array(
					'decker_page' => 'task',
					'id'          => $new_task_id,
				),
				home_url( '/' )
			);
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'new_task_id' => $new_task_id,
				'task_url'    => $task_url,
				'message'     => __( 'Task cloned successfully.', 'decker' ),
			),
			200
		);
	}

	/**
	 * Handle the REST API request to merge a task into another task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function handle_merge_task( WP_REST_Request $request ) {
		$source_task_id      = (int) $request['id'];
		$destination_task_id = (int) $request->get_param( 'destination_task_id' );

		if ( ! $destination_task_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Please choose a destination task.', 'decker' ),
				),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $source_task_id ) ||
			! current_user_can( 'edit_post', $destination_task_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __(
						'You do not have permission to merge one of the selected tasks.',
						'decker'
					),
				),
				403
			);
		}

		$result = Decker_Task_Merge::merge_tasks( $source_task_id, $destination_task_id );

		if ( ! is_wp_error( $result ) ) {
			// The destination absorbed content: invalidate any open editor form
			// on either side of the merge.
			$locks = $this->tasks->get_task_locks();
			$locks->invalidate_sessions( $source_task_id );
			$locks->invalidate_sessions( $destination_task_id );
		}

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] )
				? (int) $error_data['status']
				: 400;

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		return new WP_REST_Response(
			array(
				'success'             => true,
				'source_task_id'      => $source_task_id,
				'destination_task_id' => $destination_task_id,
				'message'             => __( 'Task merged successfully.', 'decker' ),
			),
			200
		);
	}
}
