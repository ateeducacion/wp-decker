<footer class="footer">
	<div class="container-fluid py-3">
		<div class="row align-items-center">
			<div class="col-md-6">
				<p class="mb-0">
					<?php echo date('Y'); ?> &copy; <?php echo esc_html(get_bloginfo('description')); ?>
				</p>
			</div>
			<div class="col-md-6 text-md-end">
				<a href="https://github.com/ateeducacion/wp-decker/" target="_blank" rel="noopener noreferrer" class="footer-link">
					<i class="ri-github-fill" aria-hidden="true"></i> <?php echo esc_html(__('Version')) . ': ' . esc_html(DECKER_VERSION); ?>
				</a>
			</div>
		</div>
	</div>
</footer>
