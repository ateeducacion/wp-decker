<?php
/**
 * Meta boxes on the event edit screen.
 *
 * Everything the editor sees when opening an event: the details box with its
 * dates, location, URL and category, and the sidebar box listing who the event
 * is assigned to. Reading the stored meta and turning it into form controls is
 * a separate job from registering the post type or persisting a submission,
 * both of which stay in Decker_Events.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Event_Meta_Box
 */
class Decker_Event_Meta_Box {

	/**
	 * Register the meta boxes on the event edit screen.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
	}

	/**
	 * Add meta boxes for event details
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'decker_event_details',
			__( 'Event Details', 'decker' ),
			array( $this, 'render_event_details_meta_box' ),
			'decker_event',
			'normal',
			'high'
		);

		add_meta_box(
			'decker_users_meta_box',
			__( 'Assigned Users', 'decker' ),
			array( $this, 'display_users_meta_box' ),
			'decker_event',
			'side',
			'default'
		);
	}

	/**
	 * Render the event details meta box
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_event_details_meta_box( $post ) {
		wp_nonce_field( 'decker_event_meta_box', 'decker_event_meta_box_nonce' );

		$meta = $this->get_event_meta_box_data( $post );

		$this->render_allday_field( $meta['allday'] );
		$this->render_allday_toggle_script();
		$this->render_date_field( 'event_start', __( 'Start:', 'decker' ), $meta['input_type'], $meta['start_for_input'], $meta['step_attr'] );
		$this->render_date_field( 'event_end', __( 'End:', 'decker' ), $meta['input_type'], $meta['end_for_input'], $meta['step_attr'] );
		$this->render_location_url_fields( $meta['location'], $meta['url'] );
		$this->render_category_field( $meta['category'] );
	}

	/**
	 * Collect and prepare the values rendered by the event details meta box.
	 *
	 * @param WP_Post $post The post object.
	 * @return array Associative array of prepared meta-box values.
	 */
	private function get_event_meta_box_data( $post ) {
		$allday = get_post_meta( $post->ID, 'event_allday', true );
		$start_utc = get_post_meta( $post->ID, 'event_start', true );
		$end_utc = get_post_meta( $post->ID, 'event_end', true );
		$location = get_post_meta( $post->ID, 'event_location', true );
		$url = get_post_meta( $post->ID, 'event_url', true );
		$category = get_post_meta( $post->ID, 'event_category', true );

		$start_for_input = '';
		$end_for_input   = '';
		$input_type      = $allday ? 'date' : 'datetime-local';

		if ( $allday ) {
			$start_for_input = $start_utc;
			$end_for_input   = $end_utc;
		} elseif ( $start_utc ) {
			// Already UTC; just format for <input type="datetime-local">.
			$start_for_input = str_replace( ' ', 'T', $start_utc );
			$end_for_input   = str_replace( ' ', 'T', $end_utc );
		}

				$step_attr  = $allday ? '' : ' step="60s"';          // 60s ⇒ hides seconds.

		return array(
			'allday'          => $allday,
			'location'        => $location,
			'url'             => $url,
			'category'        => $category,
			'input_type'      => $input_type,
			'start_for_input' => $start_for_input,
			'end_for_input'   => $end_for_input,
			'step_attr'       => $step_attr,
		);
	}

	/**
	 * Render the all-day checkbox and the date error container.
	 *
	 * @param string $allday The stored all-day flag.
	 */
	private function render_allday_field( $allday ) {
		?>
			<p>
	<label>
		<input type="checkbox" name="event_allday" id="event_allday" <?php checked( $allday, '1' ); ?>>
		<?php esc_html_e( 'All Day Event Event', 'decker' ); ?>
	</label>
</p>

<!-- Container for error messages -->
<div id="event_date_error" style="color: red; display: none;">
		<?php esc_html_e( 'End Date must be after Start Date.', 'decker' ); ?>
</div>
		<?php
	}

