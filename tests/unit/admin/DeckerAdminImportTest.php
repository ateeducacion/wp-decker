<?php
/**
 * Class Test_Decker_Admin_Import
 *
 * @package Decker
 */

class DeckerAdminImportTest extends WP_UnitTestCase {

	/**
	 * @var Decker_Admin_Import
	 */
	protected $admin_import;

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize the Decker_Admin_Import instance.
		$this->admin_import = new Decker_Admin_Import();
	}
}
