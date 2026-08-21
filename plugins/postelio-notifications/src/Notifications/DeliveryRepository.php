<?php
/**
 * File de livraisons (`wp_postelio_notification_deliveries`). Multi-canal (email V1,
 * push futur). Idempotence : UNIQUE (dedup_key, channel). Retry borné + backoff. Un
 * `sent` n'est jamais renvoyé ; un `processing` abandonné est récupérable.
 *
 * @package Postelio\Notifications\Notifications
 */

namespace Postelio\Notifications\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DeliveryRepository {

	public const PENDING    = 'pending';
	public const PROCESSING = 'processing';
	public const SENT       = 'sent';
	public const FAILED     = 'failed';
	public const SKIPPED    = 'skipped';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_notification_deliveries';
	}

	/**
	 * Met en file une livraison. Retourne l'id, ou 0 si un doublon (dedup_key, channel)
	 * existe déjà (idempotence).
	 *
	 * @param array<string, mixed> $data
	 */
	public function enqueue( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$prev = $wpdb->suppress_errors( true );
		$ok   = $wpdb->insert(
			self::table(),
			array(
				'public_uuid'  => $this->unique_uuid(),
				'user_id'      => (int) $data['user_id'],
				'channel'      => (string) ( $data['channel'] ?? 'email' ),
				'template'     => (string) $data['template'],
				'recipient_email' => $data['recipient_email'] ?? null,
				'payload'      => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
				'dedup_key'    => (string) $data['dedup_key'],
				'status'       => self::PENDING,
				'priority'     => (string) ( $data['priority'] ?? 'normal' ),
				'max_attempts' => (int) ( $data['max_attempts'] ?? 5 ),
				'scheduled_at' => (string) ( $data['scheduled_at'] ?? $now ),
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		$wpdb->suppress_errors( $prev );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Réclame jusqu'à $limit livraisons dues (pending échues, ou processing abandonnées
	 * depuis > $stale_seconds). Claim atomique par ligne (status → processing).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function claim_due( int $limit, int $stale_seconds = 300 ): array {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - $stale_seconds );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . " WHERE ( status = %s AND scheduled_at <= %s ) OR ( status = %s AND processing_at < %s ) ORDER BY scheduled_at ASC LIMIT %d",
				self::PENDING,
				$now,
				self::PROCESSING,
				$stale,
				$limit
			)
		);
		$claimed = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			// Claim atomique : ne passe à processing que si encore pending/processing-échu.
			$n = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::table() . ' SET status = %s, processing_at = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status IN (%s, %s)',
					self::PROCESSING,
					$now,
					$now,
					$id,
					self::PENDING,
					self::PROCESSING
				)
			);
			if ( (int) $n > 0 ) {
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
				if ( $row ) {
					$claimed[] = $this->decode( $row );
				}
			}
		}
		return $claimed;
	}

	public function mark_sent( int $id, string $provider_message_id ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->update(
			self::table(),
			array( 'status' => self::SENT, 'sent_at' => $now, 'provider_message_id' => $provider_message_id, 'last_error' => null, 'updated_at' => $now ),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Échec d'une tentative : repasse pending (avec backoff) tant que attempts < max,
	 * sinon failed définitif.
	 */
	public function mark_attempt_failed( int $id, int $attempts, int $max_attempts, string $error ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		if ( $attempts >= $max_attempts ) {
			$wpdb->update(
				self::table(),
				array( 'status' => self::FAILED, 'failed_at' => $now, 'last_error' => substr( $error, 0, 250 ), 'updated_at' => $now ),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}
		$backoff = min( 3600, (int) pow( 2, $attempts ) * 60 ); // 2,4,8… min, plafonné 1 h
		$wpdb->update(
			self::table(),
			array( 'status' => self::PENDING, 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + $backoff ), 'last_error' => substr( $error, 0, 250 ), 'processing_at' => null, 'updated_at' => $now ),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Passe une livraison (déjà réclamée/processing) en `skipped`, par id. */
	public function mark_skipped( int $id, string $reason ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->update(
			self::table(),
			array( 'status' => self::SKIPPED, 'last_error' => substr( $reason, 0, 250 ), 'updated_at' => $now ),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Passe en `skipped` une livraison encore en attente (préférence, lecture, compte…). */
	public function skip_pending( string $dedup_key, string $channel, string $reason ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET status = %s, last_error = %s, updated_at = %s WHERE dedup_key = %s AND channel = %s AND status = %s',
				self::SKIPPED,
				substr( $reason, 0, 250 ),
				$now,
				$dedup_key,
				$channel,
				self::PENDING
			)
		);
	}

	/** Passe en `skipped` toutes les livraisons pending dont la dedup_key commence par $prefix. */
	public function skip_pending_prefix( string $prefix, string $channel, string $reason ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET status = %s, last_error = %s, updated_at = %s WHERE dedup_key LIKE %s AND channel = %s AND status = %s',
				self::SKIPPED,
				substr( $reason, 0, 250 ),
				$now,
				$wpdb->esc_like( $prefix ) . '%',
				$channel,
				self::PENDING
			)
		);
	}

	public function count_recent_sent_or_pending( int $user_id, string $group_prefix, int $within_seconds ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $within_seconds );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE user_id = %d AND dedup_key LIKE %s AND status IN (%s, %s, %s) AND created_at >= %s",
				$user_id,
				$wpdb->esc_like( $group_prefix ) . '%',
				self::PENDING,
				self::PROCESSING,
				self::SENT,
				$since
			)
		);
	}

	public function delete_for_user( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/** @return array<string, mixed> */
	private function decode( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['user_id'] = (int) $row['user_id'];
		$row['attempts']     = (int) $row['attempts'];
		$row['max_attempts'] = (int) $row['max_attempts'];
		$row['payload'] = ( isset( $row['payload'] ) && '' !== $row['payload'] && null !== $row['payload'] )
			? json_decode( (string) $row['payload'], true )
			: array();
		return $row;
	}

	private function unique_uuid(): string {
		global $wpdb;
		do {
			$uuid   = wp_generate_uuid4();
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE public_uuid = %s', $uuid ) );
		} while ( null !== $exists );
		return $uuid;
	}
}
