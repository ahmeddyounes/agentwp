<?php
/**
 * Diagnostics controller tests.
 *
 * @package AgentWP\Tests\Unit\Rest
 */

namespace {
	if ( ! function_exists( 'rest_ensure_response' ) ) {
		function rest_ensure_response( $data ) {
			return new WP_REST_Response( $data );
		}
	}
}

namespace AgentWP\Tests\Unit\Rest {
	use AgentWP\Config\AgentWPConfig;
	use AgentWP\Plugin;
	use AgentWP\Rest\DiagnosticsController;
	use AgentWP\Search\Index;
	use AgentWP\Tests\TestCase;
	use WP_Mock;

	class DiagnosticsControllerTest extends TestCase {

		public function setUp(): void {
			parent::setUp();
			WP_Mock::setUp();
		}

		public function tearDown(): void {
			WP_Mock::tearDown();
			parent::tearDown();
		}

		public function test_get_diagnostics_returns_snapshot_payload(): void {
			$logs = array(
				array(
					'time'       => '2026-01-17T11:58:00Z',
					'route'      => '/agentwp/v1/settings',
					'method'     => 'GET',
					'status'     => 200,
					'error'      => '',
					'user_id'    => 11,
					'query_keys' => array(),
					'body_keys'  => array(),
				),
			);

			WP_Mock::userFunction(
				'get_current_user_id',
				array(
					'times'  => 1,
					'return' => 11,
				)
			);

			WP_Mock::userFunction(
				'get_transient',
				array(
					'args'   => array( Plugin::TRANSIENT_PREFIX . AgentWPConfig::CACHE_PREFIX_REST_LOG . '11' ),
					'return' => $logs,
				)
			);

			WP_Mock::userFunction(
				'get_option',
				array(
					'args'   => array( Index::STATE_OPTION, array() ),
					'return' => array(
						'products'  => 120,
						'orders'    => -1,
						'customers' => 0,
					),
				)
			);

			WP_Mock::userFunction(
				'get_option',
				array(
					'args'   => array( Index::VERSION_OPTION, '' ),
					'return' => '1.0',
				)
			);

			$controller = new DiagnosticsController();
			$response   = $controller->get_diagnostics( null );
			$payload    = $response->get_data();

			$this->assertTrue( $payload['success'] );
			$this->assertArrayHasKey( 'data', $payload );

			$data = $payload['data'];
			$this->assertArrayHasKey( 'health', $data );
			$this->assertSame( 'ok', $data['health']['status'] );
			$this->assertArrayHasKey( 'rest_logs', $data );
			$this->assertSame( 1, $data['rest_logs']['total'] );
			$this->assertSame( $logs, $data['rest_logs']['entries'] );
			$this->assertArrayHasKey( 'rate_limit', $data );
			$this->assertFalse( $data['rate_limit']['enabled'] );
			$this->assertArrayHasKey( 'config', $data );
			$this->assertSame( 'gpt-4o-mini', $data['config']['model'] );
			$this->assertFalse( $data['config']['demo_mode'] );
			$this->assertArrayHasKey( 'search_index', $data );
			$this->assertSame( '1.0', $data['search_index']['version'] );
			$this->assertSame( Index::VERSION, $data['search_index']['expected_version'] );
			$this->assertSame(
				array(
					'products'  => 120,
					'orders'    => -1,
					'customers' => 0,
				),
				$data['search_index']['state']
			);
			$this->assertSame(
				array(
					'products'  => false,
					'orders'    => true,
					'customers' => false,
				),
				$data['search_index']['complete']
			);
		}
	}
}
