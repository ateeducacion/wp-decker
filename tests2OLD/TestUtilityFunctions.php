<?php
/**
 * Class UtilityFunctionsTest
 *
 * @package Decker
 */

use WP_Mock\Tools\TestCase;

/**
 * Class UtilityFunctionsTest
 *
 * @package Decker
 */
class TestUtilityFunctions extends TestCase {

	/**
	 * SetUp function
	 */
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	/**
	 * TearDown function
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}
}
