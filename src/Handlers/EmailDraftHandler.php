<?php
/**
 * Handle email draft preparation and editing.
 *
 * @package AgentWP
 */

namespace AgentWP\Handlers;

use AgentWP\AI\Model;
use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Response;
use AgentWP\Context\EmailContextBuilder;
use AgentWP\Intent\Intent;
use AgentWP\Plugin;
use AgentWP\Security\Encryption;

class EmailDraftHandler {
	const DRAFT_TYPE          = 'email_draft';
	const DEFAULT_TTL_MINUTES = 30;

	/**
	 * @var EmailContextBuilder
	 */
	private $context_builder;

	/**
	 * @param EmailContextBuilder|null $context_builder Optional context builder.
	 */
	public function __construct( EmailContextBuilder $context_builder = null ) {
		$this->context_builder = $context_builder ? $context_builder : new EmailContextBuilder();
	}

	/**
	 * Handle email draft requests.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function handle( array $args ): Response {
		if ( isset( $args['draft_id'] ) ) {
			$draft_id = is_string( $args['draft_id'] ) ? trim( $args['draft_id'] ) : '';
			if ( '' === $draft_id ) {
				return Response::error( 'Missing email draft ID.', 400 );
			}

			if ( $this->has_update_payload( $args ) ) {
				return $this->update_draft( $draft_id, $args );
			}

			return $this->get_draft( $draft_id );
		}

		return $this->draft_email( $args );
	}

	/**
	 * Draft a new email.
	 *
	 * @param array $args Draft args.
	 * @return Response
	 */
	public function draft_email( array $args ): Response {
		$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		if ( 0 === $order_id ) {
			return Response::error( 'Missing order ID for email draft.', 400 );
		}

		$intent = isset( $args['intent'] ) ? $this->normalize_intent( $args['intent'] ) : '';
		if ( '' === $intent ) {
			return Response::error( 'Missing or invalid email intent.', 400 );
		}

		$tone = isset( $args['tone'] ) ? $this->normalize_tone( $args['tone'] ) : '';
		if ( '' === $tone ) {
			return Response::error( 'Missing or invalid email tone.', 400 );
		}

		$custom_instructions = isset( $args['custom_instructions'] )
			? $this->sanitize_instructions( $args['custom_instructions'] )
			: '';

		$context = $this->context_builder->build( $order_id );
		if ( isset( $context['error'] ) && '' !== $context['error'] ) {
			$status = 'Order not found.' === $context['error'] ? 404 : 400;
			return Response::error( $context['error'], $status );
		}

		$template_variables  = $this->build_template_variables( $context );
		$custom_instructions = $this->apply_template_variables( $custom_instructions, $template_variables );

		$draft = $this->generate_draft( $context, $intent, $tone, $custom_instructions, $template_variables );
		if ( empty( $draft['subject_line'] ) || empty( $draft['email_body'] ) ) {
			return Response::error( 'Unable to generate email draft.', 500 );
		}

		$draft_id   = $this->generate_draft_id();
		$ttl        = $this->get_draft_ttl_seconds();
		$expires_at = gmdate( 'c', time() + $ttl );

		$draft_payload = array(
			'order_id'           => $order_id,
			'intent'             => $intent,
			'tone'               => $tone,
			'custom_instructions' => $custom_instructions,
			'subject_line'       => $draft['subject_line'],
			'email_body'         => $draft['email_body'],
			'plain_text_version' => $draft['plain_text_version'],
			'template_variables' => $template_variables,
		);

		$stored = $this->store_draft(
			$draft_id,
			array(
				'id'         => $draft_id,
				'type'       => self::DRAFT_TYPE,
				'payload'    => $draft_payload,
				'expires_at' => $expires_at,
			),
			$ttl
		);

		if ( ! $stored ) {
			return Response::error( 'Unable to store email draft.', 500 );
		}

		return Response::success(
			array(
				'draft_id'           => $draft_id,
				'draft'              => $draft_payload,
				'subject_line'       => $draft_payload['subject_line'],
				'email_body'         => $draft_payload['email_body'],
				'plain_text_version' => $draft_payload['plain_text_version'],
				'template_variables' => $template_variables,
				'expires_at'         => $expires_at,
			)
		);
	}

	/**
	 * Retrieve a stored draft.
	 *
	 * @param string $draft_id Draft identifier.
	 * @return Response
	 */
	public function get_draft( $draft_id ): Response {
		$draft_id = is_string( $draft_id ) ? trim( $draft_id ) : '';
		if ( '' === $draft_id ) {
			return Response::error( 'Missing email draft ID.', 400 );
		}

		$draft = $this->load_draft( $draft_id );
		if ( null === $draft ) {
			return Response::error( 'Email draft not found or expired.', 404 );
		}

		if ( isset( $draft['type'] ) && self::DRAFT_TYPE !== $draft['type'] ) {
			return Response::error( 'Draft type mismatch for email draft.', 400 );
		}

		$payload = isset( $draft['payload'] ) && is_array( $draft['payload'] ) ? $draft['payload'] : $draft;

		return Response::success(
			array(
				'draft_id'           => $draft_id,
				'draft'              => $payload,
				'subject_line'       => isset( $payload['subject_line'] ) ? $payload['subject_line'] : '',
				'email_body'         => isset( $payload['email_body'] ) ? $payload['email_body'] : '',
				'plain_text_version' => isset( $payload['plain_text_version'] ) ? $payload['plain_text_version'] : '',
				'template_variables' => isset( $payload['template_variables'] ) ? $payload['template_variables'] : array(),
				'expires_at'         => isset( $draft['expires_at'] ) ? $draft['expires_at'] : '',
			)
		);
	}

