<?php
// phpcs:ignoreFile

/**
 * File topbar
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

?>
<div class="navbar-custom">
	<div class="topbar container-fluid">
		<div class="d-flex align-items-center gap-lg-2 gap-1">

		   
			<div class="logo-topbar">
				
				<a href="index.php" class="logo-light">
					<span class="logo-lg">
						<img src="<?php echo esc_url( plugins_url( 'assets/images/logo.png', __DIR__ ) ); ?>" alt="logo">
					</span>
					<span class="logo-sm">
						<img src="<?php echo esc_url( plugins_url( 'assets/images/logo-sm.png', __DIR__ ) ); ?>" alt="small logo">
					</span>

				</a>

			</div>
			
			<button class="button-toggle-menu">
				<i class="ri-menu-2-fill"></i>
			</button>

			
			<button class="navbar-toggle" data-bs-toggle="collapse" data-bs-target="#topnav-menu-content">
				<div class="lines">
					<span></span>
					<span></span>
					<span></span>
				</div>
			</button>

		</div>

		<ul class="topbar-menu d-flex align-items-center gap-3">

			<li class="dropdown notification-list">
                <a class="nav-link dropdown-toggle arrow-none" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                    <i class="ri-notification-3-line fs-22"></i>
                    <span class="noti-icon-badge"></span>
                </a>

				<div class="dropdown-menu dropdown-menu-end dropdown-menu-animated dropdown-lg py-0">

					<div class="p-2 border-top-0 border-start-0 border-end-0 border-dashed border">
					    <div class="row align-items-center">
					        <div class="col">
					            <h6 class="m-0 fs-16 fw-semibold"> <?php esc_html_e( 'Notifications', 'decker' ); ?></h6>
					        </div>
					        <div class="col-auto">
					            <a href="javascript: void(0);" class="text-dark text-decoration-underline">
					                <small><?php esc_html_e( 'Clear All', 'decker' ); ?></small>
					            </a>
					        </div>
					    </div>
					</div>

		            <div id="notification-list" style="max-height: 300px; overflow-y: auto;">
		            

		            </div>

		        </div>
            </li>

			<li class="d-none d-sm-inline-block">
				<div class="nav-link" id="light-dark-mode" data-bs-toggle="tooltip" data-bs-placement="left" title="<?php esc_attr_e( 'Theme Mode', 'decker' ); ?>">
					<i class="ri-moon-line fs-22"></i>
				</div>
			</li>


			<li class="d-none d-md-inline-block">
				<a class="nav-link" href="" data-toggle="fullscreen">
					<i class="ri-fullscreen-line fs-22"></i>
				</a>
			</li>

			<li class="dropdown">
				<a class="nav-link dropdown-toggle arrow-none nav-user px-2" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
				<?php
					$user = wp_get_current_user();
				?>
					<span class="account-user-avatar">
						<img src="<?php echo esc_url( get_avatar_url( $user->ID ) ); ?>" alt="user-image" width="32" class="rounded-circle">
					</span>
					<span class="d-lg-flex flex-column gap-1 d-none">
						<h5 class="my-0"><?php echo esc_html( $user->display_name ); ?></h5>
						<h6 class="my-0 fw-normal"><?php echo esc_html( $user->user_email ); ?></h6>
					</span>
				</a>
				<div class="dropdown-menu dropdown-menu-end dropdown-menu-animated profile-dropdown">
					
					<div class=" dropdown-header noti-title">
						<h6 class="text-overflow m-0"><?php esc_html_e( 'Welcome !', 'decker' ); ?></h6>
					</div>

					<!-- item my-profile -->
					<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>" class="dropdown-item">
						<i class="ri-account-circle-line fs-18 align-middle me-1"></i>
						<span><?php esc_html_e( 'My Profile', 'decker' ); ?></span>
					</a>

					<?php if ( current_user_can( 'manage_options' ) ) { ?> 

					<!-- item decker settings -->
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=decker_settings' ) ); ?>" class="dropdown-item">
						<i class="ri-settings-4-line fs-18 align-middle me-1"></i>
						<span><?php esc_html_e( 'Decker Settings', 'decker' ); ?></span>
					</a>

					<?php } ?>


					<?php if ( current_user_can( 'manage_options' ) ) { ?>

					<!-- item test notification -->
					<a href="#" id="sendTestNotification" class="dropdown-item">
					    <i class="ri-megaphone-line fs-18 align-middle me-1"></i>
					    <span><?php esc_html_e( 'Test Notification', 'decker' ); ?></span>
					</a>


					<?php } ?>




					<!-- item help -->
					<a href="https://ateeducacion.github.io/wp-decker/" class="dropdown-item" target="_blank">
						<i class="ri-question-line fs-18 align-middle me-1"></i>
						<span><?php esc_html_e( 'Help', 'decker' ); ?></span>
					</a>
					<!-- item logout -->
					<a href="<?php echo esc_url( wp_logout_url() ); ?>" class="dropdown-item">
						<i class="ri-logout-box-line fs-18 align-middle me-1"></i>
						<span><?php esc_html_e( 'Logout', 'decker' ); ?></span>
					</a>
				</div>
			</li>
		</ul>
	</div>
</div>

