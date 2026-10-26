<?php
/** Verbatim from Paid Memberships Pro 3.8.9 (GPL-2.0-or-later), includes/gateway-request-handlers.php lines 126-212, tag 3.8.9 = commit ae37d2a21c19813ca49f1bdad554c9fd1580be9d.
 * Test fixture only: lets the webhook-guard test run the real handler. sha256 of the extracted text: df74fa56dd2265fa73af41fdfc2ee9ae6728f90e19193cee6b64eafd8e5a1ab0 */
/**
 * Handle successful recurring payment IPN/webhook requests from gateways.
 *
 * @since 3.6
 *
 * @param array  $order_data An array of order data.
 *
 * @return string Entry to add to IPN/webhook log.
 */
function pmpro_handle_recurring_payment_succeeded_at_gateway( $order_data ) {
	// Get subscription info from order data.
	$subscription_transaction_id = isset( $order_data['subscription_transaction_id'] ) ? $order_data['subscription_transaction_id'] : '';
	$gateway = isset( $order_data['gateway'] ) ? $order_data['gateway'] : '';
	$gateway_environment = isset( $order_data['gateway_environment'] ) ? $order_data['gateway_environment'] : '';

	// Find subscription.
	$subscription = PMPro_Subscription::get_subscription_from_subscription_transaction_id( $subscription_transaction_id, $gateway, $gateway_environment );
	if ( empty( $subscription ) ) {
		// The subscription does not exist on this site. Bail.
		return 'ERROR: Could not find this subscription associated with a successful payment (subscription_transaction_id=' . $subscription_transaction_id . ').';
	}

	// Get the user associated with the subscription.
	$user = get_userdata( $subscription->get_user_id() );
	if ( empty( $user ) ) {
		// The user for this subscription does not exist
		return 'ERROR: Could not process successful payment. No user attached to subscription #' . $subscription->get_id() . ' with subscription transaction id = ' . $subscription_transaction_id . '.';
	}

	// Check if we have an existing order for this subscription and payment_transaction_id.
	$existing_orders = $subscription->get_orders(
		array(
			'payment_transaction_id' => isset( $order_data['payment_transaction_id'] ) ? $order_data['payment_transaction_id'] : '',
			'limit' => 1,
		)
	);

	// Get the existing order or build a new one.
	if ( ! empty( $existing_orders ) ) {
		$order = current( $existing_orders );

		// If this order is already succcessful, we have already processed this payment.
		if ( 'success' === $order->status ) {
			return 'We have already processed this payment. ( Order ID #' . $order->id . ')';
		}
	} else {
		// Also check for any pending orders that don't have a payment_transaction_id.
		// This could happen for PayPal Express payment failures, for example.
		$existing_orders = $subscription->get_orders(
			array(
				'status' => 'pending',
				'payment_transaction_id' => '',
				'limit' => 1,
			)
		);
		if ( ! empty( $existing_orders ) ) {
			$order = current( $existing_orders );
		} else {
			$order = new MemberOrder();
		}
	}

	// Make sure that we have the correct information for this subscription on the order.
	$order_data['user_id'] = $user->ID;
	$order_data['membership_id'] = $subscription->get_membership_level_id();
	$order_data['gateway'] = $gateway;
	$order_data['gateway_environment'] = $gateway_environment;
	$order_data['subscription_transaction_id'] = $subscription_transaction_id;
	$order_data['status'] = 'success';

	// Update the order data.
	foreach ( $order_data as $k => $v ) {
		// Check if the property exists on the order object.
		if ( property_exists( $order, $k ) ) {
			$order->$k = $v;
		}
	}
	$order->saveOrder();

	do_action('pmpro_subscription_payment_completed', $order);

	// Email the user their order.
	$pmproemail = new PMProEmail();
	$pmproemail->sendInvoiceEmail($user, $order);

	return 'Created new order with ID #' . $order->id . '.';
}
