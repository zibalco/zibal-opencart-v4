<?php
namespace Opencart\Catalog\Model\Extension\Zibal\Payment;
/**
 * Class Zibal
 *
 * @package
 */
class Zibal extends \Opencart\System\Engine\Model {
	/**
	 * @var bool
	 */
	private bool $table_checked = false;

	/**
	 * @param array $address
	 *
	 * @return array
	 */
	public function getMethods(array $address = []): array {
		$this->load->language('extension/zibal/payment/zibal');

		if ($this->cart->hasSubscription()) {
			$status = false;
		} elseif (!$this->config->get('config_checkout_payment_address')) {
			$status = true;
		} elseif (!$this->config->get('payment_zibal_geo_zone_id')) {
			$status = true;
		} else {
			$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . (int)$this->config->get('payment_zibal_geo_zone_id') . "' AND `country_id` = '" . (int)($address['country_id'] ?? 0) . "' AND (`zone_id` = '" . (int)($address['zone_id'] ?? 0) . "' OR `zone_id` = '0')");

			if ($query->num_rows) {
				$status = true;
			} else {
				$status = false;
			}
		}

		$method_data = [];

		if ($status) {
			$option_data['zibal'] = [
				'code' => 'zibal.zibal',
				'name' => $this->language->get('text_title')
			];

			$method_data = [
				'code'       => 'zibal',
				'name'       => $this->language->get('text_title'),
				'option'     => $option_data,
				'sort_order' => $this->config->get('payment_zibal_sort_order')
			];
		}

		return $method_data;
	}

	/**
	 * Secret key for the callback hash. Created on first use and kept in the extension's settings.
	 *
	 * @return string
	 */
	public function getSecret(): string {
		$secret = (string)$this->config->get('payment_zibal_secret');

		if ($secret === '') {
			$query = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = 'payment_zibal_secret'");

			if ($query->num_rows && $query->row['value'] !== '') {
				$secret = (string)$query->row['value'];
			} else {
				$secret = bin2hex(random_bytes(32));

				$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'payment_zibal', `key` = 'payment_zibal_secret', `value` = '" . $this->db->escape($secret) . "', `serialized` = '0'");
			}

			$this->config->set('payment_zibal_secret', $secret);
		}

		return $secret;
	}

	/**
	 * Created lazily so stores that installed an older version of the plugin get the table too.
	 *
	 * @return void
	 */
	public function createTable(): void {
		if ($this->table_checked) {
			return;
		}

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "zibal_transaction` (
			`zibal_transaction_id` INT(11) NOT NULL AUTO_INCREMENT,
			`order_id` INT(11) NOT NULL,
			`track_id` VARCHAR(32) NOT NULL,
			`token` VARCHAR(64) NOT NULL,
			`amount` BIGINT(20) NOT NULL,
			`status` VARCHAR(16) NOT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`zibal_transaction_id`),
			UNIQUE KEY `track_id` (`track_id`),
			KEY `order_id` (`order_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$this->table_checked = true;
	}

	/**
	 * @param int    $order_id
	 * @param string $track_id
	 * @param int    $amount amount in Rial that was sent to Zibal
	 * @param string $token  random value that goes into the callback hash
	 *
	 * @return void
	 */
	public function addTransaction(int $order_id, string $track_id, int $amount, string $token): void {
		$this->createTable();

		$this->db->query("INSERT INTO `" . DB_PREFIX . "zibal_transaction` SET `order_id` = '" . (int)$order_id . "', `track_id` = '" . $this->db->escape($track_id) . "', `token` = '" . $this->db->escape($token) . "', `amount` = '" . (int)$amount . "', `status` = 'pending', `date_added` = NOW(), `date_modified` = NOW()");
	}

	/**
	 * @param string $track_id
	 *
	 * @return array
	 */
	public function getTransaction(string $track_id): array {
		$this->createTable();

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zibal_transaction` WHERE `track_id` = '" . $this->db->escape($track_id) . "'");

		return $query->num_rows ? $query->row : [];
	}

	/**
	 * The transaction Zibal has confirmed for this order: "verified" (money confirmed) or "paid" (order recorded too).
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function getPaidTransaction(int $order_id): array {
		$this->createTable();

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zibal_transaction` WHERE `order_id` = '" . (int)$order_id . "' AND `status` IN ('verified', 'paid') ORDER BY `status` = 'paid' DESC LIMIT 1");

		return $query->num_rows ? $query->row : [];
	}

	/**
	 * Takes a pending transaction for verification. Only one request can win; a "verifying" transaction whose
	 * owner went quiet for a minute (longer than any verify call can take) may be taken over.
	 *
	 * @param string $track_id
	 *
	 * @return bool
	 */
	public function claimTransaction(string $track_id): bool {
		$this->db->query("UPDATE `" . DB_PREFIX . "zibal_transaction` SET `status` = 'verifying', `date_modified` = NOW() WHERE `track_id` = '" . $this->db->escape($track_id) . "' AND (`status` = 'pending' OR (`status` = 'verifying' AND `date_modified` < DATE_SUB(NOW(), INTERVAL 60 SECOND)))");

		return $this->db->countAffected() == 1;
	}

	/**
	 * Takes over a transaction that Zibal confirmed but whose order was never recorded, once the request
	 * that verified it has been quiet for a minute. Only one request can win.
	 *
	 * @param string $track_id
	 *
	 * @return bool
	 */
	public function claimVerifiedTransaction(string $track_id): bool {
		$this->db->query("UPDATE `" . DB_PREFIX . "zibal_transaction` SET `date_modified` = NOW() WHERE `track_id` = '" . $this->db->escape($track_id) . "' AND `status` = 'verified' AND `date_modified` < DATE_SUB(NOW(), INTERVAL 60 SECOND)");

		return $this->db->countAffected() == 1;
	}

	/**
	 * Writes straight to order_history so the order status, stock and customer emails are left alone.
	 *
	 * @param int    $order_id
	 * @param int    $order_status_id
	 * @param string $comment
	 *
	 * @return void
	 */
	public function addHistoryNote(int $order_id, int $order_status_id, string $comment): void {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET `order_id` = '" . (int)$order_id . "', `order_status_id` = '" . (int)$order_status_id . "', `notify` = '0', `comment` = '" . $this->db->escape($comment) . "', `date_added` = NOW()");
	}

	/**
	 * Moves a transaction from one status to another; returns false if another request already moved it.
	 *
	 * @param string $track_id
	 * @param string $from
	 * @param string $to
	 *
	 * @return bool
	 */
	public function updateTransactionStatus(string $track_id, string $from, string $to): bool {
		$this->db->query("UPDATE `" . DB_PREFIX . "zibal_transaction` SET `status` = '" . $this->db->escape($to) . "', `date_modified` = NOW() WHERE `track_id` = '" . $this->db->escape($track_id) . "' AND `status` = '" . $this->db->escape($from) . "'");

		return $this->db->countAffected() == 1;
	}
}
