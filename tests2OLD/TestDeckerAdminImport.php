<?php
/**
 * TestDeckerAdminImport class
 *
 * @package    Decker
 * @subpackage Decker/tests
 */

use WP_Mock\Tools\TestCase;

require_once dirname( __DIR__ ) . '/admin/class-decker-admin-import.php';

/**
 * Class TestDeckerAdminImport
 *
 * @coversDefaultClass \Decker_Admin_Import
 */
class TestDeckerAdminImport extends TestCase {

	protected $importer;  // Declare the property

	public function setUp(): void {
		// Initial test setup
		parent::setUp();

		// Mock WordPress functions used in the tests
		WP_Mock::userFunction(
			'wp_create_nonce',
			array(
				'return' => 'mocked_nonce_value',
			)
		);

		WP_Mock::userFunction(
			'check_ajax_referer',
			array(
				'return' => true, // Simulating that the nonce check passed
			)
		);

		WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'return' => function ( $data ) {
					echo wp_json_encode(
						array(
							'success' => true,
							'data'    => $data,
						)
					);
				},
			)
		);

		WP_Mock::userFunction(
			'wp_send_json_error',
			array(
				'return' => function ( $data ) {
					echo wp_json_encode(
						array(
							'success' => false,
							'data'    => $data,
						)
					);
				},
			)
		);

		// Create an instance of the class we are going to test
		$this->importer = $this->getMockBuilder( 'Decker_Admin_Import' )
								->onlyMethods( array( 'make_request' ) )
								->getMock();

		// Simulate a response for make_request
		$this->importer->method( 'make_request' )->willReturn(
			array(
				array(
					'id'     => 1,
					'title'  => 'Test Board 1',
					'color'  => 'FF5733',
					'labels' => array(
						array(
							'title' => 'Label 1',
							'color' => 'FF5733',
						),
						array(
							'title' => 'Label 2',
							'color' => '33FF57',
						),
					),
				),
				array(
					'id'     => 2,
					'title'  => 'Test Board 2',
					'color'  => '3357FF',
					'labels' => array(
						array(
							'title' => 'Label 3',
							'color' => 'FF33FF',
						),
					),
				),
			)
		);
	}

	public function test_start_import_success() {
		// Simulate the behavior of the start_import function
		$_POST['security'] = esc_attr( wp_create_nonce( 'decker_import_nonce' ) );

		// Simulate the AJAX request
		$this->importer->start_import();

		// Capture the JSON output
		$response = json_decode( $this->getActualOutput(), true );

		// Verify that the import was successful and returned the correct response
		$this->assertTrue( $response['success'] );
		$this->assertCount( 2, $response['data'] ); // Expecting 2 boards
	}

	public function test_start_import_failure() {
		// Configure make_request to return null, simulating a failure
		$this->importer->method( 'make_request' )->willReturn( null );

		// Simulate the behavior of the start_import function
		$_POST['security'] = esc_attr( wp_create_nonce( 'decker_import_nonce' ) );

		// Simulate the AJAX request
		$this->importer->start_import();

		// Capture the JSON output
		$response = json_decode( $this->getActualOutput(), true );

		// Verify that the import failed
		$this->assertFalse( $response['success'] );
		$this->assertEquals( 'Failed to retrieve boards from NextCloud.', $response['data'] );
	}
}
