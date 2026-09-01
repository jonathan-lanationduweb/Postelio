<?php
/**
 * Page « à venir » : réserve l'emplacement du menu et décrit ce que la page fera, sans logique
 * métier. Utilisée pour les entrées non prioritaires de la Phase 1.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlaceholderPage extends Page {

	private string $title;
	private string $desc;
	private string $cap;

	public function __construct( string $title, string $desc, string $cap ) {
		$this->title = $title;
		$this->desc  = $desc;
		$this->cap   = $cap;
	}

	protected function capability(): string {
		return $this->cap;
	}

	protected function body(): string {
		return Ui::header( $this->title, 'Back-office Postelio' )
			. Ui::empty_state( 'Page en préparation', $this->desc . ' — implémentation prévue dans une phase ultérieure du back-office.', '🧭' );
	}
}
