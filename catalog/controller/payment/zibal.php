<?php
namespace Opencart\Catalog\Controller\Extension\Zibal\Payment;
/**
 * Class Zibal
 *
 * @package
 */

class Zibal extends \Opencart\System\Engine\Controller {
    // Raw result of the last call to Zibal, kept for the order history notes.
    protected $last_response = array('http_code' => 0, 'error' => '', 'body' => '');

    /**
     * @return string
     */
    public function index(): string {
        $this->load->language('extension/zibal/payment/zibal');

        $data['language'] = $this->config->get('config_language');

        return $this->load->view('extension/zibal/payment/zibal', $data);
    }
    /**
     * @return void
     */
    public function confirm(): void {
        $this->load->language('extension/zibal/payment/zibal');

        $this->load->model('checkout/order');
        $this->load->model('extension/zibal/payment/zibal');

        $json = array();

        if (isset($this->session->data['order_id'])) {
            $order_id = (int)$this->session->data['order_id'];
        } else {
            $order_id = 0;
        }

        $order_info = $order_id ? $this->model_checkout_order->getOrder($order_id) : array();
        $amount = $this->getAmount($order_info);

        if (!$order_info) {
            $json['error'] = $this->language->get('error_order_id');
        } elseif (!$this->config->get('payment_zibal_status') || !$this->isZibalOrder($order_info)) {
            $json['error'] = $this->language->get('error_generic');
        } elseif ($order_info['order_status_id'] || $this->model_extension_zibal_payment_zibal->getPaidTransaction($order_id)) {
            // A confirmed or paid order must not get a second payment request.
            $json['error'] = $this->language->get('error_order_paid');
        } elseif ($amount === false) {
            $this->addNote($order_info, $this->language->get('text_note_currency') . $order_info['currency_code']);

            $json['error'] = $this->language->get('error_currency');
        } elseif (!extension_loaded('curl')) {
            $this->addNote($order_info, $this->language->get('text_note_curl'));

            $json['error'] = $this->language->get('error_generic');
        } else {
            // Random value of this payment attempt; the callback URL carries its keyed hash, not the value itself.
            $token = bin2hex(random_bytes(16));

            $parameters = array(
                'merchant' 	=> (string)$this->config->get('payment_zibal_pin'),
                'amount'		=> $amount,
                'callbackUrl' 	=> $this->url->link('extension/zibal/payment/zibal.callback', 'language=' . $this->config->get('config_language') . '&hash=' . $this->getHash($order_id, $amount, $token), true),
                'orderId' 	=> (string)$order_id,
                'description' 	=> $this->language->get('text_order_no') . $order_id
            );

            $mobile = $this->getMobile($order_info);

            if ($mobile) {
                $parameters['mobile'] = $mobile;
            }

            $result = $this->common('https://gateway.zibal.ir/v1/request', $parameters);

            if ($result && isset($result['result'], $result['trackId']) && $result['result'] == 100 && ctype_digit((string)$result['trackId'])) {
                // Remember which trackId belongs to this order and amount, so the callback can check it.
                $this->model_extension_zibal_payment_zibal->addTransaction($order_id, (string)$result['trackId'], $amount, $token);

                $this->addNote($order_info, $this->language->get('text_note_request') . $result['trackId'], false, (int)$this->config->get('config_order_status_id'));

                $json['redirect'] = 'https://gateway.zibal.ir/start/' . $result['trackId'];
            } else {
                $this->addNote($order_info, $this->language->get('text_note_request_failed'), true);

                $json['error'] = $this->language->get('error_generic');
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * @return void
     */
    public function callback(): void {
        $this->load->language('extension/zibal/payment/zibal');

        $this->load->model('checkout/order');
        $this->load->model('extension/zibal/payment/zibal');

        $this->document->setTitle($this->language->get('text_title'));

        // Zibal returns the customer to the callback URL with GET parameters.
        $success = $this->getParam('success');
        $trackId = $this->getParam('trackId');
        $orderId = $this->getParam('orderId');
        $hash = $this->getParam('hash');

        // The trackId must be one we stored when the payment was requested.
        $transaction = array();

        if ($trackId !== '' && ctype_digit($trackId)) {
            $transaction = $this->model_extension_zibal_payment_zibal->getTransaction($trackId);
        }

        // The hash proves this callback URL was built by this store for this exact order, amount and attempt.
        $hash_valid = $transaction && $hash !== '' && hash_equals($this->getHash((int)$transaction['order_id'], (int)$transaction['amount'], (string)$transaction['token']), $hash);

        // Prefer the order of the stored transaction when the hash matches; fall back to the session for the notes.
        $order_id = 0;

        if ($hash_valid) {
            $order_id = (int)$transaction['order_id'];
        } elseif (isset($this->session->data['order_id'])) {
            $order_id = (int)$this->session->data['order_id'];
        }

        $order_info = $order_id ? $this->model_checkout_order->getOrder($order_id) : array();

        $data['error_warning'] = '';

        // A verified payment stays verified: reopening the callback link, with any parameters, never calls
        // verify again and never records the payment on the order twice.
        $paid_transaction = $order_info ? $this->model_extension_zibal_payment_zibal->getPaidTransaction((int)$order_info['order_id']) : array();

        if ($paid_transaction) {
            if ($trackId !== '' && ctype_digit($trackId) && $trackId !== $paid_transaction['track_id']) {
                // A second payment attempt for an order that is already paid is not verified; leave it for the admin.
                $this->log->write('Zibal: order_id ' . $order_info['order_id'] . ' is already paid with trackId ' . $paid_transaction['track_id'] . '; callback for trackId ' . $trackId . ' was ignored and not verified.');
            }

            if ($paid_transaction['status'] == 'verified' && $this->model_extension_zibal_payment_zibal->claimVerifiedTransaction($paid_transaction['track_id'])) {
                // Zibal confirmed the money earlier but the order was never recorded (the first request died); finish it now.
                $this->recordPayment($order_info, $paid_transaction['track_id']);
            } elseif ($paid_transaction['status'] == 'verified' && $this->waitForTransaction($paid_transaction['track_id']) != 'paid') {
                // Another request is still recording this payment.
                $data['error_warning'] = $this->language->get('error_processing');
            }
        } elseif ($success !== '' && $trackId !== '' && $orderId !== '') {
            if ($success == 1) {
                if ($hash_valid && $order_info && $transaction && $transaction['order_id'] == $order_info['order_id'] && $orderId === (string)$order_info['order_id']) {
                    $amount = $this->getAmount($order_info);

                    if (!in_array($transaction['status'], array('pending', 'verifying'))) {
                        $this->addNote($order_info, $this->language->get('text_note_status') . $transaction['status'] . "\n" . $this->formatParams());

                        $data['error_warning'] = $this->language->get('error_generic');
                    } elseif ($amount === false || $amount != $transaction['amount']) {
                        // The order total changed after the payment request was made.
                        $this->addNote($order_info, $this->language->get('text_note_amount_changed') . "\n" . $this->formatParams());

                        $data['error_warning'] = $this->language->get('error_generic');
                    } elseif (!$this->model_extension_zibal_payment_zibal->claimTransaction($trackId)) {
                        // Another request is verifying this payment right now: wait for its result instead of
                        // calling verify a second time (Zibal would answer 201 to the loser).
                        $status = $this->waitForTransaction($trackId);

                        if ($status == 'pending') {
                            $data['error_warning'] = $this->language->get('error_connection');
                        } elseif ($status == 'verifying' || $status == 'verified') {
                            $data['error_warning'] = $this->language->get('error_processing');
                        } elseif ($status != 'paid') {
                            $data['error_warning'] = $this->language->get('error_generic');
                        }
                    } else {
                        // From here this request alone owns the transaction (status "verifying").
                        $parameters = array(
                            'merchant' 	=> (string)$this->config->get('payment_zibal_pin'),
                            'trackId' 	=> $trackId
                        );

                        $result = $this->common('https://gateway.zibal.ir/v1/verify', $parameters);

                        if (!$result || !isset($result['result'])) {
                            // Connection failure is not a failed payment; the customer can reload to retry verify.
                            $this->model_extension_zibal_payment_zibal->updateTransactionStatus($trackId, 'verifying', 'pending');

                            $this->addNote($order_info, $this->language->get('text_note_connection'), true);

                            $data['error_warning'] = $this->language->get('error_connection');
                        } elseif ($result['result'] == 100) {
                            if (!isset($result['amount'], $result['orderId']) || (int)$result['amount'] != (int)$transaction['amount'] || (string)$result['orderId'] !== (string)$order_info['order_id']) {
                                // Zibal has settled the money, so keep a record for a manual refund or review.
                                $this->model_extension_zibal_payment_zibal->updateTransactionStatus($trackId, 'verifying', 'review');

                                $this->addNote($order_info, $this->language->get('text_note_mismatch'), true);

                                $data['error_warning'] = $this->language->get('error_review') . $trackId;
                            } elseif ($this->model_extension_zibal_payment_zibal->updateTransactionStatus($trackId, 'verifying', 'verified')) {
                                // The money is confirmed; "verified" survives even if recording the order fails below.
                                $this->recordPayment($order_info, $trackId, $result);
                            } else {
                                $data['error_warning'] = $this->language->get('error_processing');
                            }
                        } elseif ($result['result'] == 201) {
                            // Verified earlier but never recorded here (e.g. the first response was lost): needs manual review.
                            $this->model_extension_zibal_payment_zibal->updateTransactionStatus($trackId, 'verifying', 'review');

                            $this->addNote($order_info, $this->language->get('text_note_201'), true);

                            $data['error_warning'] = $this->language->get('error_review') . $trackId;
                        } else {
                            // 202 means Zibal says it was not paid; other codes (e.g. merchant errors) keep it retryable.
                            $this->model_extension_zibal_payment_zibal->updateTransactionStatus($trackId, 'verifying', $result['result'] == 202 ? 'failed' : 'pending');

                            $this->addNote($order_info, $this->language->get('text_note_verify_failed'), true);

                            $data['error_warning'] = $this->language->get('error_generic');
                        }
                    }
                } else {
                    $this->addNote($order_info, $this->language->get('text_note_not_found') . "\n" . $this->formatParams());

                    $data['error_warning'] = $this->language->get('error_generic');
                }
            } else {
                $this->addNote($order_info, $this->language->get('text_note_cancel') . "\n" . $this->formatParams());

                $data['error_warning'] = $this->language->get('error_payment');
            }
        } else {
            $this->addNote($order_info, $this->language->get('text_note_data') . "\n" . $this->formatParams());

            $data['error_warning'] = $this->language->get('error_generic');
        }

        if (!$data['error_warning']) {
            $this->redirectSuccess();

            return;
        }

        $this->keepSessionCookie();

        $language = 'language=' . $this->config->get('config_language');

        $data['heading_title'] = $this->language->get('text_title');

        $data['button_continue'] = $this->language->get('button_view_cart');
        $data['continue'] = $this->url->link('checkout/cart', $language);

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/home', $language)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_basket'),
            'href' => $this->url->link('checkout/cart', $language)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_title'),
            'href' => $this->url->link('checkout/checkout', $language)
        );

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $this->response->setOutput($this->load->view('extension/zibal/payment/zibal_confirm', $data));
    }

    // OpenCart's session cookie is "SameSite=Strict" by default, so the browser does not send it when Zibal
    // returns the customer here, and OpenCart answers with a brand new, empty session cookie that would log
    // the customer out. Dropping that cookie from the response leaves the customer's real session untouched.
    private function keepSessionCookie() {
        if (!isset($this->request->cookie[$this->config->get('session_name')]) && !headers_sent()) {
            header_remove('Set-Cookie');
        }
    }

    // Sends the customer to the order success page, where OpenCart empties the cart.
    private function redirectSuccess() {
        $url = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true);

        if (isset($this->request->cookie[$this->config->get('session_name')])) {
            $this->response->redirect($url);

            return;
        }

        // Without the session cookie a plain redirect would still count as coming from Zibal. A page that
        // navigates by itself counts as coming from this store, so the browser sends the real session again.
        $this->keepSessionCookie();

        $link = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="robots" content="noindex">';
        $html .= '<meta http-equiv="refresh" content="1;url=' . $link . '"><title>' . $this->language->get('text_title') . '</title></head>';
        $html .= '<body><script>location.replace(' . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';
        $html .= '<p style="text-align:center;margin-top:3em;font-family:sans-serif;"><a href="' . $link . '">' . $this->language->get('button_continue') . '</a></p></body></html>';

        $this->response->setOutput($html);
    }

