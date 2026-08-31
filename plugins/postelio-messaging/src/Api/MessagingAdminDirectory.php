<?php
/**
 * Contrat de LECTURE ADMIN de la messagerie (consommé par postelio-admin). PRIVACY-FIRST :
 * métriques globales + liste de CONVERSATIONS (contexte, participants, état, dernière activité).
 * N'expose JAMAIS le CONTENU des messages (ni en liste, ni en détail). La modération éventuelle
 * passe par les contrats de modération/messagerie, pas par une lecture directe des messages ici.
 *
 * @package Postelio\Messaging\Api
 */

namespace Postelio\Messaging\Api;

use Postelio\Messaging\Conversations\ConversationRepository;
use Postelio\Messaging\Conversations\MessageRepository;
use Postelio\Messaging\Conversations\ParticipantRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MessagingAdminDirectory {

	private const STATUSES = array( 'active', 'closed', 'archived' );

	/** @return array<string,mixed> */
	public static function counts(): array {
		global $wpdb;
		$conv = ConversationRepository::table();
		$msg  = MessageRepository::table();

		$by_status = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$conv} GROUP BY status", ARRAY_A ) as $r ) {
			$by_status[ (string) $r['status'] ] = (int) $r['n'];
		}
		$total_conv = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conv}" );
		$total_msg  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$msg}" );
		// Messages des 7 derniers jours (métrique d'activité, aucun contenu).
		$recent_msg = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$msg} WHERE created_at >= ( UTC_TIMESTAMP() - INTERVAL 7 DAY )" );

		return array(
			'total'        => $total_conv,
			'active'       => (int) ( $by_status['active'] ?? 0 ),
			'closed'       => (int) ( $by_status['closed'] ?? 0 ),
			'archived'     => (int) ( $by_status['archived'] ?? 0 ),
			'messages'     => $total_msg,
			'messages_7d'  => $recent_msg,
		);
	}

	/**
	 * @param array<string,mixed> $filters status, company_id
	 * @return array{items:array<int,array<string,mixed>>, total:int}
	 */
	public static function list( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$conv  = ConversationRepository::table();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) && in_array( (string) $filters['status'], self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $filters['status'];
		}
		if ( ! empty( $filters['company_id'] ) ) {
			$where[] = 'company_id = %d';
			$args[]  = (int) $filters['company_id'];
		}
		$clause = implode( ' AND ', $where );
		$total  = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$conv} WHERE {$clause}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$conv} WHERE {$clause}" ) );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		// SELECT explicite : jamais de contenu de message (les messages sont dans une autre table).
		$sql  = "SELECT public_uuid, application_uuid, company_id, candidate_user_id, subject, status, last_message_at, created_at FROM {$conv} WHERE {$clause} ORDER BY COALESCE(last_message_at, created_at) DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::row_view( $row );
		}
		return array( 'items' => $items, 'total' => $total );
	}

	/** @return array<string,mixed>|null Contexte de conversation, SANS contenu de message. */
	public static function detail( string $uuid ): ?array {
		$row = ( new ConversationRepository() )->get_by_uuid( $uuid );
		if ( null === $row ) {
			return null;
		}
		$view                 = self::row_view( $row );
		$view['participants'] = self::participants( (int) $row['id'] );
		$view['message_count'] = self::message_count( (int) $row['id'] );
		$view['updated_at']   = (string) ( $row['updated_at'] ?? '' );
		return $view;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function row_view( array $row ): array {
		$candidate = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::display_name( (int) $row['candidate_user_id'] ) : '';
		$company   = class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) ? (string) \Postelio\Companies\Api\CompanyDirectory::name_of( (int) $row['company_id'] ) : '';
		return array(
			'uuid'             => (string) $row['public_uuid'],
			'application_uuid' => (string) ( $row['application_uuid'] ?? '' ),
			'subject'          => (string) ( $row['subject'] ?? '' ),
			'candidate'        => '' !== $candidate ? $candidate : '—',
			'company'          => '' !== $company ? $company : '—',
			'status'           => (string) $row['status'],
			'last_message_at'  => (string) ( $row['last_message_at'] ?? '' ),
			'created_at'       => (string) ( $row['created_at'] ?? '' ),
		);
	}

	/** @return array<int,array<string,mixed>> Participants (rôle + nom), pas de contenu. */
	private static function participants( int $conversation_id ): array {
		global $wpdb;
		$table = ParticipantRepository::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, role FROM {$table} WHERE conversation_id = %d", $conversation_id ), ARRAY_A );
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$name  = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::display_name( (int) $r['user_id'] ) : '';
			$out[] = array( 'role' => (string) $r['role'], 'name' => '' !== $name ? $name : '—' );
		}
		return $out;
	}

	private static function message_count( int $conversation_id ): int {
		global $wpdb;
		$table = MessageRepository::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE conversation_id = %d", $conversation_id ) );
	}
}
