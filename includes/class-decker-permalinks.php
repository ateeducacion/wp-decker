<?php
/**
 * Permalink handling for Decker plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Permalinks
 */
class Decker_Permalinks {

	/**
	 * Initialize the class and set its properties.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}

	/**
	 * Add rewrite rules for Decker pages.
	 */
	public function add_rewrite_rules() {
		// Tasks
		add_rewrite_rule( '^tasks/(\d+)/?$', 'index.php?decker_page=task&task_id=$matches[1]', 'top' );
		add_rewrite_rule( '^tasks/([^/]+)/?$', 'index.php?decker_page=task&task_slug=$matches[1]', 'top' );
		add_rewrite_rule( '^tasks/?$', 'index.php?decker_page=tasks', 'top' );

		// Boards
		add_rewrite_rule( '^board/([^/]+)/?$', 'index.php?decker_page=board&board_slug=$matches[1]', 'top' );
		add_rewrite_rule( '^boards/?$', 'index.php?decker_page=boards', 'top' );

		// Short task URL
		add_rewrite_rule( '^(\d+)/?$', 'index.php?decker_redirect_task_id=$matches[1]', 'top' );
	}

	/**
	 * Add custom query vars.
	 *
	 * @param array $vars The WP query vars array.
	 * @return array Modified WP query vars array.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'task_id';
		$vars[] = 'task_slug';
		$vars[] = 'board_slug';
		$vars[] = 'decker_redirect_task_id';
		$vars[] = 'type';
		$vars[] = 'slug';
		return $vars;
	}

	/**
	 * Template redirect for Decker pages.
	 */
	public function template_redirect() {
		global $wp_query;

		$task_id_redirect = get_query_var( 'decker_redirect_task_id' );
		if ( $task_id_redirect ) {
			$url = home_url( '/tasks/' . $task_id_redirect . '/' );
			wp_safe_redirect( $url, 301 );
			exit;
		}
	}
}
