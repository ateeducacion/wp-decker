<?php
/**
 * File class-decker-admin-import
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 * @package    Decker
 * @subpackage Decker/admin
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

require_once 'vendor/parsedown/Parsedown.php';

/**
 * Decker_Admin_Import Class
 *
 * This class handles the import functionality for the Decker plugin.
 *
 * @package    Decker
 * @subpackage Decker/admin
 */
class Decker_Admin_Import {

	/**
	 * The parsedown instance.
	 *
	 * @var Parsedown.
	 */
	private $_parsedown;

	/**
	 * Constructor
	 *
	 * Initializes the class and hooks.
	 */
	public function __construct() {
		$this->_parsedown = new Parsedown();
		add_action( 'admin_init', array( $this, 'register_importer' ) );
		add_action( 'wp_ajax_decker_start_import', array( $this, 'start_import' ) );
		add_action( 'wp_ajax_decker_import_board', array( $this, 'import_board' ) );
	}

	/**
	 * Registers the importer with WordPress.
	 */
	public function register_importer() {
		register_importer(
			'decker_import',
			esc_html__( 'Decker Tasks from NextCloud', 'decker' ),
			esc_html__( 'Import tasks, labels, and boards from NextCloud.', 'decker' ),
			array( $this, 'importer_greet' )
		);
	}