    // Confirms the order for a "verified" transaction and then marks it "paid". Safe to call again after a
    // crash: an order that already has a status is not confirmed a second time.
    private function recordPayment($order_info, $track_id, $result = array()) {
        $order_info = $this->model_checkout_order->getOrder((int)$order_info['order_id']);

        if ($order_info && !$order_info['order_status_id']) {
            $comment  = $this->language->get('text_paid') . "\n";
            $comment .= $this->language->get('text_track_id') . $track_id . "\n";
            $comment .= $this->language->get('text_results') . (isset($result['refNumber']) ? $result['refNumber'] : '-') . "\n";
            $comment .= $this->language->get('text_card_number') . (isset($result['cardNumber']) ? $result['cardNumber'] : '-') . "\n";
            $comment .= $this->language->get('text_paid_at') . (isset($result['paidAt']) ? $result['paidAt'] : '-');

            $this->model_checkout_order->addHistory((int)$order_info['order_id'], (int)$this->config->get('payment_zibal_order_status_id'), htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'), true);
        }

        $this->model_extension_zibal_payment_zibal->updateTransactionStatus($track_id, 'verified', 'paid');
    }

    // Waits a few seconds for the request that owns the transaction to finish; returns the last status seen.
    private function waitForTransaction($track_id) {
        $status = '';

        for ($i = 0; $i < 12; $i++) {
            $transaction = $this->model_extension_zibal_payment_zibal->getTransaction($track_id);
            $status = $transaction ? $transaction['status'] : '';

            if ($status != 'verifying' && $status != 'verified') {
                break;
            }

            $this->pause();
        }

        return $status;
    }

    protected function pause() {
        usleep(500000);
    }

    // Adds a note to the order history without changing the order status or emailing the customer.
    private function addNote($order_info, $note, $with_response = false, $order_status_id = 0) {
        $comment = $note;

        if ($with_response) {
            $comment .= "\n" . $this->formatResponse();
        }

        if (!$order_info) {
            $this->log->write('Zibal: ' . str_replace(array("\r", "\n"), ' | ', $comment));

            return;
        }

        // Confirmed orders are visible to the customer, so their raw Zibal response goes to the error log instead.
        if ($order_info['order_status_id'] && $with_response) {
            $this->log->write('Zibal order_id ' . $order_info['order_id'] . ': ' . str_replace(array("\r", "\n"), ' | ', $comment));

            $comment = $note . "\n" . $this->language->get('text_note_log');
        }

        if (!$order_status_id) {
            $order_status_id = $this->config->get('payment_zibal_failed_status_id') ? $this->config->get('payment_zibal_failed_status_id') : $this->config->get('config_order_status_id');
        }

        // Order history comments are printed without escaping in OpenCart.
        $this->model_extension_zibal_payment_zibal->addHistoryNote((int)$order_info['order_id'], (int)$order_status_id, htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'));
    }

    private function formatResponse() {
        $text = 'HTTP: ' . $this->last_response['http_code'];

        if ($this->last_response['error']) {
            $text .= "\n" . 'cURL error: ' . $this->last_response['error'];
        }

        $text .= "\n" . 'Zibal response: ' . substr($this->last_response['body'], 0, 2000);

        return $text;
    }

    // The browser's return parameters, for notes where Zibal was not called.
    private function formatParams() {
        $params = array();

        foreach (array('success', 'status', 'trackId', 'orderId') as $key) {
            $params[] = $key . '=' . html_entity_decode(substr($this->getParam($key), 0, 100), ENT_QUOTES, 'UTF-8');
        }

        return 'Return params: ' . implode(' & ', $params);
    }

    // A return parameter as a string; arrays and missing values become an empty string.
    private function getParam($key) {
        if (isset($this->request->get[$key])) {
            $value = $this->request->get[$key];
        } elseif (isset($this->request->post[$key])) {
            $value = $this->request->post[$key];
        } else {
            $value = '';
        }

        return is_string($value) ? $value : '';
    }

    // Order total in Rial as an integer, or false when the order currency is not Rial/Toman.
    private function getAmount($order_info) {
        if (!$order_info) {
            return false;
        }

        $currency = strtoupper((string)$order_info['currency_code']);
        $amount = (float)$this->currency->format((float)$order_info['total'], (string)$order_info['currency_code'], (float)$order_info['currency_value'], false);

        if (in_array($currency, array('RLS', 'IRR'))) {
            return (int)round($amount);
        }

        if (in_array($currency, array('TOM', 'IRT', 'TMN'))) {
            return (int)round($amount * 10);
        }

        return false;
    }

    // Keyed hash of a payment attempt; cannot be computed without the store's secret.
    private function getHash($order_id, $amount, $token) {
        return hash_hmac('sha256', 'zibal|' . (int)$order_id . '|' . (int)$amount . '|' . $token, $this->model_extension_zibal_payment_zibal->getSecret());
    }

    // The customer's mobile in the 09xxxxxxxxx form, or an empty string when it is not an Iranian mobile.
    private function getMobile($order_info) {
        $mobile = preg_replace('/\D/', '', (string)$order_info['telephone']);
        $mobile = preg_replace('/^(?:0098|98)/', '0', $mobile);

        return preg_match('/^09\d{9}$/', $mobile) ? $mobile : '';
    }

    private function isZibalOrder($order_info) {
        $method = isset($order_info['payment_method']) ? $order_info['payment_method'] : '';
        $code = is_array($method) ? (isset($method['code']) ? (string)$method['code'] : '') : (string)$method;

        return strpos($code, 'zibal') === 0;
    }

    protected function common($url, $params) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'zibal Rest Api v4');
        // Zibal's API only accepts JSON bodies.
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));

        $response = curl_exec($ch);
        $error = curl_errno($ch);

        $this->last_response = array(
            'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'error'     => $error ? $error . ' ' . curl_error($ch) : '',
            'body'      => $response === false ? '' : (string)$response
        );

        if ($error || $response === false) {
            return false;
        }

        $output = json_decode($response, true);

        return is_array($output) ? $output : false;
    }
}
