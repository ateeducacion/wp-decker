<?php
/**
 * Meta boxes on the task edit screen.
 *
 * Everything the editor sees when opening a task: the details box with its
 * due date, priority, stack and Nextcloud card fields, the sidebar boxes for
 * labels, board, assigned users and the user/date relations, and the
 * attachments box. Reading the stored meta and turning it into form controls
 * is a separate job from registering the post type or persisting a
 * submission, both of which stay in Decker_Tasks.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Meta_Boxes
 */
class Decker_Task_Meta_Boxes {

	/**
	 * Register the meta boxes on the task edit screen.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
	}

	/**
	 * Add metaboxes for the decker_task post type.
	 */
	public function add_meta_boxes() {

		// Remove default taxonomy metaboxes.
		remove_meta_box( 'tagsdiv-decker_board', 'decker_task', 'side' );
		remove_meta_box( 'tagsdiv-decker_label', 'decker_task', 'side' );

		add_meta_box(
			'decker_task_meta_box',
			__( 'Task Details', 'decker' ),
			array( $this, 'display_meta_box' ),
			'decker_task',
			'normal',
			'high'
		);

		add_meta_box(
			'decker_users_meta_box',
			__( 'Assigned Users', 'decker' ),
			array( $this, 'display_users_meta_box' ),
			'decker_task',
			'side',
			'default'
		);

		add_meta_box(
			'user_date_meta_box',
			__( 'Task User and Date', 'decker' ),
			array( $this, 'display_user_date_meta_box' ),
			'decker_task',
			'normal',
			'high'
		);

		add_meta_box(
			'attachment_meta_box',
			__( 'Attachments', 'decker' ),
			array( $this, 'display_attachment_meta_box' ),
			'decker_task',
			'normal',
			'high'
		);

		add_meta_box(
			'decker_labels_meta_box',
			__( 'Labels', 'decker' ),
			array( $this, 'display_labels_meta_box' ),
			'decker_task',
			'side',
			'default'
		);

		add_meta_box(
			'decker_board_meta_box',
			__( 'Board', 'decker' ),
			array( $this, 'display_board_meta_box' ),
			'decker_task',
			'side',
			'default'
		);
	}

