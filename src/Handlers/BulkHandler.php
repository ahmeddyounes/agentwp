<?php
/**
 * Handle bulk order operations.
 *
 * @package AgentWP
 */

namespace AgentWP\Handlers;

use AgentWP\AI\Response;
use AgentWP\Plugin;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

class BulkHandler {
	const ACTION_HOOK      = 'agentwp_bulk_process';
	const ASYNC_THRESHOLD  = 20;
	const DRAFT_TYPE       = 'bulk_action';
	const JOB_TTL_SECONDS  = 86400;
	const MAX_BULK         = 1000;
	const PROGRESS_TTL     = 86400;
	const ROLLBACK_TTL     = 86400;
	const POLL_INTERVAL    = 2;
	const MAX_ERRORS       = 100;

	/**
	 * Register background processing hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( self::ACTION_HOOK, array( __CLASS__, 'handle_scheduled_action' ), 10, 1 );
	}

	/**
	 * Execute scheduled bulk jobs.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function handle_scheduled_action( $job_id ) {
		$handler = new self();
		$handler->process_scheduled_job( $job_id );
	}

	/**
	 * Handle bulk actions and selections.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function handle( array $args ): Response {
		if ( isset( $args['criteria'] ) ) {
			return $this->select_orders( $args );
		}

		$progress_id = $this->extract_progress_id( $args );
		if ( '' !== $progress_id ) {
			return $this->get_progress_response( $progress_id );
		}

		$rollback_id = $this->extract_rollback_id( $args );
		if ( '' !== $rollback_id ) {
			return $this->rollback_bulk_action( $rollback_id );
		}

		$draft_id = $this->extract_draft_id( $args );
		if ( '' !== $draft_id ) {
			return $this->confirm_bulk_update( $draft_id );
		}

		return $this->prepare_bulk_update( $args );
	}

	/**
	 * Select orders based on criteria.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function select_orders( array $args ): Response {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return Response::error( 'WooCommerce is required to select orders.', 400 );
		}

		$criteria  = $this->normalize_criteria( isset( $args['criteria'] ) ? $args['criteria'] : array() );
		$order_ids = $this->query_order_ids( $criteria );
		$sample    = $this->build_sample_orders( $order_ids );

		return Response::success(
			array(
				'order_ids'  => $order_ids,
				'count'      => count( $order_ids ),
				'criteria'   => $criteria,
				'sample'     => $sample,
				'truncated'  => count( $order_ids ) >= self::MAX_BULK,
				'max_limit'  => self::MAX_BULK,
			)
		);
	}

	/**
	 * Prepare a bulk update draft.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	private function prepare_bulk_update( array $args ): Response {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return Response::error( 'WooCommerce is required to prepare bulk updates.', 400 );
		}

		$order_ids = $this->normalize_order_ids( isset( $args['order_ids'] ) ? $args['order_ids'] : array() );
		if ( empty( $order_ids ) ) {
			return Response::error( 'Missing order IDs for bulk update.', 400 );
		}

		if ( count( $order_ids ) > self::MAX_BULK ) {
			return Response::error( 'Bulk updates support up to 1000 orders at a time.', 400 );
		}

		$action = $this->normalize_action( isset( $args['action'] ) ? $args['action'] : '' );
		if ( '' === $action ) {
			return Response::error( 'Missing or invalid bulk action.', 400 );
		}

		$params = $this->normalize_params( $action, isset( $args['params'] ) ? $args['params'] : array() );
		if ( isset( $params['error'] ) ) {
			return Response::error( $params['error'], 400 );
		}

		$missing_orders = $this->find_missing_orders( $order_ids );
		if ( ! empty( $missing_orders ) ) {
			return Response::error(
				sprintf( 'Orders not found: %s.', implode( ', ', $missing_orders ) ),
				404,
				array( 'missing_orders' => $missing_orders )
			);
		}

		$sample = $this->build_sample_orders( $order_ids );
		$preview = array(
			'order_count'    => count( $order_ids ),
			'sample'         => $sample,
			'action'         => $action,
			'action_preview' => $this->build_action_preview( $action, $params ),
		);

		$draft_payload = array(
			'order_ids' => $order_ids,
			'action'    => $action,
			'params'    => $params,
			'preview'   => $preview,
		);

		$draft_id   = $this->generate_uuid();
		$ttl        = $this->get_draft_ttl_seconds();
		$expires_at = gmdate( 'c', time() + $ttl );
		$stored     = $this->store_draft(
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
			return Response::error( 'Unable to store bulk update draft.', 500 );
		}

		return Response::success(
			array(
				'draft_id'   => $draft_id,
				'draft'      => $draft_payload,
				'expires_at' => $expires_at,
			)
		);
	}

	/**
	 * Confirm and execute a bulk update draft.
	 *
	 * @param string $draft_id Draft identifier.
	 * @return Response
	 */
		private function confirm_bulk_update( $draft_id ): Response {
			if ( ! function_exists( 'wc_get_order' ) ) {
				return Response::error( 'WooCommerce is required to process bulk updates.', 400 );
			}

			$draft_id = trim( (string) $draft_id );
			if ( '' === $draft_id ) {
				return Response::error( 'Missing bulk update draft ID.', 400 );
			}

		// Atomically claim the draft by loading and deleting immediately.
		// This prevents race conditions where two requests could process the same draft.
		$draft = $this->claim_draft( $draft_id );
		if ( null === $draft ) {
			return Response::error( 'Bulk update draft not found or expired.', 404 );
		}

		if ( isset( $draft['type'] ) && self::DRAFT_TYPE !== $draft['type'] ) {
			return Response::error( 'Draft type mismatch for bulk update confirmation.', 400 );
		}

		$payload  = isset( $draft['payload'] ) && is_array( $draft['payload'] ) ? $draft['payload'] : $draft;
		$order_ids = $this->normalize_order_ids( isset( $payload['order_ids'] ) ? $payload['order_ids'] : array() );
		if ( empty( $order_ids ) ) {
			return Response::error( 'Bulk update draft is missing order IDs.', 400 );
		}

		if ( count( $order_ids ) > self::MAX_BULK ) {
			return Response::error( 'Bulk updates support up to 1000 orders at a time.', 400 );
		}

		$action = $this->normalize_action( isset( $payload['action'] ) ? $payload['action'] : '' );
		if ( '' === $action ) {
			return Response::error( 'Bulk update draft is missing an action.', 400 );
		}

		$params = $this->normalize_params( $action, isset( $payload['params'] ) ? $payload['params'] : array() );
		if ( isset( $params['error'] ) ) {
			return Response::error( $params['error'], 400 );
		}

		$job_id      = $this->generate_uuid();
		$progress_id = $this->generate_uuid();
		$rollback_id = $this->generate_uuid();

		$rollback_expires = gmdate( 'c', time() + self::ROLLBACK_TTL );
		$this->store_rollback(
			$rollback_id,
			array(
				'id'         => $rollback_id,
				'action'     => $action,
				'created_at' => gmdate( 'c' ),
				'expires_at' => $rollback_expires,
				'orders'     => array(),
			),
			self::ROLLBACK_TTL
		);

		$progress = array(
			'id'                  => $progress_id,
			'status'              => 'queued',
			'action'              => $action,
			'order_count'         => count( $order_ids ),
			'processed'           => 0,
			'updated'             => 0,
			'failed'              => 0,
			'errors'              => array(),
			'created_at'          => gmdate( 'c' ),
			'started_at'          => '',
			'last_updated'        => gmdate( 'c' ),
			'completed_at'        => '',
			'draft_id'            => $draft_id,
			'rollback_id'         => $rollback_id,
			'undo_available_until'=> $rollback_expires,
		);
		$this->store_progress( $progress_id, $progress, self::PROGRESS_TTL );

		$job = array(
			'id'          => $job_id,
			'order_ids'   => $order_ids,
			'action'      => $action,
			'params'      => $params,
			'progress_id' => $progress_id,
			'rollback_id' => $rollback_id,
			'draft_id'    => $draft_id,
		);
		$this->store_job( $job_id, $job, self::JOB_TTL_SECONDS );

		$async = count( $order_ids ) > self::ASYNC_THRESHOLD && $this->action_scheduler_available();
		if ( $async ) {
			$action_id = $this->schedule_job( $job_id );
			if ( ! $action_id ) {
				return Response::error( 'Unable to schedule bulk update.', 500 );
			}

			// Draft was already deleted atomically in claim_draft().

			return Response::success(
				array(
					'status'     => 'scheduled',
					'draft_id'   => $draft_id,
					'job_id'     => $job_id,
					'progress'   => $progress,
					'polling'    => array(
						'progress_id'      => $progress_id,
						'interval_seconds' => self::POLL_INTERVAL,
					),
					'rollback_id' => $rollback_id,
					'undo_available_until' => $rollback_expires,
				)
			);
		}

		$progress = $this->update_progress(
			$progress_id,
			array(
				'status'     => 'running',
				'started_at' => gmdate( 'c' ),
			),
			true
		);

		$result = $this->run_bulk_job( $job );

		$progress = $this->update_progress(
			$progress_id,
			array(
				'status'       => 'completed',
				'completed_at' => gmdate( 'c' ),
				'result'       => $result,
			),
			true
		);

		$this->delete_job( $job_id );
		// Draft was already deleted atomically in claim_draft().

		return Response::success(
			array(
				'status'      => 'completed',
				'draft_id'    => $draft_id,
				'result'      => $result,
				'progress'    => $progress,
				'polling'     => array(
					'progress_id'      => $progress_id,
					'interval_seconds' => self::POLL_INTERVAL,
				),
				'rollback_id' => $rollback_id,
				'undo_available_until' => $rollback_expires,
			)
		);
	}

