<?php
/**
 * REST controller permissions tests.
 *
 * @package AgentWP\Tests\Unit\Rest
 */

namespace AgentWP\Tests\Unit\Rest;

use AgentWP\Rest\RestController;
use AgentWP\Tests\TestCase;
use WP_Mock;

class RestControllerPermissionsTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_permissions_check_uses_default_rest_capability(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->once()
			->with( 'agentwp_config_rest_capability', 'manage_woocommerce' )
			->andReturn( 'manage_woocommerce' );

		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'manage_woocommerce' )
			->andReturn( true );

		$controller = new TestRestController();
		$result     = $controller->permissions_check( null );

		$this->assertTrue( $result );
	}

	public function test_permissions_check_uses_filtered_rest_capability(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->once()
			->with( 'agentwp_config_rest_capability', 'manage_woocommerce' )
			->andReturn( 'manage_options' );

		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( true );

		$controller = new TestRestController();
		$result     = $controller->permissions_check( null );

		$this->assertTrue( $result );
	}
}

class TestRestController extends RestController {

	protected function verify_nonce( $request ) {
		return true;
	}

	protected function check_rate_limit_via_service() {
		return true;
	}
}