	/**
	 * Display the task details meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_meta_box( $post ) {
		$duedate           = get_post_meta( $post->ID, 'duedate', true );
		$max_priority      = get_post_meta( $post->ID, 'max_priority', true );
		$stack             = get_post_meta( $post->ID, 'stack', true );
		$id_nextcloud_card = get_post_meta( $post->ID, 'id_nextcloud_card', true );
		$responsable       = get_post_meta( $post->ID, 'responsable', true );
		$hidden            = get_post_meta( $post->ID, 'hidden', true );

		wp_nonce_field( 'save_decker_task', 'decker_task_nonce' );

		?>
		<p>
			<label for="duedate"><?php esc_html_e( 'Due Date', 'decker' ); ?></label>
			<input type="date" name="duedate" value="<?php echo esc_attr( $duedate ); ?>" class="widefat">
		</p>
		<p>
			<label for="max_priority"><?php esc_html_e( 'Max Priority', 'decker' ); ?></label>
			<input type="checkbox" name="max_priority" value="1" <?php checked( '1', $max_priority ); ?> class="widefat">
		</p>
		<p>
			<label for="stack"><?php esc_html_e( 'Stack', 'decker' ); ?></label>
			<select name="stack" class="widefat">
				<option value="to-do" <?php selected( 'to-do', $stack ); ?>><?php esc_html_e( 'To-Do', 'decker' ); ?></option>
				<option value="in-progress" <?php selected( 'in-progress', $stack ); ?>><?php esc_html_e( 'In Progress', 'decker' ); ?></option>
				<option value="done" <?php selected( 'done', $stack ); ?>><?php esc_html_e( 'Done', 'decker' ); ?></option>
			</select>
		</p>
		<p>
			<label for="id_nextcloud_card"><?php esc_html_e( 'Nextcloud Card ID', 'decker' ); ?></label>
			<input type="number" name="id_nextcloud_card" value="<?php echo esc_attr( $id_nextcloud_card ); ?>" class="widefat">
		</p>

		<p>
			<label for="responsable"><?php esc_html_e( 'Responsable', 'decker' ); ?></label>
			<input type="number" name="responsable" value="<?php echo esc_attr( $responsable ); ?>" class="widefat">
		</p>

		<p>
			<label for="hidden"><?php esc_html_e( 'Hidden', 'decker' ); ?></label>
			<input type="checkbox" name="hidden" value="1" <?php checked( '1', $hidden ); ?> class="widefat">
		</p>

		<?php
	}

	/**
	 * Display the Labels meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_labels_meta_box( $post ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_label',
				'hide_empty' => false,
			)
		);
		$assigned_labels = wp_get_post_terms( $post->ID, 'decker_label', array( 'fields' => 'ids' ) );
		$assigned_labels = is_array( $assigned_labels ) ? $assigned_labels : array();
		?>
		<div id="decker-labels" class="categorydiv">
			<ul class="categorychecklist form-no-clear">
				<?php foreach ( $terms as $term ) { ?>
					<li>
						<label class="selectit">
							<input type="checkbox" name="decker_labels[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( is_array( $assigned_labels ) && in_array( $term->term_id, $assigned_labels ) ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</label>
					</li>
				<?php } ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Display the Board meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_board_meta_box( $post ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
			)
		);
		$assigned_board = wp_get_post_terms( $post->ID, 'decker_board', array( 'fields' => 'ids' ) );
		$assigned_board = ! empty( $assigned_board ) ? $assigned_board[0] : '';
		?>
		<select name="decker_board" id="decker_board" class="widefat">
			<option value=""><?php esc_html_e( 'Select Board', 'decker' ); ?></option>
			<?php foreach ( $terms as $term ) { ?>
				<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $assigned_board, $term->term_id ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php } ?>
		</select>
		<?php
	}


	/**
	 * Display the users meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_users_meta_box( $post ) {
		$users          = get_users( array( 'orderby' => 'display_name' ) );
		$assigned_users = get_post_meta( $post->ID, 'assigned_users', true );
		?>
		<div id="assigned-users" class="categorydiv">
			<ul class="categorychecklist form-no-clear">
				<?php foreach ( $users as $user ) { ?>
					<li>
						<label class="selectit">
							<input type="checkbox" name="assigned_users[]" value="<?php echo esc_attr( $user->ID ); ?>" <?php checked( is_array( $assigned_users ) && in_array( $user->ID, $assigned_users ) ); ?>>
							<?php echo esc_html( $user->display_name ); ?>
						</label>
					</li>
				<?php } ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Display the user and date meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_user_date_meta_box( $post ) {
		// Retrieve existing relations from post meta; initialize as empty array if none exist.
		$relations = get_post_meta( $post->ID, '_user_date_relations', true );
		$relations = is_array( $relations ) ? $relations : array();

		// Retrieve all users to populate the select dropdown.
		$users = get_users();
		?>
		<div id="user-date-meta-box">
			<!-- User Selection -->
			<p>
				<label for="assigned_user"><?php esc_html_e( 'Assign User:', 'decker' ); ?></label>
				<select id="assigned_user" class="widefat">
					<option value=""><?php esc_html_e( '-- Select User --', 'decker' ); ?></option>
					<?php foreach ( $users as $user ) { ?>
						<option value="<?php echo esc_attr( $user->ID ); ?>">
							<?php echo esc_html( $user->display_name ); ?>
						</option>
					<?php } ?>
				</select>
			</p>
			
			<!-- Date Selection -->
			<p>
				<label for="assigned_date"><?php esc_html_e( 'Assign Date:', 'decker' ); ?></label>
				<input type="date" id="assigned_date" class="widefat" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
			</p>
			
			<!-- Add Relation Button -->
			<p>
				<button type="button" class="button" id="add-user-date-relation"><?php esc_html_e( 'Add Relation', 'decker' ); ?></button>
			</p>
			
			<!-- Relations List -->
			<ul id="user-date-relations-list">
				<?php $this->render_user_date_relations_list( $relations ); ?>
			</ul>
		</div>

		<!-- Inline JavaScript for Meta Box Functionality -->
		<?php
		$this->render_user_date_meta_box_script();
	}

	/**
	 * Render the existing user-date relations as list items.
	 *
	 * @param array $relations The list of user-date relations.
	 */
	private function render_user_date_relations_list( array $relations ) {
		foreach ( $relations as $relation ) {
			// Safely retrieve user data.
			$user         = get_userdata( $relation['user_id'] );
			$display_name = $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown User', 'decker' );
			$date         = esc_html( $relation['date'] );
			?>
					<li data-user-id="<?php echo esc_attr( $relation['user_id'] ); ?>" data-date="<?php echo esc_attr( $relation['date'] ); ?>">
						<?php echo esc_html( $display_name ) . ' - ' . esc_html( $date ); ?>
						<button type="button" class="button remove-relation"><?php esc_html_e( 'Remove', 'decker' ); ?></button>
					</li>
				<?php
		}
	}

