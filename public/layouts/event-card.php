<?php
/**
 * File event-card
 *
 * @package    Decker
 * @subpackage Decker/public/layouts
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Render an event card
 *
 * @param Event $event The event to render.
 */
function render_event_card( $event ) {
	?>
	<tr>
		<td class="event-title">
			<?php echo esc_html( $event->get_title() ); ?>
		</td>
		<td class="event-start">
			<?php echo esc_html( $event->get_start_date()->format( 'Y-m-d H:i' ) ); ?>
		</td>
		<td class="event-end">
			<?php echo esc_html( $event->get_end_date()->format( 'Y-m-d H:i' ) ); ?>
		</td>
		<td class="event-category">
			<span class="badge <?php echo esc_attr( $event->get_category() ); ?>">
				<?php echo esc_html( str_replace( 'bg-', '', $event->get_category() ) ); ?>
			</span>
		</td>
		<td>
			<a href="#" class="btn btn-sm btn-info me-2 edit-event" 
			   data-id="<?php echo esc_attr( $event->get_id() ); ?>">
				<i class="ri-pencil-line"></i>
			</a>
			<a href="#" class="btn btn-sm btn-danger delete-event" 
			   data-id="<?php echo esc_attr( $event->get_id() ); ?>">
				<i class="ri-delete-bin-line"></i>
			</a>
			<span class="event-description d-none">
				<?php echo esc_html( $event->get_description() ); ?>
			</span>
			<span class="event-assigned-users d-none">
				<?php echo esc_attr( json_encode( $event->get_assigned_users() ) ); ?>
			</span>
		</td>
	</tr>
	<?php
}