	/**
	 * Update a stored draft.
	 *
	 * @param string $draft_id Draft identifier.
	 * @param array  $args Update payload.
	 * @return Response
	 */
	public function update_draft( $draft_id, array $args ): Response {
		$draft_id = is_string( $draft_id ) ? trim( $draft_id ) : '';
		if ( '' === $draft_id ) {
			return Response::error( 'Missing email draft ID.', 400 );
		}

		$draft = $this->load_draft( $draft_id );
		if ( null === $draft ) {
			return Response::error( 'Email draft not found or expired.', 404 );
		}

		if ( isset( $draft['type'] ) && self::DRAFT_TYPE !== $draft['type'] ) {
			return Response::error( 'Draft type mismatch for email draft update.', 400 );
		}

		$payload = isset( $draft['payload'] ) && is_array( $draft['payload'] ) ? $draft['payload'] : $draft;
		$updates = array();

		if ( isset( $args['subject_line'] ) ) {
			$updates['subject_line'] = $this->sanitize_subject( $args['subject_line'] );
		}

		if ( isset( $args['email_body'] ) ) {
			$updates['email_body'] = $this->sanitize_body( $args['email_body'] );
		}

		if ( isset( $args['plain_text_version'] ) ) {
			$updates['plain_text_version'] = $this->sanitize_plain_text( $args['plain_text_version'] );
		}

		if ( isset( $args['tone'] ) ) {
			$normalized = $this->normalize_tone( $args['tone'] );
			if ( '' === $normalized ) {
				return Response::error( 'Invalid tone for email draft update.', 400 );
			}
			$updates['tone'] = $normalized;
		}

		if ( isset( $args['intent'] ) ) {
			$normalized = $this->normalize_intent( $args['intent'] );
			if ( '' === $normalized ) {
				return Response::error( 'Invalid intent for email draft update.', 400 );
			}
			$updates['intent'] = $normalized;
		}

		if ( isset( $args['custom_instructions'] ) ) {
			$updates['custom_instructions'] = $this->sanitize_instructions( $args['custom_instructions'] );
		}

		if ( empty( $updates ) ) {
			return Response::success(
				array(
					'draft_id'           => $draft_id,
					'draft'              => $payload,
					'subject_line'       => isset( $payload['subject_line'] ) ? $payload['subject_line'] : '',
					'email_body'         => isset( $payload['email_body'] ) ? $payload['email_body'] : '',
					'plain_text_version' => isset( $payload['plain_text_version'] ) ? $payload['plain_text_version'] : '',
					'template_variables' => isset( $payload['template_variables'] ) ? $payload['template_variables'] : array(),
					'expires_at'         => isset( $draft['expires_at'] ) ? $draft['expires_at'] : '',
				)
			);
		}

		$template_variables = isset( $payload['template_variables'] ) && is_array( $payload['template_variables'] )
			? $payload['template_variables']
			: array();

		$payload = array_merge( $payload, $updates );

		if ( isset( $payload['subject_line'] ) ) {
			$payload['subject_line'] = $this->apply_template_variables( $payload['subject_line'], $template_variables );
			$payload['subject_line'] = $this->replace_known_placeholders( $payload['subject_line'], $template_variables );
		}

		if ( isset( $payload['email_body'] ) ) {
			$payload['email_body'] = $this->apply_template_variables( $payload['email_body'], $template_variables );
			$payload['email_body'] = $this->replace_known_placeholders( $payload['email_body'], $template_variables );
			$payload['email_body'] = $this->normalize_email_html( $payload['email_body'] );
			$payload['email_body'] = $this->sanitize_email_html( $payload['email_body'] );
		}

		if ( isset( $payload['plain_text_version'] ) ) {
			$payload['plain_text_version'] = $this->apply_template_variables( $payload['plain_text_version'], $template_variables );
			$payload['plain_text_version'] = $this->replace_known_placeholders( $payload['plain_text_version'], $template_variables );
		}

		if ( ! isset( $payload['plain_text_version'] ) || '' === trim( $payload['plain_text_version'] ) ) {
			$payload['plain_text_version'] = $this->build_plain_text_from_html(
				isset( $payload['email_body'] ) ? $payload['email_body'] : ''
			);
		}

		$ttl        = $this->get_draft_ttl_seconds();
		$expires_at = gmdate( 'c', time() + $ttl );

		$stored = $this->store_draft(
			$draft_id,
			array(
				'id'         => $draft_id,
				'type'       => self::DRAFT_TYPE,
				'payload'    => $payload,
				'expires_at' => $expires_at,
			),
			$ttl
		);

		if ( ! $stored ) {
			return Response::error( 'Unable to update email draft.', 500 );
		}

		return Response::success(
			array(
				'updated'            => true,
				'draft_id'           => $draft_id,
				'draft'              => $payload,
				'subject_line'       => isset( $payload['subject_line'] ) ? $payload['subject_line'] : '',
				'email_body'         => isset( $payload['email_body'] ) ? $payload['email_body'] : '',
				'plain_text_version' => isset( $payload['plain_text_version'] ) ? $payload['plain_text_version'] : '',
				'template_variables' => $template_variables,
				'expires_at'         => $expires_at,
			)
		);
	}

	/**
	 * @param array $context Order context.
	 * @param string $intent Email intent.
	 * @param string $tone Email tone.
	 * @param string $custom_instructions Custom instructions.
	 * @param array $template_variables Template variables.
	 * @return array
	 */
	private function generate_draft( array $context, $intent, $tone, $custom_instructions, array $template_variables ) {
		$draft = $this->generate_draft_with_ai( $context, $intent, $tone, $custom_instructions, $template_variables );
		if ( ! empty( $draft['subject_line'] ) && ! empty( $draft['email_body'] ) ) {
			return $draft;
		}

		return $this->generate_fallback_draft( $context, $intent, $tone, $custom_instructions, $template_variables );
	}

