<?php

namespace WooYouPay\controllers;

use WooYouPay\bootstrap\Loader;

/**
 * Class ProcessPayment
 *
 * @package WooYouPay\controllers
 */
class ProcessPayment {

	use LoaderTrait;

	/**
	 * Loader Function
	 *
	 * @param Loader $loader main loader var.
	 */
	public function loader( Loader $loader ) {
		$loader->add_action( 'parse_request', $this,
            'sniff_requests', 0 );
	}

	/**
	 * Payment Completed
	 *
	 * @param mixed $order_id OrderID number.
	 *
	 * @throws \WC_Data_Exception \Exception Thrown.
	 */
	public function payment_complete() {
		$youpay_id = sanitize_text_field($_GET['youpay_id']);

		$youpay_order = $this->youpay->api->getOrder( $youpay_id );

		// This shouldn't ever pass as true
		if ( ! $youpay_order->completed && empty( (float) $youpay_order->balance ) ) {
			throw new \Exception( 'Error, not paid.' );
		}

		$order_id = $youpay_order->store_order_id;
		$order    = \wc_get_order( $order_id );

		$processed = $order->get_meta( 'youpay_processed', 'true' );
		if ( $processed ) {
			wp_safe_redirect( $this->youpay->settings['woocommerce']['redirect_url'] );
			exit;
		}

		// Mark Order as processed.
		$order->add_meta_data( 'youpay_processed', 'true' );
		// TODO: Allow the status to be set by options.
		$order->update_status( 'processing', 'Payment taken by YouPay. Order ID: ' . $youpay_id );
		$order->save();

		// Redirect to the front page if there is nowhere set.
		if ( empty( $this->youpay->settings['woocommerce'] ) || empty( $this->youpay->settings['woocommerce']['redirect_url'] ) ) {
			wp_safe_redirect( '/' );
			exit;
		}

		wp_safe_redirect( $this->youpay->settings['woocommerce']['redirect_url'] );
		exit;
	}


	/**
	 * Look for the Requests that have been setup with rewrite rules,
	 *  - rewrite rules are included further on
	 *
	 * @throws \Exception Exception.
	 */
	public function sniff_requests() {
		if ( ! empty( $_GET['youpay_status_updated'] ) ) {
			$this->status_updated( $_GET['youpay_status_updated'] );
			exit;
		}
		if ( ! empty( $_GET['youpay_id'] ) ) {
			$this->payment_complete();
			exit;
		}
	}

	/**
	 * Update Cancelled Order
	 *
	 * @param string $youpay_id YouPay Order ID.
	 *
	 * @throws \Exception
	 */
	public function status_updated( $youpay_id ) {
		$youpay_id    = sanitize_text_field( $youpay_id );
		$youpay_order = $this->youpay->api->getOrder( $youpay_id );
		$order_id     = $youpay_order->store_order_id;
		$order        = \wc_get_order( $order_id );

		// This shouldn't ever pass as true.
		if ( $youpay_order->status_id ) {
			if ( $order->get_status() === 'on-hold' ) {
				return;
			}
			$order->update_status( 'on-hold', __( 'Awaiting YouPay Payment.', 'youpay' ) );
			return;
		}

		if ( $order->get_status() === 'cancelled' ) {
			return;
		}

		$order->update_status( 'cancelled', 'YouPay Order Cancelled' );
	}
}
