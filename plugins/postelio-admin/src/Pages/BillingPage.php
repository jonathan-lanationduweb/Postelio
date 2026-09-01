<?php
/**
 * Facturation : KPI + liste des commandes + détail, consommant l'API Billing existante
 * (/billing/admin/orders, /billing/health). Action Retry fulfillment. Aucun secret exposé
 * (jamais de clé/webhook/payload Stripe), aucun ID SQL.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BillingPage extends Page {

	protected function capability(): string {
		return 'pst_manage_billing';
	}

	protected function body(): string {
		if ( ! Contracts::module_active( 'billing' ) ) {
			return Ui::toolbar( 'Facturation', 'Commandes et paiements Postelio.' ) . Ui::empty_state( 'Module indisponible', 'Le module Facturation n\'est pas actif.', '💳' );
		}
		$view = $this->current( 'view' );
		if ( '' !== $view ) {
			return $this->detail( $view );
		}
		$tab   = $this->current( 'tab', 'all' );
		$paged = $this->paged();

		$out  = Ui::toolbar( 'Facturation', 'Commandes et paiements Postelio.' );

		// KPI (compacts, libellés métier)
		$health = Contracts::rest( 'GET', '/postelio/v1/billing/health' );
		$hd     = is_array( $health['data'] ) ? ( $health['data']['data'] ?? array() ) : array();
		$grid   = '<div class="pst-admin-grid pst-admin-grid--6">';
		foreach ( array( 'fulfilled' => 'Payées', 'awaiting_payment' => 'En attente', 'fulfillment_pending' => 'Traitement en cours', 'fulfillment_failed' => 'En échec', 'manual_review' => 'À vérifier', 'refunded' => 'Remboursées' ) as $st => $lbl ) {
			$n = Contracts::rest_total( '/postelio/v1/billing/admin/orders', array( 'status' => $st ) );
			$grid .= Ui::stat( $lbl, null === $n ? '—' : (string) $n, '', 'fulfillment_failed' === $st, null === $n );
		}
		$grid .= '</div>';
		$out  .= $grid;

		if ( ! empty( $hd ) && empty( $hd['invoice_legal_ready'] ) ) {
			$out .= Ui::alert( 'Configuration incomplète — les informations légales du vendeur doivent être renseignées avant l\'émission de factures. Voir Réglages → Facturation.', 'warning' );
		}

		// Tabs par statut
		$tabs = array( array( 'label' => 'Toutes', 'url' => $this->url( 'postelio-billing', array( 'tab' => 'all' ) ), 'active' => 'all' === $tab ) );
		foreach ( array( 'fulfilled' => 'Payées', 'awaiting_payment' => 'En attente', 'fulfillment_failed' => 'En échec', 'manual_review' => 'À vérifier', 'refunded' => 'Remboursées' ) as $st => $lbl ) {
			$tabs[] = array( 'label' => $lbl, 'url' => $this->url( 'postelio-billing', array( 'tab' => $st ) ), 'active' => $st === $tab );
		}
		$out .= Ui::tabs( $tabs );

		$query = array( 'page' => $paged, 'per_page' => 20 );
		if ( 'all' !== $tab ) {
			$query['status'] = $tab;
		}
		$res = Contracts::rest( 'GET', '/postelio/v1/billing/admin/orders', $query );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return $out . Ui::alert( 'Liste des commandes momentanément indisponible.', 'warning' );
		}
		$items = (array) ( $res['data']['data'] ?? array() );
		$total = (int) ( $res['data']['meta']['pagination']['total'] ?? count( $items ) );

		$rows = array();
		foreach ( $items as $o ) {
			$rows[] = $this->row( (array) $o );
		}
		$out .= Ui::table( array( 'Commande', 'Produit', 'Montant', 'Paiement', 'Fulfillment', 'Créée', 'Actions' ), $rows, 'Aucune commande.' );
		$out .= Ui::pager( $this->url( 'postelio-billing', array( 'tab' => $tab ) ), $paged, 20, $total );
		return $out;
	}

	/** @param array<string,mixed> $o @return array<int,string> */
	private function row( array $o ): array {
		$uuid = (string) ( $o['order_uuid'] ?? '' );
		$amt  = number_format_i18n( (int) ( $o['amount'] ?? 0 ) / 100, 2 ) . ' ' . (string) ( $o['currency'] ?? 'EUR' );
		$stv  = array( 'fulfilled' => 'success', 'fulfillment_failed' => 'error', 'manual_review' => 'warning', 'awaiting_payment' => 'info', 'refunded' => 'neutral', 'payment_failed' => 'error' );
		return array(
			Ui::text( mb_substr( $uuid, 0, 8 ) . '…', true ),
			Ui::text( (string) ( $o['product']['label'] ?? $o['product']['code'] ?? '—' ), false, true ),
			Ui::text( $amt ),
			Ui::badge( (string) ( $o['payment_status'] ?? '—' ), 'succeeded' === ( $o['payment_status'] ?? '' ) ? 'success' : 'neutral' ),
			Ui::badge( (string) ( $o['status'] ?? '—' ), $stv[ $o['status'] ?? '' ] ?? 'neutral', true ),
			Ui::text( substr( (string) ( $o['created_at'] ?? '' ), 0, 10 ), false, true ),
			'<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-billing', array( 'view' => $uuid ) ) ) . '">Voir</a>',
		);
	}

	private function detail( string $uuid ): string {
		$back = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-billing' ) ) . '">← Liste</a>';
		$res  = Contracts::rest( 'GET', '/postelio/v1/billing/admin/orders/' . $uuid );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return Ui::header( 'Commande', 'Facturation', $back ) . Ui::empty_state( 'Introuvable', 'Commande introuvable.', '💳' );
		}
		$o = (array) ( $res['data']['data'] ?? array() );

		$retry = '';
		if ( in_array( (string) ( $o['status'] ?? '' ), array( 'fulfillment_failed', 'manual_review' ), true ) ) {
			$retry = Ui::action_button( 'pst_admin_billing_retry', array( 'uuid' => $uuid ), 'Relancer le fulfillment', 'primary', 'Relancer le traitement de cette commande ?' );
		}
		$out  = Ui::header( 'Commande ' . mb_substr( $uuid, 0, 8 ), 'Détail de facturation', $back . ' ' . $retry );
		$out .= '<div class="pst-admin-cols"><div>';

		$out .= Ui::card_open( 'Commande' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Statut</dt><dd>' . Ui::badge( (string) ( $o['status'] ?? '—' ), 'neutral', true ) . '</dd>';
		$out .= '<dt>Paiement</dt><dd>' . Ui::badge( (string) ( $o['payment_status'] ?? '—' ), 'neutral' ) . '</dd>';
		$out .= '<dt>Fulfillment</dt><dd>' . esc_html( (string) ( $o['fulfillment_status'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Produit</dt><dd>' . esc_html( (string) ( $o['product']['label'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Ressource</dt><dd>' . esc_html( (string) ( $o['resource']['type'] ?? '' ) . ' ' . mb_substr( (string) ( $o['resource']['uuid'] ?? '' ), 0, 8 ) ) . '</dd>';
		$out .= '<dt>Montant</dt><dd>' . esc_html( number_format_i18n( (int) ( $o['amount'] ?? 0 ) / 100, 2 ) . ' ' . (string) ( $o['currency'] ?? 'EUR' ) ) . '</dd>';
		$out .= '<dt>Tentatives fulfillment</dt><dd>' . esc_html( (string) ( $o['fulfillment_attempts'] ?? 0 ) ) . '</dd>';
		if ( ! empty( $o['last_fulfillment_error'] ) ) {
			$out .= '<dt>Dernière erreur</dt><dd>' . esc_html( (string) $o['last_fulfillment_error'] ) . '</dd>';
		}
		$out .= '</dl>' . Ui::card_close();

		// Paiements
		$prows = array();
		foreach ( (array) ( $o['payments'] ?? array() ) as $p ) {
			$prows[] = array( Ui::badge( (string) ( $p['status'] ?? '' ), 'succeeded' === ( $p['status'] ?? '' ) ? 'success' : 'neutral' ), Ui::text( number_format_i18n( (int) ( $p['amount'] ?? 0 ) / 100, 2 ) . ' ' . (string) ( $p['currency'] ?? '' ) ), Ui::text( substr( (string) ( $p['created_at'] ?? '' ), 0, 10 ), false, true ) );
		}
		$out .= Ui::card_open( 'Paiements' ) . Ui::table( array( 'Statut', 'Montant', 'Date' ), $prows, 'Aucun paiement.' ) . Ui::card_close();
		$out .= '</div><div>';

		// Chronologie
		$tl = array();
		$tl[] = array( 'label' => 'Commande créée', 'time' => $this->fmt( $o['created_at'] ?? '' ), 'done' => ! empty( $o['created_at'] ) );
		$tl[] = array( 'label' => 'Paiement reçu', 'time' => $this->fmt( $o['paid_at'] ?? '' ), 'done' => ! empty( $o['paid_at'] ) );
		$tl[] = array( 'label' => 'Renouvellement appliqué', 'time' => $this->fmt( $o['fulfilled_at'] ?? '' ), 'done' => ! empty( $o['fulfilled_at'] ) );
		$out .= Ui::card_open( 'Chronologie' ) . Ui::timeline( $tl ) . Ui::card_close();

		// Reçu
		$out .= Ui::card_open( 'Justificatif' );
		if ( ! empty( $o['receipt_url'] ) ) {
			$out .= '<a class="pst-btn pst-btn--sm" target="_blank" rel="noopener" href="' . esc_url( (string) $o['receipt_url'] ) . '">Ouvrir le reçu Stripe</a>';
		} else {
			$out .= '<p class="pst-admin-stat__sub">Aucun reçu disponible.</p>';
		}
		$out .= '<p style="margin-top:10px">' . Ui::badge( 'Facture légale non disponible', 'warning' ) . '</p>';
		$out .= Ui::card_close();
		$out .= '</div></div>';
		return $out;
	}

	private function fmt( $v ): string {
		$v = (string) $v;
		return ( '' !== $v && '0000-00-00 00:00:00' !== $v ) ? mysql2date( 'd/m/Y H:i', $v ) : '—';
	}
}