	/**
	 * @param array $context Order context.
	 * @param string $intent Email intent.
	 * @param string $tone Email tone.
	 * @param string $custom_instructions Custom instructions.
	 * @param array $template_variables Template variables.
	 * @return array
	 */
	private function generate_draft_with_ai( array $context, $intent, $tone, $custom_instructions, array $template_variables ) {
		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return array();
		}

		$model    = $this->get_model();
		$client   = new OpenAIClient(
			$api_key,
			$model,
			array(
				'intent_type' => Intent::EMAIL_DRAFT,
			)
		);
		$messages = $this->build_prompt_messages( $context, $intent, $tone, $custom_instructions, $template_variables );

		$result = $client->chat( $messages, array() );
		if ( ! $result->is_success() ) {
			return array();
		}

		$data    = $result->get_data();
		$content = isset( $data['content'] ) ? (string) $data['content'] : '';
		$parsed  = $this->parse_json_response( $content );

		if ( empty( $parsed ) ) {
			return array();
		}

		$subject = isset( $parsed['subject_line'] ) ? $parsed['subject_line'] : ( $parsed['subject'] ?? '' );
		$body    = isset( $parsed['email_body'] ) ? $parsed['email_body'] : ( $parsed['body'] ?? '' );
		$plain   = isset( $parsed['plain_text_version'] ) ? $parsed['plain_text_version'] : ( $parsed['plain_text'] ?? '' );

		$subject = $this->sanitize_subject( $subject );
		$body    = $this->sanitize_body( $body );
		$plain   = $this->sanitize_plain_text( $plain );

		$subject = $this->apply_template_variables( $subject, $template_variables );
		$subject = $this->replace_known_placeholders( $subject, $template_variables );
		$body    = $this->apply_template_variables( $body, $template_variables );
		$body    = $this->replace_known_placeholders( $body, $template_variables );

		$body = $this->normalize_email_html( $body );
		$body = $this->sanitize_email_html( $body );

		if ( '' === trim( $plain ) ) {
			$plain = $this->build_plain_text_from_html( $body );
		}

		$plain = $this->apply_template_variables( $plain, $template_variables );
		$plain = $this->replace_known_placeholders( $plain, $template_variables );

