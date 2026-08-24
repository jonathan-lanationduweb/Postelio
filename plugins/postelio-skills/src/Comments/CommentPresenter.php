<?php
/**
 * Vue publique d'un commentaire : auteur public minimal, jamais d'ID SQL ni de donnée privée.
 *
 * @package Postelio\Skills\Comments
 */

namespace Postelio\Skills\Comments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CommentPresenter {

	/** @param array<string,mixed> $c @return array<string,mixed> */
	public static function view( array $c ): array {
		$name = '';
		if ( class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ) {
			$name = \Postelio\Users\Api\UserDirectory::display_name( (int) $c['author_user_id'] );
		}
		return array(
			'uuid'       => (string) $c['public_uuid'],
			'body'       => (string) $c['body'],
			'author'     => array( 'name' => $name, 'role' => (string) ( $c['author_role'] ?? '' ) ),
			'created_at' => self::iso( (string) $c['created_at'] ),
		);
	}

	private static function iso( string $mysql_utc ): ?string {
		if ( '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return null;
		}
		return str_replace( ' ', 'T', $mysql_utc ) . 'Z';
	}
}