	/**
	 * Process a scheduled bulk job.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
		private function process_scheduled_job( $job_id ) {
			$job_id = trim( (string) $job_id );
			if ( '' === $job_id ) {
				return;
			}

		$job = $this->load_job( $job_id );
		if ( ! is_array( $job ) ) {
			return;
		}

		$progress_id = isset( $job['progress_id'] ) ? (string) $job['progress_id'] : '';
		if ( '' !== $progress_id ) {
			$this->update_progress(
				$progress_id,
				array(
					'status'     => 'running',
					'started_at' => gmdate( 'c' ),
				),
				true
			);
		}

		$result = $this->run_bulk_job( $job );

		if ( '' !== $progress_id ) {
			$this->update_progress(
				$progress_id,
				array(
					'status'       => 'completed',
					'completed_at' => gmdate( 'c' ),
					'result'       => $result,
				),
				true
			);
		}

		$draft_id = isset( $job['draft_id'] ) ? (string) $job['draft_id'] : '';
		if ( '' !== $draft_id ) {
			// Draft was already deleted by claim_draft() when job was created.
			// This is a no-op but kept for safety in case of manual job creation.
			$this->delete_draft( $draft_id );
		}

		$this->delete_job( $job_id );
	}

	/**
	 * Execute a bulk job.
	 *
	 * @param array $job Job payload.
	 * @return array
	 */
	private function run_bulk_job( array $job ) {
		$order_ids   = isset( $job['order_ids'] ) ? $this->normalize_order_ids( $job['order_ids'] ) : array();
		$action      = isset( $job['action'] ) ? $this->normalize_action( $job['action'] ) : '';
		$params      = isset( $job['params'] ) && is_array( $job['params'] ) ? $job['params'] : array();
		$progress_id = isset( $job['progress_id'] ) ? (string) $job['progress_id'] : '';
		$rollback_id = isset( $job['rollback_id'] ) ? (string) $job['rollback_id'] : '';

		$result = array(
			'action'    => $action,
			'order_ids' => $order_ids,
			'updated'   => array(),
			'failed'    => array(),
			'errors'    => array(),
		);

		$rollback = $this->load_rollback( $rollback_id );
		if ( ! is_array( $rollback ) ) {
			$rollback = array(
				'id'         => $rollback_id,
				'action'     => $action,
				'created_at' => gmdate( 'c' ),
				'expires_at' => gmdate( 'c', time() + self::ROLLBACK_TTL ),
				'orders'     => array(),
			);
		}

		$processed = 0;
		$updated   = 0;
		$failed    = 0;
		$errors    = array();
		$rows      = array();

		// Batch load all orders upfront to avoid N+1 queries.
		$orders_map = $this->batch_load_orders( $order_ids );

		foreach ( $order_ids as $order_id ) {
			$processed++;
			$order = isset( $orders_map[ $order_id ] ) ? $orders_map[ $order_id ] : null;
			if ( ! $order ) {
				$failed++;
				$this->add_error( $errors, $order_id, 'Order not found.' );
				$this->maybe_update_progress( $progress_id, $processed, $updated, $failed, $errors );
				continue;
			}

			switch ( $action ) {
				case 'update_status':
					$current_status = $this->normalize_status( $order->get_status() );
					$new_status     = isset( $params['new_status'] ) ? $this->normalize_status( $params['new_status'] ) : '';
					if ( '' === $new_status ) {
						$failed++;
						$this->add_error( $errors, $order_id, 'Missing target status.' );
						break;
					}

					$rollback['orders'][ $order_id ] = array(
						'status' => $current_status,
					);

					$note            = isset( $params['note'] ) ? (string) $params['note'] : '';
					$notify_customer = $this->normalize_bool( isset( $params['notify_customer'] ) ? $params['notify_customer'] : false );
					$updated_flag    = $this->apply_status_update( $order, $new_status, $note, $notify_customer );

					if ( $updated_flag ) {
						$updated++;
						$result['updated'][] = $order_id;
					} else {
						$failed++;
						$this->add_error( $errors, $order_id, 'Unable to update status.' );
					}
					break;
				case 'add_tag':
					$tags = isset( $params['tags'] ) ? $params['tags'] : array();
					$tag  = isset( $params['tag'] ) ? $params['tag'] : '';
					$tags = $this->normalize_tags( $tags, $tag );
					if ( empty( $tags ) ) {
						$failed++;
						$this->add_error( $errors, $order_id, 'Missing tags to add.' );
						break;
					}

					$before_tags = $this->get_order_tags( $order_id );
					$rollback['orders'][ $order_id ] = array(
						'tags' => $before_tags,
					);

					$updated_flag = $this->apply_tags_update( $order, $tags );
					if ( $updated_flag ) {
						$updated++;
						$result['updated'][] = $order_id;
					} else {
						$failed++;
						$this->add_error( $errors, $order_id, 'Unable to add tags.' );
					}
					break;
				case 'add_note':
					$note = isset( $params['note'] ) ? trim( (string) $params['note'] ) : '';
					if ( '' === $note ) {
						$failed++;
						$this->add_error( $errors, $order_id, 'Missing note content.' );
						break;
					}

					$is_customer_note = $this->normalize_bool( isset( $params['is_customer_note'] ) ? $params['is_customer_note'] : false );
					$note_id          = $this->apply_order_note( $order, $note, $is_customer_note );
					if ( $note_id > 0 ) {
						$updated++;
						$result['updated'][] = $order_id;
						$rollback['orders'][ $order_id ] = array(
							'notes' => array( $note_id ),
						);
					} else {
						$failed++;
						$this->add_error( $errors, $order_id, 'Unable to add note.' );
					}
					break;
				case 'export_csv':
					$row = $this->format_export_row( $order );
					if ( ! empty( $row ) ) {
						$rows[] = $row;
						$updated++;
						$result['updated'][] = $order_id;
					} else {
						$failed++;
						$this->add_error( $errors, $order_id, 'Unable to export order row.' );
					}
					break;
				default:
					$failed++;
					$this->add_error( $errors, $order_id, 'Unsupported bulk action.' );
			}

			$this->maybe_update_progress( $progress_id, $processed, $updated, $failed, $errors );
		}

		$result['errors'] = $errors;
		$result['failed'] = array_unique( array_merge( $result['failed'], wp_list_pluck( $errors, 'order_id' ) ) );

		if ( 'export_csv' === $action ) {
			$export = $this->export_csv( $rows, isset( $params['fields'] ) ? $params['fields'] : array() );
			if ( isset( $export['error'] ) ) {
				$errors[]         = array( 'order_id' => 0, 'message' => $export['error'] );
				$result['errors'] = $errors;
			} else {
				$result['export'] = $export;
			}
		}

		$this->store_rollback( $rollback_id, $rollback, self::ROLLBACK_TTL );

		if ( '' !== $progress_id ) {
			$this->update_progress(
				$progress_id,
				array(
					'processed' => $processed,
					'updated'   => $updated,
					'failed'    => $failed,
					'errors'    => $errors,
				),
				true
			);
		}

		return $result;
	}

	/**
	 * Provide progress updates to polling clients.
	 *
	 * @param string $progress_id Progress identifier.
	 * @return Response
	 */
	private function get_progress_response( $progress_id ): Response {
		$progress = $this->load_progress( $progress_id );
		if ( null === $progress ) {
			return Response::error( 'Bulk progress not found or expired.', 404 );
		}

		return Response::success(
			array(
				'progress' => $progress,
				'polling'  => array(
					'progress_id'      => $progress_id,
					'interval_seconds' => self::POLL_INTERVAL,
				),
			)
		);
	}

