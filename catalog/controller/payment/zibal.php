<?php
namespace Opencart\Catalog\Controller\Extension\Zibal\Payment;
/**
 * Class Zibal
 *
 * @package
 */

class Zibal extends \Opencart\System\Engine\Controller {
    /**
     * @return string
     */
    public function index(): string {
        $this->load->language('extension/zibal/payment/zibal');

        $data['text_connect'] = $this->language->get('text_connect');
        $data['text_loading'] = $this->language->get('text_loading');
        $data['text_wait'] = $this->language->get('text_wait');

        $data['button_confirm'] = $this->language->get('button_confirm');

        return $this->load->view('extension/zibal/payment/zibal', $data);
    }
    /**
     * @return void
     */
    public function confirm(): void {
        $this->load->language('extension/zibal/payment/zibal');

        $this->load->model('checkout/order');

        if (isset($this->session->data['order_id'])) {
            $order_id = $this->session->data['order_id'];
        } else {
            $order_id = 0;
        }
        $order_info = $this->model_checkout_order->getOrder($order_id);
        $order_total = $this->correctAmount($order_info);

        $json = array();

        $MerchantID = $this->config->get('payment_zibal_pin');  	//Required
        $Amount = $order_total; 									//Amount will be based on Rial  - Required
        $Description = $this->language->get('text_order_no') . $order_info['order_id']; // Required
        $Email = $order_info['email'] ?? null; 	// Optional
        $Mobile = (string) $order_info['telephone'] ?? null; 	// Optional
        /*$enc_order_id = $this->encryption->encrypt($this->config->get('config_encryption'), $order_id);*/
        $CallbackURL = $this->url->link('extension/zibal/payment/zibal.callback', 'order_id=' . $order_id, true);  // Required

        $parameters = array(
            'merchant' 	=> $MerchantID,
            'amount'		=> $Amount,
            'callbackUrl' 	=> $CallbackURL,
            'description' 	=> $Description
        );

        if ($Mobile || $Email) {
            $metadata = [];
            if ($Mobile) {
                $metadata['mobile'] = $Mobile;
            }
            if ($Email) {
                $metadata['email'] = $Email;
            }
            $parameters['metadata'] = $metadata;
        }

        $json_data = json_encode($parameters);
        $ch = curl_init('https://gateway.zibal.ir/v1/request');
        curl_setopt($ch, CURLOPT_USERAGENT, 'zibal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json_data)
        ));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        $result = json_decode($result, true);
        curl_close($ch);

     //  echo "<pre>" . print_r($result, true) . "</pre>";
     //   die();
        if(!$result) {
            $json['error'] = $this->language->get('error_cant_connect');
        } elseif($result['result'] == 100) {
            $action = 'https://gateway.zibal.ir/start/' . $result['trackId'];
            $json['success'] = $action;
        } else {
            $json['error'] = $this->checkState($result['result']);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function callback() {
        /*if ($this->session->data['payment_method']['code'] == 'zibal') {*/
        $this->load->language('extension/zibal/payment/zibal');

        $this->document->setTitle($this->language->get('text_title'));

        $data['heading_title'] = $this->language->get('text_title');
        $data['text_results'] = $this->language->get('text_results');
        $data['button_continue'] = $this->language->get('button_continue');

        $data['results'] = "";
        $data['error_warning'] = "";
        $data['continue'] = "";

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/home', '', true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_title'),
            'href' => $this->url->link('checkout/checkout', '', true)
        );

        if (isset($this->request->get['order_id'])) {
            /*$my_order_id = $this->encryption->decrypt($this->config->get('config_encryption'), $this->request->get['order_id']);*/
            $my_order_id = $this->request->get['order_id'];
        } else {
            $my_order_id = 0;
        }
        if ($my_order_id > 0) {
            $order_id = $my_order_id;
        } else {
            if (isset($this->session->data['order_id'])) {
                $order_id = $this->session->data['order_id'];
            } else {
                $order_id = 0;
            }
        }

        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        try {
            if($this->request->get['success'] != '1')
                throw new \Exception($this->language->get('error_verify'));

            if (!$order_info)
                throw new \Exception($this->language->get('error_order_id'));

            $trackId = $this->request->get['trackId'];
            $MerchantID = $this->config->get('payment_zibal_pin'); 

            $verify_result = $this->verifyPayment($trackId, $MerchantID);

            if (!$verify_result) {
                throw new \Exception($this->language->get('error_connect_verify'));

            } else if($verify_result['result'] == 100) {
                // success
                $RefID_number = $verify_result['refNumber'];

                $comment = $this->language->get('text_results') . $RefID_number;
                $this->model_checkout_order->addHistory(
                    $order_id,
                    $this->config->get('payment_zibal_order_status_id'),
                    $comment,
                    true
                );

                $data['results'] = $RefID_number;
                $data['button_continue'] = $this->language->get('button_continue');
                $data['continue'] = $this->url->link('checkout/success');
            } else {
                // show error with status-code
                $status_code = $verify_result['result'];
                throw new \Exception($this->checkState($status_code));
            }

        } catch (\Exception $e) {
            $data['error_warning'] = $e->getMessage();
            $data['button_continue'] = $this->language->get('button_view_cart');
            $data['continue'] = $this->url->link('checkout/cart');
        }

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $this->response->setOutput($this->load->view('extension/zibal/payment/zibal_confirm', $data));
    }

    private function correctAmount($order_info) {
        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], 10, false);
        $amount = round($amount);
        $amount = $this->currency->convert($amount, $order_info['currency_code'], "RLS");
        return (int)$amount;
    }

    private function checkState($status) {
        $error_message = $this->language->get('error_status_undefined');

        if ($this->language->get('error_status_' . $status) != 'error_status_' . $status ) {
            $error_message = $this->language->get('error_status_' . $status);
        }

        return $error_message;
    }

    private function verifyPayment($trackId, $MerchantID) {
        $MerchantID = $this->config->get('payment_zibal_pin');

        $post_data = array(
            'merchant' 	=> $MerchantID,
            'trackId' 	=> $trackId,
            
        );
        $json_data = json_encode($post_data);
        $ch = curl_init('https://gateway.zibal.ir/v1/verify');
        curl_setopt($ch, CURLOPT_USERAGENT, 'zibal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json_data)
        ));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $result = json_decode($result, true);

        if(!$result) {
            // echo  $this->language->get('error_cant_connect');
            return false;
        } else {
            return $result;
        }
    }

    public function encrypt($value) {
        $key = $this->config->get('config_encryption');
        $my_key = hash('sha256', $key, true);
        return strtr(base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, hash('sha256', $my_key, true), $value, MCRYPT_MODE_ECB)), '+/=', '-_,');
    }
    public function decrypt($value) {
        $key = $this->config->get('config_encryption');
        $my_key = hash('sha256', $key, true);
        return trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, hash('sha256', $my_key, true), base64_decode(strtr($value, '-_,', '+/=')), MCRYPT_MODE_ECB));
    }
}