<?php
namespace Opencart\Admin\Controller\Extension\Zibal\Payment;
/**
 * Class Zibal
 *
 * @package Opencart\Admin\Controller\Extension\Zibal\Payment
 */
class Zibal extends \Opencart\System\Engine\Controller
{
    /**
     * @return void
     */
    public function index(): void
    {
        $this->load->language('extension/zibal/payment/zibal');

        $this->document->setTitle(strip_tags($this->language->get('heading_title')));

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
        ];

        $data['breadcrumbs'][] = [
            'text' => strip_tags($this->language->get('heading_title')),
            'href' => $this->url->link('extension/zibal/payment/zibal', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['save'] = $this->url->link('extension/zibal/payment/zibal.save', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

        $data['payment_zibal_pin'] = $this->config->get('payment_zibal_pin');

        $data['payment_zibal_order_status_id'] = (int)$this->config->get('payment_zibal_order_status_id');
        $data['payment_zibal_failed_status_id'] = (int)$this->config->get('payment_zibal_failed_status_id');

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['payment_zibal_geo_zone_id'] = (int)$this->config->get('payment_zibal_geo_zone_id');

        $this->load->model('localisation/geo_zone');

        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['payment_zibal_status'] = $this->config->get('payment_zibal_status');
        $data['payment_zibal_sort_order'] = $this->config->get('payment_zibal_sort_order');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/zibal/payment/zibal', $data));
    }

    /**
     * @return void
     */
    public function save(): void
    {
        $this->load->language('extension/zibal/payment/zibal');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/zibal/payment/zibal')) {
            $json['error']['warning'] = $this->language->get('error_permission');
        }

        if (!isset($this->request->post['payment_zibal_pin']) || !is_string($this->request->post['payment_zibal_pin']) || trim($this->request->post['payment_zibal_pin']) === '') {
            $json['error']['pin'] = $this->language->get('error_pin');
        }

        if (!$json) {
            $this->load->model('setting/setting');

            $post = $this->request->post;

            $post['payment_zibal_pin'] = trim($post['payment_zibal_pin']);

            // The callback hash secret is not a form field; keep it (or create it) so saving never drops it.
            $post['payment_zibal_secret'] = $this->config->get('payment_zibal_secret') ? $this->config->get('payment_zibal_secret') : bin2hex(random_bytes(32));

            $this->model_setting_setting->editSetting('payment_zibal', $post);

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
