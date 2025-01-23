<?php
/**
 * File left-sidebar
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

?>
<!-- ========== Left Sidebar Start ========== -->


<?php

/**
 * Determina si la página actual de Decker coincide con la proporcionada.
 *
 * @param string $page El valor de `decker_page` a verificar.
 * @return string 'menuitem-active' if it matches, otherwise an empty string.
 */
function decker_is_active_page( $page ) {
	if ( isset( $_GET['decker_page'] ) && sanitize_text_field( wp_unslash( $_GET['decker_page'] ) ) === $page ) {
		return 'menuitem-active';
	}
	return '';
}

/**
 * Determina si la subpágina actual de Decker coincide con la proporcionada.
 *
 * @param string $get_parameter The GET parameter to check.
 * @param string $page El valor de `decker_page` a verificar.
 * @return string 'menuitem-active' if it matches, otherwise an empty string.
 */
function decker_is_active_subpage( $get_parameter, $page ) {
	if ( isset( $_GET[ $get_parameter ] ) && sanitize_text_field( wp_unslash( $_GET[ $get_parameter ] ) ) === $page ) {
		return 'active';
	}
	return '';
}

?>

<div class="leftside-menu">
  <!-- Brand Logo Light -->
  <a href="<?php echo esc_url( add_query_arg( 'decker_page', 'priority', home_url( '/' ) ) ); ?>" class="logo logo-light">
	<span class="logo-lg">
	  <img src="<?php echo esc_url( plugins_url( 'assets/images/logo.png', __DIR__ ) ); ?>" alt="logo" />
	</span>
	<span class="logo-sm">
	  <img src="<?php echo esc_url( plugins_url( 'assets/images/logo-sm.png', __DIR__ ) ); ?>" alt="small logo" />
	</span>
  </a>

  <!-- Sidebar Hover Menu Toggle Button -->
  <div
	class="button-sm-hover"
	data-bs-toggle="tooltip"
	data-bs-placement="right"
	title="<?php echo esc_attr_e( 'Show Full Sidebar', 'decker' ); ?>"
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
	  <li class="side-nav-title"><?php esc_html_e( 'Navigation', 'decker' ); ?></li>


	  <li class="side-nav-item <?php echo esc_attr( decker_is_active_page( 'priority' ) ); ?>">
		<a href="<?php echo esc_url( add_query_arg( 'decker_page', 'priority', home_url( '/' ) ) ); ?>" class="side-nav-link">
		  <i class="ri-home-4-line"></i>
		  <span><?php esc_html_e( 'Priority', 'decker' ); ?></span>
		</a>
	  </li>

	  <li class="side-nav-item <?php echo esc_attr( decker_is_active_page( 'upcoming' ) ); ?>">


			<a href="<?php echo esc_url( add_query_arg( array( 'decker_page' => 'upcoming' ), home_url( '/' ) ) ); ?>" class="side-nav-link">
			<i class="ri-inbox-line"></i>
			  <span><?php esc_html_e( 'Upcoming Tasks', 'decker' ); ?></span>
			</a>
	 
			</a>
	  </li>


	  <li class="side-nav-item <?php echo esc_attr( decker_is_active_page( 'my-board' ) ); ?>">


			<a href="<?php echo esc_url( add_query_arg( array( 'decker_page' => 'my-board' ), home_url( '/' ) ) ); ?>" class="side-nav-link">
			<i class="ri-trello-line"></i>
			  <span><?php esc_html_e( 'My Board', 'decker' ); ?></span>
			</a>
	 
			</a>
	  </li>

	  <li class="side-nav-title"><?php esc_html_e( 'Apps', 'decker' ); ?></li>


		<!-- Calendar -->
	  <li class="side-nav-item <?php echo esc_attr( decker_is_active_page( 'calendar' ) ); ?>">


			<a href="<?php echo esc_url( add_query_arg( array( 'decker_page' => 'calendar' ), home_url( '/' ) ) ); ?>" class="side-nav-link">
			<i class="ri-calendar-event-line"></i>
			  <span><?php esc_html_e( 'Calendar', 'decker' ); ?></span>
			</a>
	 
			</a>
	  </li>



	  <!-- Tasks -->
	  <li class="side-nav-item <?php echo esc_attr( decker_is_active_page( 'tasks' ) ); ?>">
		<a
		  data-bs-toggle="collapse"
		  href="#sidebarTasks"
		  aria-expanded="false"
		  aria-controls="sidebarTasks"
		  class="side-nav-link"
		>
		  <i class="ri-task-line"></i>
		  <span><?php esc_html_e( 'Tasks', 'decker' ); ?></span>
		  <span class="menu-arrow"></span>
		</a>
		<div class="collapse<?php echo ( 'tasks' === get_query_var( 'decker_page' ) ) ? ' show' : ''; ?>" id="sidebarTasks">
		  <ul class="side-nav-second-level">
			<?php

			$task_manager         = new taskManager();

			$active_tasks         = $task_manager->get_tasks_by_status( 'publish' );
			$active_tasks_count   = count( $active_tasks );

			$my_tasks             = $task_manager->get_tasks_by_user( get_current_user_id() );
			$my_tasks_count       = count( $my_tasks );

			$archived_tasks_count = wp_count_posts( 'decker_task' )->archived;
			?>
			<li class="<?php echo esc_attr( decker_is_active_subpage( 'type', 'active' ) ); ?>"><span class="badge bg-success float-end"><?php echo esc_html( $active_tasks_count ); ?></span><a href="
								  <?php
									echo esc_url(
										add_query_arg(
											array(
												'decker_page' => 'tasks',
												'type'        => 'active',
											),
											home_url( '/' )
										)
									);
									?>
								"><?php esc_html_e( 'Active Tasks', 'decker' ); ?></a></li>
			<li class="<?php echo esc_attr( decker_is_active_subpage( 'type', 'my' ) ); ?>"><span class="badge bg-info float-end"><?php echo esc_html( $my_tasks_count ); ?></span><a href="
								  <?php
									echo esc_url(
										add_query_arg(
											array(
												'decker_page' => 'tasks',
												'type'        => 'my',
											),
											home_url( '/' )
										)
									);
									?>
																	"><?php esc_html_e( 'My Tasks', 'decker' ); ?></a></li>
			<li class="<?php echo esc_attr( decker_is_active_subpage( 'type', 'archived' ) ); ?>"><span class="badge bg-warning float-end"><?php echo esc_html( $archived_tasks_count ); ?></span><a href="
								  <?php
									echo esc_url(
										add_query_arg(
											array(
												'decker_page' => 'tasks',
												'type'        => 'archived',
											),
											home_url( '/' )
										)
									);
									?>
																	"><?php esc_html_e( 'Archived Tasks', 'decker' ); ?></a></li>
			<li class="<?php echo esc_attr( decker_is_active_subpage( 'type', 'new' ) ); ?>"><a href="
								  <?php
									echo esc_url(
										add_query_arg(
											array(
												'decker_page' => 'task',
												'type'        => 'new',
											),
											home_url( '/' )
										)
									);
									?>
			"><?php esc_html_e( 'New task', 'decker' ); ?></a></li>
		  </ul>
		</div>
	  </li>

	  <!-- Boards -->
	  <li class="side-nav-item" <?php echo esc_attr( decker_is_active_page( 'board' ) ); ?>>
		<a
		  data-bs-toggle="collapse"
		  href="#sidebarBoards"
		  aria-expanded="false"
		  aria-controls="sidebarBoards"
		  class="side-nav-link"
		>
		  <i class="ri-list-check-3"></i>
				   


		  <!-- <span class="badge bg-success float-end"></span> -->
		  <span><?php esc_html_e( 'Boards', 'decker' ); ?></span>
		  <span class="menu-arrow"></span>



		</a>
		<div class="collapse<?php echo ( 'board' === get_query_var( 'decker_page' ) ) ? ' show' : ''; ?>" id="sidebarBoards">
		  <ul class="side-nav-second-level">

			<?php

				// Obtener el slug del board desde la URL.
				$current_board_slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : '';

			$boards = BoardManager::get_all_boards();
			foreach ( $boards as $board ) {

				echo '<li class="' . esc_attr( decker_is_active_subpage( 'slug', $board->slug ) ) . '"><a class="text-truncate" title="' . esc_html( $board->name ) . '" href="' . esc_url(
					esc_url(
						add_query_arg(
							array(
								'decker_page' => 'board',
								'slug'        => $board->slug,
							),
							home_url( '/' )
						)
					)
				) . '">' . esc_html( $board->name ) . '</a></li>';
			}
			?>

		  </ul>
		</div>
	  </li>

	  <!-- Analytics -->
	  <li class="side-nav-item <?php echo esc_attr( decker_is_active_page( 'analytics' ) ); ?>">
		<a href="<?php echo esc_url( add_query_arg( 'decker_page', 'analytics', home_url( '/' ) ) ); ?>" class="side-nav-link">
		  <i class="ri-bar-chart-line"></i>
		  <span><?php esc_html_e( 'Analytics', 'decker' ); ?></span>
		</a>
	  </li>


	  <?php

		if ( current_user_can( 'manage_options' ) ) {

			?>

	  <!-- Utilities -->

	  <li class="side-nav-item" <?php echo esc_attr( decker_is_active_page( 'utilities' ) ); ?>>
		<a
		  data-bs-toggle="collapse"
		  href="#sidebarUtilities"
		  aria-expanded="false"
		  aria-controls="sidebarUtilities"
		  class="side-nav-link"
		>
		  <i class="ri-tools-line"></i>
				   


		  <!-- <span class="badge bg-success float-end"></span> -->
		  <span><?php esc_html_e( 'Utilities', 'decker' ); ?></span>
		  <span class="menu-arrow"></span>



		</a>
		<div class="collapse<?php echo ( 'term-manager' === get_query_var( 'decker_page' ) ) ? ' show' : ''; ?>" id="sidebarUtilities">
			<ul class="side-nav-second-level">


				<li class="<?php echo esc_attr( decker_is_active_subpage( 'type', 'label' ) ); ?>"><span class="badge bg-success float-end"><?php echo esc_html( count( LabelManager::get_all_labels() ) ); ?></span><a href="
									  <?php
										echo esc_url(
											add_query_arg(
												array(
													'decker_page' => 'term-manager',
													'type'        => 'label',
												),
												home_url( '/' )
											)
										);
										?>
				"><?php esc_html_e( 'Labels', 'decker' ); ?></a></li>

				<li class="<?php echo esc_attr( decker_is_active_subpage( 'type', 'board' ) ); ?>"><span class="badge bg-success float-end"><?php echo esc_html( count( BoardManager::get_all_boards() ) ); ?></span><a href="
										<?php
										echo esc_url(
											add_query_arg(
												array(
													'decker_page' => 'term-manager',
													'type'        => 'board',
												),
												home_url( '/' )
											)
										);
										?>
				"><?php esc_html_e( 'Boards', 'decker' ); ?></a></li>

				  <?php
					/*
					TO-DO: Add the actions manager
					<li class="<?php echo esc_attr( decker_is_active_subpage( 'type', 'active' ) ); ?>"><span class="badge bg-success float-end"><?php echo esc_html( $active_tasks_count ); ?></span><a href="<?php
					echo esc_url( esc_url( add_query_arg(
						array(
							'decker_page' => 'term-manager',
							'type' => 'action',
						),
						home_url( '/' ) )
					);
					?>"><?php esc_html_e('Actions', 'decker'); ?></a></li>
					*/
					?>

		  </ul>
		</div>
	  </li>
			<?php

		}

		?>


	</ul>
	<!--- End Sidemenu -->

	<div class="clearfix"></div>
  </div>
</div>
<!-- ========== Left Sidebar End ========== -->
