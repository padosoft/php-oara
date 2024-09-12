<?php

namespace Oara\Network\Publisher;
/**
 * The goal of the Open Affiliate Report Aggregator (OARA) is to develop a set
 * of PHP classes that can download affiliate reports from a number of affiliate networks, and store the data in a common format.
 *
 * Copyright (C) 2016  Fubra Limited
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Contact
 * ------------
 * Fubra Limited <support@fubra.com> , +44 (0)1252 367 200
 **/

/**
 * Export Class
 *
 * @author     Carlos Morillo Merino
 * @category   Td
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */
class TradeDoubler extends \Oara\Network
{

	protected $_sitesAllowed = array();
	protected $_client = null;
	protected $_dateFormat = null;
	protected $_apiUrl = 'https://connect.tradedoubler.com';

	public function login($credentials)
	{
		//https://tradedoubler.docs.apiary.io/#/reference/o-auth-2-0/bearer-and-refresh-token/bearer-token/200?mc=reference%2Fo-auth-2-0%2Fbearer-and-refresh-token%2Fbearer-token%2F200
		$this->_credentials = $credentials;
		$user = $this->_credentials['user'];
		$password = $this->_credentials['password'];
		$this->_grant_type = 'password';
		$this->getToken();
	}

	private function getToken()
	{
		try {
			if (!empty($this->_token)) {
				return $this->_token;
			}
			// Retrieve Bearer token
			$loginUrl = $this->_apiUrl . '/uaa/oauth/token';
			$client_id = $_ENV['TRADEDOUBLER_CLIENT_ID'];
			$client_secret = $_ENV['TRADEDOUBLER_CLIENT_SECRET'];
			$params = array(
				new \Oara\Curl\Parameter('grant_type', $this->_grant_type),
				new \Oara\Curl\Parameter('username', $this->_credentials['user']),
				new \Oara\Curl\Parameter('password', $this->_credentials['password'])
			);

			$apiKey = base64_encode($client_id . ':' . $client_secret);
			$p = array();
			foreach ($params as $parameter) {
				$p[] = $parameter->getKey() . '=' . \urlencode($parameter->getValue());
			}
			$post_params = implode('&', $p);

			$ch = curl_init();

			curl_setopt($ch, CURLOPT_URL, $loginUrl);
			curl_setopt($ch, CURLOPT_POST, TRUE);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_params);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Basic " . $apiKey, "Content-Type: application/x-www-form-urlencoded"));

			$curl_results = curl_exec($ch);
			curl_close($ch);
			$response = json_decode($curl_results);
			if ($response) {
				if (isset($response->error)) {
					if (isset($response->error_description)) {
						throw new \Exception('[php-oara][Oara][Network][Publisher][TradeDoubler][getToken] ' . $response->error_description);
					}
				}
				if (isset($response->access_token)) {
					$this->_token = $response->access_token;
				}

			}
			return $this->_token;
		} catch (\Exception $e) {
			throw new \Exception('[php-oara][Oara][Network][Publisher][TradeDoubler][getToken][Exception] ' . $e->getMessage());
		}

	}


	/**
	 * @return array
	 */
	public function getNeededCredentials()
	{
		$credentials = array();

		$parameter = array();
		$parameter["description"] = "User Log in";
		$parameter["required"] = true;
		$parameter["name"] = "User";
		$credentials["user"] = $parameter;

		$parameter = array();
		$parameter["description"] = "Password to Log in";
		$parameter["required"] = true;
		$parameter["name"] = "Password";
		$credentials["password"] = $parameter;

		return $credentials;
	}

	/**
	 * @return bool
	 */
	public function checkConnection()
	{
		$connection = true;
		return $connection;
	}

	/**
	 * @return array
	 * @throws \Exception
	 */
	public function getMerchantList()
	{
		$merchants = Array();
		try {
			//https://tradedoubler.docs.apiary.io/#/reference/programs/program/list-programs/200?mc=reference%2Fprograms%2Fprogram%2Flist-programs%2F200
			$url_programs = $this->_apiUrl . '/publisher/programs';
			$limit = 100;
			$offset = 0;
			$loop = true;

			while ($loop) {
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url_programs . '?sourceId=' . $this->_credentials['idSite'] . '&limit=' . $limit . '&offset=' . $offset);
				curl_setopt($ch, CURLOPT_POST, false);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer " . $this->getToken(), "Content-Type: application/x-www-form-urlencoded"));

				$curl_results = curl_exec($ch);
				curl_close($ch);
				$a_merchants = json_decode($curl_results, true);
				if (isset($a_merchants[0]['code']) && isset($a_merchants[0]['message'])) {
					$loop = false;
					throw new \Exception('[php-oara][Oara][Network][Publisher][TradeDoubler][getMerchantList] ' . $a_merchants[0]['message']);
				} elseif (isset($a_merchants['items']) && isset($a_merchants['total'])) {
					foreach ($a_merchants['items'] as $merchantJson) {
						$obj = Array();
						$obj['cid'] = $merchantJson['id'];
						$obj['name'] = $merchantJson['name'];
						//Possible values: 0: Not Applied, 1: Under Consideration, 2: On-hold while under consideration, 3: Accepted, 4: Ended, 5: Denied, 6: On Hold while Accepted, 7: Final Denied, 8: Written Off
						switch ($merchantJson['statusId']) {
							case 0:
								$obj['status'] = 'Not Applied';
								break;
							case 1:
								$obj['status'] = ' Under Consideration';
								break;
							case 2:
								$obj['status'] = 'On-hold while under consideration';
								break;
							case 3:
								$obj['status'] = 'Accepted';
								break;
							case 4:
								$obj['status'] = 'Ended';
								break;
							case 5:
								$obj['status'] = 'Denied';
								break;
							case 6:
								$obj['status'] = 'On Hold while Accepted';
								break;
							case 7:
								$obj['status'] = 'Final Denied';
								break;
							case 8:
								$obj['status'] = 'Written Off';
								break;
							default:
								$obj['status'] = 'Unknown';
								echo '[php-oara][Oara][Network][Publisher][TradeDoubler][getMerchantList] Merchant status unexpected ' . $merchantJson['statusId'];
								break;
						}
						$obj['launch_date'] = $merchantJson['startDate'];
						$obj['application_date'] = $merchantJson['applicationDate'];
						$merchants[] = $obj;
					}
					if ((int)$a_merchants['total'] <= $offset) {
						$loop = false;
					}
					$offset = (int)($limit + $offset);
				} else {
					echo '[php-oara][Oara][Network][Publisher][TradeDoubler][getMerchantList] invalid response';
					$loop = false;
				}
			}
		} catch (\Exception $e) {
			throw new \Exception('[php-oara][Oara][Network][Publisher][TradeDoubler][getMerchantList][Exception] ' . $e->getMessage());
		}
		return $merchants;
	}


	/**
	 * @param null $merchantList
	 * @param \DateTime|null $dStartDate
	 * @param \DateTime|null $dEndDate
	 * @return array
	 * @throws \Exception
	 */
	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null)
	{
		$totalTransactions = array();

		try {
			$limit = 100;
			$offset = 0;
			$loop = true;

			if (isset($_ENV['TRADEDOUBLER_CURRENCY'])) {
				$currency = $_ENV['TRADEDOUBLER_CURRENCY'];
			} else {
				$currency = 'EUR';
			}
			while ($loop) {
				/**
				 * https://tradedoubler.docs.apiary.io/#/reference/reporting/transaction/list-transaction/200?mc=reference%2Freporting%2Ftransaction%2Flist-transaction%2F200
				 * The values for dates should be in format Ymd
				 * Returns the result in the JSON format
				 */
				$url_transactions = $this->_apiUrl . '/publisher/report/transactions';
				$params = array(
					new \Oara\Curl\Parameter('reportCurrencyCode', $currency),
					new \Oara\Curl\Parameter('fromDate', $dStartDate->format("Ymd")),
					new \Oara\Curl\Parameter('toDate', $dEndDate->format("Ymd")),
					new \Oara\Curl\Parameter('limit', $limit),
					new \Oara\Curl\Parameter('offset', $offset)
				);

				$p = array();
				foreach ($params as $parameter) {
					$p[] = $parameter->getKey() . '=' . \urlencode($parameter->getValue());
				}
				$get_params = implode('&', $p);

				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url_transactions . '?' . $get_params);
				curl_setopt($ch, CURLOPT_POST, false);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer " . $this->getToken(), "Content-Type: application/x-www-form-urlencoded"));

				$curl_results = curl_exec($ch);
				curl_close($ch);
				$transactionsList = json_decode($curl_results, true);
				foreach ($transactionsList['items'] as $transactionJson) {

					$transaction = Array();
					if (isset($transactionJson['orderNumber']) && !empty($transactionJson['orderNumber'])) {
						$transaction['unique_id'] = $transactionJson['orderNumber'];
						$eventTypeId = $transactionJson['eventTypeId'];
						$transaction['action'] = \Oara\Utilities::TYPE_SALE;
					} elseif (isset($transactionJson['leadNumber']) && !empty($transactionJson['leadNumber'])) {
						$transaction['unique_id'] = $transactionJson['leadNumber'];
						$eventTypeId = $transactionJson['eventTypeId'];
						$transaction['action'] = \Oara\Utilities::TYPE_LEAD;
					} else {
						//Cannot identified an unique attribute for the transaction
						echo '[php-oara][Oara][Network][Publisher][TradeDoubler][getTransactionList] Cannot identified an unique attribute for the transaction ' . $transactionJson;
						continue;
					}
					$transaction['event_id'] = $transactionJson['eventId'];
					$transaction['merchantId'] = $transactionJson['programId'];
					$transaction['merchantName'] = $transactionJson['programName'];
					$transaction['date'] = $transactionJson['timeOfTransaction'];
					$transaction['click_date'] = $transactionJson['timeOfLastClick'];
					$transaction['update_date'] = $transactionJson['timeOfLastModified'];
					$transaction['amount'] = \Oara\Utilities::parseDouble($transactionJson['orderValue']);
					$transaction['commission'] = \Oara\Utilities::parseDouble($transactionJson['commission']);
					$transaction['currency'] = $transactionsList['reportCurrencyCode'];
					$transaction['custom_id'] = $transactionJson['epi'];
					if ($transactionJson['status'] == 'A') {
						$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
					} elseif ($transactionJson['status'] == 'P') {
						$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
					} elseif ($transactionJson['status'] == 'D') {
						$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
					} else {
						$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
						echo '[php-oara][Oara][Network][Publisher][TradeDoubler][getTransactionList] Transaction status unexpected ' . $transactionJson['status'];
					}
					$totalTransactions[] = $transaction;
				}
				if ((int)count($transactionsList['items']) < $limit) {
					$loop = false;
				}
				$offset = (int)($limit + $offset);
			}
		} catch (\Exception $e) {
			throw new \Exception('[php-oara][Oara][Network][Publisher][TradeDoubler][getTransactionList][Exception] ' . $e->getMessage());
		}
		return $totalTransactions;
	}


	/**
	 * @param $dateString
	 * @return bool|\DateTime
	 * @throws \Exception
	 */
	protected function toDate($dateString)
	{
		$transactionDate = false;
		$hour_separator = ':';
		if (strlen($dateString) > 10) {
			if (strpos(substr($dateString, 10), '.') !== false) {
				$hour_separator = '.';
			}
		}
		if ($this->_dateFormat == 'dd/MM/yy') {
			$transactionDate = \DateTime::createFromFormat("d/m/y H{$hour_separator}i{$hour_separator}s", \trim($dateString));
		} else
			if ($this->_dateFormat == 'M/d/yy') {
				// Check for AM/PM time - 2019-04-15 <PN>
				$transactionDate = \DateTime::createFromFormat("m/d/y h{$hour_separator}i{$hour_separator}s A", \trim($dateString));
				if ($transactionDate === false) {
					// Check for H24 time
					$transactionDate = \DateTime::createFromFormat("m/d/y H{$hour_separator}i{$hour_separator}s", \trim($dateString));
				}
				if ($transactionDate === false) {
					// Try to get only the date
					$pos = strpos($dateString, ' ');
					if ($pos !== false) {
						$dateString = substr($dateString, 0, $pos);
						$transactionDate = \DateTime::createFromFormat("m/d/y", trim($dateString));
					}
				}
			} else
				if ($this->_dateFormat == 'd/MM/yy') {
					$transactionDate = \DateTime::createFromFormat("j/m/y H:i:s", \trim($dateString));
				} else
					if ($this->_dateFormat == 'tt.MM.uu') {
						$transactionDate = \DateTime::createFromFormat("d.m.y H:i:s", \trim($dateString));
					} else
						if ($this->_dateFormat == 'jj-MM-aa') {
							$transactionDate = \DateTime::createFromFormat("d-m-y H:i:s", \trim($dateString));
						} else
							if ($this->_dateFormat == 'jj/MM/aa') {
								$transactionDate = \DateTime::createFromFormat("d/m/y H:i:s", \trim($dateString));
							} else
								if ($this->_dateFormat == 'dd.MM.yy') {
									$transactionDate = \DateTime::createFromFormat("d.m.y H:i:s", \trim($dateString));
								} else
									if ($this->_dateFormat == 'yy-MM-dd') {
										$transactionDate = \DateTime::createFromFormat("y-m-d H:i:s", \trim($dateString));
									} else
										if ($this->_dateFormat == 'd-M-yy') {
											$transactionDate = \DateTime::createFromFormat("j-n-y H:i:s", \trim($dateString));
										} else
											if ($this->_dateFormat == 'yyyy/MM/dd') {
												$transactionDate = \DateTime::createFromFormat("Y/m/d H:i:s", \trim($dateString));
											} else
												if ($this->_dateFormat == 'yyyy-MM-dd') {
													$transactionDate = \DateTime::createFromFormat("Y-m-d H:i:s", \trim($dateString));
												} else {
													throw new \Exception("\n Date Format not supported " . $this->_dateFormat . "\n");
												}
		if ($transactionDate === false) {
			throw new \Exception("TradeDoubler - Date Format not supported " . $this->_dateFormat . " for date: " . $dateString . "\n");
		}
		return $transactionDate;
	}

	/**
	 * @return array
	 * @throws \Exception
	 * Attention ! OLD API connection
	 */
	public function getPaymentHistory()
	{
		$paymentHistory = array();

		$urls = array();
		$urls[] = new \Oara\Curl\Request('http://publisher.tradedoubler.com/pan/reportSelection/Payment?', array());
		$exportReport = $this->_client->get($urls);
		/*** load the html into the object ***/
		$doc = new \DOMDocument();
		\libxml_use_internal_errors(true);
		$doc->validateOnParse = true;
		$doc->loadHTML($exportReport[0]);
		$selectList = $doc->getElementsByTagName('select');
		$paymentSelect = null;
		if ($selectList->length > 0) {
			// looking for the payments select
			$it = 0;
			while ($it < $selectList->length) {
				$selectName = $selectList->item($it)->attributes->getNamedItem('name')->nodeValue;
				if ($selectName == 'payment_id') {
					$paymentSelect = $selectList->item($it);
					break;
				}
				$it++;
			}
			if ($paymentSelect != null) {
				$paymentLines = $paymentSelect->childNodes;
				for ($i = 0; $i < $paymentLines->length; $i++) {
					$pid = $paymentLines->item($i)->attributes->getNamedItem("value")->nodeValue;
					if (\is_numeric($pid)) {
						$obj = array();

						$paymentLine = $paymentLines->item($i)->nodeValue;
						$value = \preg_replace('/[^0-9\.,]/', "", \substr($paymentLine, 10));

						$paymentParts = \explode(" ", $paymentLine);
						$date = self::toDate($paymentParts[0] . " 00:00:00");

						$obj['date'] = $date->format("Y-m-d H:i:s");
						$obj['pid'] = $pid;
						$obj['method'] = 'BACS';
						$obj['value'] = \Oara\Utilities::parseDouble($value);

						$paymentHistory[] = $obj;
					}
				}
			}
		}
		return $paymentHistory;
	}

	/**
	 * @param $paymentId
	 * @return array
	 * @throws \Exception
	 * Attention ! OLD API connection
	 */
	public function paymentTransactions($paymentId)
	{
		$transactionList = array();

		$urls = array();
		$valuesFormExport = array();
		$valuesFormExport[] = new \Oara\Curl\Parameter('popup', 'true');
		$valuesFormExport[] = new \Oara\Curl\Parameter('payment_id', $paymentId);
		$urls[] = new \Oara\Curl\Request('http://publisher.tradedoubler.com/pan/reports/Payment.html?', $valuesFormExport);
		$exportReport = $this->_client->get($urls);


		$dom = new \Laminas\Dom\Query($exportReport[0]);
		$results = $dom->execute('//a');

		$urls = array();
		foreach ($results as $result) {
			$url = $result->getAttribute('href');
			$urls[] = new \Oara\Curl\Request("http://publisher.tradedoubler.com" . $url . "&format=CSV", array());
		}
		$exportReportList = $this->_client->get($urls);
		foreach ($exportReportList as $exportReport) {
			$exportReportData = \str_getcsv($exportReport, "\r\n");
			$num = \count($exportReportData);
			for ($i = 2; $i < $num - 1; $i++) {
				$transactionExportArray = \str_getcsv($exportReportData[$i], ";");
				if (\count($this->_sitesAllowed) == 0 || \in_array($transactionExportArray[2], $this->_sitesAllowed)) {
					$transaction = Array();
					$transaction['merchantId'] = $transactionExportArray[2];
					$transactionDate = self::toDate($transactionExportArray[6]);
					$transaction['date'] = $transactionDate->format("Y-m-d H:i:s");
					if ($transactionExportArray[8] != '') {
						$transaction['unique_id'] = \substr($transactionExportArray[8], 0, 200);
					} else
						if ($transactionExportArray[7] != '') {
							$transaction['unique_id'] = \substr($transactionExportArray[7], 0, 200);
						} else {
							throw new \Exception("No Identifier");
						}


					if ($transactionExportArray[9] != '') {
						$transaction['custom_id'] = $transactionExportArray[9];
					}

					if ($transactionExportArray[11] == 'A') {
						$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
					} else
						if ($transactionExportArray[11] == 'P') {
							$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
						} else
							if ($transactionExportArray[11] == 'D') {
								$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
							}

					if ($transactionExportArray[13] != '') {
						$transaction['amount'] = \Oara\Utilities::parseDouble($transactionExportArray[13]);
					} else {
						$transaction['amount'] = \Oara\Utilities::parseDouble($transactionExportArray[14]);
					}

					$transaction['commission'] = \Oara\Utilities::parseDouble($transactionExportArray[15]);
					$transactionList[] = $transaction;
				}
			}
		}

		return $transactionList;
	}
}
