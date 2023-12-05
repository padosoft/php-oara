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
 **/
/**
 * Api Class for Webgains using REST API
 *
 * @author     Sławek Naczyński
 * @category   Wg
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */
class WebGainsSmart extends \Oara\Network
{

    private $_campaignMap  = [];
    private $_sitesAllowed = [];
    private $_apiKey       = '';
    private $_publisherId  = '';
    private $_apiBaseUrl   = 'https://platform-api.webgains.com/';

    /**
     * @param array $credentials
     */
    public function login(array $credentials)
    {
        $this->_apiKey       = $credentials['api-key'] ?? '';
        $this->_sitesAllowed = array_map('intval', $credentials['sitesAllowed'] ?? []);
        $this->_publisherId  = $credentials['publisherId'] ?? '';
        $this->_campaignMap  = self::getCampaignMap();
    }

    /**
     * https://docs.webgains.dev/docs/platform-api-1/c65f8e5f917a2-get-publisher-campaigns
     * @return array
     */
    private function getCampaignMap()
    {
        $response    = $this->getCurlResponse($this->_apiBaseUrl . 'publishers/' . $this->_publisherId . '/campaigns');
        $campaingMap = [];
        if (self::isJSON($response)) {
            $resArray = json_decode($response, true);
            if (isset($resArray['data']) && is_array($resArray['data']) && count($resArray['data']) > 0) {
                foreach ($resArray['data'] as $oneCampaign) {
                    if (is_array($this->_sitesAllowed) && count($this->_sitesAllowed) > 0) {
                        if (in_array((int) $oneCampaign['id'], $this->_sitesAllowed, true)) {
                            $campaingMap[(int) $oneCampaign['id']] = $oneCampaign['name'];
                        }
                    } else {
                        $campaingMap[(int) $oneCampaign['id']] = $oneCampaign['name'];
                    }
                }
            }
        }

        if (count($campaingMap) > 0) {
            return $campaingMap;
        }
        return [];
    }

    /**
     * @return bool
     */
    public function checkConnection()
    {
        $connection = false;
        if (isset($this->_apiKey) && is_string($this->_apiKey) && $this->_apiKey != '') {
            $connection = true;
        }
        return $connection;
    }

    /**
     * Get array of all merchants
     * https://docs.webgains.dev/docs/platform-api-1/5a04fe3173176-get-programs
     * @return array
     */
    public function getMerchantList()
    {
        $merchants = [];
        $i         = 1;
        $maxPages  = 1;
        while ($i <= $maxPages) {
            $getMerchants = $this->getCurlResponse($this->_apiBaseUrl . 'merchants/programs?page=' . $i);
            if (self::isJSON($getMerchants)) {
                $getMerchants = json_decode($getMerchants, true);
                $maxPages     = $getMerchants['pagination']['last_page'];
                if (is_array($getMerchants['data']) && count($getMerchants['data']) > 0) {
                    foreach ($getMerchants['data'] as $oneMarchant) {
                        $obj                = [];
                        $obj['cid']         = $oneMarchant['id'];
                        $obj['name']        = $oneMarchant['name'];
                        $obj['url']         = $oneMarchant['homepage_url'];
                        $obj['launch_date'] = date('Y-m-d H:i:s', $oneMarchant['create_date']);
                        $merchants[]        = $obj;
                    }
                }
            }
            $i++;
        }
        
        return $merchants;
    }