	/**
	 * Roll back a bulk action.
	 *
	 * @param string $rollback_id Rollback identifier.
	 * @return Response
	 */
		private function rollback_bulk_action( $rollback_id ): Response {
			$rollback_id = trim( (string) $rollback_id );
			if ( '' === $rollback_id ) {
				return Response::error( 'Missing rollback ID.', 400 );
			}

		$rollback = $this->load_rollback( $rollback_id );
		if ( ! is_array( $rollback ) ) {
			return Response::error( 'Rollback data not found or expired.', 404 );
		}

		$action       = isset( $rollback['action'] ) ? $this->normalize_action( $rollback['action'] ) : '';
		$orders_data  = isset( $rollback['orders'] ) && is_array( $rollback['orders'] ) ? $rollback['orders'] : array();
		$undone       = array();
		$failed       = array();
		$errors       = array();

		// Batch load all orders to avoid N+1 queries.
		$order_ids  = array_map( 'absint', array_keys( $orders_data ) );
		$orders_map = $this->batch_load_orders( $order_ids );

		foreach ( $orders_data as $order_id => $data ) {
			$order_id = absint( $order_id );
			$order    = isset( $orders_map[ $order_id ] ) ? $orders_map[ $order_id ] : null;
			if ( ! $order ) {
				$failed[] = $order_id;
				$this->add_error( $errors, $order_id, 'Order not found for rollback.' );
				continue;
			}

			switch ( $action ) {
				case 'update_status':
					$previous_status = isset( $data['status'] ) ? $this->normalize_status( $data['status'] ) : '';
					if ( '' === $previous_status ) {
						$failed[] = $order_id;
						$this->add_error( $errors, $order_id, 'Missing previous status.' );
						break;
					}

					$updated = $this->apply_status_update( $order, $previous_status, 'Rollback to previous status.', false );
					if ( $updated ) {
						$undone[] = $order_id;
					} else {
						$failed[] = $order_id;
						$this->add_error( $errors, $order_id, 'Unable to restore previous status.' );
					}
					break;
				case 'add_tag':
					$tags = isset( $data['tags'] ) ? $data['tags'] : array();
					$restored = $this->restore_order_tags( $order_id, $tags );
					if ( $restored ) {
						$undone[] = $order_id;
					} else {
						$failed[] = $order_id;
						$this->add_error( $errors, $order_id, 'Unable to restore tags.' );
					}
					break;
				case 'add_note':
					$notes = isset( $data['notes'] ) ? (array) $data['notes'] : array();
					$deleted = $this->delete_order_notes( $notes );
					if ( $deleted ) {
						$undone[] = $order_id;
					} else {
						$failed[] = $order_id;
						$this->add_error( $errors, $order_id, 'Unable to remove notes.' );
					}
					break;
				default:
					$failed[] = $order_id;
					$this->add_error( $errors, $order_id, 'Rollback not supported for this action.' );
			}
		}

		return Response::success(
			array(
				'rollback_id' => $rollback_id,
				'action'      => $action,
				'undone'      => $undone,
				'failed'      => $failed,
				'errors'      => $errors,
			)
		);
	}

		/**
		 * @param array|string $criteria Criteria input.
		 * @return array
		 */
		private function normalize_criteria( $criteria ) {
		$parsed = array(
			'status'         => '',
			'date_range'     => null,
			'customer_email' => '',
			'total_min'      => null,
			'total_max'      => null,
			'country'        => '',
		);
		$query  = '';

		if ( is_string( $criteria ) ) {
			$query = sanitize_text_field( $criteria );
		} elseif ( is_array( $criteria ) ) {
			$query = isset( $criteria['query'] ) ? sanitize_text_field( $criteria['query'] ) : '';
			$parsed['status'] = isset( $criteria['status'] ) ? $this->normalize_status( $criteria['status'] ) : '';
			$parsed['date_range'] = $this->normalize_date_range_input( isset( $criteria['date_range'] ) ? $criteria['date_range'] : null );
			$parsed['customer_email'] = isset( $criteria['customer_email'] ) ? sanitize_email( $criteria['customer_email'] ) : '';
			$parsed['total_min'] = isset( $criteria['total_min'] ) ? $this->normalize_amount( $criteria['total_min'] ) : null;
			$parsed['total_max'] = isset( $criteria['total_max'] ) ? $this->normalize_amount( $criteria['total_max'] ) : null;
			$parsed['country'] = isset( $criteria['country'] ) ? $this->normalize_country( $criteria['country'] ) : '';
		}

			if ( '' !== $query ) {
				$text_parsed = $this->parse_criteria_text( $query );
				foreach ( $text_parsed as $key => $value ) {
					if ( ! isset( $parsed[ $key ] ) || '' === $parsed[ $key ] ) {
						$parsed[ $key ] = $value;
					}
				}
			}

		if ( '' === $parsed['status'] ) {
			unset( $parsed['status'] );
		}
		if ( '' === $parsed['customer_email'] ) {
			unset( $parsed['customer_email'] );
		}
		if ( '' === $parsed['country'] ) {
			unset( $parsed['country'] );
		}
		if ( null === $parsed['total_min'] ) {
			unset( $parsed['total_min'] );
		}
		if ( null === $parsed['total_max'] ) {
			unset( $parsed['total_max'] );
		}
		if ( null === $parsed['date_range'] ) {
			unset( $parsed['date_range'] );
		}

		return $parsed;
	}

	/**
	 * @param string $query Query string.
	 * @return array
	 */
	private function parse_criteria_text( $query ) {
		$query  = trim( (string) $query );
		$lower  = strtolower( $query );
		$result = array();

		$status = $this->detect_status( $lower );
		if ( '' !== $status ) {
			$result['status'] = $status;
		}

		$date_range = $this->parse_date_range_from_query( $lower );
		if ( null !== $date_range ) {
			$result['date_range'] = $date_range;
		}

		$email = $this->extract_email( $query );
		if ( '' !== $email ) {
			$result['customer_email'] = $email;
		}

		$totals = $this->extract_total_range( $lower );
		if ( isset( $totals['min'] ) ) {
			$result['total_min'] = $totals['min'];
		}
		if ( isset( $totals['max'] ) ) {
			$result['total_max'] = $totals['max'];
		}

		$country = $this->extract_country( $query );
		if ( '' !== $country ) {
			$result['country'] = $country;
		}

		return $result;
	}

	/**
	 * @param array $criteria Query criteria.
	 * @return array
	 */
	private function query_order_ids( array $criteria ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$args = array(
			'limit'   => self::MAX_BULK,
			'return'  => 'ids',
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		if ( isset( $criteria['status'] ) && '' !== $criteria['status'] ) {
			$args['status'] = $criteria['status'];
		}

		if ( isset( $criteria['date_range'] ) && is_array( $criteria['date_range'] ) ) {
			$args['date_created'] = $criteria['date_range']['start'] . '...' . $criteria['date_range']['end'];
		}

		$meta_query = array( 'relation' => 'AND' );

		if ( isset( $criteria['customer_email'] ) && '' !== $criteria['customer_email'] ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_billing_email',
					'value'   => $criteria['customer_email'],
					'compare' => '=',
				),
				array(
					'key'     => '_shipping_email',
					'value'   => $criteria['customer_email'],
					'compare' => '=',
				),
			);
		}

