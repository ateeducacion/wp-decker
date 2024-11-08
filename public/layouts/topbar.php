<div class="navbar-custom">
	<div class="topbar container-fluid">
		<div class="d-flex align-items-center gap-lg-2 gap-1">

		   
			<div class="logo-topbar">
				
				<a href="index.php" class="logo-light">
					<span class="logo-lg">
						<img src="<?php echo plugins_url( 'assets/images/logo.png', __DIR__ ); ?>" alt="logo">
					</span>
					<span class="logo-sm">
						<img src="<?php echo plugins_url( 'assets/images/logo-sm.png', __DIR__ ); ?>" alt="small logo">
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


			<li class="d-none d-sm-inline-block">
				<div class="nav-link" id="light-dark-mode" data-bs-toggle="tooltip" data-bs-placement="left" title="Theme Mode">
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
					$current_user = wp_get_current_user();
					?>
					<span class="account-user-avatar">
						<img src="<?php echo esc_url( get_avatar_url( $current_user->ID ) ); ?>" alt="user-image" width="32" class="rounded-circle">
					</span>
					<span class="d-lg-flex flex-column gap-1 d-none">
						<h5 class="my-0"><?php echo esc_html( $current_user->display_name ); ?></h5>
						<h6 class="my-0 fw-normal"><?php echo esc_html( $current_user->user_email ); ?></h6>
					</span>
				</a>
				<div class="dropdown-menu dropdown-menu-end dropdown-menu-animated profile-dropdown">
					
					<div class=" dropdown-header noti-title">
						<h6 class="text-overflow m-0">Welcome !</h6>
					</div>

					<!-- item-->
					<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>" class="dropdown-item">
						<i class="ri-account-circle-line fs-18 align-middle me-1"></i>
						<span>My Profile</span>
					</a>
					<!-- item-->
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=decker_settings' ) ); ?>" class="dropdown-item">
						<i class="ri-settings-4-line fs-18 align-middle me-1"></i>
						<span>Decker Settings</span>
					</a>
					<!-- item-->
					<a href="<?php echo esc_url( wp_logout_url() ); ?>" class="dropdown-item">
						<i class="ri-logout-box-line fs-18 align-middle me-1"></i>
						<span>Logout</span>
					</a>
				</div>
			</li>
		</ul>
	</div>
</div>
