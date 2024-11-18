<?php
$options = get_option( 'decker_settings', array() );
$alert_message = isset( $options['alert_message'] ) ? $options['alert_message'] : '';
$alert_color = isset( $options['alert_color'] ) ? $options['alert_color'] : 'info';

if ( ! empty( $alert_message ) ) : ?>
	<div class="alert alert-<?php echo esc_attr( $alert_color ); ?> alert-dismissible fade show" role="alert">
		<i class="ri-alert-fill"></i>
		<?php echo wp_kses_post( $alert_message ); ?>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
	</div>
<?php endif; ?>