    /**
     * https://docs.webgains.dev/docs/platform-api-1/4e131c6a36cca-get-transaction-report
     * @param null $merchantList
     * @param \DateTime|null $dStartDate
     * @param \DateTime|null $dEndDate
     * @return array
     * @throws Exception
     */
    public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null)
    {
        $totalTransactions = [];
        $startTimestamp    = (!is_null($dStartDate)) ? $dStartDate->getTimestamp() : strtotime('-1 year');
        $endTimestamp      = (!is_null($dEndDate)) ? $dEndDate->getTimestamp() : strtotime('-1 minute');
        $apiUrl            = $this->_apiBaseUrl . 'publishers/' . $this->_publisherId . '/reports/transactions?sort_order=ASC&sort=date&size=250&';

        if (is_array($this->_campaignMap) && count($this->_campaignMap) > 0) {
            foreach ($this->_campaignMap as $campaignKey => $campaignValue) {
                $apiUrl .= 'filters[campaign_ids][]=' . $campaignKey . '&';
            }
        } else {
            return [];
        }
        $apiUrl   .= 'filters[start_date]=' . $startTimestamp . '&filters[end_date]=' . $endTimestamp;
        $i        = 1;
        $maxPages = 1;
        while ($i <= $maxPages) {
            $getTransactions = $this->getCurlResponse($apiUrl . '&page=' . $i);
            if (self::isJSON($getTransactions)) {
                $getTransactions = json_decode($getTransactions, true);
                $maxPages        = $getTransactions['pagination']['last_page'];
                if (is_array($getTransactions['data']) && count($getTransactions['data']) > 0) {
                    foreach ($getTransactions['data'] as $oneTrans) {
                        $transaction               = [];
                        $transaction['merchantId'] = $oneTrans['program']['id'];
                        $transaction['date']       = date('Y-m-d H:i:s', $oneTrans['date']);
                        $transaction['unique_id']  = $oneTrans['id'];
                        $transaction['custom_id']  = $oneTrans['click_reference'] ?? '';
                        $transaction['status']     = null;
                        $transaction['amount']     = (float) substr($oneTrans['value']['amount'], 0, -4) . '.' . substr($oneTrans['value']['amount'], -4);
                        $transaction['commission'] = (float) substr($oneTrans['commission']['amount'], 0, -4) . '.' . substr($oneTrans['commission']['amount'], -4);
                
                        // Check both for status + paymentStatus
                        // https://docs.webgains.dev/docs/platform-api-1/ip0xqw2v0z6i9-transaction-statuses
                        if (in_array($oneTrans['status'], [10, 20], true)) {
                            $transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
                        } elseif (in_array($oneTrans['status'], [30, 40, 50, 60], true)) {
                            $transaction['status'] = \Oara\Utilities::STATUS_PENDING;
                        } elseif (in_array($oneTrans['status'], [70], true)) {
                            $transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
                        }

                        if (in_array($oneTrans['status'], [10], true)) {
                            $transaction['paid'] = true;
                        } else {
                            $transaction['paid'] = false;
                        }
                        $transaction['currency'] = $oneTrans['commission']['currency_code'];
                        $totalTransactions[] = $transaction;
                    }
                }
            }

            $i++;
        }

        return $totalTransactions;
    }

    /**
     * Get list of Vouchers
     * https://docs.webgains.dev/docs/platform-api-1/a6d544e23aefd-get-vouchers
     * @param $id_site   account ID needed to access data feed
     * @return array
     */
    public function getVouchers($id_site)
    {
        $vouchers = [];
        $i        = 1;
        $maxPages = 1;
        while ($i <= $maxPages) {
            $getVouchers = $this->getCurlResponse($this->_apiBaseUrl . 'publishers/' . $this->_publisherId . '/campaigns/' . $id_site . '/vouchers?page=' . $i);
            if (self::isJSON($getVouchers)) {
                $getVouchers = json_decode($getVouchers, true);
                $maxPages    = $getVouchers['pagination']['last_page'];
                $vouchers    = array_merge($vouchers, $getVouchers['data']);
            }
            $i++;
        }
        
        return $vouchers;
    }

    /**
     * Get list of Offers
     * https://docs.webgains.dev/docs/platform-api-1/677389db104fa-get-offers
     * @param $id_site   account ID needed to access data feed
     * @return array
     */
    public function getOffers($id_site)
    {
        $offers   = [];
        $i        = 1;
        $maxPages = 1;
        while ($i <= $maxPages) {
            $getOffers = $this->getCurlResponse($this->_apiBaseUrl . 'publishers/' . $this->_publisherId . '/campaigns/' . $id_site . '/offers?page=' . $i);
            if (self::isJSON($getOffers)) {
                $getOffers = json_decode($getOffers, true);
                $maxPages  = $getOffers['pagination']['last_page'];
                $offers    = array_merge($offers, $getOffers['data']);
            }
            $i++;
        }

        return $offers;
    }

    /**
     * Check if string is JSON
     * @param string $string
     * @return bool
     */
    private static function isJSON(string $string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * Get data with cUrl
     * @param string $url
     * @return mixed
     */
    private function getCurlResponse(string $url)
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->_apiKey
            ],
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
        return $response;
    }
}
