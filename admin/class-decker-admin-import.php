<?php
require_once 'vendor/parsedown-1.7.4/Parsedown.php';

/**
 * Decker_Admin_Import Class
 *
 * This class handles the import functionality for the Decker plugin.
 *
 * @package    Decker
 * @subpackage Decker/admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_Admin_Import
 *
 * Manages the import process for tasks, labels, and boards from NextCloud.
 */
class Decker_Admin_Import {

	private $Parsedown;

	/**
	 * Constructor
	 *
	 * Initializes the class and hooks.
	 */
	public function __construct() {
		$this->Parsedown = new Parsedown();
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

		$settings = get_option( 'decker_settings' );
		$errors   = array();

		// Check if NextCloud settings are configured.
		if ( empty( $settings['nextcloud_url_base'] ) || empty( $settings['nextcloud_username'] ) || empty( $settings['nextcloud_access_token'] ) ) {
			$errors[] = esc_html__( 'NextCloud settings are not configured.', 'decker' );
		}

		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Import Decker Tasks from NextCloud', 'decker' ); ?></h2>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error">
					<ul>
					<?php
					foreach ( $errors as $error ) :
						?>
						<li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul>
				</div>
			<?php else : ?>
				<form method="post" id="decker-import-form">
					<?php wp_nonce_field( 'decker-import' ); ?>
					<label for="ignore-existing">
						<input type="checkbox" id="ignore-existing" name="ignore_existing" value="1" checked>
						<?php esc_html_e( 'Overwrite existing tasks', 'decker' ); ?>
						<span class="description"><?php esc_html_e( 'If checked, tasks that already exist in the system will be updated.', 'decker' ); ?></span>
					</label>
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
			<?php endif; ?>
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
		const maxRetries = 3;  // Maximum number of retries allowed
		<?php
			$options    = get_option( 'decker_settings', array() );
		?>
		const ignoredBoardIds = '<?php echo esc_js( $options['decker_ignored_board_ids'] ); ?>'.split(',').map(id => id.trim());

		form.addEventListener('submit', function(event) {
			event.preventDefault();
			progressContainer.style.display = 'block';

			const formData = new FormData(form);
			formData.append('action', 'decker_start_import');
			formData.append('security', '<?php echo esc_js( wp_create_nonce( 'decker_import_nonce' ) ); ?>');
			formData.append('ignore_existing', document.getElementById('ignore-existing').checked ? 1 : 0);

			fetch(ajaxurl, {
				method: 'POST',
				body: formData
			})
			.then(response => response.json())
			.then(importData => {
				if (importData.success) {
					// const totalBoards = importData.data.length;

					const totalBoards = 2; //TO DEBUG just one board

					let importedBoards = 0;

					const importBoard = (retries = 0) => {
						if (importedBoards < totalBoards) {
							const currentBoard = importData.data[importedBoards];
							const boardId = currentBoard.id.toString();

							if (ignoredBoardIds.includes(boardId)) {
								logMessages.innerHTML += `<li style="color: gray;">Ignoring board with ID: ${boardId}</li>`;
								importedBoards++;
								importBoard();  // Skip to the next board
								return;
							}

							const boardData = new FormData();
							boardData.append('action', 'decker_import_board');
							boardData.append('security', '<?php echo esc_js( wp_create_nonce( 'decker_import_nonce' ) ); ?>');
							boardData.append('board', JSON.stringify(currentBoard));
							boardData.append('ignore_existing', formData.get('ignore_existing'));

							fetch(ajaxurl, {
								method: 'POST',
								body: boardData
							})
							.then(response => response.json())
							.then(boardResponse => {
								if (boardResponse.success) {
									importedBoards++;
									const progress = (importedBoards / totalBoards) * 100;
									progressBar.style.width = progress + '%';
									progressText.textContent = `Imported ${importedBoards} of ${totalBoards} boards...`;

									logMessages.innerHTML += `<li>Imported board with ID: ${boardId}</li>`;

									importBoard(); 
								} else {
									logMessages.innerHTML += `<li style="color: red;">Error importing board with ID: ${boardId} - ${boardResponse.data}</li>`;
								}
							})
							.catch(error => {
								if (retries < maxRetries) {
									logMessages.innerHTML += `<li style="color: orange;">Retrying board with ID: ${boardId} (${retries + 1}/${maxRetries})...</li>`;
									setTimeout(() => importBoard(retries + 1), 3000); // Retry after 3 seconds
								} else {
									logMessages.innerHTML += `<li style="color: red;">Failed to import board with ID: ${boardId} after ${maxRetries} attempts. Error: ${error.message}</li>`;
								}
							});
						} else {
							progressBar.style.width = '100%';
							progressText.textContent = 'Import completed!';
							logMessages.innerHTML += `<li>Import completed successfully.</li>`;
						}
					};

					importBoard();
				} else {
					logMessages.innerHTML += `<li style="color: red;">Error starting import: ${importData.data}</li>`;
				}
			});
		});
	});
