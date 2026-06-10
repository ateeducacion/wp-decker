<?php
/**
 * Class DeckerPublicAccessTest
 *
 * Characterization / lock-in tests for the front-end access gate in
 * public/class-decker-public.php.
 *
 * These tests pin the CURRENT observable behavior of:
 *  - decker_template_redirect()  (the login + minimum-role gate that wraps
 *    include_decker_page() and exits),
 *  - decker_query_vars()         (the registered public query vars),
 *  - decker_add_rewrite_rules()  (the registered top rewrite rules).
 *
 * The gate's terminal methods (redirect_to_login / deny_access /
 * include_decker_page) are followed by exit/wp_die in production, so the
 * happy/blocked paths are observed either through the test harness'
 * WPDieException + wp_redirect interception, or through a thin probe subclass
 * that records which branch fired and halts before the exit is reached.
 *
 * @package Decker
 */

/**
 * Marker exception thrown by the probe to halt before the production exit.
 */
class Decker_Access_Gate_Exception extends Exception {}

/**
 * Probe subclass that records which gate branch fired without exiting.
 *
 * Each override mirrors the production signature, captures the decision, and
 * throws a marker exception so the trailing exit in decker_template_redirect()
 * is never reached during the test.
 */
class Decker_Public_Access_Probe extends Decker_Public {

	/**
	 * Name of the last gate branch that fired ('login' | 'deny' | 'include').
	 *
	 * @var string
	 */
	public $branch = '';

	/**
	 * The decker_page value passed to include_decker_page(), if any.
	 *
	 * @var string
	 */
	public $included_page = '';

	/**
	 * Record the login-redirect branch and halt before the production exit.
	 */
	protected function redirect_to_login() {
		$this->branch = 'login';
		throw new Decker_Access_Gate_Exception( 'login' );
	}

	/**
	 * Record the access-denied branch and halt before include/exit.
	 */
	protected function deny_access() {
		$this->branch = 'deny';
		throw new Decker_Access_Gate_Exception( 'deny' );
	}

	/**
	 * Record the include branch and the requested page, then halt before exit.
	 *
	 * @param string $decker_page The Decker page to include.
	 */
	protected function include_decker_page( $decker_page ) {
		$this->branch        = 'include';
		$this->included_page = $decker_page;
		throw new Decker_Access_Gate_Exception( 'include' );
	}
}

class DeckerPublicAccessTest extends Decker_Test_Base {

	/**
	 * Probe instance under test.
	 *
	 * @var Decker_Public_Access_Probe
	 */
	protected $probe;

	/**
	 * Setup before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'WP_TESTS_RUNNING' ) ) {
			define( 'WP_TESTS_RUNNING', true );
		}

		// Lock the minimum role so the role check is deterministic.
		update_option( 'decker_settings', array( 'minimum_user_profile' => 'editor' ) );

		$this->probe = new Decker_Public_Access_Probe( 'decker', '1.0.0' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		set_query_var( 'decker_page', '' );
		wp_set_current_user( 0 );
		delete_option( 'decker_settings' );
		parent::tear_down();
	}

	/* ----------  decker_template_redirect() GATE  ---------- */

	/**
	 * An anonymous request to a decker_page is sent to the login redirect and
	 * the page is never included.
	 */
	public function test_anonymous_request_is_redirected_to_login() {
		wp_set_current_user( 0 );
		set_query_var( 'decker_page', 'priority' );

		$caught = false;
		try {
			$this->probe->decker_template_redirect();
		} catch ( Decker_Access_Gate_Exception $e ) {
			$caught = true;
		}

		$this->assertTrue( $caught, 'The gate must halt for an anonymous request.' );
		$this->assertSame( 'login', $this->probe->branch, 'Anonymous users must hit the login redirect.' );
		$this->assertSame( '', $this->probe->included_page, 'The Decker page must NOT be included for anonymous users.' );
	}

	/**
	 * A logged-in user below the minimum role is denied and the page is never
	 * included.
	 */
	public function test_user_below_minimum_role_is_denied() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		set_query_var( 'decker_page', 'priority' );

		$caught = false;
		try {
			$this->probe->decker_template_redirect();
		} catch ( Decker_Access_Gate_Exception $e ) {
			$caught = true;
		}

