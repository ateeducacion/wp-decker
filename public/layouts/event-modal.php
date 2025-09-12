<?php
/**
 * File event-modal
 *
 * @package    Decker
 * @subpackage Decker/public/layouts
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<!-- Event Modal -->
<div class="modal fade event-modal" id="event-modal" tabindex="-1" role="dialog" aria-labelledby="NewEventModalLabel">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="NewEventModalLabel"><?php esc_html_e( 'Event', 'decker' ); ?></h4>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div id="event-modal-body" class="modal-body">
				<div id="event-modal-content">
									<!-- Dynamic content from event-card.php will load here -->
				</div>
			</div>
		</div><!-- /.modal-content -->
	</div><!-- /.modal-dialog -->
</div><!-- /.modal -->