	/**
	 * Displays the importer's greeting page.
	 */
	public function importer_greet() {
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Import Decker Tasks from NextCloud', 'decker' ); ?></h2>
			<form method="post" id="decker-import-form">
				<?php wp_nonce_field( 'decker-import', 'decker_import_nonce_field' ); ?>
				
				<table class="form-table">
					<tr>
						<th scope="row"><label for="nextcloud_url_base"><?php esc_html_e( 'NextCloud URL Base', 'decker' ); ?></label></th>
						<td>
							<input type="url" id="nextcloud_url_base" name="nextcloud_url_base" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'The base URL of your NextCloud instance', 'decker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nextcloud_username"><?php esc_html_e( 'NextCloud Username', 'decker' ); ?></label></th>
						<td>
							<input type="text" id="nextcloud_username" name="nextcloud_username" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'Your NextCloud username', 'decker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nextcloud_access_token"><?php esc_html_e( 'NextCloud Access Token', 'decker' ); ?></label></th>
						<td>
							<input type="text" id="nextcloud_access_token" name="nextcloud_access_token" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'Your NextCloud access token', 'decker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ignored_board_ids"><?php esc_html_e( 'Ignored Board IDs', 'decker' ); ?></label></th>
						<td>
							<input type="text" id="ignored_board_ids" name="ignored_board_ids" class="regular-text">
							<p class="description"><?php esc_html_e( 'Comma-separated list of board IDs to ignore', 'decker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skip-existing"><?php esc_html_e( 'Skip Existing Tasks', 'decker' ); ?></label></th>
						<td>
							<input type="checkbox" id="skip-existing" name="skip_existing" value="1" checked>
							<span class="description"><?php esc_html_e( 'If checked, tasks that already exist in the system will be skipped and not updated.', 'decker' ); ?></span>
						</td>
					</tr>
				</table>
				
				<?php submit_button( esc_html__( 'Import Now', 'decker' ) ); ?>
			</form>
				<div id="import-progress" style="display: none;">
					<h3><?php esc_html_e( 'Import Progress', 'decker' ); ?></h3>
					<div id="progress-bar" style="width: 100%; background-color: #ccc;">
						<div id="progress" style="width: 0%; height: 30px; background-color: #4caf50;"></div>
					</div>
					<p id="progress-text"></p>
					<div id="log-container" style="max-height: 200px; overflow-y: auto;">
						<ul id="log-messages"></ul>
					</div>
				</div>
		</div>

<script>
// JavaScript for managing import progress and log display    
document.addEventListener('DOMContentLoaded', function() {
	const form = document.getElementById('decker-import-form');
	const progressContainer = document.getElementById('import-progress');
	const progressBar = document.getElementById('progress');
	const progressText = document.getElementById('progress-text');
	const logContainer = document.getElementById('log-container');
	const logMessages = document.getElementById('log-messages');
	const maxRetries = 4;  // Maximum number of retries

	form.addEventListener('submit', function(event) {
		event.preventDefault(); // Prevent form submission
		progressContainer.style.display = 'block';

		const formData = new FormData(form);
		const nonce = '<?php echo esc_attr( wp_create_nonce( 'decker_import_nonce' ) ); ?>';

		const settings = {
			nextcloud_url_base: document.getElementById('nextcloud_url_base').value,
			nextcloud_username: document.getElementById('nextcloud_username').value,
			nextcloud_access_token: document.getElementById('nextcloud_access_token').value,
			ignored_board_ids: document.getElementById('ignored_board_ids').value,
			skip_existing: document.getElementById('skip-existing').checked ? 1 : 0,
			security: nonce
		};

		fetch(ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: new URLSearchParams({
				action: 'decker_start_import',
				security: settings.security,
				nextcloud_url_base: settings.nextcloud_url_base,
				nextcloud_username: settings.nextcloud_username,
				nextcloud_access_token: settings.nextcloud_access_token,
				ignored_board_ids: settings.ignored_board_ids,
				skip_existing: settings.skip_existing
			})
		})
		.then(response => response.json())
		.then(importData => {
			if (importData.success) {
				const totalBoards = importData.data.length;
				let importedBoards = 0;

				// Filter boards that are not ignored
				const ignoredBoardIds = settings.ignored_board_ids.split(',').map(id => id.trim());
				const boardsToProcess = importData.data.filter(board => !ignoredBoardIds.includes(board.id.toString()));

				// Start the board import process
				processBoards(boardsToProcess, importedBoards, boardsToProcess.length, settings);
			} else {
				logMessages.innerHTML += `<li style="color: red;">Error starting import: ${importData.data}</li>`;
			}
		})
		.catch(error => {
			logMessages.innerHTML += `<li style="color: red;">Error starting import: ${error.message}</li>`;
		});
	});

	function processBoards(boards, importedBoards, totalBoards, settings) {
		if (boards.length === 0) {
			progressBar.style.width = '100%';
			progressText.textContent = 'Import completed!';
			logMessages.innerHTML += `<li>Import completed successfully.</li>`;
			return;
		}

		const currentBoard = boards.shift();
		const boardId = currentBoard.id.toString();

		const boardData = new URLSearchParams({
			action: 'decker_import_board',
			security: settings.security,
			board: JSON.stringify(currentBoard),
			skip_existing: settings.skip_existing,
			nextcloud_url_base: settings.nextcloud_url_base,
			nextcloud_username: settings.nextcloud_username,
			nextcloud_access_token: settings.nextcloud_access_token,
			ignored_board_ids: settings.ignored_board_ids
		});

		fetch(ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: boardData
		})
		.then(response => response.json())
		.then(responseData => {
			if (responseData.success) {
				importedBoards++;
				const progress = (importedBoards / totalBoards) * 100;
				progressBar.style.width = progress + '%';
				progressText.textContent = `Imported ${importedBoards} of ${totalBoards} boards...`;
				logMessages.innerHTML += `<li>Imported board with ID: ${boardId}</li>`;
			} else {
				logMessages.innerHTML += `<li style="color: red;">Error importing board with ID: ${boardId} - ${responseData.data}</li>`;
			}
			// Process the next board
			setTimeout(() => {
				processBoards(boards, importedBoards, totalBoards, settings);
			}, 500); // Add a small delay if necessary
		})
		.catch(error => {
			logMessages.innerHTML += `<li style="color: red;">Failed to import board with ID: ${boardId}. Error: ${error.message}</li>`;
			// Process the next board
			processBoards(boards, importedBoards, totalBoards, settings);
		});
	}
});
</script>

