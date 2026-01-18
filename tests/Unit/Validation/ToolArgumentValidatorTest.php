<?php
/**
 * Tests for ToolArgumentValidator.
 */

namespace AgentWP\Tests\Unit\Validation;

use AgentWP\AI\Functions\FunctionSchema;
use AgentWP\Tests\TestCase;
use AgentWP\Validation\ToolArgumentValidator;
use Mockery;

class ToolArgumentValidatorTest extends TestCase {

	private ToolArgumentValidator $validator;

	public function setUp(): void {
		parent::setUp();
		$this->validator = new ToolArgumentValidator();
	}

	public function test_valid_arguments_pass_validation(): void {
		$schema = $this->createMockSchema(
			'test_tool',
			array(
				'type'       => 'object',
				'properties' => array(
					'name' => array( 'type' => 'string' ),
					'age'  => array( 'type' => 'integer' ),
				),
			)
		);

		$result = $this->validator->validate(
			$schema,
			array(
				'name' => 'John',
				'age'  => 30,
			)
		);

		$this->assertTrue( $result->isValid );
		$this->assertSame( 'test_tool', $result->toolName );
		$this->assertEmpty( $result->errors );
	}

	public function test_wrong_type_fails_validation(): void {
		$schema = $this->createMockSchema(
			'test_tool',
			array(
				'type'       => 'object',
				'properties' => array(
					'order_id' => array( 'type' => 'integer' ),
				),
			)
		);

		$result = $this->validator->validate(
			$schema,
			array( 'order_id' => 'not_a_number' )
		);

		$this->assertFalse( $result->isValid );
		$this->assertSame( 'test_tool', $result->toolName );
		$this->assertNotEmpty( $result->errors );
	}

	public function test_missing_required_field_fails_validation(): void {
		$schema = $this->createMockSchema(
			'update_status',
			array(
				'type'       => 'object',
				'required'   => array( 'order_id', 'new_status' ),
				'properties' => array(
					'order_id'   => array( 'type' => 'integer' ),
					'new_status' => array( 'type' => 'string' ),
				),
			)
		);

		$result = $this->validator->validate(
			$schema,
			array( 'order_id' => 123 ) // Missing required 'new_status'.
		);

		$this->assertFalse( $result->isValid );
		$this->assertNotEmpty( $result->errors );
	}

	public function test_invalid_enum_value_fails_validation(): void {
		$schema = $this->createMockSchema(
			'update_status',
			array(
				'type'       => 'object',
				'properties' => array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'pending', 'processing', 'completed' ),
					),
				),
			)
		);

		$result = $this->validator->validate(
			$schema,
			array( 'status' => 'invalid_status' )
		);

		$this->assertFalse( $result->isValid );
		$this->assertNotEmpty( $result->errors );
	}

	public function test_value_below_minimum_fails_validation(): void {
		$schema = $this->createMockSchema(
			'search_orders',
			array(
				'type'       => 'object',
				'properties' => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);

		$result = $this->validator->validate(
			$schema,
			array( 'limit' => 0 )
		);

		$this->assertFalse( $result->isValid );
		$this->assertNotEmpty( $result->errors );
	}

	public function test_nested_object_validation(): void {
		$schema = $this->createMockSchema(
			'search_orders',
			array(
				'type'       => 'object',
				'properties' => array(
					'date_range' => array(
						'type'       => 'object',
						'required'   => array( 'start', 'end' ),
						'properties' => array(
							'start' => array( 'type' => 'string' ),
							'end'   => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		// Valid nested object.
		$result = $this->validator->validate(
			$schema,
			array(
				'date_range' => array(
					'start' => '2024-01-01',
					'end'   => '2024-01-31',
				),
			)
		);
		$this->assertTrue( $result->isValid );

		// Missing required nested field.
		$result = $this->validator->validate(
			$schema,
			array(
				'date_range' => array(
					'start' => '2024-01-01',
					// Missing 'end'.
				),
			)
		);
		$this->assertFalse( $result->isValid );
	}

	public function test_to_error_array_returns_consistent_shape(): void {
		$schema = $this->createMockSchema(
			'test_tool',
			array(
				'type'       => 'object',
				'required'   => array( 'required_field' ),
				'properties' => array(
					'required_field' => array( 'type' => 'string' ),
				),
			)
		);

		$result = $this->validator->validate( $schema, array() );

		$this->assertFalse( $result->isValid );

		$errorArray = $result->toErrorArray();

		$this->assertArrayHasKey( 'success', $errorArray );
		$this->assertArrayHasKey( 'error', $errorArray );
		$this->assertArrayHasKey( 'code', $errorArray );
		$this->assertArrayHasKey( 'validation_errors', $errorArray );

		$this->assertFalse( $errorArray['success'] );
		$this->assertSame( 'invalid_tool_arguments', $errorArray['code'] );
		$this->assertStringContainsString( 'test_tool', $errorArray['error'] );
		$this->assertIsArray( $errorArray['validation_errors'] );
	}

	public function test_empty_arguments_with_no_required_fields_passes(): void {
		$schema = $this->createMockSchema(
			'optional_tool',
			array(
				'type'       => 'object',
				'properties' => array(
					'optional_field' => array( 'type' => 'string' ),
				),
			)
		);

		$result = $this->validator->validate( $schema, array() );

		$this->assertTrue( $result->isValid );
	}

	public function test_additional_properties_validation(): void {
		$schema = $this->createMockSchema(
			'strict_tool',
			array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'allowed_field' => array( 'type' => 'string' ),
				),
			)
		);

		$result = $this->validator->validate(
			$schema,
			array(
				'allowed_field' => 'value',
				'extra_field'   => 'not allowed',
			)
		);

		$this->assertFalse( $result->isValid );
	}

	/**
	 * Create a mock FunctionSchema for testing.
	 *
	 * @param string $name       Tool name.
	 * @param array  $parameters JSON schema for parameters.
	 * @return FunctionSchema
	 */
	private function createMockSchema( string $name, array $parameters ): FunctionSchema {
		$schema = Mockery::mock( FunctionSchema::class );
		$schema->shouldReceive( 'get_name' )->andReturn( $name );
		$schema->shouldReceive( 'get_parameters' )->andReturn( $parameters );
		return $schema;
	}
}
