<?php
/**
 * Unit tests for OpenAIKeyValidator class.
 */

namespace AgentWP\Tests\Unit\Infrastructure;

use AgentWP\Config\AgentWPConfig;
use AgentWP\DTO\HttpResponse;
use AgentWP\Infrastructure\OpenAIKeyValidator;
use AgentWP\Tests\Fakes\FakeHttpClient;
use AgentWP\Tests\TestCase;
use WP_Error;

class OpenAIKeyValidatorTest extends TestCase {

	private FakeHttpClient $httpClient;
	private OpenAIKeyValidator $validator;

	public function setUp(): void {
		parent::setUp();
		$this->httpClient = new FakeHttpClient();
		$this->validator  = new OpenAIKeyValidator( $this->httpClient );
	}

	public function test_validate_returns_true_for_valid_key(): void {
		$this->httpClient->queueSuccess( '{"data": []}', 200 );

		$result = $this->validator->validate( 'sk-valid-key-1234567890' );

		$this->assertTrue( $result );
		$this->assertSame( 1, $this->httpClient->getRequestCount() );

		$request = $this->httpClient->getLastRequest();
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 'https://api.openai.com/v1/models', $request['url'] );
		$this->assertSame( 'Bearer sk-valid-key-1234567890', $request['options']['headers']['Authorization'] );
	}

	public function test_validate_returns_error_for_invalid_key(): void {
		$this->httpClient->queueResponse(
			HttpResponse::error( 'HTTP error', 'http_401', 401 )
		);

		$result = $this->validator->validate( 'sk-invalid-key' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( AgentWPConfig::ERROR_CODE_OPENAI_INVALID, $result->get_error_code() );
	}

	public function test_validate_returns_error_when_api_unreachable(): void {
		$this->httpClient->queueError( 'Connection timeout', 'http_request_failed', 0 );

		$result = $this->validator->validate( 'sk-some-key' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( AgentWPConfig::ERROR_CODE_OPENAI_UNREACHABLE, $result->get_error_code() );
	}

	public function test_validate_returns_error_for_non_200_status(): void {
		$this->httpClient->queueResponse(
			HttpResponse::error( 'Forbidden', 'http_403', 403 )
		);

		$result = $this->validator->validate( 'sk-forbidden-key' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( AgentWPConfig::ERROR_CODE_OPENAI_INVALID, $result->get_error_code() );
	}

	public function test_validate_sends_correct_request_options(): void {
		$this->httpClient->queueSuccess( '{}', 200 );

		$this->validator->validate( 'sk-test-key' );

		$request = $this->httpClient->getLastRequest();

		$this->assertSame( 3, $request['options']['timeout'] );
		$this->assertSame( 0, $request['options']['redirection'] );
		$this->assertTrue( $request['options']['sslverify'] );
	}
}
