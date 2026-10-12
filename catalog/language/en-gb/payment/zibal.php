<?php

// Heading
$_['heading_title']               = 'Zibal Online Payment';
$_['text_title']                  = 'Zibal Online Payment';

// Text
$_['text_connect']                = 'Connecting to the Zibal gateway...';
$_['text_basket']                 = 'Shopping Cart';
$_['text_results']                = 'Reference number: ';
$_['text_order_no']               = 'Order number: ';

// Button
$_['button_view_cart']            = 'View Cart';
$_['button_confirm']              = 'Pay Now';
$_['button_continue']             = 'Continue';

// Error (shown to the customer)
$_['error_order_id']              = 'Order could not be found!';
$_['error_order_paid']            = 'This order has already been placed or paid.';
$_['error_payment']               = 'The transaction failed or was cancelled by the payer.';
$_['error_currency']              = 'The order currency must be Rial or Toman.';
$_['error_connection']            = 'Zibal could not be reached to verify the transaction. Please reload this page. If your account was charged, contact support.';
$_['error_processing']            = 'Your payment is being checked and recorded. Please reload this page in a few moments.';
$_['error_review']                = 'Your payment needs a manual review. Please contact support. Transaction number: ';
$_['error_generic']               = 'The payment failed. If your account was charged, contact store support.';

// Successful payment (order history)
$_['text_paid']                   = 'Zibal payment successful';
$_['text_track_id']               = 'Transaction number: ';
$_['text_card_number']            = 'Card number: ';
$_['text_paid_at']                = 'Paid at: ';

// Order history notes (admin only)
$_['text_note_request']           = 'Payment request created at Zibal. Transaction number: ';
$_['text_note_request_failed']    = 'Creating the payment request at Zibal failed.';
$_['text_note_currency']          = 'The order currency is not supported by the Zibal gateway: ';
$_['text_note_curl']              = 'The cURL extension is not enabled on the server.';
$_['text_note_cancel']            = 'The customer returned from Zibal with a failed status (not paid or cancelled).';
$_['text_note_data']              = 'The data returned from Zibal is incomplete.';
$_['text_note_not_found']         = 'The returned transaction number is not registered for this order. Not verified.';
$_['text_note_status']            = 'This transaction was already processed, status: ';
$_['text_note_amount_changed']    = 'The order total changed after the payment request was created. Not verified.';
$_['text_note_connection']        = 'Zibal could not be reached to verify the transaction. The customer can reload the page.';
$_['text_note_verify_failed']     = 'Verifying the transaction at Zibal failed.';
$_['text_note_mismatch']          = 'Zibal confirmed the payment but the amount or order number does not match. The money was taken; manual review and possibly a refund is needed.';
$_['text_note_201']               = 'Zibal says this transaction was already verified, but it is not recorded in the store. Manual review is needed.';
$_['text_note_log']               = 'The details of the Zibal response were written to the system error log.';