		return array(
			'subject_line'       => $subject,
			'email_body'         => $body,
			'plain_text_version' => $plain,
		);
	}

	/**
	 * @param array $context Order context.
	 * @param string $intent Email intent.
	 * @param string $tone Email tone.
	 * @param string $custom_instructions Custom instructions.
	 * @param array $template_variables Template variables.
	 * @return array
	 */
	private function generate_fallback_draft( array $context, $intent, $tone, $custom_instructions, array $template_variables ) {
		$subject = $this->build_subject_from_template( $intent, $tone );
		$subject = $this->apply_template_variables( $subject, $template_variables );

		$body_parts  = array();
		$text_parts  = array();
		$greeting    = $this->build_greeting( $tone, $template_variables );
		$intro       = $this->build_intro_sentence( $intent, $tone, $context );
		$items_block = $this->build_items_block( $context );
		$tracking    = $this->build_tracking_block( $intent, $tone, $context );
		$issue_block = $this->build_issue_block( $intent, $tone, $context );
		$closing     = $this->build_closing( $tone, $template_variables );

		if ( '' !== $greeting['html'] ) {
			$body_parts[] = $greeting['html'];
			$text_parts[] = $greeting['text'];
		}

		if ( '' !== $intro['html'] ) {
			$body_parts[] = $intro['html'];
			$text_parts[] = $intro['text'];
		}

		if ( '' !== $issue_block['html'] ) {
			$body_parts[] = $issue_block['html'];
			$text_parts[] = $issue_block['text'];
		}

		if ( '' !== $items_block['html'] ) {
			$body_parts[] = $items_block['html'];
			$text_parts[] = $items_block['text'];
		}

		if ( '' !== $tracking['html'] ) {
			$body_parts[] = $tracking['html'];
			$text_parts[] = $tracking['text'];
		}

		if ( '' !== $custom_instructions ) {
			$body_parts[] = '<p>' . $this->escape_html( $custom_instructions ) . '</p>';
			$text_parts[] = $custom_instructions;
		}

		if ( '' !== $closing['html'] ) {
			$body_parts[] = $closing['html'];
			$text_parts[] = $closing['text'];
		}

		$email_body = implode( "\n", $body_parts );
		$email_body = $this->apply_template_variables( $email_body, $template_variables );
		$email_body = $this->replace_known_placeholders( $email_body, $template_variables );
		$email_body = $this->normalize_email_html( $email_body );
		$email_body = $this->sanitize_email_html( $email_body );

		$plain_text = implode( "\n\n", array_filter( $text_parts ) );
		$plain_text = $this->apply_template_variables( $plain_text, $template_variables );
		$plain_text = $this->replace_known_placeholders( $plain_text, $template_variables );

		return array(
			'subject_line'       => $subject,
			'email_body'         => $email_body,
			'plain_text_version' => $plain_text,
		);
	}

	/**
	 * @param array $context Order context.
	 * @param string $intent Email intent.
	 * @param string $tone Email tone.
	 * @param string $custom_instructions Custom instructions.
	 * @param array $template_variables Template variables.
	 * @return array
	 */
	private function build_prompt_messages( array $context, $intent, $tone, $custom_instructions, array $template_variables ) {
		$store_name    = $this->get_store_name();
		$intent_notes  = $this->get_intent_guidance( $intent, $context );
		$tone_notes    = $this->get_tone_guidance( $tone );
		$summary       = $this->prune_prompt_payload( $this->build_prompt_context( $context ) );
		$template_hint = $this->prune_prompt_payload( $template_variables );

		$system = sprintf(
			'You are a customer support assistant for %s. Draft a customer email response. Return JSON with keys subject_line, email_body, plain_text_version. The email_body must be valid HTML using simple tags like <p>, <ul>, <li>, <strong>, and <a>. Mention specific products and tracking details when available. Avoid placeholder text when data exists.',
			$store_name
		);

		$user = array(
			'Intent: ' . $intent,
			'Tone: ' . $tone,
			'Intent guidance: ' . $intent_notes,
			'Tone guidance: ' . $tone_notes,
			'Order context (JSON): ' . $this->encode_json( $summary ),
			'Template variables (JSON): ' . $this->encode_json( $template_hint ),
		);

		if ( '' !== $custom_instructions ) {
			$user[] = 'Custom instructions: ' . $custom_instructions;
		}

		$user[] = 'Output only JSON with the requested keys.';

		return array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => implode( "\n", $user ),
			),
		);
	}

	/**
	 * @param array $context Order context.
	 * @return array
	 */
	private function build_prompt_context( array $context ) {
		$order = isset( $context['order'] ) && is_array( $context['order'] ) ? $context['order'] : array();
		$shipping = isset( $context['shipping'] ) && is_array( $context['shipping'] ) ? $context['shipping'] : array();
		$issues = isset( $context['issues'] ) && is_array( $context['issues'] ) ? $context['issues'] : array();

		return array(
			'order_id' => isset( $context['order_id'] ) ? $context['order_id'] : 0,
			'order'    => array(
				'status'     => isset( $order['status'] ) ? $order['status'] : '',
				'items'      => isset( $order['items'] ) ? $order['items'] : array(),
				'item_count' => isset( $order['item_count'] ) ? $order['item_count'] : 0,
				'totals'     => isset( $order['totals'] ) ? $order['totals'] : array(),
				'dates'      => isset( $order['dates'] ) ? $order['dates'] : array(),
			),
			'customer' => isset( $context['customer'] ) ? $context['customer'] : array(),
			'shipping' => array(
				'tracking'           => isset( $shipping['tracking'] ) ? $shipping['tracking'] : array(),
				'methods'            => isset( $shipping['methods'] ) ? $shipping['methods'] : array(),
				'estimated_delivery' => isset( $shipping['estimated_delivery'] ) ? $shipping['estimated_delivery'] : '',
			),
			'payment'  => isset( $context['payment'] ) ? $context['payment'] : array(),
			'issues'   => $issues,
			'notes'    => isset( $context['notes'] ) ? $context['notes'] : array(),
		);
	}

	/**
	 * Remove empty values to keep prompts compact.
	 *
	 * @param mixed $payload Input payload.
	 * @return mixed
	 */
	private function prune_prompt_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}

		foreach ( $payload as $key => $value ) {
			$filtered = $this->prune_prompt_payload( $value );

			if ( is_array( $filtered ) && empty( $filtered ) ) {
				unset( $payload[ $key ] );
				continue;
			}

			if ( is_string( $filtered ) && '' === trim( $filtered ) ) {
				unset( $payload[ $key ] );
				continue;
			}

			if ( null === $filtered ) {
				unset( $payload[ $key ] );
				continue;
			}

			$payload[ $key ] = $filtered;
		}

		return $payload;
	}

	/**
	 * @param array $context Order context.
	 * @return array
	 */
	private function build_template_variables( array $context ) {
		$order   = isset( $context['order'] ) && is_array( $context['order'] ) ? $context['order'] : array();
		$customer = isset( $context['customer'] ) && is_array( $context['customer'] ) ? $context['customer'] : array();
		$shipping = isset( $context['shipping'] ) && is_array( $context['shipping'] ) ? $context['shipping'] : array();
		$payment  = isset( $context['payment'] ) && is_array( $context['payment'] ) ? $context['payment'] : array();
		$tracking = isset( $shipping['tracking'] ) && is_array( $shipping['tracking'] ) ? $shipping['tracking'] : array();

		$items_summary = $this->build_items_summary( $order );

		return array(
			'{{customer_name}}'    => isset( $customer['name'] ) ? $customer['name'] : '',
			'{{customer_email}}'   => isset( $customer['email'] ) ? $customer['email'] : '',
			'{{order_id}}'         => isset( $context['order_id'] ) ? (string) $context['order_id'] : '',
			'{{order_status}}'     => isset( $order['status'] ) ? $order['status'] : '',
			'{{order_total}}'      => $this->format_currency( isset( $order['totals']['total'] ) ? $order['totals']['total'] : '' ),
			'{{order_date}}'       => isset( $order['dates']['created'] ) ? $order['dates']['created'] : '',
			'{{item_list}}'        => $items_summary['summary'],
			'{{item_count}}'       => $items_summary['count'],
			'{{tracking_url}}'     => isset( $tracking['url'] ) ? $tracking['url'] : '',
			'{{tracking_number}}'  => isset( $tracking['number'] ) ? $tracking['number'] : '',
			'{{tracking_provider}}' => isset( $tracking['provider'] ) ? $tracking['provider'] : '',
			'{{estimated_delivery}}' => isset( $shipping['estimated_delivery'] ) ? $shipping['estimated_delivery'] : '',
			'{{shipping_method}}'  => $this->get_primary_shipping_method( $shipping ),
			'{{payment_method}}'   => isset( $payment['method'] ) ? $payment['method'] : '',
			'{{store_name}}'       => $this->get_store_name(),
			'{{support_email}}'    => $this->get_support_email(),
		);
	}

	/**
	 * @param array $args Input payload.
	 * @return bool
	 */
	private function has_update_payload( array $args ) {
		return isset( $args['subject_line'] )
			|| isset( $args['email_body'] )
			|| isset( $args['plain_text_version'] )
			|| isset( $args['tone'] )
			|| isset( $args['intent'] )
			|| isset( $args['custom_instructions'] );
	}

	/**
	 * @param mixed $intent Input intent.
	 * @return string
	 */
	private function normalize_intent( $intent ) {
		$intent = is_string( $intent ) ? strtolower( trim( $intent ) ) : '';
		$valid  = array( 'shipping_update', 'refund_confirmation', 'order_issue', 'general_inquiry', 'review_request' );

		return in_array( $intent, $valid, true ) ? $intent : '';
	}

	/**
	 * @param mixed $tone Input tone.
	 * @return string
	 */
	private function normalize_tone( $tone ) {
		$tone  = is_string( $tone ) ? strtolower( trim( $tone ) ) : '';
		$valid = array( 'professional', 'friendly', 'apologetic' );

		return in_array( $tone, $valid, true ) ? $tone : '';
	}

	/**
	 * @param mixed $value Input.
	 * @return string
	 */
	private function sanitize_instructions( $value ) {
		$value = is_string( $value ) ? $value : '';

		if ( function_exists( 'sanitize_textarea_field' ) ) {
			return sanitize_textarea_field( wp_unslash( $value ) );
		}

		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * @param mixed $value Input.
	 * @return string
	 */
	private function sanitize_subject( $value ) {
		$value = is_string( $value ) ? $value : '';
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * @param mixed $value Input.
	 * @return string
	 */
	private function sanitize_body( $value ) {
		$value = is_string( $value ) ? $value : '';
		return wp_unslash( $value );
	}

	/**
	 * @param mixed $value Input.
	 * @return string
	 */
	private function sanitize_plain_text( $value ) {
		$value = is_string( $value ) ? $value : '';
		if ( function_exists( 'sanitize_textarea_field' ) ) {
			return sanitize_textarea_field( wp_unslash( $value ) );
		}

		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * @param string $intent Email intent.
	 * @param string $tone Email tone.
	 * @return string
	 */
	private function build_subject_from_template( $intent, $tone ) {
		$templates = array(
			'shipping_update' => array(
				'professional' => 'Shipping update for order #{{order_id}}',
				'friendly'     => 'Your order #{{order_id}} is on the way!',
				'apologetic'   => 'Update on your order #{{order_id}}',
			),
			'refund_confirmation' => array(
				'professional' => 'Refund confirmation for order #{{order_id}}',
				'friendly'     => 'Your refund for order #{{order_id}} is on its way',
				'apologetic'   => 'Refund update for order #{{order_id}}',
			),
			'order_issue' => array(
				'professional' => 'Update regarding order #{{order_id}}',
				'friendly'     => 'Quick update on order #{{order_id}}',
				'apologetic'   => 'We are sorry about order #{{order_id}}',
			),
			'general_inquiry' => array(
				'professional' => 'Following up on order #{{order_id}}',
				'friendly'     => 'Thanks for reaching out about order #{{order_id}}',
				'apologetic'   => 'Update on your request for order #{{order_id}}',
			),
			'review_request' => array(
				'professional' => 'How was your order #{{order_id}}?',
				'friendly'     => 'We would love your feedback on order #{{order_id}}',
				'apologetic'   => 'Thank you for your order #{{order_id}}',
			),
		);

		if ( isset( $templates[ $intent ][ $tone ] ) ) {
			return $templates[ $intent ][ $tone ];
		}

		return 'Update on order #{{order_id}}';
	}

	/**
	 * @param string $tone Email tone.
	 * @param array $template_variables Template variables.
	 * @return array
	 */
	private function build_greeting( $tone, array $template_variables ) {
		$name = isset( $template_variables['{{customer_name}}'] ) ? $template_variables['{{customer_name}}'] : '';
		$name = '' !== $name ? $name : 'there';

		$greeting = 'Hello';
		if ( 'friendly' === $tone ) {
			$greeting = 'Hi';
		} elseif ( 'apologetic' === $tone ) {
			$greeting = 'Hi';
		}

		$line = sprintf( '%s %s,', $greeting, $name );

		return array(
			'html' => '<p>' . $this->escape_html( $line ) . '</p>',
			'text' => $line,
		);
	}

	/**
	 * @param string $intent Email intent.
	 * @param string $tone Email tone.
	 * @param array $context Order context.
	 * @return array
	 */
	private function build_intro_sentence( $intent, $tone, array $context ) {
		$order_id = isset( $context['order_id'] ) ? $context['order_id'] : '';
		$order_id = '' !== $order_id ? $order_id : '{{order_id}}';

		$message = '';
		switch ( $intent ) {
			case 'shipping_update':
				$message = sprintf( 'Here is the latest shipping update for order #%s.', $order_id );
				break;
			case 'refund_confirmation':
				$message = sprintf( 'Your refund for order #%s has been initiated.', $order_id );
				if ( 'apologetic' === $tone ) {
					$message = sprintf( 'We are sorry for the trouble. Your refund for order #%s has been initiated.', $order_id );
				}
				break;
			case 'order_issue':
				$message = sprintf( 'We are looking into the issue with order #%s and wanted to update you.', $order_id );
				if ( 'apologetic' === $tone ) {
					$message = sprintf( 'We are sorry for the issue with order #%s. Here is where we are right now.', $order_id );
				}
				break;
			case 'general_inquiry':
				$message = sprintf( 'Thanks for reaching out about order #%s. Here is what we have so far.', $order_id );
				break;
			case 'review_request':
				$message = sprintf( 'We hope you are enjoying your order #%s.', $order_id );
				break;
			default:
				$message = sprintf( 'Here is an update on order #%s.', $order_id );
		}

		return array(
			'html' => '' !== $message ? '<p>' . $this->escape_html( $message ) . '</p>' : '',
			'text' => $message,
		);
	}

	/**
	 * @param array $context Order context.
	 * @return array
	 */
	private function build_items_block( array $context ) {
		$order = isset( $context['order'] ) && is_array( $context['order'] ) ? $context['order'] : array();
		$items = isset( $order['items'] ) && is_array( $order['items'] ) ? $order['items'] : array();

		$lines = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}

			$line = $item['name'];
			if ( isset( $item['quantity'] ) && (int) $item['quantity'] > 1 ) {
				$line .= ' (x' . intval( $item['quantity'] ) . ')';
			}

			$lines[] = $line;
		}

		if ( empty( $lines ) ) {
			return array(
				'html' => '',
				'text' => '',
			);
		}

		$max_lines = 4;
		if ( count( $lines ) > $max_lines ) {
			$lines = array_slice( $lines, 0, $max_lines );
			$lines[] = 'and more';
		}

		if ( count( $lines ) > 1 ) {
			$html = '<ul><li>' . implode( '</li><li>', array_map( array( $this, 'escape_html' ), $lines ) ) . '</li></ul>';
			$text = "Items:\n- " . implode( "\n- ", $lines );
		} else {
			$html = '<p>Item: ' . $this->escape_html( $lines[0] ) . '.</p>';
			$text = 'Item: ' . $lines[0] . '.';
		}

		return array(
			'html' => $html,
			'text' => $text,
		);
	}

	/**
	 * @param string $intent Email intent.
	 * @param string $tone Email tone.
	 * @param array $context Order context.
	 * @return array
	 */
	private function build_tracking_block( $intent, $tone, array $context ) {
		if ( 'refund_confirmation' === $intent || 'review_request' === $intent ) {
			return array(
				'html' => '',
				'text' => '',
			);
		}

		$shipping = isset( $context['shipping'] ) && is_array( $context['shipping'] ) ? $context['shipping'] : array();
		$tracking = isset( $shipping['tracking'] ) && is_array( $shipping['tracking'] ) ? $shipping['tracking'] : array();
		$url      = isset( $tracking['url'] ) ? $tracking['url'] : '';
		$number   = isset( $tracking['number'] ) ? $tracking['number'] : '';
		$provider = isset( $tracking['provider'] ) ? $tracking['provider'] : '';
		$estimated = isset( $shipping['estimated_delivery'] ) ? $shipping['estimated_delivery'] : '';

		if ( '' === $url && '' === $number && '' === $estimated ) {
			$message = 'We will share tracking details as soon as they are available.';
			if ( 'apologetic' === $tone ) {
				$message = 'We will share tracking details as soon as they are available. Thank you for your patience.';
			}

			return array(
				'html' => '<p>' . $this->escape_html( $message ) . '</p>',
				'text' => $message,
			);
		}

		$lines = array();
		if ( '' !== $number ) {
			$lines[] = '' !== $provider ? sprintf( 'Tracking number (%s): %s', $provider, $number ) : sprintf( 'Tracking number: %s', $number );
		}

		if ( '' !== $estimated ) {
			$lines[] = sprintf( 'Estimated delivery: %s', $estimated );
		}

		$html = '';
		if ( '' !== $url ) {
			$link_label = 'Track your package';
			$html       = '<p><a href="' . $this->escape_url( $url ) . '">' . $this->escape_html( $link_label ) . '</a></p>';
		}

		if ( ! empty( $lines ) ) {
			$html_lines = '<ul><li>' . implode( '</li><li>', array_map( array( $this, 'escape_html' ), $lines ) ) . '</li></ul>';
			$html       = '' !== $html ? $html . $html_lines : $html_lines;
		}

		$text = implode( "\n", $lines );
		if ( '' !== $url ) {
			$text = ( '' !== $text ? $text . "\n" : '' ) . 'Tracking link: ' . $url;
		}

		return array(
			'html' => $html,
			'text' => $text,
		);
	}

	/**
	 * @param string $intent Email intent.
	 * @param string $tone Email tone.
	 * @param array $context Order context.
	 * @return array
	 */
	private function build_issue_block( $intent, $tone, array $context ) {
		if ( 'order_issue' !== $intent ) {
			return array(
				'html' => '',
				'text' => '',
			);
		}

		$issues = isset( $context['issues'] ) && is_array( $context['issues'] ) ? $context['issues'] : array();
		$lines  = array();

		if ( ! empty( $issues['payment_failed'] ) ) {
			$lines[] = 'We were unable to process the payment on file.';
		}

		if ( ! empty( $issues['delayed_shipping'] ) ) {
			$lines[] = 'Shipping is taking longer than expected.';
		}

		if ( ! empty( $issues['backordered_items'] ) && is_array( $issues['backordered_items'] ) ) {
			$item_names = array();
			foreach ( $issues['backordered_items'] as $item ) {
				if ( isset( $item['name'] ) && '' !== $item['name'] ) {
					$item_names[] = $item['name'];
				}
			}

			if ( ! empty( $item_names ) ) {
				$lines[] = 'Backordered items: ' . implode( ', ', $item_names ) . '.';
			}
		}

		if ( empty( $lines ) ) {
			$lines[] = 'We are reviewing the issue and will follow up shortly.';
		}

		if ( 'apologetic' === $tone ) {
			array_unshift( $lines, 'We are sorry for the inconvenience.' );
		}

		$html = '<ul><li>' . implode( '</li><li>', array_map( array( $this, 'escape_html' ), $lines ) ) . '</li></ul>';
		$text = implode( "\n", $lines );

		return array(
			'html' => $html,
			'text' => $text,
		);
	}

	/**
	 * @param string $tone Email tone.
	 * @param array $template_variables Template variables.
	 * @return array
	 */
	private function build_closing( $tone, array $template_variables ) {
		$store_name = isset( $template_variables['{{store_name}}'] ) ? $template_variables['{{store_name}}'] : $this->get_store_name();

		$line = 'Thanks';
		if ( 'friendly' === $tone ) {
			$line = 'Thanks so much';
		} elseif ( 'apologetic' === $tone ) {
			$line = 'Thanks for your patience';
		}

		$closing = sprintf( '%s, %s Support', $line, $store_name );

		return array(
			'html' => '<p>' . $this->escape_html( $closing ) . '</p>',
			'text' => $closing,
		);
	}

	/**
	 * @param string $intent Email intent.
	 * @param array $context Order context.
	 * @return string
	 */
	private function get_intent_guidance( $intent, array $context ) {
		switch ( $intent ) {
			case 'shipping_update':
				return 'Share tracking information and estimated delivery. Mention the items when possible.';
			case 'refund_confirmation':
				return 'Confirm the refund and explain next steps. Be clear but avoid guessing refund amounts.';
			case 'order_issue':
				return 'Address the issue with empathy and describe the next steps.';
			case 'general_inquiry':
				return 'Provide a helpful status update and ask if there is anything else needed.';
			case 'review_request':
				return 'Invite the customer to leave a review and include an appreciative tone.';
			default:
				return 'Provide a helpful customer update.';
		}
	}

	/**
	 * @param string $tone Email tone.
	 * @return string
	 */
	private function get_tone_guidance( $tone ) {
		switch ( $tone ) {
			case 'friendly':
				return 'Warm, upbeat, and conversational.';
			case 'apologetic':
				return 'Empathetic and apologetic while remaining confident.';
			default:
				return 'Professional, concise, and helpful.';
		}
	}

	/**
	 * @param array $order Order context.
	 * @return array
	 */
	private function build_items_summary( array $order ) {
		$items = isset( $order['items'] ) && is_array( $order['items'] ) ? $order['items'] : array();
		$names = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}

			$name = $item['name'];
			if ( isset( $item['quantity'] ) && (int) $item['quantity'] > 1 ) {
				$name .= ' (x' . intval( $item['quantity'] ) . ')';
			}

			$names[] = $name;
		}

		return array(
			'summary' => implode( ', ', $names ),
			'count'   => count( $names ),
		);
	}

	/**
	 * @param array $shipping Shipping context.
	 * @return string
	 */
	private function get_primary_shipping_method( array $shipping ) {
		if ( empty( $shipping['methods'] ) || ! is_array( $shipping['methods'] ) ) {
			return '';
		}

		$method = $shipping['methods'][0];
		if ( is_array( $method ) && ! empty( $method['method_title'] ) ) {
			return $method['method_title'];
		}

		return '';
	}

	/**
	 * @return string
	 */
	private function get_store_name() {
		if ( function_exists( 'get_bloginfo' ) ) {
			$name = get_bloginfo( 'name' );
			if ( '' !== $name ) {
				return $name;
			}
		}

		if ( function_exists( 'get_option' ) ) {
			$name = (string) get_option( 'blogname' );
			if ( '' !== $name ) {
				return $name;
			}
		}

		return 'our store';
	}

	/**
	 * @return string
	 */
	private function get_support_email() {
		if ( function_exists( 'get_option' ) ) {
			$email = (string) get_option( 'admin_email' );
			if ( '' !== $email ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * @param mixed $value Input.
	 * @return string
	 */
	private function format_currency( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}

		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $value ) );
		}

		return (string) $value;
	}

	/**
	 * @param string $text Input.
	 * @param array $variables Template variables.
	 * @return string
	 */
	private function apply_template_variables( $text, array $variables ) {
		$text = is_string( $text ) ? $text : '';
		if ( '' === $text || empty( $variables ) ) {
			return $text;
		}

		$replace = array();
		foreach ( $variables as $key => $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( '' === $value ) {
				continue;
			}

			$replace[ $key ] = $value;
		}

		if ( empty( $replace ) ) {
			return $text;
		}

		return str_replace( array_keys( $replace ), array_values( $replace ), $text );
	}

	/**
	 * @param string $text Input.
	 * @param array $variables Template variables.
	 * @return string
	 */
	private function replace_known_placeholders( $text, array $variables ) {
		$text = is_string( $text ) ? $text : '';
		if ( '' === $text ) {
			return $text;
		}

		$replacements = array(
			'[TRACKING URL]'   => isset( $variables['{{tracking_url}}'] ) ? $variables['{{tracking_url}}'] : '',
			'[TRACKING LINK]'  => isset( $variables['{{tracking_url}}'] ) ? $variables['{{tracking_url}}'] : '',
			'[TRACKING NUMBER]' => isset( $variables['{{tracking_number}}'] ) ? $variables['{{tracking_number}}'] : '',
			'[ORDER ID]'       => isset( $variables['{{order_id}}'] ) ? $variables['{{order_id}}'] : '',
			'[CUSTOMER NAME]'  => isset( $variables['{{customer_name}}'] ) ? $variables['{{customer_name}}'] : '',
			'[STORE NAME]'     => isset( $variables['{{store_name}}'] ) ? $variables['{{store_name}}'] : '',
		);

		foreach ( $replacements as $placeholder => $value ) {
			if ( '' === $value ) {
				unset( $replacements[ $placeholder ] );
			}
		}

		if ( empty( $replacements ) ) {
			return $text;
		}

		return str_ireplace( array_keys( $replacements ), array_values( $replacements ), $text );
	}

	/**
	 * @param string $html Input HTML.
	 * @return string
	 */
	private function normalize_email_html( $html ) {
		$html = is_string( $html ) ? trim( $html ) : '';
		if ( '' === $html ) {
			return '';
		}

		if ( ! $this->looks_like_html( $html ) ) {
			$parts = preg_split( "/\r?\n\r?\n/", $html );
			$parts = array_map( 'trim', is_array( $parts ) ? $parts : array( $html ) );
			$parts = array_filter( $parts );

			if ( empty( $parts ) ) {
				return '';
			}

			$wrapped = array();
			foreach ( $parts as $part ) {
				$wrapped[] = '<p>' . $this->escape_html( $part ) . '</p>';
			}

			return implode( "\n", $wrapped );
		}

		return $html;
	}

	/**
	 * @param string $html Input HTML.
	 * @return string
	 */
	private function sanitize_email_html( $html ) {
		$html = is_string( $html ) ? $html : '';

		if ( function_exists( 'wp_kses' ) ) {
			$allowed = array(
				'p'      => array(),
				'br'     => array(),
				'strong' => array(),
				'em'     => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'a'      => array(
					'href'   => true,
					'title'  => true,
					'target' => true,
					'rel'    => true,
				),
			);

			return wp_kses( $html, $allowed );
		}

		return $html;
	}

	/**
	 * @param string $html Input HTML.
	 * @return string
	 */
	private function build_plain_text_from_html( $html ) {
		$html = is_string( $html ) ? $html : '';
		if ( '' === $html ) {
			return '';
		}

		$text = preg_replace( '/<\s*br\s*\/?>/i', "\n", $html );
		$text = preg_replace( '/<\/\s*p\s*>/i', "\n\n", $text );
		$text = preg_replace( '/<\/\s*li\s*>/i', "\n", $text );
		$text = strip_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * @param string $text Input.
	 * @return bool
	 */
	private function looks_like_html( $text ) {
		return (bool) preg_match( '/<[^>]+>/', $text );
	}

	/**
	 * @param string $value Input value.
	 * @return string
	 */
	private function escape_html( $value ) {
		$value = is_string( $value ) ? $value : '';
		if ( function_exists( 'esc_html' ) ) {
			return esc_html( $value );
		}

		return htmlspecialchars( $value, ENT_QUOTES );
	}

	/**
	 * @param string $value Input value.
	 * @return string
	 */
	private function escape_url( $value ) {
		$value = is_string( $value ) ? $value : '';
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $value );
		}

		return htmlspecialchars( $value, ENT_QUOTES );
	}

	/**
	 * @param mixed $value Input.
	 * @return string
	 */
	private function encode_json( $value ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $value );
		}

		return json_encode( $value );
	}

	/**
	 * @param string $content Response content.
	 * @return array
	 */
	private function parse_json_response( $content ) {
		$content = is_string( $content ) ? trim( $content ) : '';
		if ( '' === $content ) {
			return array();
		}

		$decoded = json_decode( $content, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$start = strpos( $content, '{' );
		$end   = strrpos( $content, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return array();
		}

		$snippet = substr( $content, $start, $end - $start + 1 );
		$decoded = json_decode( $snippet, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @return string
	 */
	private function get_api_key() {
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}

		$stored = get_option( Plugin::OPTION_API_KEY, '' );
		$stored = is_string( $stored ) ? $stored : '';
		if ( '' === $stored ) {
			return '';
		}

		$encryption = new Encryption();
		$decrypted  = $encryption->decrypt( $stored );

		if ( '' !== $decrypted ) {
			return $decrypted;
		}

		return $encryption->isEncrypted( $stored ) ? '' : $stored;
	}

	/**
	 * @return string
	 */
	private function get_model() {
		$model = '';

		if ( function_exists( 'get_option' ) ) {
			$settings = get_option( Plugin::OPTION_SETTINGS, array() );
			if ( is_array( $settings ) && isset( $settings['model'] ) ) {
				$model = (string) $settings['model'];
			}
		}

		return Model::normalize( $model );
	}

	/**
	 * @return string
	 */
	private function generate_draft_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'draft_', true );
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @return string
	 */
	private function build_draft_key( $draft_id ) {
		return Plugin::TRANSIENT_PREFIX . 'draft_' . $draft_id;
	}

	/**
	 * @return int
	 */
	private function get_draft_ttl_seconds() {
		$minute_seconds = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;

		return self::DEFAULT_TTL_MINUTES * $minute_seconds;
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @param array  $draft Draft payload.
	 * @param int    $ttl_seconds Expiration seconds.
	 * @return bool
	 */
	private function store_draft( $draft_id, array $draft, $ttl_seconds ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}

		return set_transient( $this->build_draft_key( $draft_id ), $draft, $ttl_seconds );
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @return array|null
	 */
	private function load_draft( $draft_id ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$draft = get_transient( $this->build_draft_key( $draft_id ) );

		return is_array( $draft ) ? $draft : null;
	}
}
