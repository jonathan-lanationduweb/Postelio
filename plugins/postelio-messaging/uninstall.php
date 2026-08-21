<?php
/**
 * Désinstallation de Postelio Messaging. NON destructif par défaut ; suppression des
 * tables uniquement si `postelio_delete_data_on_uninstall` = true.
 *
 * @package Postelio\Messaging
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
if ( ! get_option( 'postelio_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;
foreach ( array( 'postelio_messages', 'postelio_conversation_participants', 'postelio_conversations' ) as $s ) {
	$table = $wpdb->prefix . $s;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
delete_option( 'postelio_messaging_schema' );
