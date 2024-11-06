<!-- ========== Left Sidebar Start ========== -->
<div class="leftside-menu">
  <!-- Brand Logo Light -->
  <a href="<?php echo add_query_arg( 'decker_page', 'priority', home_url( '/' ) ); ?>" class="logo logo-light">
	<span class="logo-lg">
	  <img src="<?php echo plugins_url( 'assets/images/logo.png', __DIR__ ); ?>" alt="logo" />
	</span>
	<span class="logo-sm">
	  <img src="<?php echo plugins_url( 'assets/images/logo-sm.png', __DIR__ ); ?>" alt="small logo" />
	</span>
  </a>

  <!-- Sidebar Hover Menu Toggle Button -->
  <div
	class="button-sm-hover"
	data-bs-toggle="tooltip"
	data-bs-placement="right"
	title="Show Full Sidebar"
  >
	<i class="ri-checkbox-blank-circle-line align-middle"></i>
  </div>

  <!-- Full Sidebar Menu Close Button -->
  <div class="button-close-fullsidebar">
	<i class="ri-close-fill align-middle"></i>
  </div>


  <!-- Sidebar -left -->
  <div class="h-100" id="leftside-menu-container" data-simplebar>

	<!--- Sidemenu -->
	<ul class="side-nav">
	  <li class="side-nav-title">Navigation</li>


	  <li class="side-nav-item">
		<a href="<?php echo add_query_arg( 'decker_page', 'priority', home_url( '/' ) ); ?>" class="side-nav-link">
		  <i class="ri-home-4-line"></i>
		  <span> Priority </span>
		</a>
	  </li>

	  <li class="side-nav-item">


		<a href="<?php echo add_query_arg( array( 'decker_page' => 'upcoming' ), home_url( '/' ) ); ?>" class="side-nav-link">
		<i class="ri-inbox-line"></i>
		  <span> Upcoming Tasks </span>
		</a>
 
		</a>
	  </li>


	  <li class="side-nav-title">Apps</li>

	  <li class="side-nav-item">
		<a
		  data-bs-toggle="collapse"
		  href="#sidebarTasks"
		  aria-expanded="false"
		  aria-controls="sidebarTasks"
		  class="side-nav-link"
		>
		  <i class="ri-task-line"></i>
		  <span> Tasks </span>
		  <span class="menu-arrow"></span>
		</a>
		<div class="collapse<?php echo ( get_query_var( 'decker_page' ) === 'tasks' ) ? ' show' : ''; ?>" id="sidebarTasks">
		  <ul class="side-nav-second-level">
			<?php
			$active_tasks_count = wp_count_posts( 'decker_task' )->publish;
			$current_user_id = get_current_user_id();
			$my_tasks_count = count(
				get_posts(
					array(
						'post_type' => 'decker_task',
						'meta_key' => 'assigned_users',
						'meta_value' => $current_user_id,
						'meta_compare' => 'LIKE',
					)
				)
			);
			$archived_tasks_count = wp_count_posts( 'decker_task' )->archived;
			?>
			<li><span class="badge bg-success float-end"><?php echo esc_html( $active_tasks_count ); ?></span><a href="
								<?php
								echo add_query_arg(
									array(
										'decker_page' => 'tasks',
										'type' => 'active',
									),
									home_url( '/' )
								);
								?>
			">Active Tasks</a></li>
			<li><span class="badge bg-success float-end"><?php echo esc_html( $my_tasks_count ); ?></span><a href="
																	<?php
																	echo add_query_arg(
																		array(
																			'decker_page' => 'tasks',
																			'type' => 'my',
																		),
																		home_url( '/' )
																	);
																	?>
			">My Tasks</a></li>
			<li><span class="badge bg-success float-end"><?php echo esc_html( $archived_tasks_count ); ?></span><a href="
																	<?php
																	echo add_query_arg(
																		array(
																			'decker_page' => 'tasks',
																			'type' => 'archived',
																		),
																		home_url( '/' )
																	);
																	?>
			">Archived Tasks</a></li>
			<li><a href="<?php echo add_query_arg( 'decker_page', 'task', home_url( '/' ) ); ?>">One Tasks (sample)</a></li>
		  </ul>
		</div>
	  </li>

	  <li class="side-nav-item">
		<a
		  data-bs-toggle="collapse"
		  href="#sidebarBoards"
		  aria-expanded="false"
		  aria-controls="sidebarBoards"
		  class="side-nav-link"
		>
		  <i class="ri-list-check-3"></i>
				   


		  <!-- <span class="badge bg-success float-end"></span> -->
		  <span> Boards </span>
		  <span class="menu-arrow"></span>



		</a>
		<div class="collapse<?php echo ( get_query_var( 'decker_page' ) === 'board' ) ? ' show' : ''; ?>" id="sidebarBoards">
		  <ul class="side-nav-second-level">

			<?php

			$boards = get_terms(
				array(
					'taxonomy' => 'decker_board',
					'hide_empty' => false,
				)
			);

			foreach ( $boards as $board ) {
				echo '<li><a class="text-truncate" title="' . esc_html( $board->name ) . '" href="' . esc_url(
					add_query_arg(
						array(
							'decker_page' => 'board',
							'slug' => $board->slug,
						),
						home_url( '/' )
					)
				) . '">' . esc_html( $board->name ) . '</a></li>';
			}
			?>

		  </ul>
		</div>
	  </li>

	  <li class="side-nav-item">
		<a href="<?php echo add_query_arg( 'decker_page', 'analytics', home_url( '/' ) ); ?>" class="side-nav-link">
		  <i class="ri-bar-chart-line"></i>
		  <span> Analytics </span>
		</a>
	  </li>


	</ul>
	<!--- End Sidemenu -->

	<div class="clearfix"></div>
  </div>
</div>
<!-- ========== Left Sidebar End ========== -->
