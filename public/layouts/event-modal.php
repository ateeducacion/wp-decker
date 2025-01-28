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
<div class="modal fade" id="event-modal" tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header py-3 px-4 border-bottom-0">
				<h5 class="modal-title" id="modal-title"><?php esc_html_e( 'Event', 'decker' ); ?></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body px-4 pb-4 pt-0" id="event-modal-content">
				<!-- Event card content will be loaded here -->
			</div>
		</div>
	</div>
</div>
