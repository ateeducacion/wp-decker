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
<!--  Add new task modal -->
<div class="modal fade task-modal" id="task-modal" tabindex="-1" role="dialog" aria-labelledby="NewTaskModalLabel">
	<div class="modal-dialog modal-dialog-centered modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="NewTaskModalLabel"><?php esc_html_e( 'Task', 'decker' ); ?></h4>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div id="task-modal-content">
					<!-- Aquí se cargará el contenido dinámico desde task-card.php -->
				</div>
			</div>
		</div><!-- /.modal-content -->
	</div><!-- /.modal-dialog -->
</div><!-- /.modal -->