	/**
	 * Render the inline JavaScript for the user-date meta box.
	 */
	private function render_user_date_meta_box_script() {
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			const addBtn = document.getElementById('add-user-date-relation');
			const userSelect = document.getElementById('assigned_user');
			const dateInput = document.getElementById('assigned_date');
			const relationsList = document.getElementById('user-date-relations-list');

			// Add Relation Button Click Event
			addBtn.addEventListener('click', function () {
				const userId = userSelect.value;
				const userName = userSelect.options[userSelect.selectedIndex].text;
				const date = dateInput.value;

				// Validate user selection and date input.
				if (!userId || !date) {
					alert('<?php echo esc_js( __( 'Please select a user and date.', 'decker' ) ); ?>');
					return;
				}

				// Check if the user is already added with the same date.
				const existing = Array.from(relationsList.children).some(item =>
					item.getAttribute('data-user-id') === userId && item.getAttribute('data-date') === date
				);
				if (existing) {
					alert('<?php echo esc_js( __( 'This user and date combination already exists.', 'decker' ) ); ?>');
					return;
				}

				// Create a new list item for the relation.
				const listItem = document.createElement('li');
				listItem.setAttribute('data-user-id', userId);
				listItem.setAttribute('data-date', date);
				listItem.innerHTML = `
					${userName} - ${date}
					<button type="button" class="button remove-relation"><?php echo esc_js( __( 'Remove', 'decker' ) ); ?></button>
				`;
				relationsList.appendChild(listItem);

				// Add event listener to the remove button.
				listItem.querySelector('.remove-relation').addEventListener('click', function () {
					listItem.remove();
				});

				// Reset the select and date input.
				userSelect.value = '';
				dateInput.value = '';
			});

			// Add event listeners to existing remove buttons
			document.querySelectorAll('.remove-relation').forEach(button => {
				button.addEventListener('click', function () {
					button.parentElement.remove();
				});
			});

			// Add hidden fields to the form when saving the post.
			document.getElementById('post').addEventListener('submit', function () {
				// Remove any existing hidden inputs to prevent duplicates.
				const existingInput = document.querySelector('input[name="user_date_relations"]');
				if (existingInput) {
					existingInput.remove();
				}

				const relations = [];
				document.querySelectorAll('#user-date-relations-list li').forEach(item => {
					relations.push({
						user_id: item.getAttribute('data-user-id'),
						date: item.getAttribute('data-date')
					});
				});

				const hiddenInput = document.createElement('input');
				hiddenInput.type = 'hidden';
				hiddenInput.name = 'user_date_relations';
				hiddenInput.value = JSON.stringify(relations);
				this.appendChild(hiddenInput);
			});
		});
		</script>
		<?php
	}

	/**
	 * Displays the attachment meta box for the 'decker_task' post type.
	 *
	 * This meta box allows users to view, add, and remove attachments for a specific task.
	 * The attachments are displayed as a list with options to remove them, and users can
	 * add new attachments via the WordPress media library modal.
	 *
	 * @param WP_Post $post The current post object for which the meta box is displayed.
	 *
	 * @return void Outputs the HTML and JavaScript for managing attachments.
	 */
	public function display_attachment_meta_box( $post ) {
		// Retrieve existing attachments linked to post.
		$attachments = get_attached_media( '', $post->ID );

		// Include the nonce field for security.
		wp_nonce_field( 'save_decker_task', 'decker_task_nonce' );
		?>
	<div id="attachments-meta-box">
		<!-- Button to open the media library modal -->
		<p>
			<button type="button" class="button" id="add-attachments"><?php esc_html_e( 'Add Attachments', 'decker' ); ?></button>
		</p>
		
		<!-- List of attached media -->
		<ul id="attachments-list">
			<?php
			foreach ( $attachments as $attachment ) :
				$attachment_url   = $attachment->guid;
				$attachment_title = $attachment->post_title;
				$file_extension   = pathinfo( $attachment_url, PATHINFO_EXTENSION );
				$file_name        = $attachment->post_title . '.' . $file_extension;

				?>
				<li data-attachment-id="<?php echo esc_attr( $attachment->ID ); ?>">
					<a href="<?php echo esc_url( $attachment_url ); ?>" target="_blank"><?php echo esc_html( $file_name ); ?></a>
 
					<button type="button" class="button remove-attachment"><?php esc_html_e( 'Remove', 'decker' ); ?></button>
					<!-- Hidden input to store attachment IDs -->
					<input type="hidden" name="attachments[]" value="<?php echo esc_attr( $attachment->ID ); ?>">
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<!-- JavaScript to handle the media library modal -->
	<script>
	jQuery(document).ready(function($){
		var frame;
		jQuery('#add-attachments').on('click', function(e){
			e.preventDefault();
			// If the media frame already exists, reopen it.
			if ( frame ) {
				frame.open();
				return;
			}
			// Create a new media frame.
			frame = wp.media({
				title: '<?php echo esc_js( __( 'Select Attachments', 'decker' ) ); ?>',
				button: {
					text: '<?php echo esc_js( __( 'Add Attachments', 'decker' ) ); ?>',
				},
				multiple: true // Set to true to allow multiple files to be selected.
			});
			// When an attachment is selected, run a callback.
			frame.on( 'select', function() {
				var attachments = frame.state().get('selection').toJSON();
				attachments.forEach(function(attachment){
					// Append the selected attachments to the list.
					jQuery('#attachments-list').append(
						'<li data-attachment-id="' + attachment.id + '">' +
							'<a href="' + attachment.url + '" target="_blank">' + attachment.title + '</a> ' +
							'<button type="button" class="button remove-attachment"><?php echo esc_js( __( 'Remove', 'decker' ) ); ?></button>' +
							'<input type="hidden" name="attachments[]" value="' + attachment.id + '">' +
						'</li>'
					);
				});
			});
			// Finally, open the modal.
			frame.open();
		});
		// Handle removal of attachments.
		jQuery('#attachments-list').on('click', '.remove-attachment', function(){
			jQuery(this).closest('li').remove();
		});
	});
	</script>
		<?php
	}
}