</script>

		<?php
	}

	/**
	 * Retrieves the list of boards from NextCloud.
	 *
	 * @return array|null The list of boards or null on failure.
	 */
	private function get_nextcloud_boards() {
		$options    = get_option( 'decker_settings', array() );
		$auth       = base64_encode( $options['nextcloud_username'] . ':' . $options['nextcloud_access_token'] );
		$boards_url = $options['nextcloud_url_base'] . '/index.php/apps/deck/api/v1.0/boards?details=true';

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
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Basic ' . esc_attr( $auth ),
				'OCS-APIRequest' => 'true',
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
			),
		);

		if ( $method !== 'GET' && $data ) {
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
	    if (!current_user_can('manage_options')) {
	        wp_send_json_error('Access denied.');
	    }

		$boards = $this->get_nextcloud_boards();
		if ( $boards ) {
			wp_send_json_success( $boards );
		} else {
			wp_send_json_error( 'Failed to retrieve boards from NextCloud.' );
		}
	}

	/**
	 * Imports a single board from NextCloud.
	 */
	public function import_board() {
		check_ajax_referer( 'decker_import_nonce', 'security' );
		check_ajax_referer( 'decker_import_nonce', 'security' );
	    if (!current_user_can('manage_options')) {
	        wp_send_json_error('Access denied.');
	    }

		$ignore_existing = isset( $_POST['ignore_existing'] ) && $_POST['ignore_existing'] == 1;
		$board = json_decode( sanitize_text_field( wp_unslash( $_POST['board'] ) ), true );
		$options    = get_option( 'decker_settings', array() );
		$ignored_board_ids = explode( ',', $options['decker_ignored_board_ids'] );

		if ( ! in_array( $board['id'], $ignored_board_ids, true ) ) {

			$board_term = null;
			if ( ! empty( $board['title'] ) ) {

		        if ( is_numeric( $board['title'] ) ) {
		            Decker_Utility_Functions::write_log( 'Skipped board with numeric title: ' . $board['id'], Decker_Utility_Functions::LOG_LEVEL_ERROR );
		            wp_send_json_error( 'Skipped board with invalid title.' );
		            return;
		        }

				$board_term = $this->maybe_create_term( $board['title'], 'decker_board', $board['color'] );
				if ( is_wp_error( $board_term ) ) {
					Decker_Utility_Functions::write_log( 'Error creating board term for board ID: ' . $board['id'], Decker_Utility_Functions::LOG_LEVEL_ERROR );
					wp_send_json_error( 'Failed to create board term.' );
					return;
				}
			} else {
		        Decker_Utility_Functions::write_log( 'Skipped board with empty title: ' . $board['id'], Decker_Utility_Functions::LOG_LEVEL_ERROR );
		        wp_send_json_error( 'Skipped board with empty title.' );
		        return;
			}

			// Import the regular tasks.
			$import_result = $this->import_labels_and_tasks( $board, $board_term );
			if ( $import_result === false ) {
				wp_send_json_error( 'Failed to import tasks.' );
				return;
			}

			// Import the archived tasks.
			$import_result = $this->import_labels_and_tasks( $board, $board_term, true );
			if ( $import_result === false ) {
				wp_send_json_error( 'Failed to import archived tasks.' );
				return;
			}

			wp_send_json_success( 'Board imported successfully.' );
		} else {
			wp_send_json_error( 'Board is ignored.' );
		}
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

	    // Si el término ya existe y su nombre coincide con otro ID de board, manejar el conflicto.
	    if ( $existing_term && is_numeric( $title ) && intval( $title ) !== $existing_term['term_id'] ) {
	        Decker_Utility_Functions::write_log( 'Conflict: Term name matches another board ID: ' . $title, Decker_Utility_Functions::LOG_LEVEL_ERROR );
	        return new WP_Error( 'term_conflict', 'Term name matches another board ID.' );
	    }

	    // Si el título es numérico, manejalo adecuadamente.
	    if ( is_numeric( $title ) ) {
	        // Opcional: Agregar un prefijo para evitar conflictos.
	        $title = $taxonomy . '-' . $title;

	        Decker_Utility_Functions::write_log( 'Adjusted term title to avoid numeric conflict: ' . $title, Decker_Utility_Functions::LOG_LEVEL_INFO );
	    }

	    // Si el término no existe, crearlo.
	    if ( ! $existing_term ) {
	        $sanitized_color = '';
	        if ( $color ) {
	            $sanitized_color = sanitize_hex_color( strpos( $color, '#' ) === 0 ? $color : '#' . $color );
	        }

	        // Insertar el término
	        $term = wp_insert_term(
	            $title,
	            $taxonomy,
	            array(
	                'slug' => sanitize_title( $title ),
	            )
	        );

	        if ( is_wp_error( $term ) ) {
	            Decker_Utility_Functions::write_log( 'Error creating term: ' . $title . ' in taxonomy: ' . $taxonomy . '. Error: ' . $term->get_error_message(), Decker_Utility_Functions::LOG_LEVEL_ERROR );
	            return $term;
	        }

	        // Añadir metadatos para el color si se creó correctamente
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
	 * @param bool  $archived Whether to import archived tasks.
	 * @return array|false An array with counts of labels and tasks, or false on failure.
	 */
	private function import_labels_and_tasks( $board, $board_term, $archived = false ) {
		$ignore_existing = isset( $_POST['ignore_existing'] ) && $_POST['ignore_existing'] == 1;
		$label_count = 0;
		$task_count  = 0;

		$archived_suffix = '';
		if ( $archived ) {
			$archived_suffix = '/archived';
		}

		if ( isset( $board['labels'] ) && is_array( $board['labels'] ) ) {
			foreach ( $board['labels'] as $label ) {
				$label_term = $this->maybe_create_term( $label['title'], 'decker_label', $label['color'] );
				if ( is_wp_error( $label_term ) ) {
					Decker_Utility_Functions::write_log( 'Error creating label term: ' . $label['title'], Decker_Utility_Functions::LOG_LEVEL_ERROR );
					return false;
				}
				$label_count++;
			}
		}

		$options    = get_option( 'decker_settings', array() );
		$auth       = base64_encode( $options['nextcloud_username'] . ':' . $options['nextcloud_access_token'] );
		$stacks_url = $options['nextcloud_url_base'] . "/index.php/apps/deck/api/v1.0/boards/{$board['id']}/stacks" . $archived_suffix;

		Decker_Utility_Functions::write_log( 'Requesting stacks from URL: ' . $stacks_url, Decker_Utility_Functions::LOG_LEVEL_DEBUG );

		$stacks = $this->make_request( $stacks_url, $auth );

		if ( ! is_array( $stacks ) || empty( $stacks ) ) {
			Decker_Utility_Functions::write_log( 'Failed to retrieve stacks for board ID: ' . $board['id'], Decker_Utility_Functions::LOG_LEVEL_ERROR );
			return false;
		}

		Decker_Utility_Functions::write_log( 'Stacks retrieved successfully.', Decker_Utility_Functions::LOG_LEVEL_INFO );

		// Sort stacks by the 'order' field before processing
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
							'meta_key'   => 'id_nextcloud_card',
							'meta_value' => $card['id'],
							'post_type'  => 'decker_task',
							'post_status' => 'any',
						    'fields' 	  => 'ids', // Only retrieve IDs for performance optimization
						    'posts_per_page' => 1,
						)
					);

					if ( empty( $existing_task ) ) {

						Decker_Utility_Functions::write_log( 'Creating task for card ID: ' . $card['id'], Decker_Utility_Functions::LOG_LEVEL_DEBUG );

						// Map the stack titles to the corresponding values used in the task creation.
						$stack_title_map = array(
							'Completada'   => 'done',
							'En revisión'  => 'done', // Esta columna desaparece
							'En progreso'  => 'in-progress',
							'Por hacer'    => 'to-do',
							'Hay que'      => 'to-do', // Assuming "Hay que" is similar to "Por hacer"
						);

						// Determine the correct stack value based on the title.
						$stack_title = isset( $stack_title_map[ $stack['title'] ] ) ? $stack_title_map[ $stack['title'] ] : $stack['title'];

						$post_id = $this->create_task( $card, $board_term, $stack_title, $archived );

						if ( is_wp_error( $post_id ) ) {
							Decker_Utility_Functions::write_log( 'Error creating task for card ID: ' . $card['id'], Decker_Utility_Functions::LOG_LEVEL_ERROR );
							return false;
						}

						Decker_Utility_Functions::write_log( 'Task created successfully with ID: ' . $post_id, Decker_Utility_Functions::LOG_LEVEL_INFO );
						$task_count++;
					} elseif ( $ignore_existing ) {
						// Update the existing task.
						$post_id = $existing_task[0];
						wp_update_post(
							array(
								'ID'           => $post_id,
								'post_title'   => trim( $card['title'] ),
								'post_content' => $this->Parsedown->text( $card['description'] ),
							)
						);
						Decker_Utility_Functions::write_log( 'Task updated successfully with ID: ' . $post_id, Decker_Utility_Functions::LOG_LEVEL_INFO );
					}

					usleep( 100000 ); // Little sleep to not be banned by nextcloud

				}
			}
		}

		return array(
			'labels' => $label_count,
			'tasks' => $task_count,
		);
	}

	/**
	 * Creates a task (custom post type) from a card.
	 *
	 * @param array  $card The card data from NextCloud.
	 * @param array  $board_term The term array for the board.
	 * @param string $stack_title The title of the stack the card belongs to.
	 * @param bool   $archived Whether the task is archived.
	 * @return int|WP_Error The post ID on success, WP_Error on failure.
	 */
	private function create_task( $card, $board_term, $stack_title, $archived ) {

	    // Determinar el estado del post basado en si está archivado o no
	    $post_status = ! empty( $archived ) && $archived ? 'archived' : 'publish';

	    // Convertir la descripción usando Parsedown
	    $html_description = $this->Parsedown->text( $card['description'] );

	    // Obtener la fecha de vencimiento
	    $due_date_str = ! empty( $card['due_date'] ) ? sanitize_text_field( $card['due_date'] ) : null;
	    try {
	        $due_date = $due_date_str ? new DateTime( $due_date_str ) : new DateTime();
	    } catch ( Exception $e ) {
	        // Manejar la excepción si la fecha no es válida
	        Decker_Utility_Functions::write_log( 'Fecha de vencimiento inválida para la tarea: ' . $card['title'], Decker_Utility_Functions::LOG_LEVEL_ERROR );
	        $due_date = new DateTime(); // Asignar una fecha por defecto
	    }

	    // Determinar el propietario (owner)
	    if ( is_string( $card['owner'] ) ) {
	        $nickname = sanitize_text_field( $card['owner'] );
	    } elseif ( is_array( $card['owner'] ) && isset( $card['owner']['uid'] ) ) {
	        $nickname = sanitize_text_field( $card['owner']['uid'] );
	    } else {
	        $nickname = '';
	    }

	    // Obtener el ID del propietario
	    $owner_obj = get_users( [
	        'search'         => $nickname,
	        'search_columns' => [ 'display_name', 'nickname' ],
	        'number'         => 1,
	    ] );
	    $owner = get_current_user_id();
	    if ( ! empty( $owner_obj ) && is_array( $owner_obj ) ) {
	        $owner = $owner_obj[0]->ID;
	    }

	    // Obtener los IDs de los usuarios asignados
	    $assigned_users = array();
	    if ( is_array( $card['assignedUsers'] ) ) {	
	        foreach ( $card['assignedUsers'] as $user ) {
	            if ( isset( $user['participant']['uid'] ) ) {
	                $user_obj = get_user_by( 'login', sanitize_user( $user['participant']['uid'] ) );
	                if ( $user_obj ) {
	                    $assigned_users[] = $user_obj->ID;
	                }
	            }
	        }
	    }

	    // Preparar los términos para tax_input
	    $tax_input = array();

	    // Asignar la taxonomía 'decker_board' con el ID del board
	    if ( ! is_wp_error( $board_term ) && isset( $board_term['term_id'] ) ) {
	        $tax_input['decker_board'] = array( intval( $board_term['term_id'] ) );
	    } else {
	        Decker_Utility_Functions::write_log( 'Invalid board term for task creation.', Decker_Utility_Functions::LOG_LEVEL_ERROR );
	        // Opcional: asignar un término por defecto o manejar el error de otra manera
	    }

	    // Preparar etiquetas como IDs de términos
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

	    // Determinar si la tarea tiene máxima prioridad
	    $max_priority = false;
	    if ( is_array( $card['labels'] ) && in_array( 'PRIORIDAD MÁXIMA 🔥🧨', array_column( $card['labels'], 'title' ), true ) ) {
	        $max_priority = true;
	    }

	    // Preparar la fecha de creación
	    try {
	        $creation_date = new DateTime( date( 'Y-m-d H:i:s', $card['createdAt'] ) );
	    } catch ( Exception $e ) {
	        Decker_Utility_Functions::write_log( 'Fecha de creación inválida para la tarea: ' . $card['title'], Decker_Utility_Functions::LOG_LEVEL_ERROR );
	        $creation_date = new DateTime(); // Asignar una fecha por defecto
	    }

	    // id_nextcloud_card
	    $id_nextcloud_card = isset( $card['id'] ) ? intval( $card['id'] ) : 0;

	    // Llamar a la función común para crear o actualizar la tarea
	    $task_id = Decker_Tasks::create_or_update_task(
	        0, // 0 indica que es una nueva tarea
	        trim( $card['title'] ),
	        $html_description,
	        sanitize_text_field( $stack_title ),
	        intval( $board_term['term_id'] ),
	        $max_priority,
	        $due_date,
	        intval( $owner ),
	        $assigned_users,
	        $label_ids,
	        $creation_date,
	        $archived,
	        $id_nextcloud_card
	    );

	    if ( is_wp_error( $task_id ) ) {
	        Decker_Utility_Functions::write_log( 'Error al crear/actualizar la tarea: ' . $task_id->get_error_message(), Decker_Utility_Functions::LOG_LEVEL_ERROR );
	        return 0;
	    }

	    // Si no está archivada, importar y procesar comentarios
	    if ( ! $archived ) {
	        $this->import_comments( $card['id'], $task_id );
	    }

	    return $task_id;
	}


	// private function create_task( $card, $board_term, $stack_title, $archived ) {

	// 	// Determine the post status based on whether the card is archived or not.
	// 	$post_status = ! empty( $card['archived'] ) && $card['archived'] ? 'archived' : 'publish';

	// 	$html_description = $this->Parsedown->text( $card['description'] );

	// 	$due_date = ! empty( $card['due_date'] ) ? sanitize_text_field( $card['due_date'] ) : null;


	// 	// Check if the owner is directly a string (UUID) or an object containing 'uid'.
	// 	if ( is_string( $card['owner'] ) ) {
	// 		$nickname = sanitize_text_field( $card['owner'] );
	// 	} elseif ( is_array( $card['owner'] ) && isset( $card['owner']['uid'] ) ) {
	// 		$nickname = sanitize_text_field( $card['owner']['uid'] );
	// 	} else {
	// 		$nickname = '';
	// 	}

	// 	// Use the "nickname" field because the login won't be valid.
	// 	$owner_obj = get_users(
	// 		array(
	// 			'search'         => $nickname,
	// 			'search_columns' => array( 'display_name', 'nickname' ),
	// 			'number'         => 1,
	// 		)
	// 	);
	// 	$owner = get_current_user();
	// 	if ( ! empty( $owner_obj ) && is_array( $owner_obj ) ) {
	// 		$owner = $owner_obj[0]->ID;
	// 	}

	// 	$assigned_users = array();
	// 	if ( is_array( $card['assignedUsers'] ) ) {	
	// 		foreach ( $card['assignedUsers'] as $user ) {
	// 			$user_obj = get_user_by( 'login', sanitize_user( $user['participant']['uid'] ) );
	// 			if ( $user_obj ) {
	// 				$assigned_users[] = $user_obj->ID;
	// 			}
	// 		}
	// 	}

	//     // Preparar los términos para tax_input
	//     $tax_input = array();

	//     if ( ! is_wp_error( $board_term ) && isset( $board_term['term_id'] ) ) {
	//         // Obtener el nombre del término usando su ID
	//         $term = get_term( intval( $board_term['term_id'] ), 'decker_board' );
	//         if ( ! is_wp_error( $term ) && $term ) {
	//             $tax_input['decker_board'] = array( $term->name );
	//         }
	//     } else {
	//         Decker_Utility_Functions::write_log( 'Invalid board term for task creation.', Decker_Utility_Functions::LOG_LEVEL_ERROR );
	//     }

	//     // Incluir etiquetas en tax_input si las hay.
	//     if ( is_array( $card['labels'] ) ) {
	//         $tax_input['decker_label'] = array(); // Inicializar el array.
	//         foreach ( $card['labels'] as $label ) {
	//             $label_term = term_exists( $label['title'], 'decker_label' );
	//             if ( ! is_wp_error( $label_term ) && $label_term ) {
	//                 // Obtener el objeto del término usando su ID.
	//                 $term = get_term( intval( $label_term['term_id'] ), 'decker_label' );
	//                 if ( ! is_wp_error( $term ) && $term ) {
	//                     $tax_input['decker_label'][] = $term->name;
	//                 }
	//             }
	//         }
	//         // Eliminar la taxonomía si no hay etiquetas válidas.
	//         if ( empty( $tax_input['decker_label'] ) ) {
	//             unset( $tax_input['decker_label'] );
	//         }
	//     }

	// 	$post_id = wp_insert_post(
	// 		array(
	// 			'post_title'   => trim( $card['title'] ),
	// 			'post_content' => $html_description,
	// 			'post_status'  => $post_status,
	// 			'post_type'    => 'decker_task',
	// 			'post_date'    => date( 'Y-m-d H:i:s', $card['createdAt'] ),
	// 			'post_author'  => $owner,
	// 			'meta_input'   => array(
	// 				'id_nextcloud_card' => $card['id'],
	// 				'stack'             => esc_html( $stack_title ),
	// 				'duedate'          => $due_date,
	// 				'max_priority'      => ( isset( $card['labels'] ) && is_array( $card['labels'] ) && in_array( 'PRIORIDAD MÁXIMA 🔥🧨', array_column( $card['labels'], 'title' ), true ) ) ? '1' : '',
	// 				'assigned_users'    => $assigned_users,
	// 			),
	// 			'tax_input'    => $tax_input,
	// 		)
	// 	);

	// 	if ( ! $archived ) {
	// 		// Import and process comments for this task.
	// 		$this->import_comments( $card['id'], $post_id );
	// 	}

	// 	return $post_id;
	// }

	/**
	 * Imports comments for a task.
	 *
	 * @param int $card_id The ID of the card in NextCloud.
	 * @param int $post_id The ID of the WordPress post.
	 */
	private function import_comments( $card_id, $post_id ) {

		$options       = get_option( 'decker_settings', array() );
		$auth          = base64_encode( $options['nextcloud_username'] . ':' . $options['nextcloud_access_token'] );
		$comments_url  = $options['nextcloud_url_base'] . '/ocs/v2.php/apps/deck/api/v1.0/cards/' . $card_id . '/comments';
		$comments_data = $this->make_request( $comments_url, $auth );

		if ( isset( $comments_data['ocs']['data'] ) && is_array( $comments_data['ocs']['data'] ) ) {
			foreach ( $comments_data['ocs']['data'] as $comment ) {
				$this->process_comment( $comment, $post_id );
			}
		}
	}

	/**
	 * Processes and saves a comment in WordPress.
	 *
	 * @param array $comment The comment data from NextCloud.
	 * @param int   $post_id The ID of the WordPress post.
	 */
	private function process_comment( $comment, $post_id ) {
		$message = trim( $comment['message'] );
		$user_date_relations = get_post_meta( $post_id, '_user_date_relations', true ) ?: array();

		// Determine the user who made the comment.
		$user_nickname = sanitize_text_field( $comment['actorId'] );
		$user_obj = get_users(
			array(
				'search' => $user_nickname,
				'search_columns' => array( 'nickname' ),
				'number' => 1,
			)
		);

		$user_id = ! empty( $user_obj ) && is_array( $user_obj ) ? $user_obj[0]->ID : null;

		// Check if the comment is in the format "hoy" or "hoy-@uuid".
		if ( preg_match( '/^hoy(\s*-\s*@[\w]+)?$/i', $message, $matches ) ) {

			if ( ! empty( $matches[1] ) ) { // "hoy-@uuid" format.
				$mentioned_nickname = sanitize_text_field( trim( str_replace( array( '-', '@' ), '', $matches[1] ) ) );
				$mentioned_user_obj = get_users(
					array(
						'search' => $mentioned_nickname,
						'search_columns' => array( 'nickname' ),
						'number' => 1,
					)
				);

				if ( ! empty( $mentioned_user_obj ) && is_array( $mentioned_user_obj ) ) {
					$user_id = $mentioned_user_obj[0]->ID;
				}
			}

			if ( $user_id ) {

				// Extract the date (without time) from the comment's creation date.
				$date = date( 'Y-m-d', strtotime( $comment['creationDateTime'] ) );

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
						'date' => $date,
					);
					update_post_meta( $post_id, '_user_date_relations', $user_date_relations );
				}
			}
		} else {
			// Add the comment as a regular WordPress comment.
			wp_insert_comment(
				array(
					'comment_post_ID'      => $post_id,
					'comment_author'       => $comment['actorDisplayName'],
					'comment_content'      => $message,
					'comment_type'         => '',
					'user_id'              => $user_id ?: 0,
					'comment_author_IP'    => '',
					'comment_agent'        => 'NextCloud API',
					'comment_date'         => $comment['creationDateTime'],
					'comment_approved'     => 1,
				)
			);
		}
	}
}
