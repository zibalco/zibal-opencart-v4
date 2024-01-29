<?php
namespace Opencart\Admin\Controller\Extension\Zibal\Payment;
/**
 * Class Zibal
 *
 * @package Opencart\Admin\Controller\Extension\Zibal\Payment
 */
class Zibal extends \Opencart\System\Engine\Controller
{
    private $error = array();
    /**
     * @return void
     */
    public function index(): void
    {
        $this->load->language('extension/zibal/payment/zibal');

        $this->document->setTitle(strip_tags($this->language->get('heading_title')));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_zibal', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->error['pin'])) {
            $data['error_pin'] = $this->error['pin'];
        } else {
            $data['error_pin'] = '';
        }

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

        $data['save'] = $this->url->link('extension/zibal/payment/zibal', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

        $data['text_zibal'] = $this->language->get('text_zibal');

        $data['payment_zibal_order_status_id'] = $this->config->get('payment_zibal_order_status_id');

        if (isset($this->request->post['payment_zibal_pin'])) {
            $data['payment_zibal_pin'] = $this->request->post['payment_zibal_pin'];
        } else {
            $data['payment_zibal_pin'] = $this->config->get('payment_zibal_pin');
        }

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['payment_zibal_geo_zone_id'] = $this->config->get('payment_zibal_geo_zone_id');

        $this->load->model('localisation/geo_zone');

        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['payment_zibal_status'] = $this->config->get('payment_zibal_status');
        $data['payment_zibal_sort_order'] = $this->config->get('payment_zibal_sort_order');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/zibal/payment/zibal', $data));
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/zibal/payment/zibal')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->request->post['payment_zibal_pin']) {
            $this->error['pin'] = $this->language->get('error_pin');
        }

        return !$this->error;
    }
}