		$this->assertTrue( $caught, 'The gate must halt for an under-privileged user.' );
		$this->assertSame( 'deny', $this->probe->branch, 'A subscriber (below editor) must be denied.' );
		$this->assertSame( '', $this->probe->included_page, 'The Decker page must NOT be included for denied users.' );
	}

	/**
	 * A user at the minimum role (editor) is allowed and the requested page is
	 * included.
	 */
	public function test_user_at_minimum_role_is_allowed() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		set_query_var( 'decker_page', 'priority' );

		$caught = false;
		try {
			$this->probe->decker_template_redirect();
		} catch ( Decker_Access_Gate_Exception $e ) {
			$caught = true;
		}

		$this->assertTrue( $caught, 'The gate must reach the include branch and halt before exit.' );
		$this->assertSame( 'include', $this->probe->branch, 'An editor (at the minimum role) must be allowed.' );
		$this->assertSame( 'priority', $this->probe->included_page, 'The requested Decker page must be included.' );
	}

	/**
	 * A user above the minimum role (administrator) is allowed and the
	 * requested page is included.
	 */
	public function test_user_above_minimum_role_is_allowed() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		set_query_var( 'decker_page', 'board' );

		$caught = false;
		try {
			$this->probe->decker_template_redirect();
		} catch ( Decker_Access_Gate_Exception $e ) {
			$caught = true;
		}

		$this->assertTrue( $caught, 'The gate must reach the include branch and halt before exit.' );
		$this->assertSame( 'include', $this->probe->branch, 'An administrator (above the minimum role) must be allowed.' );
		$this->assertSame( 'board', $this->probe->included_page, 'The requested Decker page must be included.' );
	}

	/**
	 * Without a decker_page query var the gate is a no-op: no branch fires.
	 */
	public function test_no_decker_page_is_a_noop() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		set_query_var( 'decker_page', '' );

		$this->probe->decker_template_redirect();

		$this->assertSame( '', $this->probe->branch, 'No gate branch should fire without a decker_page.' );
		$this->assertSame( '', $this->probe->included_page, 'No page should be included without a decker_page.' );
	}

	/* ----------  PRODUCTION TERMINAL BEHAVIOR (no probe)  ---------- */

	/**
	 * The real deny_access() path (below-minimum user) calls wp_die().
	 *
	 * Pinned through the test harness, which converts wp_die() into a
	 * WPDieException.
	 */
	public function test_real_gate_denies_with_wp_die_for_under_privileged_user() {
		$public     = new Decker_Public( 'decker', '1.0.0' );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		set_query_var( 'decker_page', 'priority' );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You do not have permission to view this page.' );

		$public->decker_template_redirect();
	}

	/**
	 * The real redirect_to_login() path (anonymous user) issues a redirect to
	 * the login URL.
	 *
	 * The wp_redirect filter is intercepted to capture the location and halt
	 * before the trailing exit is reached.
	 */
	public function test_real_gate_redirects_anonymous_user_to_login() {
		$public = new Decker_Public( 'decker', '1.0.0' );
		wp_set_current_user( 0 );
		set_query_var( 'decker_page', 'priority' );

		$captured = '';
		$catcher  = function ( $location ) use ( &$captured ) {
			$captured = $location;
			throw new Decker_Access_Gate_Exception( 'redirect' );
		};
		add_filter( 'wp_redirect', $catcher );

		$caught = false;
		try {
			$public->decker_template_redirect();
		} catch ( Decker_Access_Gate_Exception $e ) {
			$caught = true;
		} finally {
			remove_filter( 'wp_redirect', $catcher );
		}

		$this->assertTrue( $caught, 'An anonymous request must trigger a redirect.' );
		$this->assertStringContainsString( wp_login_url(), $captured, 'The redirect must target the login URL.' );
		$this->assertStringContainsString( 'redirect_to=', $captured, 'The login URL must carry a redirect_to parameter.' );
	}

	/* ----------  decker_query_vars()  ---------- */

	/**
	 * The custom public query vars are registered and appended to the array.
	 */
	public function test_query_vars_are_registered() {
		$public = new Decker_Public( 'decker', '1.0.0' );

		$vars = $public->decker_query_vars( array( 'existing' ) );

		// Existing vars are preserved.
		$this->assertContains( 'existing', $vars );

		// Decker's custom vars are appended.
		$this->assertContains( 'decker_page', $vars );
		$this->assertContains( 'slug', $vars );
		$this->assertContains( 'decker_task', $vars );
		$this->assertContains( 'id', $vars );
	}

	/**
	 * The query_vars filter wired in the constructor exposes the Decker vars to
	 * WP_Query.
	 */
	public function test_query_vars_filter_is_wired() {
		new Decker_Public( 'decker', '1.0.0' );

		$vars = apply_filters( 'query_vars', array() );

		$this->assertContains( 'decker_page', $vars );
		$this->assertContains( 'decker_task', $vars );
	}

	/* ----------  decker_add_rewrite_rules()  ---------- */

	/**
	 * The Decker rewrite rules are registered at the top of the rewrite table.
	 */
	public function test_rewrite_rules_are_registered() {
		global $wp_rewrite;

		$public = new Decker_Public( 'decker', '1.0.0' );
		$public->decker_add_rewrite_rules();

		$top = $wp_rewrite->extra_rules_top;

		$this->assertArrayHasKey( '^decker/?$', $top );
		$this->assertSame( 'index.php?decker_page=priority', $top['^decker/?$'] );

		$this->assertArrayHasKey( '^decker/board/([^/]+)/?$', $top );
		$this->assertSame( 'index.php?decker_page=board&slug=$matches[1]', $top['^decker/board/([^/]+)/?$'] );

		$this->assertArrayHasKey( '^decker/analytics/?$', $top );
		$this->assertArrayHasKey( '^decker/task/new/?$', $top );

		$this->assertArrayHasKey( '^decker/task/([^/]+)/?$', $top );
		$this->assertSame( 'index.php?decker_page=task&id=$matches[1]', $top['^decker/task/([^/]+)/?$'] );

		$this->assertArrayHasKey( '^decker/tasks/?$', $top );
		$this->assertArrayHasKey( '^decker/tasks/active/?$', $top );
		$this->assertArrayHasKey( '^decker/tasks/my/?$', $top );
		$this->assertArrayHasKey( '^decker/tasks/archived/?$', $top );

		// Short task URL.
		$this->assertArrayHasKey( '^t/(\d+)/?$', $top );
		$this->assertSame( 'index.php?decker_page=task&id=$matches[1]', $top['^t/(\d+)/?$'] );
	}
}
