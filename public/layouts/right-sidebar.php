<?php
/**
 * File right-sidebar
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

?>
<div class="offcanvas offcanvas-end" tabindex="-1" id="theme-settings-offcanvas">
	<div class="d-flex align-items-center bg-primary p-3 offcanvas-header">
		<h5 class="text-white m-0"><?php esc_html_e( 'Theme Settings', 'decker' ); ?></h5>
		<button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas" aria-label="Close"></button>
	</div>

	<div class="offcanvas-body p-0">
		<div data-simplebar class="h-100">
			<div class="card mb-0 p-3">
				<div class="alert alert-warning" role="alert">
					<strong><?php esc_html_e( 'Customize', 'decker' ); ?> </strong> <?php esc_html_e( 'the overall color scheme, sidebar menu, etc.', 'decker' ); ?>
				</div>

				<h5 class="mt-0 fs-16 fw-bold mb-3"><?php esc_html_e( 'Choose Layout', 'decker' ); ?></h5>
				<div class="d-flex flex-column gap-2">
					<div class="form-check form-switch">
						<input id="customizer-layout01" name="data-layout" type="checkbox" value="vertical" class="form-check-input">
						<label class="form-check-label" for="customizer-layout01"><?php esc_html_e( 'Vertical', 'decker' ); ?></label>
					</div>
					<div class="form-check form-switch">
						<input id="customizer-layout02" name="data-layout" type="checkbox" value="horizontal" class="form-check-input">
						<label class="form-check-label" for="customizer-layout02"><?php esc_html_e( 'Horizontal', 'decker' ); ?></label>
					</div>
				</div>

				<h5 class="my-3 fs-16 fw-bold"><?php esc_html_e( 'Color Scheme', 'decker' ); ?></h5>

				<div class="d-flex flex-column gap-2">
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" name="data-bs-theme" id="layout-color-light" value="light">
						<label class="form-check-label" for="layout-color-light"><?php esc_html_e( 'Light', 'decker' ); ?></label>
					</div>

					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" name="data-bs-theme" id="layout-color-dark" value="dark">
						<label class="form-check-label" for="layout-color-dark"><?php esc_html_e( 'Dark', 'decker' ); ?></label>
					</div>
				</div>

				<div id="layout-width">
					<h5 class="my-3 fs-16 fw-bold"><?php esc_html_e( 'Layout Mode', 'decker' ); ?></h5>

					<div class="d-flex flex-column gap-2">
						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="data-layout-mode" id="layout-mode-fluid" value="fluid">
							<label class="form-check-label" for="layout-mode-fluid"><?php esc_html_e( 'Fluid', 'decker' ); ?></label>
						</div>

						<div id="layout-boxed">
							<div class="form-check form-switch">
								<input class="form-check-input" type="checkbox" name="data-layout-mode" id="layout-mode-boxed" value="boxed">
								<label class="form-check-label" for="layout-mode-boxed"><?php esc_html_e( 'Boxed', 'decker' ); ?></label>
							</div>
						</div>

						<div id="layout-detached">
							<div class="form-check form-switch">
								<input class="form-check-input" type="checkbox" name="data-layout-mode" id="data-layout-detached" value="detached">
								<label class="form-check-label" for="data-layout-detached"><?php esc_html_e( 'Detached', 'decker' ); ?></label>
							</div>
						</div>
					</div>
				</div>

				<h5 class="my-3 fs-16 fw-bold"><?php esc_html_e( 'Topbar Color', 'decker' ); ?></h5>

				<div class="d-flex flex-column gap-2">
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" name="data-topbar-color" id="topbar-color-light" value="light">
						<label class="form-check-label" for="topbar-color-light"><?php esc_html_e( 'Light', 'decker' ); ?></label>
					</div>

					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" name="data-topbar-color" id="topbar-color-dark" value="dark">
						<label class="form-check-label" for="topbar-color-dark"><?php esc_html_e( 'Dark', 'decker' ); ?></label>
					</div>

					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" name="data-topbar-color" id="topbar-color-brand" value="brand">
						<label class="form-check-label" for="topbar-color-brand"><?php esc_html_e( 'Brand', 'decker' ); ?></label>
					</div>
				</div>

				<div>
					<h5 class="my-3 fs-16 fw-bold"><?php esc_html_e( 'Menu Color', 'decker' ); ?></h5>

					<div class="d-flex flex-column gap-2">
						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="data-menu-color" id="leftbar-color-light" value="light">
							<label class="form-check-label" for="leftbar-color-light"><?php esc_html_e( 'Light', 'decker' ); ?></label>
						</div>

						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="data-menu-color" id="leftbar-color-dark" value="dark">
							<label class="form-check-label" for="leftbar-color-dark"><?php esc_html_e( 'Dark', 'decker' ); ?></label>
						</div>
						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="data-menu-color" id="leftbar-color-brand" value="brand">
							<label class="form-check-label" for="leftbar-color-brand"><?php esc_html_e( 'Brand', 'decker' ); ?></label>
						</div>
					</div>
				</div>

				<div id="sidebar-size">
					<h5 class="my-3 fs-16 fw-bold"><?php esc_html_e( 'Sidebar Size', 'decker' ); ?></h5>

					<div class="d-flex flex-column gap-2">
						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="data-sidenav-size" id="leftbar-size-default" value="default">
							<label class="form-check-label" for="leftbar-size-default"><?php esc_html_e( 'Default', 'decker' ); ?></label>
						</div>

						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="data-sidenav-size" id="leftbar-size-compact" value="compact">
							<label class="form-check-label" for="leftbar-size-compact"><?php esc_html_e( 'Compact', 'decker' ); ?></label>
						</div>

						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="data-sidenav-size" id="leftbar-size-small" value="condensed">
							<label class="form-check-label" for="leftbar-size-small"><?php esc_html_e( 'Condensed', 'decker' ); ?></label>
						</div>

						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="data-sidenav-size" id="leftbar-size-small-hover" value="sm-hover">
							<label class="form-check-label" for="leftbar-size-small-hover"><?php esc_html_e( 'Hover View', 'decker' ); ?></label>
						</div>

						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="data-sidenav-size" id="leftbar-size-full" value="full">
							<label class="form-check-label" for="leftbar-size-full"><?php esc_html_e( 'Full Layout', 'decker' ); ?></label>
						</div>

						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="data-sidenav-size" id="leftbar-size-fullscreen" value="fullscreen">
							<label class="form-check-label" for="leftbar-size-fullscreen"><?php esc_html_e( 'Fullscreen Layout', 'decker' ); ?></label>
						</div>
					</div>
				</div>

				<div id="layout-position">
					<h5 class="my-3 fs-16 fw-bold"><?php esc_html_e( 'Layout Position', 'decker' ); ?></h5>

					<div class="btn-group checkbox" role="group">
						<input type="radio" class="btn-check" name="data-layout-position" id="layout-position-fixed" value="fixed">
						<label class="btn btn-soft-primary w-sm" for="layout-position-fixed"><?php esc_html_e( 'Fixed', 'decker' ); ?></label>

						<input type="radio" class="btn-check" name="data-layout-position" id="layout-position-scrollable" value="scrollable">
						<label class="btn btn-soft-primary w-sm ms-0" for="layout-position-scrollable"><?php esc_html_e( 'Scrollable', 'decker' ); ?></label>
					</div>
				</div>

				<div id="sidebar-user">
					<div class="d-flex justify-content-between align-items-center mt-3">
						<label class="fs-16 fw-bold m-0" for="sidebaruser-check"><?php esc_html_e( 'Sidebar User Info', 'decker' ); ?></label>
						<div class="form-check form-switch">
							<input type="checkbox" class="form-check-input" name="sidebar-user" id="sidebaruser-check">
						</div>
					</div>
				</div>

			</div>
		</div>

	</div>
	<div class="offcanvas-footer border-top p-3 text-center">
		<div class="row">
			<div class="col-6">
				<button type="button" class="btn btn-light w-100" id="reset-layout"><?php esc_html_e( 'Reset', 'decker' ); ?></button>
			</div>
			<div class="col-6">
				<a href="#" role="button" class="btn btn-primary w-100"><?php esc_html_e( 'Send', 'decker' ); ?></a>
			</div>
		</div>
	</div>
</div>
