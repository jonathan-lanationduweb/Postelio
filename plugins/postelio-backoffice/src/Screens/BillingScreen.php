<?php
/**
 * Facturation : indicateurs (payées / en attente / anomalies / remboursées), table des commandes et
 * détail (paiements, chronologie, justificatif), via l'API du module Billing (`/billing/health`,
 * `/billing/admin/orders`). La relance du traitement est déléguée à l'endpoint du domaine. AUCUN
 * secret Stripe n'est lu ni affiché ; les états techniques sont traduits en libellés humains. Aucun
 * total de revenus n'est exposé par l'API : il n'est donc pas affiché.
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Support\Data;
use Postelio\Backoffice\Support\Fmt;
use Postelio\Backoffice\Support\Rest;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BillingScreen extends ListScreen {

	/** @var array<string,array{0:string,1:string}> statut technique => [libellé humain, variante] */
	private const STATUSES = array(
		'fulfilled'           => array( 'Payée', 'success' ),
		'awaiting_payment'    => array( 'En attente de paiement', 'info' ),
		'fulfillment_pending' => array( 'Traitement en cours', 'info' ),
		'fulfillment_failed'  => array( 'En échec', 'error' ),
		'manual_review'       => array( 'À vérifier', 'warning' ),
		'refunded'            => array( 'Remboursée', 'neutral' ),
		'payment_failed'      => array( 'Paiement refusé', 'error' ),
		'cancelled'           => array( 'Annulée', 'neutral' ),
	);

	/** Onglets proposés (sous-ensemble lisible des statuts). */
	private const TABS = array( 'fulfilled', 'awaiting_payment', 'fulfillment_failed', 'manual_review', 'refunded' );

	protected function capability(): string {
		return Menu::CAP_BILLING;
	}

	protected function eyebrow(): string {
		return 'Postelio · Gestion';
	}

	protected function slug(): string {
		return 'postelio-billing';
	}

	private function label( string $status ): string {
		return ( self::STATUSES[ $status ] ?? array( Fmt::or_dash( $status ), 'neutral' ) )[0];
	}

	private function variant( string $status ): string {
		return ( self::STATUSES[ $status ] ?? array( '', 'neutral' ) )[1];
	}

	protected function index(): string {
		if ( ! Data::module_active( 'billing' ) ) {
			return $this->module_missing( 'Facturation', 'Facturation' );
		}
		$tab    = $this->current( 'tab', 'all' );
		$health = Rest::payload( '/postelio/v1/billing/health' );

		$actions = '';
		if ( ! empty( $health ) && empty( $health['invoice_legal_ready'] ) ) {
			$actions = Ui::badge( 'Facture légale à configurer', 'warning', true ) . Ui::button( 'Réglages', $this->url( 'postelio-settings', array( 'tab' => 'billing' ) ), '', true );
		}
		$out = $this->header( 'Facturation', 'Commandes et paiements des recruteurs.', $actions );

		// Indicateurs réellement calculés (un compteur indisponible n'est pas affiché).
		$paid    = Rest::total( '/postelio/v1/billing/admin/orders', array( 'status' => 'fulfilled' ) );
		$waiting = Rest::total( '/postelio/v1/billing/admin/orders', array( 'status' => 'awaiting_payment' ) );
		$pending = Rest::total( '/postelio/v1/billing/admin/orders', array( 'status' => 'fulfillment_pending' ) );
		$failed  = Rest::total( '/postelio/v1/billing/admin/orders', array( 'status' => 'fulfillment_failed' ) );
		$review  = Rest::total( '/postelio/v1/billing/admin/orders', array( 'status' => 'manual_review' ) );
		$refund  = Rest::total( '/postelio/v1/billing/admin/orders', array( 'status' => 'refunded' ) );
		$anom    = ( null === $failed && null === $review ) ? null : (int) $failed + (int) $review;

		$kpis = array();
		if ( null !== $paid ) {
			$kpis[] = Ui::kpi( 'Commandes payées', $paid, '', false, $this->url( $this->slug(), array( 'tab' => 'fulfilled' ) ) );
		}
		if ( null !== $waiting || null !== $pending ) {
			$kpis[] = Ui::kpi( 'En attente', (int) $waiting + (int) $pending, (int) $pending > 0 ? (int) $pending . ' en traitement' : '', false, $this->url( $this->slug(), array( 'tab' => 'awaiting_payment' ) ) );
		}
		if ( null !== $anom ) {
			$kpis[] = Ui::kpi( 'Anomalies', $anom, (int) $failed > 0 ? (int) $failed . ' en échec' : '', $anom > 0, $this->url( $this->slug(), array( 'tab' => 'fulfillment_failed' ) ) );
		}
		if ( null !== $refund ) {
			$kpis[] = Ui::kpi( 'Remboursées', $refund, '', false, $this->url( $this->slug(), array( 'tab' => 'refunded' ) ) );
		}
		if ( $kpis ) {
			$out .= Ui::kpis_open( count( $kpis ) ) . implode( '', $kpis ) . Ui::kpis_close();
		}

		$tabs = array( array( 'label' => 'Toutes', 'url' => $this->url( $this->slug(), array( 'tab' => 'all' ) ), 'active' => 'all' === $tab ) );
		foreach ( self::TABS as $st ) {
			$tabs[] = array( 'label' => $this->label( $st ), 'url' => $this->url( $this->slug(), array( 'tab' => $st ) ), 'active' => $st === $tab );
		}
		$out .= $this->toolbar( Ui::tabs( $tabs, 'Filtrer les commandes' ) );

		$query = array( 'page' => $this->paged(), 'per_page' => static::PER_PAGE );
		if ( 'all' !== $tab ) {
			$query['status'] = $tab;
		}
		$res = Rest::call( 'GET', '/postelio/v1/billing/admin/orders', $query );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return $out . Ui::alert( 'La liste des commandes est momentanément indisponible.', 'warning' );
		}
		$items = (array) ( $res['data']['data'] ?? array() );
		$total = (int) ( $res['data']['meta']['pagination']['total'] ?? count( $items ) );

		$rows = array();
		foreach ( $items as $o ) {
			$rows[] = $this->row( (array) $o );
		}
		if ( empty( $rows ) ) {
			$out .= 'all' === $tab
				? Ui::empty_state( 'Aucune commande pour le moment', 'Les paiements et renouvellements des recruteurs apparaîtront ici.', '', 'card', true )
				: Ui::empty_state( 'Aucune commande dans cet état', 'Changez de filtre pour voir les autres commandes.', '', 'card', true );
		} else {
			$out .= Ui::table( array( 'Commande', 'Montant', 'État', 'Créée', '' ), $rows );
		}
		$out .= $this->pagination( $total, array( 'tab' => $tab ) );
		return $out;
	}

	/** @param array<string,mixed> $o @return array<int,string> */
	private function row( array $o ): array {
		$uuid   = (string) ( $o['order_uuid'] ?? '' );
		$status = (string) ( $o['status'] ?? '' );
		return array(
			Ui::entity( (string) ( $o['product']['label'] ?? ( $o['product']['code'] ?? 'Commande' ) ), 'Commande du ' . Fmt::date( $o['created_at'] ?? '' ), '', true ),
			Ui::text( Fmt::money( (int) ( $o['amount'] ?? 0 ), (string) ( $o['currency'] ?? 'EUR' ) ), true ),
			Ui::badge( $this->label( $status ), $this->variant( $status ), true ),
			Ui::text( Fmt::date( $o['created_at'] ?? '' ), false, true ),
			$this->view_link( $uuid ),
		);
	}

	protected function detail( string $uuid ): string {
		if ( ! Data::module_active( 'billing' ) ) {
			return $this->module_missing( 'Facturation', 'Facturation' );
		}
		$res = Rest::call( 'GET', '/postelio/v1/billing/admin/orders/' . $uuid );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return $this->not_found( 'Commande', 'Cette commande n\'existe pas.' );
		}
		$o      = (array) ( $res['data']['data'] ?? array() );
		$status = (string) ( $o['status'] ?? '' );
		$paid   = 'succeeded' === (string) ( $o['payment_status'] ?? '' );

		$actions = $this->back_link();
		if ( in_array( $status, array( 'fulfillment_failed', 'manual_review' ), true ) && current_user_can( Menu::CAP_BILLING ) ) {
			$actions .= Ui::action_button( 'pst_admin_billing_retry', array( 'uuid' => $uuid ), 'Relancer le traitement', 'primary', 'Relancer le traitement de cette commande ?' );
		}

		$out  = $this->header( (string) ( $o['product']['label'] ?? 'Commande' ), 'Commande du ' . Fmt::date( $o['created_at'] ?? '' ) . ' · ' . Fmt::money( (int) ( $o['amount'] ?? 0 ), (string) ( $o['currency'] ?? 'EUR' ) ), $actions, 'Postelio · Facturation' );
		$out .= Ui::cols_open() . Ui::col_open();

		$out .= Ui::card_open( 'Commande', '', Ui::badge( $this->label( $status ), $this->variant( $status ), true ) ) . Ui::kv( array(
			'Paiement'  => Ui::badge( $paid ? 'Encaissé' : ( '' !== (string) ( $o['payment_status'] ?? '' ) ? ucfirst( str_replace( '_', ' ', (string) $o['payment_status'] ) ) : 'En attente' ), $paid ? 'success' : 'neutral' ),
			'Produit'   => Ui::text( (string) ( $o['product']['label'] ?? 'Commande' ), true ),
			'Montant'   => Ui::text( Fmt::money( (int) ( $o['amount'] ?? 0 ), (string) ( $o['currency'] ?? 'EUR' ) ), true ),
			'Concerne'  => Ui::text( $this->resource_label( (array) ( $o['resource'] ?? array() ) ) ),
		) );
		if ( ! empty( $o['last_fulfillment_error'] ) ) {
			$out .= Ui::alert( 'Dernier échec de traitement : ' . Fmt::excerpt( (string) $o['last_fulfillment_error'], 160 ), 'error' );
		}
		$out .= Ui::details( 'Détails techniques', Ui::kv( array(
			'Statut technique'         => Ui::text( '' !== $status ? $status : 'inconnu', false, true ),
			'Traitement'               => Ui::text( '' !== (string) ( $o['fulfillment_status'] ?? '' ) ? (string) $o['fulfillment_status'] : 'aucun', false, true ),
			'Tentatives de traitement' => Ui::text( (string) (int) ( $o['fulfillment_attempts'] ?? 0 ) ),
			'Référence commande'       => Ui::text( Fmt::ref( $uuid, 12 ), false, true ),
		), true ) ) . Ui::card_close();

		$prows = array();
		foreach ( (array) ( $o['payments'] ?? array() ) as $p ) {
			$p       = (array) $p;
			$ok      = 'succeeded' === (string) ( $p['status'] ?? '' );
			$prows[] = array(
				Ui::badge( $ok ? 'Encaissé' : ucfirst( str_replace( '_', ' ', (string) ( $p['status'] ?? 'inconnu' ) ) ), $ok ? 'success' : 'neutral', true ),
				Ui::text( Fmt::money( (int) ( $p['amount'] ?? 0 ), (string) ( $p['currency'] ?? 'EUR' ) ) ),
				Ui::text( Fmt::date( $p['created_at'] ?? '' ), false, true ),
			);
		}
		$out .= Ui::card_open( 'Paiements', '', '', 'bo-card--flush' ) . Ui::table( array( 'État', 'Montant', 'Date' ), $prows, 'Aucun paiement enregistré.' ) . Ui::card_close();

		$out .= Ui::col_close() . Ui::col_open();
		$out .= Ui::card_open( 'Chronologie', '', '', 'bo-card--aside' ) . Ui::timeline( array(
			array( 'label' => 'Commande créée', 'time' => Fmt::datetime( $o['created_at'] ?? '' ), 'done' => ! empty( $o['created_at'] ) ),
			array( 'label' => 'Paiement reçu', 'time' => Fmt::datetime( $o['paid_at'] ?? '' ), 'done' => ! empty( $o['paid_at'] ) ),
			array( 'label' => 'Renouvellement appliqué', 'time' => Fmt::datetime( $o['fulfilled_at'] ?? '' ), 'done' => ! empty( $o['fulfilled_at'] ) ),
		) ) . Ui::card_close();

		$out .= Ui::card_open( 'Justificatif', '', '', 'bo-card--aside' );
		$out .= ! empty( $o['receipt_url'] )
			? Ui::button( 'Ouvrir le reçu de paiement', (string) $o['receipt_url'], 'primary', true, true )
			: Ui::help( 'Aucun reçu de paiement disponible pour cette commande.' );
		$out .= '<div class="bo-section">' . Ui::badge( 'Facture légale non disponible', 'warning' )
			. Ui::help( 'Postelio n\'émet pas encore de facture légale : la configuration de facturation est incomplète.' ) . '</div>';
		$out .= Ui::card_close();
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}

	/** @param array<string,mixed> $resource */
	private function resource_label( array $resource ): string {
		$types = array( 'job' => 'Offre', 'company' => 'Entreprise' );
		$type  = (string) ( $resource['type'] ?? '' );
		$label = $types[ $type ] ?? Fmt::or_dash( $type );
		$uuid  = (string) ( $resource['uuid'] ?? '' );
		return '' !== $uuid ? $label . ' ' . Fmt::ref( $uuid ) : $label;
	}
}