		<?php
	}

	/**
	 * Retrieves the list of boards from NextCloud.
	 *
	 * @param array $config The import configuration settings.
	 * @return array|null The list of boards or null on failure.
	 */
	private function get_nextcloud_boards( $config ) {
		$auth       = base64_encode( $config['nextcloud_username'] . ':' . $config['nextcloud_access_token'] );
		$boards_url = rtrim( $config['nextcloud_url_base'], '/' ) . '/index.php/apps/deck/api/v1.0/boards?details=true';

		return $this->make_request( $boards_url, $auth );
	}

	/**
	 * Makes an HTTP request to NextCloud API.
	 *
	 * @param string     $url The API endpoint.
	 * @param string     $auth The authorization header.
	 * @param string     $method The HTTP method (default: 'GET').
	 * @param array|null $data The data to send with the request.
	 * @return array|null The API response or null on failure.
	 */
	protected function make_request( $url, $auth, $method = 'GET', $data = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array(
				'Authorization'  => 'Basic ' . esc_attr( $auth ),
				'OCS-APIRequest' => 'true',
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
			),
		);

		if ( 'GET' !== $method && $data ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( esc_url_raw( $url ), $args );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Starts the import process by retrieving boards.
	 */
	public function start_import() {
		check_ajax_referer( 'decker_import_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Access denied.' );
		}

		// Get the form data.
		$nextcloud_url_base     = isset( $_POST['nextcloud_url_base'] ) ? esc_url_raw( wp_unslash( $_POST['nextcloud_url_base'] ) ) : '';
		$nextcloud_username     = isset( $_POST['nextcloud_username'] ) ? sanitize_text_field( wp_unslash( $_POST['nextcloud_username'] ) ) : '';
		$nextcloud_access_token = isset( $_POST['nextcloud_access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['nextcloud_access_token'] ) ) : '';
		$ignored_board_ids      = isset( $_POST['ignored_board_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ignored_board_ids'] ) ) : '';
		$skip_existing          = isset( $_POST['skip_existing'] ) && 1 == $_POST['skip_existing'] ? true : false;

		// Validate required fields.
		if ( empty( $nextcloud_url_base ) || empty( $nextcloud_username ) || empty( $nextcloud_access_token ) ) {
			wp_send_json_error( 'Missing required fields.' );
		}

		$config = array(
			'nextcloud_url_base'     => $nextcloud_url_base,
			'nextcloud_username'     => $nextcloud_username,
			'nextcloud_access_token' => $nextcloud_access_token,
			'ignored_board_ids'      => $ignored_board_ids,
			'skip_existing'          => $skip_existing,
		);

		$boards = $this->get_nextcloud_boards( $config );
		if ( $boards && isset( $boards['ocs']['data'] ) ) {
			wp_send_json_success( $boards['ocs']['data'] );
		} else {
			wp_send_json_error( 'Failed to retrieve boards from NextCloud.' );
		}
	}

	/**
	 * Imports a single board from NextCloud.
	 */
	public function import_board() {
		check_ajax_referer( 'decker_import_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Access denied.' );
		}

		// Get the settings from the AJAX request.
		$nextcloud_url_base     = isset( $_POST['nextcloud_url_base'] ) ? esc_url_raw( wp_unslash( $_POST['nextcloud_url_base'] ) ) : '';
		$nextcloud_username     = isset( $_POST['nextcloud_username'] ) ? sanitize_text_field( wp_unslash( $_POST['nextcloud_username'] ) ) : '';
		$nextcloud_access_token = isset( $_POST['nextcloud_access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['nextcloud_access_token'] ) ) : '';
		$ignored_board_ids      = isset( $_POST['ignored_board_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ignored_board_ids'] ) ) : '';
		$skip_existing          = isset( $_POST['skip_existing'] ) && 1 == $_POST['skip_existing'] ? true : false;

		// Validate required fields.
		if ( empty( $nextcloud_url_base ) || empty( $nextcloud_username ) || empty( $nextcloud_access_token ) ) {
			wp_send_json_error( 'Missing required settings.' );
		}

		$config = array(
			'nextcloud_url_base'     => $nextcloud_url_base,
			'nextcloud_username'     => $nextcloud_username,
			'nextcloud_access_token' => $nextcloud_access_token,
			'ignored_board_ids'      => $ignored_board_ids,
			'skip_existing'          => $skip_existing,
		);

		// Get the board data.
		$board = isset( $_POST['board'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['board'] ) ), true ) : array();

		if ( empty( $board ) || ! is_array( $board ) ) {
			wp_send_json_error( 'Invalid board data.' );
		}

		// Check if the board is ignored.
		$ignored_board_ids_array = array_map( 'trim', explode( ',', $config['ignored_board_ids'] ) );
		if ( in_array( $board['id'], $ignored_board_ids_array, true ) ) {
			wp_send_json_error( 'Board is ignored.' );
		}

		// Create or retrieve the board term.
		$board_term = null;
		if ( ! empty( $board['title'] ) ) {

			if ( is_numeric( $board['title'] ) ) {
				error_log( 'Skipped board with numeric title: ' . $board['id'] );
				wp_send_json_error( 'Skipped board with invalid title.' );
				return;
			}

			$board_term = $this->maybe_create_term( $board['title'], 'decker_board', $board['color'] );
			if ( is_wp_error( $board_term ) ) {
				error_log( 'Error creating board term for board ID: ' . $board['id'] );
				wp_send_json_error( 'Failed to create board term.' );
				return;
			}
		} else {
			error_log( 'Skipped board with empty title: ' . $board['id'] );
			wp_send_json_error( 'Skipped board with empty title.' );
			return;
		}

		// Import the regular tasks.
		$import_result = $this->import_labels_and_tasks( $board, $board_term, $config );
		if ( false === $import_result ) {
			wp_send_json_error( 'Failed to import tasks.' );
			return;
		}

		// Import the archived tasks.
		$import_result = $this->import_labels_and_tasks( $board, $board_term, $config, true );
		if ( false === $import_result ) {
			wp_send_json_error( 'Failed to import archived tasks.' );
			return;
		}

		wp_send_json_success( 'Board imported successfully.' );
	}

	/**
	 * Creates a term if it doesn't exist and checks for name-ID conflicts.
	 *
	 * @param string $title The term title.
	 * @param string $taxonomy The taxonomy slug.
	 * @param string $color The color associated with the term.
	 * @return array|false|WP_Error The term array, false on failure, or WP_Error on error.
	 */
	private function maybe_create_term( $title, $taxonomy, $color ) {
		$existing_term = term_exists( $title, $taxonomy );

		// If the term exists and the title is numeric, handle conflict.
		if ( $existing_term && is_numeric( $title ) && intval( $title ) !== $existing_term['term_id'] ) {
			error_log( 'Conflict: Term name matches another board ID: ' . $title );
			return new WP_Error( 'term_conflict', 'Term name matches another board ID.' );
		}

		// If the title is numeric, prepend taxonomy to avoid conflicts.
		if ( is_numeric( $title ) ) {
			$title = $taxonomy . '-' . $title;
		}

		// If the term does not exist, create it.
		if ( ! $existing_term ) {
			$sanitized_color = '';
			if ( $color ) {
				$sanitized_color = sanitize_hex_color( 0 === strpos( $color, '#' ) ? $color : '#' . $color );
			}

			// Insert the term.
			$term = wp_insert_term(
				$title,
				$taxonomy,
				array(
					'slug' => sanitize_title( $title ),
				)
			);

			if ( is_wp_error( $term ) ) {
				error_log( 'Error creating term: ' . $title . ' in taxonomy: ' . $taxonomy . '. Error: ' . $term->get_error_message() );
				return $term;
			}

			// Add metadata for the color if created successfully.
			if ( $sanitized_color ) {
				add_term_meta( $term['term_id'], 'term-color', $sanitized_color, true );
			}
			return $term;
		}

		return $existing_term;
	}

	/**
	 * Imports labels and tasks for a board.
	 *
	 * @param array $board The board data.
	 * @param array $board_term The board term array.
	 * @param array $config The import configuration settings.
	 * @param bool  $archived Whether to import archived tasks.
	 * @return array|false An array with counts of labels and tasks, or false on failure.
	 */
	private function import_labels_and_tasks( $board, $board_term, $config, $archived = false ) {
		$label_count   = 0;
		$task_count    = 0;

		$archived_suffix = '';
		if ( $archived ) {
			$archived_suffix = '/archived';
		}

		if ( isset( $board['labels'] ) && is_array( $board['labels'] ) ) {
			foreach ( $board['labels'] as $label ) {
				$label_term = $this->maybe_create_term( $label['title'], 'decker_label', $label['color'] );
				if ( is_wp_error( $label_term ) ) {
					error_log( 'Error creating label term: ' . $label['title'] );
					return false;
				}
				$label_count++;
			}
		}

		$auth       = base64_encode( $config['nextcloud_username'] . ':' . $config['nextcloud_access_token'] );
		$stacks_url = rtrim( $config['nextcloud_url_base'], '/' ) . "/index.php/apps/deck/api/v1.0/boards/{$board['id']}/stacks" . $archived_suffix;

		$stacks = $this->make_request( $stacks_url, $auth );

		if ( ! is_array( $stacks ) || empty( $stacks ) ) {
			error_log( 'Failed to retrieve stacks for board ID: ' . $board['id'] );
			return false;
		}

		// Sort stacks by the 'order' field before processing.
		usort(
			$stacks,
			function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		foreach ( $stacks as $stack ) {

			if ( isset( $stack['cards'] ) && is_array( $stack['cards'] ) ) {

				foreach ( $stack['cards'] as $card ) {
					$existing_task = get_posts(
						array(
							'meta_key'       => 'id_nextcloud_card',
							'meta_value'     => $card['id'],
							'post_type'      => 'decker_task',
							'post_status'    => 'any',
							'fields'         => 'ids', // Only retrieve IDs for performance optimization.
							'posts_per_page' => 1,
						)
					);

					if ( empty( $existing_task ) ) {

						error_log( 'Creating task for card ID: ' . $card['id'] );

						// Map the stack titles to the corresponding values used in the task creation.
						$stack_title_map = array(
							'Completada'  => 'done',
							'En revisión' => 'done', // This column will be removed.
							'En progreso' => 'in-progress',
							'Por hacer'   => 'to-do',
							'Hay que'     => 'to-do', // "Hay que" is equivalent to "to-do".
						);

						// Determine the correct stack value based on the title.
						$stack_title = isset( $stack_title_map[ $stack['title'] ] ) ? $stack_title_map[ $stack['title'] ] : $stack['title'];

						$post_id = $this->create_task( $card, $board_term, $stack_title, $archived, $config );

						if ( is_wp_error( $post_id ) ) {
							error_log( 'Error creating task for card ID: ' . $card['id'] );
							return false;
						}
						$task_count++;
					} elseif ( ! $config['skip_existing'] ) {
						// Update the existing task.
						$post_id = $existing_task[0];
						wp_update_post(
							array(
								'ID'           => $post_id,
								'post_title'   => trim( $card['title'] ),
								'post_content' => $this->_parsedown->text( $card['description'] ),
							)
						);
					}

					usleep( 100 ); // Little sleep to not be banned by NextCloud.
				}
			}
		}

		return array(
			'labels' => $label_count,
			'tasks'  => $task_count,
		);
	}

	/**
	 * Creates a task (custom post type) from a card.
	 *
	 * @param array  $card The card data from NextCloud.
	 * @param array  $board_term The term array for the board.
	 * @param string $stack_title The title of the stack the card belongs to.
	 * @param bool   $archived Whether the task is archived.
	 * @param array  $config The import configuration settings.
	 * @return int|WP_Error The post ID on success, WP_Error on failure.
	 */
	private function create_task( $card, $board_term, $stack_title, $archived, $config ) {

		// Determine post status based on whether it's archived or not.
		$post_status = ( ! empty( $archived ) && $archived ) ? 'archived' : 'publish';

		// Convert description using Parsedown.
		$html_description = $this->_parsedown->text( $card['description'] );

		// Get due date.
		$due_date_str = ! empty( $card['duedate'] ) ? sanitize_text_field( $card['duedate'] ) : null;
		$due_date     = null;
		if ( $due_date_str ) {
			try {
				$due_date = new DateTime( $due_date_str );
			} catch ( Exception $e ) {
				error_log( 'Invalid due date for task: ' . $card['title'] );
			}
		}

		// Determine the owner.
		if ( is_string( $card['owner'] ) ) {
			$nickname = sanitize_text_field( $card['owner'] );
		} elseif ( is_array( $card['owner'] ) && isset( $card['owner']['uid'] ) ) {
			$nickname = sanitize_text_field( $card['owner']['uid'] );
		} else {
			$nickname = '';
		}

		$owner = $this->search_user( $nickname );

		// Get assigned users IDs.
		$assigned_users = array();
		if ( is_array( $card['assignedUsers'] ) ) {
			foreach ( $card['assignedUsers'] as $user ) {
				if ( isset( $user['participant']['uid'] ) ) {

					$participant    = sanitize_user( $user['participant']['uid'] );
					$participant_id = $this->search_user( $participant );

					if ( $participant_id ) {
						$assigned_users[] = $participant_id;
					}
				}
			}
		}

		// Prepare terms for tax_input.
		$tax_input = array();

		// Assign 'decker_board' taxonomy with board ID.
		if ( ! is_wp_error( $board_term ) && isset( $board_term['term_id'] ) ) {
			$tax_input['decker_board'] = array( intval( $board_term['term_id'] ) );
		} else {
			error_log( 'Invalid board term for task creation.' );
			// Optional: assign default term or handle error differently.
		}

		// Prepare labels as term IDs.
		$label_ids = array();
		if ( is_array( $card['labels'] ) ) {
			foreach ( $card['labels'] as $label ) {
				if ( isset( $label['title'] ) ) {
					$label_term = term_exists( sanitize_text_field( $label['title'] ), 'decker_label' );
					if ( ! is_wp_error( $label_term ) && $label_term ) {
						$label_ids[] = intval( $label_term['term_id'] );
					}
				}
			}
			if ( ! empty( $label_ids ) ) {
				$tax_input['decker_label'] = $label_ids;
			}
		}

		// Determine if task has maximum priority.
		$max_priority = false;
		if ( is_array( $card['labels'] ) && in_array( 'PRIORIDAD MÁXIMA 🔥🧨', array_column( $card['labels'], 'title' ), true ) ) {
			$max_priority = true;
		}

		// Prepare creation date.
		$creation_date = null;
		if ( ! empty( $card['createdAt'] ) && is_numeric( $card['createdAt'] ) ) {
			try {
				$creation_date = new DateTime( gmdate( 'Y-m-d H:i:s', $card['createdAt'] ) );
			} catch ( Exception $e ) {
				error_log( 'Invalid creation date for task: ' . $card['title'] );
				$creation_date = new DateTime(); // Assign default date if there's an error.
			}
		}

		// id_nextcloud_card.
		$id_nextcloud_card = isset( $card['id'] ) ? intval( $card['id'] ) : 0;

		// Call our common function to create task.
		$task_id = Decker_Tasks::create_or_update_task(
			0, // 0 indicates a new task.
			trim( $card['title'] ),
			$html_description,
			sanitize_text_field( $stack_title ),
			intval( $board_term['term_id'] ),
			$max_priority,
			$due_date,
			intval( $owner ),
			intval( $owner ),
			$assigned_users,
			$label_ids,
			$creation_date,
			$archived,
			$id_nextcloud_card
		);

		if ( is_wp_error( $task_id ) ) {
			error_log( 'Error creating/updating task: ' . $task_id->get_error_message() );
			return 0;
		}

		// If not archived, import and process comments.
		if ( ! $archived ) {
			$this->import_comments( $card['id'], $task_id, $config );
		}

		return $task_id;
	}

	/**
	 * Imports comments for a task.
	 *
	 * @param int   $card_id The ID of the card in NextCloud.
	 * @param int   $post_id The ID of the WordPress post.
	 * @param array $config  The import configuration settings.
	 */
	private function import_comments( $card_id, $post_id, $config ) {

		$auth          = base64_encode( $config['nextcloud_username'] . ':' . $config['nextcloud_access_token'] );
		$comments_url  = rtrim( $config['nextcloud_url_base'], '/' ) . '/ocs/v2.php/apps/deck/api/v1.0/cards/' . $card_id . '/comments';
		$comments_data = $this->make_request( $comments_url, $auth );

		if ( isset( $comments_data['ocs']['data'] ) && is_array( $comments_data['ocs']['data'] ) ) {
			foreach ( $comments_data['ocs']['data'] as $comment ) {
				$this->process_comment( $comment, $post_id, $config );
			}
		}
	}

	/**
	 * Search for a user by nickname. If no user is found with the specified nickname,
	 * search by the user login instead.
	 *
	 * @param string $nickname The nickname or login to search for.
	 * @return int|null The ID of the first matching user object or null if no user is found.
	 */
	private function search_user( string $nickname ) {
		// Search for the user by nickname.
		$users = get_users(
			array(
				'meta_query' => array(
					array(
						'key'     => 'nickname',
						'value'   => $nickname,
						'compare' => '=',
					),
				),
				'number' => 1, // Limit the query to one user.
			)
		);

		// If a user is found, return the first one.
		if ( ! empty( $users ) && is_array( $users ) ) {
			return $users[0]->ID;
		}

		// If no user was found by nickname, search by user login.
		$users = get_users(
			array(
				'search'         => $nickname,
				'search_columns' => array( 'user_login' ),
				'number'         => 1, // Limit the query to one user.
			)
		);

		// Return the first user found or null if no user matches.
		if ( ! empty( $users ) && is_array( $users ) ) {
			return $users[0]->ID;
		}

		return null; // Return null if no user is found in either search.
	}

	/**
	 * Processes and saves a comment in WordPress.
	 *
	 * @param array $comment The comment data from NextCloud.
	 * @param int   $post_id The ID of the WordPress post.
	 * @param array $config  The import configuration settings.
	 */
	private function process_comment( $comment, $post_id, $config ) {
		$message             = trim( $comment['message'] );
		$user_date_relations = get_post_meta( $post_id, '_user_date_relations', true );
		$user_date_relations = $user_date_relations ? $user_date_relations : array();

		// Determine the user who made the comment.
		$user_nickname = sanitize_text_field( $comment['actorId'] );

		$user_id = $this->search_user( $user_nickname );

		// Check if the comment is in the format "hoy" or "hoy-@uuid".
		if ( preg_match( '/^hoy(\s*-\s*@[\w]+)?$/i', $message, $matches ) ) {

			if ( ! empty( $matches[1] ) ) { // "hoy-@uuid" format.
				$mentioned_nickname = sanitize_text_field( trim( str_replace( array( '-', '@' ), '', $matches[1] ) ) );

				$user_id = $this->search_user( $mentioned_nickname );
			}

			if ( $user_id ) {

				// Extract the date (without time) from the comment's creation date.
				$date = gmdate( 'Y-m-d', strtotime( $comment['creationDateTime'] ) );

				// Check if there's already an entry for this user and date.
				$exists = false;
				foreach ( $user_date_relations as $relation ) {
					if ( $relation['user_id'] === $user_id && $relation['date'] === $date ) {
						$exists = true;
						break;
					}
				}

				// Only add if no existing relation for this user and date.
				if ( ! $exists ) {
					$user_date_relations[] = array(
						'user_id' => $user_id,
						'date'    => $date,
					);
					update_post_meta( $post_id, '_user_date_relations', $user_date_relations );
				}
			}
		} else {
			// Add the comment as a regular WordPress comment.
			wp_insert_comment(
				array(
					'comment_post_ID'   => $post_id,
					'comment_author'    => $comment['actorDisplayName'],
					'comment_content'   => $message,
					'comment_type'      => '',
					'user_id'           => $user_id,
					'comment_author_IP' => '',
					'comment_agent'     => 'NextCloud API',
					'comment_date'      => $comment['creationDateTime'],
					'comment_date_gmt'  => get_gmt_from_date( $comment['creationDateTime'] ),
					'comment_approved'  => 1,
				)
			);
		}
	}
}