		if ( isset( $criteria['country'] ) && '' !== $criteria['country'] ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_billing_country',
					'value'   => $criteria['country'],
					'compare' => '=',
				),
				array(
					'key'     => '_shipping_country',
					'value'   => $criteria['country'],
					'compare' => '=',
				),
			);
		}

		if ( isset( $criteria['total_min'] ) ) {
			$meta_query[] = array(
				'key'     => '_order_total',
				'value'   => $criteria['total_min'],
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}

		if ( isset( $criteria['total_max'] ) ) {
			$meta_query[] = array(
				'key'     => '_order_total',
				'value'   => $criteria['total_max'],
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}

			if ( count( $meta_query ) > 1 ) {
				$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Needed for bulk filtering.
			}

			$order_ids = wc_get_orders( $args );
			if ( ! is_array( $order_ids ) ) {
				return array();
			}

			$normalized_ids = array();
			foreach ( $order_ids as $order_id ) {
				$normalized = 0;

				if ( is_numeric( $order_id ) ) {
					$normalized = absint( $order_id );
				} elseif ( is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ) {
					$normalized = absint( $order_id->get_id() );
				}

				if ( $normalized > 0 ) {
					$normalized_ids[] = $normalized;
				}
			}
			$order_ids = array_values( array_unique( $normalized_ids ) );

			return $order_ids;
		}

	/**
	 * Batch load orders to avoid N+1 queries.
	 *
	 * @param array $order_ids Order IDs to load.
	 * @return array Associative array of order_id => order object.
	 */
	private function batch_load_orders( array $order_ids ) {
		if ( empty( $order_ids ) || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders_map = array();
		$batch_size = 100;
		$chunks     = array_chunk( $order_ids, $batch_size );

		foreach ( $chunks as $chunk ) {
			$batch = wc_get_orders(
				array(
					'include' => $chunk,
					'limit'   => count( $chunk ),
					'orderby' => 'none',
				)
			);

				if ( is_array( $batch ) ) {
					foreach ( $batch as $order ) {
						if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
							$order_id = absint( $order->get_id() );
							if ( $order_id > 0 ) {
								$orders_map[ $order_id ] = $order;
							}
						}
					}
				}
			}

		return $orders_map;
	}

	/**
	 * @param array $order_ids Order IDs.
	 * @return array
	 */
	private function build_sample_orders( array $order_ids ) {
		$sample = array();
		$order_ids = array_slice( $order_ids, 0, 5 );

		// Batch load orders to avoid N+1 queries.
		$orders_map = $this->batch_load_orders( $order_ids );

		foreach ( $order_ids as $order_id ) {
			$order = isset( $orders_map[ $order_id ] ) ? $orders_map[ $order_id ] : null;
			if ( $order ) {
				$summary = $this->format_order_summary( $order );
				if ( ! empty( $summary ) ) {
					$sample[] = $summary;
				}
			}
		}

		return $sample;
	}

	/**
	 * @param object $order Order object.
	 * @return array
	 */
		private function format_order_summary( $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				return array();
			}

			$order_id = absint( $order->get_id() );
			if ( $order_id <= 0 ) {
				return array();
			}

			$date_created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$created_at   = ( is_object( $date_created ) && method_exists( $date_created, 'date' ) ) ? $date_created->date( 'c' ) : '';
			$status       = method_exists( $order, 'get_status' ) ? sanitize_text_field( $order->get_status() ) : '';
			$total        = method_exists( $order, 'get_total' ) ? $order->get_total() : 0;

			return array(
				'order_id'       => $order_id,
				'status'         => $status,
				'total'          => $total,
				'currency'       => method_exists( $order, 'get_currency' ) ? sanitize_text_field( $order->get_currency() ) : '',
				'customer_name'  => sanitize_text_field( $this->get_customer_name( $order ) ),
				'customer_email' => sanitize_email( $this->get_customer_email( $order ) ),
				'date_created'   => $created_at,
				'country'        => sanitize_text_field( $this->get_order_country( $order ) ),
			);
		}

	/**
	 * @param mixed $order_ids Order ID input.
	 * @return array
	 */
	private function normalize_order_ids( $order_ids ) {
		$ids = array();

		if ( is_string( $order_ids ) ) {
			$order_ids = preg_split( '/[\s,]+/', $order_ids );
		}

		if ( ! is_array( $order_ids ) ) {
			return $ids;
		}

			foreach ( $order_ids as $order_id ) {
				$normalized = 0;

				if ( is_numeric( $order_id ) ) {
					$normalized = absint( $order_id );
				} elseif ( is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ) {
					$normalized = absint( $order_id->get_id() );
				}

				if ( $normalized > 0 ) {
					$ids[] = $normalized;
				}
			}

		$ids = array_values( array_unique( $ids ) );

		return $ids;
	}

	/**
	 * @param mixed $action Raw action.
	 * @return string
	 */
	private function normalize_action( $action ) {
		$action = is_string( $action ) ? strtolower( trim( $action ) ) : '';
		$allowed = array( 'update_status', 'add_tag', 'add_note', 'export_csv' );

		return in_array( $action, $allowed, true ) ? $action : '';
	}

	/**
	 * @param string $action Action name.
	 * @param mixed  $params Raw params.
	 * @return array
	 */
	private function normalize_params( $action, $params ) {
		$params = is_array( $params ) ? $params : array();
		$normalized = array();

		switch ( $action ) {
			case 'update_status':
				$new_status = isset( $params['new_status'] ) ? $this->normalize_status( $params['new_status'] ) : '';
				if ( '' === $new_status ) {
					return array( 'error' => 'Missing new status for bulk update.' );
				}

				$valid_statuses = $this->get_valid_statuses();
				if ( ! in_array( $new_status, $valid_statuses, true ) ) {
					return array( 'error' => 'Invalid status for bulk update.' );
				}

				$normalized['new_status'] = $new_status;
				$normalized['note'] = isset( $params['note'] ) ? sanitize_text_field( wp_unslash( $params['note'] ) ) : '';
				$normalized['notify_customer'] = $this->normalize_bool( isset( $params['notify_customer'] ) ? $params['notify_customer'] : false );
				break;
			case 'add_tag':
				$tag  = isset( $params['tag'] ) ? $params['tag'] : '';
				$tags = isset( $params['tags'] ) ? $params['tags'] : array();
				$normalized['tags'] = $this->normalize_tags( $tags, $tag );
				if ( empty( $normalized['tags'] ) ) {
					return array( 'error' => 'Missing tags for bulk update.' );
				}
				break;
			case 'add_note':
				$note = isset( $params['note'] ) ? trim( (string) $params['note'] ) : '';
				if ( '' === $note ) {
					return array( 'error' => 'Missing note for bulk update.' );
				}
				$normalized['note'] = sanitize_text_field( wp_unslash( $note ) );
				$normalized['is_customer_note'] = $this->normalize_bool( isset( $params['is_customer_note'] ) ? $params['is_customer_note'] : false );
				break;
			case 'export_csv':
				$fields = isset( $params['fields'] ) ? $params['fields'] : array();
				$normalized['fields'] = $this->normalize_fields( $fields );
				break;
			default:
				return array( 'error' => 'Unsupported bulk action.' );
		}

		return $normalized;
	}

	/**
	 * @param string $action Action name.
	 * @param array  $params Params.
	 * @return array
	 */
	private function build_action_preview( $action, array $params ) {
		switch ( $action ) {
			case 'update_status':
				return array(
					'new_status'      => isset( $params['new_status'] ) ? $params['new_status'] : '',
					'notify_customer' => ! empty( $params['notify_customer'] ),
					'note'            => isset( $params['note'] ) ? $params['note'] : '',
				);
			case 'add_tag':
				return array(
					'tags' => isset( $params['tags'] ) ? $params['tags'] : array(),
				);
			case 'add_note':
				return array(
					'note' => isset( $params['note'] ) ? $params['note'] : '',
				);
			case 'export_csv':
				return array(
					'fields' => isset( $params['fields'] ) ? $params['fields'] : $this->normalize_fields( array() ),
				);
			default:
				return array();
		}
	}

	/**
	 * @param array $order_ids Order IDs.
	 * @return array
	 */
	private function find_missing_orders( array $order_ids ) {
		$missing = array();

		foreach ( $order_ids as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! $order ) {
				$missing[] = $order_id;
			}
		}

		return $missing;
	}

	/**
	 * @param string $progress_id Progress identifier.
	 * @param int    $processed Processed count.
	 * @param int    $updated Updated count.
	 * @param int    $failed Failed count.
	 * @param array  $errors Error list.
	 * @return void
	 */
	private function maybe_update_progress( $progress_id, $processed, $updated, $failed, array $errors ) {
		if ( '' === $progress_id ) {
			return;
		}

		$this->update_progress(
			$progress_id,
			array(
				'processed' => $processed,
				'updated'   => $updated,
				'failed'    => $failed,
				'errors'    => $errors,
			),
			false
		);
	}

	/**
	 * @param string $progress_id Progress ID.
	 * @param array  $updates Updates.
	 * @param bool   $force Force update.
	 * @return array
	 */
	private function update_progress( $progress_id, array $updates, $force ) {
		$progress = $this->load_progress( $progress_id );
		if ( null === $progress ) {
			$progress = array(
				'id'           => $progress_id,
				'status'       => 'queued',
				'processed'    => 0,
				'updated'      => 0,
				'failed'       => 0,
				'errors'       => array(),
				'created_at'   => gmdate( 'c' ),
				'last_updated' => gmdate( 'c' ),
			);
		}

		$last_updated = isset( $progress['last_updated'] ) ? strtotime( $progress['last_updated'] ) : 0;
		$should_update = $force || ( time() - $last_updated >= self::POLL_INTERVAL );

		foreach ( $updates as $key => $value ) {
			$progress[ $key ] = $value;
		}

		if ( $should_update ) {
			$progress['last_updated'] = gmdate( 'c' );
			$this->store_progress( $progress_id, $progress, self::PROGRESS_TTL );
		}

		return $progress;
	}

	/**
	 * @param string $progress_id Progress ID.
	 * @return array|null
	 */
	private function load_progress( $progress_id ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$progress = get_transient( $this->build_progress_key( $progress_id ) );
		if ( false === $progress || ! is_array( $progress ) ) {
			return null;
		}

		return $progress;
	}

	/**
	 * @param string $progress_id Progress ID.
	 * @param array  $progress Progress payload.
	 * @param int    $ttl TTL seconds.
	 * @return bool
	 */
	private function store_progress( $progress_id, array $progress, $ttl ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}

		return set_transient( $this->build_progress_key( $progress_id ), $progress, $ttl );
	}

	/**
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	private function load_job( $job_id ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$job = get_transient( $this->build_job_key( $job_id ) );
		if ( false === $job || ! is_array( $job ) ) {
			return null;
		}

		return $job;
	}

	/**
	 * @param string $job_id Job ID.
	 * @param array  $job Job payload.
	 * @param int    $ttl TTL seconds.
	 * @return bool
	 */
	private function store_job( $job_id, array $job, $ttl ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}

		return set_transient( $this->build_job_key( $job_id ), $job, $ttl );
	}

	/**
	 * @param string $job_id Job ID.
	 * @return void
	 */
	private function delete_job( $job_id ) {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $this->build_job_key( $job_id ) );
		}
	}

	/**
	 * @param string $rollback_id Rollback ID.
	 * @return array|null
	 */
	private function load_rollback( $rollback_id ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$rollback = get_transient( $this->build_rollback_key( $rollback_id ) );
		if ( false === $rollback || ! is_array( $rollback ) ) {
			return null;
		}

		return $rollback;
	}

	/**
	 * @param string $rollback_id Rollback ID.
	 * @param array  $rollback Rollback data.
	 * @param int    $ttl TTL seconds.
	 * @return bool
	 */
	private function store_rollback( $rollback_id, array $rollback, $ttl ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}

		return set_transient( $this->build_rollback_key( $rollback_id ), $rollback, $ttl );
	}

	/**
	 * @return string
	 */
	private function generate_uuid() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		// Fallback: use cryptographically secure random bytes.
		try {
			return 'bulk_' . bin2hex( random_bytes( 16 ) );
			} catch ( \Exception $e ) {
				$crypto_strong = false;
				$bytes         = openssl_random_pseudo_bytes( 16, $crypto_strong );
				if ( ! $crypto_strong ) {
					// Last resort: use uniqid with more entropy (less secure, but better than failing).
					return 'bulk_' . uniqid( '', true ) . bin2hex( (string) wp_rand( 0, PHP_INT_MAX ) );
				}
				return 'bulk_' . bin2hex( $bytes );
			}
		}

		/**
		 * @param string $draft_id Draft identifier.
		 * @return string
		 */
	private function build_draft_key( $draft_id ) {
		// Include user ID in the key to prevent cross-user draft access.
		$user_id = get_current_user_id();
		return Plugin::TRANSIENT_PREFIX . 'bulk_draft_' . $user_id . '_' . $draft_id;
	}

	/**
	 * @param string $progress_id Progress identifier.
	 * @return string
	 */
	private function build_progress_key( $progress_id ) {
		// Include user ID in the key to prevent cross-user progress access (IDOR protection).
		$user_id = get_current_user_id();
		return Plugin::TRANSIENT_PREFIX . 'bulk_progress_' . $user_id . '_' . $progress_id;
	}

	/**
	 * @param string $job_id Job identifier.
	 * @return string
	 */
	private function build_job_key( $job_id ) {
		return Plugin::TRANSIENT_PREFIX . 'bulk_job_' . $job_id;
	}

	/**
	 * @param string $rollback_id Rollback identifier.
	 * @return string
	 */
	private function build_rollback_key( $rollback_id ) {
		// Include user ID in the key to prevent cross-user rollback access (IDOR protection).
		$user_id = get_current_user_id();
		return Plugin::TRANSIENT_PREFIX . 'bulk_rollback_' . $user_id . '_' . $rollback_id;
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @param array  $draft Draft payload.
	 * @param int    $ttl TTL seconds.
	 * @return bool
	 */
	private function store_draft( $draft_id, array $draft, $ttl ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}

		return set_transient( $this->build_draft_key( $draft_id ), $draft, $ttl );
	}

		/**
		 * Atomically claim a draft by loading and immediately deleting it.
		 *
		 * This prevents race conditions where two concurrent requests could
	 * both load the same draft before either deletes it, causing double-processing.
	 *
	 * @param string $draft_id Draft identifier.
	 * @return array|null The draft data if successfully claimed, null otherwise.
	 */
	private function claim_draft( $draft_id ) {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'delete_transient' ) ) {
			return null;
		}

		$key   = $this->build_draft_key( $draft_id );
		$draft = get_transient( $key );

		if ( false === $draft || ! is_array( $draft ) ) {
			return null;
		}

		// Atomically claim by deleting - only the first delete succeeds.
		// This prevents race conditions where two requests could both claim the same draft.
		$deleted = delete_transient( $key );

		// If delete failed, check if already claimed by another request.
		if ( ! $deleted ) {
			$check = get_transient( $key );
			if ( false !== $check ) {
				// Transient still exists but delete failed - system issue, don't process.
				return null;
			}
			// Transient was deleted by another concurrent request - reject this claim.
			return null;
		}

		return $draft;
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @return void
	 */
	private function delete_draft( $draft_id ) {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $this->build_draft_key( $draft_id ) );
		}
	}

	/**
	 * @return int
	 */
	private function get_draft_ttl_seconds() {
		$ttl_minutes = null;

		if ( function_exists( 'get_option' ) ) {
			$option_ttl = get_option( Plugin::OPTION_DRAFT_TTL, null );
			if ( null !== $option_ttl && '' !== $option_ttl ) {
				$ttl_minutes = intval( $option_ttl );
			}
		}

		if ( ! is_int( $ttl_minutes ) || $ttl_minutes <= 0 ) {
			$ttl_minutes = 10;
		}

		$minute_seconds = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;

		return $ttl_minutes * $minute_seconds;
	}

	/**
	 * @param mixed $status Raw status.
	 * @return string
	 */
	private function normalize_status( $status ) {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';
		if ( '' === $status ) {
			return '';
		}

		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}

		return sanitize_key( $status );
	}

	/**
	 * @return array
	 */
	private function get_valid_statuses() {
		$allowed = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
		$normalized = array();

		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$statuses = wc_get_order_statuses();
			if ( is_array( $statuses ) ) {
				foreach ( array_keys( $statuses ) as $status ) {
					$normalized_status = $this->normalize_status( $status );
					if ( '' !== $normalized_status ) {
						$normalized[] = $normalized_status;
					}
				}
			}
		}

		if ( ! empty( $normalized ) ) {
			$allowed = array_values( array_intersect( $allowed, $normalized ) );
		}

		sort( $allowed );

		return $allowed;
	}

	/**
	 * @param mixed $value Input.
	 * @return bool
	 */
		private function normalize_bool( $value ) {
			if ( function_exists( 'rest_sanitize_boolean' ) ) {
				if ( is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
					return rest_sanitize_boolean( $value );
				}
			}

			// Handle string representations properly (e.g., "false" should be false).
			if ( is_string( $value ) ) {
				$value = strtolower( trim( $value ) );
			return ! in_array( $value, array( 'false', '0', 'no', 'off', '' ), true );
		}

		return (bool) $value;
	}

	/**
	 * @param mixed $amount Amount input.
	 * @return float|null
	 */
	private function normalize_amount( $amount ) {
		if ( null === $amount || '' === $amount ) {
			return null;
		}

		if ( is_string( $amount ) ) {
			$amount = preg_replace( '/[^0-9\.\-]/', '', $amount );
		}

		if ( '' === $amount || ! is_numeric( $amount ) ) {
			return null;
		}

		$amount   = (float) $amount;
		$decimals = $this->get_price_decimals();

		if ( function_exists( 'wc_format_decimal' ) ) {
			return (float) wc_format_decimal( $amount, $decimals );
		}

		return round( $amount, $decimals );
	}

	/**
	 * @return int
	 */
	private function get_price_decimals() {
		if ( function_exists( 'wc_get_price_decimals' ) ) {
			return wc_get_price_decimals();
		}

		return 2;
	}

	/**
	 * @param mixed $country Country input.
	 * @return string
	 */
	private function normalize_country( $country ) {
		$country = is_string( $country ) ? strtoupper( trim( $country ) ) : '';
		if ( '' === $country ) {
			return '';
		}

		if ( strlen( $country ) === 2 ) {
			return $country;
		}

		$map = $this->get_country_map();
		$key = strtolower( $country );

		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * @param mixed $tags Tags list.
	 * @param mixed $tag Single tag.
	 * @return array
	 */
	private function normalize_tags( $tags, $tag ) {
		$list = array();

		if ( is_string( $tags ) ) {
			$tags = preg_split( '/[\s,]+/', $tags );
		}

		if ( is_array( $tags ) ) {
			foreach ( $tags as $item ) {
				$item = sanitize_text_field( (string) $item );
				if ( '' !== $item ) {
					$list[] = $item;
				}
			}
		}

		if ( is_string( $tag ) ) {
			$tag = sanitize_text_field( $tag );
			if ( '' !== $tag ) {
				$list[] = $tag;
			}
		}

		$list = array_values( array_unique( $list ) );

		return $list;
	}

	/**
	 * @param mixed $fields Fields input.
	 * @return array
	 */
	private function normalize_fields( $fields ) {
		$default = array( 'order_id', 'status', 'total', 'currency', 'customer_name', 'customer_email', 'date_created', 'billing_country', 'shipping_country' );
		if ( empty( $fields ) ) {
			return $default;
		}

		if ( is_string( $fields ) ) {
			$fields = preg_split( '/[\s,]+/', $fields );
		}

		if ( ! is_array( $fields ) ) {
			return $default;
		}

		$sanitized = array();
		foreach ( $fields as $field ) {
			$field = sanitize_key( $field );
			if ( '' !== $field ) {
				$sanitized[] = $field;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

		/**
		 * @param object $order Order object.
		 * @param string $new_status New status.
		 * @param string $note Note for audit.
		 * @param bool $notify_customer Notify flag.
		 * @return bool
		 */
		private function apply_status_update( $order, $new_status, $note, $notify_customer ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'update_status' ) || ! method_exists( $order, 'get_status' ) ) {
				return false;
			}

			$current_status = $this->normalize_status( (string) $order->get_status() );
			if ( $current_status === $new_status ) {
				return false;
			}

		// Include actor information for audit trail.
		$actor = $this->get_current_actor();

		$note = trim( (string) $note );
		$audit_note = sprintf( '[AgentWP] Bulk status update by %s: %s -> %s.', $actor, $current_status, $new_status );
		if ( '' !== $note ) {
			$audit_note .= ' Note: ' . $note . '.';
		}

		$notify_customer = $this->normalize_bool( $notify_customer );
		$notify_customer = apply_filters( 'agentwp_status_notify_customer', $notify_customer, $order, $new_status );

		// Use named method reference instead of closure for proper remove_filter().
		if ( ! $notify_customer ) {
			add_filter( 'woocommerce_email_enabled', array( $this, 'disable_email_notifications' ), 10, 2 );
		}

		$order->update_status( $new_status, $audit_note );

		if ( ! $notify_customer ) {
			remove_filter( 'woocommerce_email_enabled', array( $this, 'disable_email_notifications' ), 10 );
		}

		return true;
	}

	/**
	 * Get the current user for audit trail purposes.
	 *
	 * @return string User display name or identifier.
	 */
	private function get_current_actor() {
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return 'system';
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return sprintf( 'user #%d', $user_id );
		}

		return $user->display_name ?: $user->user_login;
	}

	/**
	 * Filter callback to disable WooCommerce email notifications.
	 *
	 * Using a named method instead of a closure allows proper removal
	 * via remove_filter(), since PHP cannot compare closures for equality.
	 *
	 * @param bool   $enabled Whether email is enabled.
	 * @param object $email   Email object.
	 * @return bool Always false.
	 */
		public function disable_email_notifications( $enabled, $email ) {
			unset( $enabled, $email );
			return false;
		}

	/**
	 * @param object $order Order object.
	 * @param array  $tags Tags to add.
	 * @return bool
	 */
	private function apply_tags_update( $order, array $tags ) {
		$order_id = method_exists( $order, 'get_id' ) ? $order->get_id() : 0;
		if ( $order_id <= 0 ) {
			return false;
		}

		$taxonomy = $this->get_order_tag_taxonomy();
		if ( '' !== $taxonomy ) {
			$existing = wp_get_object_terms( $order_id, $taxonomy, array( 'fields' => 'names' ) );
			if ( is_wp_error( $existing ) ) {
				$existing = array();
			}
			$merged = array_values( array_unique( array_merge( $existing, $tags ) ) );
			wp_set_object_terms( $order_id, $merged, $taxonomy, false );
			return true;
		}

		if ( ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return false;
		}

		$existing = $order->get_meta( '_agentwp_order_tags', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$merged = array_values( array_unique( array_merge( $existing, $tags ) ) );
		$order->update_meta_data( '_agentwp_order_tags', $merged );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		return true;
	}

	/**
	 * @param object $order Order object.
	 * @param string $note Note text.
	 * @param bool   $is_customer_note Customer visibility.
	 * @return int
	 */
		private function apply_order_note( $order, $note, $is_customer_note ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'add_order_note' ) ) {
				return 0;
			}

		$note = trim( (string) $note );
		if ( '' === $note ) {
			return 0;
		}

		$note_id = $order->add_order_note( $note, $is_customer_note );

		return absint( $note_id );
	}

	/**
	 * @param object $order Order object.
	 * @return array
	 */
		private function format_export_row( $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				return array();
			}

			$order_id = absint( $order->get_id() );
			if ( $order_id <= 0 ) {
				return array();
			}

			$date_created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$currency     = method_exists( $order, 'get_currency' ) ? $order->get_currency() : '';
			$billing_country  = method_exists( $order, 'get_billing_country' ) ? $order->get_billing_country() : '';
			$shipping_country = method_exists( $order, 'get_shipping_country' ) ? $order->get_shipping_country() : '';
			$status           = method_exists( $order, 'get_status' ) ? sanitize_text_field( $order->get_status() ) : '';
			$total            = method_exists( $order, 'get_total' ) ? $order->get_total() : 0;
			$created_at       = ( is_object( $date_created ) && method_exists( $date_created, 'date' ) ) ? $date_created->date( 'c' ) : '';

			// Sanitize all user-controlled fields to prevent XSS.
			return array(
				'order_id'        => $order_id,
				'status'          => $status,
				'total'           => $total,
				'currency'        => sanitize_text_field( $currency ),
				'customer_name'   => sanitize_text_field( $this->get_customer_name( $order ) ),
				'customer_email'  => sanitize_email( $this->get_customer_email( $order ) ),
				'date_created'    => $created_at,
				'billing_country' => sanitize_text_field( $billing_country ),
				'shipping_country'=> sanitize_text_field( $shipping_country ),
			);
		}

	/**
	 * @param array $rows Data rows.
	 * @param array $fields Fields list.
	 * @return array
	 */
		private function export_csv( array $rows, array $fields ) {
			$fields = $this->normalize_fields( $fields );
			$csv     = $this->build_csv_content( $rows, $fields );
			$base64  = base64_encode( $csv );
			$random  = $this->generate_export_suffix();
			$random  = '' !== $random ? '-' . $random : '';
			$filename = 'agentwp-bulk-export-' . gmdate( 'Ymd-His' ) . $random . '.csv';

			return array(
				'filename'       => $filename,
				'mime_type'      => 'text/csv',
				'content_base64' => $base64,
				'file_url'       => 'data:text/csv;base64,' . $base64,
				'rows'           => count( $rows ),
			);
		}

		/**
		 * Build CSV content as a string.
		 *
		 * @param array $rows   Data rows.
		 * @param array $fields Fields list.
		 * @return string
		 */
		private function build_csv_content( array $rows, array $fields ): string {
			$lines   = array();
			$lines[] = $this->build_csv_row( $fields );

			foreach ( $rows as $row ) {
				$line = array();
				foreach ( $fields as $field ) {
					$line[] = isset( $row[ $field ] ) ? $row[ $field ] : '';
				}
				$lines[] = $this->build_csv_row( $line );
			}

			return implode( "\r\n", $lines ) . "\r\n";
		}

		/**
		 * Build a single CSV row.
		 *
		 * @param array $fields Fields to output.
		 * @return string
		 */
		private function build_csv_row( array $fields ): string {
			$encoded = array();
			foreach ( $fields as $field ) {
				$encoded[] = $this->escape_csv_field( $field );
			}
			return implode( ',', $encoded );
		}

		/**
		 * Escape a value for inclusion in a CSV field.
		 *
		 * @param mixed $value Field value.
		 * @return string
		 */
		private function escape_csv_field( $value ): string {
			$value = is_scalar( $value ) ? (string) $value : '';

			// Normalize newlines and prevent spreadsheet formula injection.
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
			if ( '' !== $value && preg_match( '/^[=+\\-@]/', ltrim( $value ) ) ) {
				$value = "'" . $value;
			}

			$needs_quotes = str_contains( $value, ',' ) || str_contains( $value, '"' ) || str_contains( $value, "\n" );
			$value       = str_replace( '"', '""', $value );

			if ( $needs_quotes ) {
				return '"' . $value . '"';
			}

			return $value;
		}

		/**
		 * Generate a random suffix for filenames.
		 *
		 * @return string
		 */
		private function generate_export_suffix(): string {
			if ( function_exists( 'wp_generate_password' ) ) {
				$random = wp_generate_password( 6, false );
				return is_string( $random ) ? $random : '';
			}

			try {
				return bin2hex( random_bytes( 3 ) );
			} catch ( Exception $e ) {
				return '';
			}
		}

	/**
	 * @param int $order_id Order ID.
	 * @return array
	 */
	private function get_order_tags( $order_id ) {
		$taxonomy = $this->get_order_tag_taxonomy();
		if ( '' !== $taxonomy ) {
			$existing = wp_get_object_terms( $order_id, $taxonomy, array( 'fields' => 'names' ) );
			if ( is_wp_error( $existing ) ) {
				return array();
			}
			return $existing;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}

		$existing = $order->get_meta( '_agentwp_order_tags', true );
		if ( ! is_array( $existing ) ) {
			return array();
		}

		return $existing;
	}

	/**
	 * @param int   $order_id Order ID.
	 * @param array $tags Tags.
	 * @return bool
	 */
	private function restore_order_tags( $order_id, $tags ) {
		$taxonomy = $this->get_order_tag_taxonomy();
		if ( '' !== $taxonomy ) {
			wp_set_object_terms( $order_id, (array) $tags, $taxonomy, false );
			return true;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! method_exists( $order, 'update_meta_data' ) ) {
			return false;
		}

		$order->update_meta_data( '_agentwp_order_tags', (array) $tags );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		return true;
	}

	/**
	 * @param array $note_ids Note IDs.
	 * @return bool
	 */
	private function delete_order_notes( array $note_ids ) {
		if ( ! function_exists( 'wp_delete_comment' ) ) {
			return false;
		}

		$deleted_any = false;
		foreach ( $note_ids as $note_id ) {
			$note_id = absint( $note_id );
			if ( $note_id > 0 ) {
				wp_delete_comment( $note_id, true );
				$deleted_any = true;
			}
		}

		return $deleted_any;
	}

	/**
	 * @return string
	 */
	private function get_order_tag_taxonomy() {
		$taxonomy = apply_filters( 'agentwp_order_tag_taxonomy', 'shop_order_tag' );
		$taxonomy = is_string( $taxonomy ) ? trim( $taxonomy ) : '';

		if ( '' !== $taxonomy && function_exists( 'taxonomy_exists' ) && taxonomy_exists( $taxonomy ) ) {
			return $taxonomy;
		}

		return '';
	}

	/**
	 * @param array|null $date_range Date range input.
	 * @return array|null
	 */
	private function normalize_date_range_input( $date_range ) {
		if ( ! is_array( $date_range ) ) {
			return null;
		}

		$start = isset( $date_range['start'] ) ? sanitize_text_field( $date_range['start'] ) : '';
		$end   = isset( $date_range['end'] ) ? sanitize_text_field( $date_range['end'] ) : '';

		return $this->normalize_date_range_values( $start, $end );
	}

	/**
	 * @param string $query Query text.
	 * @return array|null
	 */
	private function parse_date_range_from_query( $query ) {
		if ( false !== strpos( $query, 'yesterday' ) ) {
			return $this->relative_date_range( 'yesterday' );
		}

		if ( false !== strpos( $query, 'last week' ) ) {
			return $this->relative_date_range( 'last week' );
		}

		if ( false !== strpos( $query, 'this month' ) ) {
			return $this->relative_date_range( 'this month' );
		}

		$range = $this->extract_explicit_date_range( $query );
		if ( null !== $range ) {
			return $range;
		}

		return null;
	}

	/**
	 * @param string $phrase Relative phrase.
	 * @return array|null
	 */
	private function relative_date_range( $phrase ) {
		$timezone = $this->get_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$start    = null;
		$end      = null;

		switch ( $phrase ) {
			case 'yesterday':
				$start = $now->setTime( 0, 0, 0 )->modify( '-1 day' );
				$end   = $start->setTime( 23, 59, 59 );
				break;
			case 'last week':
				$start = $now->modify( '-7 days' )->setTime( 0, 0, 0 );
				$end   = $now->modify( '-1 day' )->setTime( 23, 59, 59 );
				break;
			case 'this month':
				$start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				$end   = $now->setTime( 23, 59, 59 );
				break;
			default:
				return null;
		}

		return $this->format_date_range( $start, $end );
	}

	/**
	 * @param string $query Query string.
	 * @return array|null
	 */
	private function extract_explicit_date_range( $query ) {
		$patterns = array(
			'/\bfrom\s+([a-z0-9,\/\-\s]+?)\s+to\s+([a-z0-9,\/\-\s]+)\b/i',
			'/\bbetween\s+([a-z0-9,\/\-\s]+?)\s+and\s+([a-z0-9,\/\-\s]+)\b/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $query, $matches ) ) {
				continue;
			}

			$start = trim( $matches[1] );
			$end   = trim( $matches[2] );

			if ( false !== strpos( $start, '@' ) || false !== strpos( $end, '@' ) ) {
				continue;
			}

			$range = $this->normalize_date_range_values( $start, $end );
			if ( null !== $range ) {
				return $range;
			}
		}

		return null;
	}

	/**
	 * @param string $start Start date.
	 * @param string $end End date.
	 * @return array|null
	 */
	private function normalize_date_range_values( $start, $end ) {
		$start_date = $this->parse_date_string( $start, false );
		$end_date   = $this->parse_date_string( $end, true );

		if ( null === $start_date || null === $end_date ) {
			return null;
		}

		if ( $end_date < $start_date ) {
			$temp       = $start_date;
			$start_date = $end_date;
			$end_date   = $temp;
		}

		return $this->format_date_range( $start_date, $end_date );
	}

	/**
	 * @param string $date_string Date string.
	 * @param bool   $end_of_day End of day flag.
	 * @return DateTimeImmutable|null
	 */
	private function parse_date_string( $date_string, $end_of_day ) {
		$date_string = trim( (string) $date_string );
		if ( '' === $date_string ) {
			return null;
		}

		$timezone = $this->get_timezone();
		$base_ts  = $this->get_base_timestamp();
		$ts       = strtotime( $date_string, $base_ts );

		if ( false === $ts ) {
			return null;
		}

		$date = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $timezone );
		$date = $end_of_day ? $date->setTime( 23, 59, 59 ) : $date->setTime( 0, 0, 0 );

		return $date;
	}

	/**
	 * @param DateTimeImmutable $start Start date.
	 * @param DateTimeImmutable $end End date.
	 * @return array
	 */
	private function format_date_range( DateTimeImmutable $start, DateTimeImmutable $end ) {
		// Convert to UTC for WooCommerce database queries (stores dates in UTC).
		$utc       = new DateTimeZone( 'UTC' );
		$start_utc = $start->setTimezone( $utc );
		$end_utc   = $end->setTimezone( $utc );

		return array(
			'start' => $start_utc->format( 'Y-m-d H:i:s' ),
			'end'   => $end_utc->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * @return DateTimeZone
	 */
	private function get_timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$timezone = '';
		if ( function_exists( 'wp_timezone_string' ) ) {
			$timezone = wp_timezone_string();
		}

		if ( '' === $timezone && function_exists( 'get_option' ) ) {
			$timezone = (string) get_option( 'timezone_string' );
		}

		// Handle GMT offset when timezone_string is empty.
		if ( '' === $timezone && function_exists( 'get_option' ) ) {
			$gmt_offset = (float) get_option( 'gmt_offset', 0 );
			if ( 0.0 !== $gmt_offset ) {
				$hours    = (int) $gmt_offset;
				$minutes  = abs( (int) ( ( $gmt_offset - $hours ) * 60 ) );
				$sign     = $gmt_offset >= 0 ? '+' : '-';
				$timezone = sprintf( '%s%02d:%02d', $sign, abs( $hours ), $minutes );
			}
		}

		if ( '' === $timezone ) {
			$timezone = 'UTC';
		}

		try {
			return new DateTimeZone( $timezone );
		} catch ( Exception $exception ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/**
	 * @return int
	 */
	private function get_base_timestamp() {
		if ( function_exists( 'current_time' ) ) {
			return (int) current_time( 'timestamp' );
		}

		return time();
	}

	/**
	 * @param string $query Query string.
	 * @return string
	 */
	private function detect_status( $query ) {
		$map = array(
			'pending'    => array( 'pending', 'awaiting payment' ),
			'processing' => array( 'processing', 'in progress' ),
			'completed'  => array( 'completed', 'complete', 'fulfilled' ),
			'on-hold'    => array( 'on hold', 'on-hold', 'hold' ),
			'cancelled'  => array( 'cancelled', 'canceled' ),
			'refunded'   => array( 'refunded', 'refund' ),
			'failed'     => array( 'failed', 'declined' ),
		);

		foreach ( $map as $status => $terms ) {
			foreach ( $terms as $term ) {
				if ( false !== strpos( $query, $term ) ) {
					return $status;
				}
			}
		}

		return '';
	}

	/**
	 * @param string $query Query string.
	 * @return string
	 */
	private function extract_email( $query ) {
		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $query, $matches ) ) {
			return sanitize_email( $matches[0] );
		}

		return '';
	}

	/**
	 * @param string $query Query string.
	 * @return array
	 */
	private function extract_total_range( $query ) {
		$result = array();

		if ( preg_match( '/\bbetween\s+[\$£\x{20AC}]?([0-9\.,]+)\s+(?:and|to)\s+[\$£\x{20AC}]?([0-9\.,]+)/ui', $query, $matches ) ) {
			$result['min'] = $this->parse_amount_string( $matches[1] );
			$result['max'] = $this->parse_amount_string( $matches[2] );
			return $result;
		}

		if ( preg_match( '/\b(over|above|more than|greater than|at least|minimum|min)\s+[\$£\x{20AC}]?([0-9\.,]+)/ui', $query, $matches ) ) {
			$result['min'] = $this->parse_amount_string( $matches[2] );
		}

		if ( preg_match( '/\b(under|below|less than|at most|maximum|max)\s+[\$£\x{20AC}]?([0-9\.,]+)/ui', $query, $matches ) ) {
			$result['max'] = $this->parse_amount_string( $matches[2] );
		}

		if ( preg_match( '/\b>=\s*[\$£\x{20AC}]?([0-9\.,]+)/ui', $query, $matches ) ) {
			$result['min'] = $this->parse_amount_string( $matches[1] );
		}

		if ( preg_match( '/\b<=\s*[\$£\x{20AC}]?([0-9\.,]+)/ui', $query, $matches ) ) {
			$result['max'] = $this->parse_amount_string( $matches[1] );
		}

		return $result;
	}

	/**
	 * @param string $value Amount string.
	 * @return float|null
	 */
	private function parse_amount_string( $value ) {
		$value = str_replace( array( ',', ' ' ), '', (string) $value );
		return $this->normalize_amount( $value );
	}

	/**
	 * @param string $query Query string.
	 * @return string
	 */
	private function extract_country( $query ) {
		$query_lower = strtolower( $query );
		$map         = $this->get_country_map();

		foreach ( $map as $name => $code ) {
			$pattern = '/\b' . preg_quote( $name, '/' ) . '\b/i';
			if ( preg_match( $pattern, $query_lower ) ) {
				return $code;
			}
		}

		if ( preg_match_all( '/\b([A-Z]{2})\b/', strtoupper( $query ), $matches ) ) {
			foreach ( $matches[1] as $code ) {
				if ( isset( $map[ strtolower( $code ) ] ) ) {
					return $map[ strtolower( $code ) ];
				}
			}
		}

		return '';
	}

	/**
	 * @return array
	 */
	private function get_country_map() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}

			$countries = array();
			if ( function_exists( 'WC' ) ) {
				$wc = WC();
				if ( $wc && isset( $wc->countries ) && is_object( $wc->countries ) && method_exists( $wc->countries, 'get_countries' ) ) {
					$countries = $wc->countries->get_countries();
				}
			}

		if ( empty( $countries ) && class_exists( 'WC_Countries' ) ) {
			$wc_countries = new \WC_Countries();
			$countries    = $wc_countries->get_countries();
		}

		$map = array();
		if ( is_array( $countries ) ) {
			foreach ( $countries as $code => $name ) {
				$map[ strtolower( $name ) ] = strtoupper( $code );
				$map[ strtolower( $code ) ] = strtoupper( $code );
			}
		}

		$map['usa'] = 'US';
		$map['us']  = 'US';
		$map['uk']  = 'GB';

		return $map;
	}

	/**
	 * @param object $order Order object.
	 * @return string
	 */
	private function get_customer_name( $order ) {
		$first = method_exists( $order, 'get_billing_first_name' ) ? $order->get_billing_first_name() : '';
		$last  = method_exists( $order, 'get_billing_last_name' ) ? $order->get_billing_last_name() : '';
		$name  = trim( $first . ' ' . $last );

		if ( '' !== $name ) {
			return $name;
		}

		$first = method_exists( $order, 'get_shipping_first_name' ) ? $order->get_shipping_first_name() : '';
		$last  = method_exists( $order, 'get_shipping_last_name' ) ? $order->get_shipping_last_name() : '';

		return trim( $first . ' ' . $last );
	}

	/**
	 * @param object $order Order object.
	 * @return string
	 */
	private function get_customer_email( $order ) {
		$email = method_exists( $order, 'get_billing_email' ) ? $order->get_billing_email() : '';
		if ( '' !== $email ) {
			return $email;
		}

		if ( method_exists( $order, 'get_meta' ) ) {
			$email = $order->get_meta( '_shipping_email' );
		}

		return is_string( $email ) ? $email : '';
	}

	/**
	 * @param object $order Order object.
	 * @return string
	 */
	private function get_order_country( $order ) {
		$country = method_exists( $order, 'get_shipping_country' ) ? $order->get_shipping_country() : '';
		if ( '' !== $country ) {
			return $country;
		}

		if ( method_exists( $order, 'get_billing_country' ) ) {
			$country = $order->get_billing_country();
		}

		return is_string( $country ) ? $country : '';
	}

	/**
	 * @param array $args Request args.
	 * @return string
	 */
	private function extract_draft_id( array $args ) {
		$draft_id = isset( $args['draft_id'] ) ? $args['draft_id'] : '';
		if ( '' === $draft_id && isset( $args['params']['draft_id'] ) ) {
			$draft_id = $args['params']['draft_id'];
		}

		return is_string( $draft_id ) ? trim( $draft_id ) : '';
	}

	/**
	 * @param array $args Request args.
	 * @return string
	 */
	private function extract_progress_id( array $args ) {
		$progress_id = isset( $args['progress_id'] ) ? $args['progress_id'] : '';
		if ( '' === $progress_id && isset( $args['params']['progress_id'] ) ) {
			$progress_id = $args['params']['progress_id'];
		}

		return is_string( $progress_id ) ? trim( $progress_id ) : '';
	}

	/**
	 * @param array $args Request args.
	 * @return string
	 */
	private function extract_rollback_id( array $args ) {
		$rollback_id = isset( $args['rollback_id'] ) ? $args['rollback_id'] : '';
		if ( '' === $rollback_id && isset( $args['params']['rollback_id'] ) ) {
			$rollback_id = $args['params']['rollback_id'];
		}

		return is_string( $rollback_id ) ? trim( $rollback_id ) : '';
	}

	/**
	 * @return bool
	 */
	private function action_scheduler_available() {
		return function_exists( 'as_enqueue_async_action' ) || function_exists( 'as_schedule_single_action' );
	}

	/**
	 * @param string $job_id Job identifier.
	 * @return int
	 */
	private function schedule_job( $job_id ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return (int) as_enqueue_async_action( self::ACTION_HOOK, array( 'job_id' => $job_id ) );
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			return (int) as_schedule_single_action( time(), self::ACTION_HOOK, array( 'job_id' => $job_id ) );
		}

		return 0;
	}

	/**
	 * Add an error to the errors array with bounds checking.
	 *
	 * Prevents unbounded memory growth by limiting the number of
	 * detailed error messages stored.
	 *
	 * @param array  $errors   Reference to errors array.
	 * @param int    $order_id Order ID.
	 * @param string $message  Error message.
	 * @return void
	 */
	private function add_error( array &$errors, int $order_id, string $message ): void {
		if ( count( $errors ) >= self::MAX_ERRORS ) {
			// Only track the first truncation.
			if ( ! isset( $errors['truncated'] ) ) {
				$errors['truncated'] = true;
			}
			return;
		}
		$errors[] = array(
			'order_id' => $order_id,
			'message'  => $message,
		);
	}
}