	/**
	 * Render the inline script that toggles the date/datetime input types.
	 */
	private function render_allday_toggle_script() {
		?>
<!-- Script to handle field visibility and validation -->
<script>
(function($) {
	$(document).ready(function() {
		function toggleDateType() {
			const isAllDay = $('#event_allday').is(':checked');
			const type = isAllDay ? 'date' : 'datetime-local';

			$('#event_start, #event_end').each(function() {
				const value = this.value;
				const newInput = this.cloneNode();
				newInput.type = type;

				// Reassign value (convert if necessary)
				if (type === 'date') {
					newInput.value = value.split('T')[0];
				} else {
					const parts = value.split('T');
					if (parts.length === 2) {
						newInput.value = value;
					} else {
						newInput.value = value + 'T00:00';
					}
				}

				$(this).replaceWith(newInput);
			});
		}

		$('#event_allday').on('change', toggleDateType);
	});
})(jQuery);
</script>
		<?php
	}

	/**
	 * Render a single start/end date input block.
	 *
	 * @param string $field_id   The input id/name (event_start or event_end).
	 * @param string $label      The label text.
	 * @param string $input_type The input type ('date' or 'datetime-local').
	 * @param string $value      The pre-formatted input value.
	 * @param string $step_attr  The optional step attribute fragment.
	 */
	private function render_date_field( $field_id, $label, $input_type, $value, $step_attr ) {
		?>
<p>
	<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label><br>
	<input type="<?php echo esc_attr( $input_type ); ?>"
		   id="<?php echo esc_attr( $field_id ); ?>"
		   name="<?php echo esc_attr( $field_id ); ?>"
		   value="<?php echo esc_attr( $value ); ?>"
		   class="widefat"<?php echo esc_attr( $step_attr ); ?>>
	<small class="description">
		<?php esc_html_e( 'Time is stored in UTC. Adjust accordingly.', 'decker' ); ?>
	</small>

</p>
		<?php
	}

	/**
	 * Render the location and URL text inputs.
	 *
	 * @param string $location The stored location value.
	 * @param string $url      The stored URL value.
	 */
	private function render_location_url_fields( $location, $url ) {
		?>
		<p>
			<label for="event_location"><?php esc_html_e( 'Location:', 'decker' ); ?></label><br>
			<input type="text" id="event_location" name="event_location"
				value="<?php echo esc_attr( $location ); ?>" class="widefat">
		</p>
		<p>
			<label for="event_url"><?php esc_html_e( 'URL:', 'decker' ); ?></label><br>
			<input type="url" id="event_url" name="event_url"
				value="<?php echo esc_attr( $url ); ?>" class="widefat">
		</p>
		<?php
	}

	/**
	 * Render the category select.
	 *
	 * @param string $category The stored category value.
	 */
	private function render_category_field( $category ) {
		?>
		<p>
			<label for="event_category"><?php esc_html_e( 'Category:', 'decker' ); ?></label><br>
			<select id="event_category" name="event_category">
				<option value="bg-danger" <?php selected( $category, 'bg-danger' ); ?>><?php esc_html_e( 'Danger', 'decker' ); ?></option>
				<option value="bg-success" <?php selected( $category, 'bg-success' ); ?>><?php esc_html_e( 'Success', 'decker' ); ?></option>
				<option value="bg-primary" <?php selected( $category, 'bg-primary' ); ?>><?php esc_html_e( 'Primary', 'decker' ); ?></option>
				<option value="bg-info" <?php selected( $category, 'bg-info' ); ?>><?php esc_html_e( 'Info', 'decker' ); ?></option>
				<option value="bg-dark" <?php selected( $category, 'bg-dark' ); ?>><?php esc_html_e( 'Dark', 'decker' ); ?></option>
				<option value="bg-warning" <?php selected( $category, 'bg-warning' ); ?>><?php esc_html_e( 'Warning', 'decker' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Display the users meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_users_meta_box( $post ) {
		$users = get_users( array( 'orderby' => 'display_name' ) );
		$assigned_users = get_post_meta( $post->ID, 'event_assigned_users', true );
		?>
		<div id="assigned-users" class="categorydiv">
			<ul class="categorychecklist form-no-clear">
				<?php foreach ( $users as $user ) { ?>
					<li>
						<label class="selectit">
							<input type="checkbox" name="event_assigned_users[]" value="<?php echo esc_attr( $user->ID ); ?>" <?php checked( is_array( $assigned_users ) && in_array( $user->ID, $assigned_users ) ); ?>>
							<?php echo esc_html( $user->display_name ); ?>
						</label>
					</li>
				<?php } ?>
			</ul>
		</div>
		<?php
	}
}
