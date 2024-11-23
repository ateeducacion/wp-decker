<!-- ========== Left Sidebar Start ========== -->


<?php

/**
 * Determina si la página actual de Decker coincide con la proporcionada.
 *
 * @param string $page El valor de `decker_page` a verificar.
 * @return string 'active' si coincide, de lo contrario, una cadena vacía.
 */
function decker_is_active_page( $page ) {
    if ( isset( $_GET['decker_page'] ) && sanitize_text_field( $_GET['decker_page'] ) === $page ) {
        return 'menuitem-active';
    }
    return '';
}

function decker_is_active_subpage( $get_parameter, $page ) {
    if ( isset( $_GET[$get_parameter] ) && sanitize_text_field( $_GET[$get_parameter] ) === $page ) {
        return 'active';
    }
    return '';
}

?>

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
	title="<?php esc_attr_e('Show Full Sidebar', 'decker'); ?>"
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
	  <li class="side-nav-title"><?php _e('Navigation', 'decker'); ?></li>


	  <li class="side-nav-item <?php echo decker_is_active_page( 'priority' ); ?>">
		<a href="<?php echo add_query_arg( 'decker_page', 'priority', home_url( '/' ) ); ?>" class="side-nav-link">
		  <i class="ri-home-4-line"></i>
		  <span><?php _e('Priority', 'decker'); ?></span>
		</a>
	  </li>

	  <li class="side-nav-item <?php echo decker_is_active_page( 'upcoming' ); ?>">


			<a href="<?php echo add_query_arg( array( 'decker_page' => 'upcoming' ), home_url( '/' ) ); ?>" class="side-nav-link">
			<i class="ri-inbox-line"></i>
			  <span><?php _e('Upcoming Tasks', 'decker'); ?></span>
			</a>
	 
			</a>
	  </li>


	  <li class="side-nav-item <?php echo decker_is_active_page( 'my-board' ); ?>">


			<a href="<?php echo add_query_arg( array( 'decker_page' => 'my-board' ), home_url( '/' ) ); ?>" class="side-nav-link">
			<i class="ri-trello-line"></i>
			  <span><?php _e('My Board', 'decker'); ?></span>
			</a>
	 
			</a>
	  </li>

	  <li class="side-nav-title"><?php _e('Apps', 'decker'); ?></li>

	  <!-- Tasks -->
	  <li class="side-nav-item <?php echo decker_is_active_page( 'tasks' ); ?>">
		<a
		  data-bs-toggle="collapse"
		  href="#sidebarTasks"
		  aria-expanded="false"
		  aria-controls="sidebarTasks"
		  class="side-nav-link"
		>
		  <i class="ri-task-line"></i>
		  <span><?php _e('Tasks', 'decker'); ?></span>
		  <span class="menu-arrow"></span>
		</a>
		<div class="collapse<?php echo ( get_query_var( 'decker_page' ) === 'tasks' ) ? ' show' : ''; ?>" id="sidebarTasks">
		  <ul class="side-nav-second-level">
			<?php
			$active_tasks_count = wp_count_posts( 'decker_task' )->publish;
      $taskManager = new taskManager();
      $my_tasks = $taskManager->getTasksByUser(get_current_user_id());
      $my_tasks_count = count($my_tasks);
			$archived_tasks_count = wp_count_posts( 'decker_task' )->archived;
			?>
			<li class="<?php echo decker_is_active_subpage( 'type', 'active' ); ?>"><span class="badge bg-success float-end"><?php echo esc_html( $active_tasks_count ); ?></span><a href="<?php
								echo add_query_arg(
									array(
										'decker_page' => 'tasks',
										'type' => 'active',
									),
									home_url( '/' )
								);
								?>"><?php _e('Active Tasks', 'decker'); ?></a></li>
			<li class="<?php echo decker_is_active_subpage( 'type', 'my' ); ?>"><span class="badge bg-info float-end"><?php echo esc_html( $my_tasks_count ); ?></span><a href="<?php
																	echo add_query_arg(
																		array(
																			'decker_page' => 'tasks',
																			'type' => 'my',
																		),
																		home_url( '/' )
																	);
																	?>"><?php _e('My Tasks', 'decker'); ?></a></li>
			<li class="<?php echo decker_is_active_subpage( 'type', 'archived' ); ?>"><span class="badge bg-warning float-end"><?php echo esc_html( $archived_tasks_count ); ?></span><a href="<?php
																	echo add_query_arg(
																		array(
																			'decker_page' => 'tasks',
																			'type' => 'archived',
																		),
																		home_url( '/' )
																	);
																	?>"><?php _e('Archived Tasks', 'decker'); ?></a></li>
			<li class="<?php echo decker_is_active_subpage( 'type', 'new' ); ?>"><a href="<?php echo add_query_arg( array( 'decker_page' => 'task', 'type' => 'new'), home_url( '/' ) ); ?>"><?php _e('New task', 'decker'); ?></a></li>
		  </ul>
		</div>
	  </li>

	  <!-- Boards -->
	  <li class="side-nav-item" <?php echo decker_is_active_page( 'board' ); ?>>
		<a
		  data-bs-toggle="collapse"
		  href="#sidebarBoards"
		  aria-expanded="false"
		  aria-controls="sidebarBoards"
		  class="side-nav-link"
		>
		  <i class="ri-list-check-3"></i>
				   


		  <!-- <span class="badge bg-success float-end"></span> -->
		  <span><?php _e('Boards', 'decker'); ?></span>
		  <span class="menu-arrow"></span>



		</a>
		<div class="collapse<?php echo ( get_query_var( 'decker_page' ) === 'board' ) ? ' show' : ''; ?>" id="sidebarBoards">
		  <ul class="side-nav-second-level">

			<?php

				// Obtener el slug del board desde la URL
				$current_board_slug = isset( $_GET['slug'] ) ? sanitize_title( $_GET['slug'] ) : '';

				$boards = BoardManager::getAllBoards();
				foreach ( $boards as $board ) {

					echo '<li class="' . decker_is_active_subpage( 'slug', $board->slug ) .'"><a class="text-truncate" title="' . esc_html( $board->name ) . '" href="' . esc_url(
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

	  <!-- Analytics -->
	  <li class="side-nav-item <?php echo decker_is_active_page( 'analytics' ); ?>">
		<a href="<?php echo add_query_arg( 'decker_page', 'analytics', home_url( '/' ) ); ?>" class="side-nav-link">
		  <i class="ri-bar-chart-line"></i>
		  <span><?php _e('Analytics', 'decker'); ?></span>
		</a>
	  </li>


	  <!-- Utilities -->
	  <li class="side-nav-item" <?php echo decker_is_active_page( 'utilities' ); ?>>
		<a
		  data-bs-toggle="collapse"
		  href="#sidebarUtilities"
		  aria-expanded="false"
		  aria-controls="sidebarUtilities"
		  class="side-nav-link"
		>
		  <i class="ri-list-check-3"></i>
				   


		  <!-- <span class="badge bg-success float-end"></span> -->
		  <span><?php _e('Utilities', 'decker'); ?></span>
		  <span class="menu-arrow"></span>



		</a>
		<div class="collapse<?php echo ( get_query_var( 'decker_page' ) === 'board' ) ? ' show' : ''; ?>" id="sidebarUtilities">
			<ul class="side-nav-second-level">


				<li class="<?php echo decker_is_active_subpage( 'type', 'active' ); ?>"><span class="badge bg-success float-end"><?php echo esc_html( $active_tasks_count ); ?></span><a href="<?php
					echo add_query_arg(
						array(
							'decker_page' => 'term-manager',
							'type' => 'label',
						),
						home_url( '/' )
					);
				?>"><?php _e('Labels', 'decker'); ?></a></li>

				<li class="<?php echo decker_is_active_subpage( 'type', 'active' ); ?>"><span class="badge bg-success float-end"><?php echo esc_html( $active_tasks_count ); ?></span><a href="<?php
					echo add_query_arg(
						array(
							'decker_page' => 'term-manager',
							'type' => 'board',
						),
						home_url( '/' )
					);
				?>"><?php _e('Boards', 'decker'); ?></a></li>

				<?php /* TO-DO: Add the actions manager
				<li class="<?php echo decker_is_active_subpage( 'type', 'active' ); ?>"><span class="badge bg-success float-end"><?php echo esc_html( $active_tasks_count ); ?></span><a href="<?php
					echo add_query_arg(
						array(
							'decker_page' => 'term-manager',
							'type' => 'action',
						),
						home_url( '/' )
					);
				?>"><?php _e('Actions', 'decker'); ?></a></li>
				*/ ?>

		  </ul>
		</div>
	  </li>



	</ul>
	<!--- End Sidemenu -->

	<div class="clearfix"></div>
  </div>
</div>
<!-- ========== Left Sidebar End ========== -->
