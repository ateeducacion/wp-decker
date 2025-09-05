<?php
/**
 * File task-modal
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<!-- Task Modal -->
<div class="modal fade task-modal" id="task-modal" tabindex="-1" role="dialog" aria-labelledby="NewTaskModalLabel">
	<div class="modal-dialog modal-dialog-centered modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="NewTaskModalLabel"><?php esc_html_e( 'Task', 'decker' ); ?></h4>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div id="task-modal-body"  class="modal-body">
				<div id="task-modal-content">
                                    <!-- Dynamic content from task-card.php will load here -->
				</div>
			</div>
		</div><!-- /.modal-content -->
	</div><!-- /.modal-dialog -->
</div><!-- /.modal -->
