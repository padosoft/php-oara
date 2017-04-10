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
 * Api Class
 *
 * @author     Carlos Morillo Merino
 * @category   Zn
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */

require "Zanox/Zapi/ApiClient.php";

class Zanox extends \Oara\Network
{

    protected $_apiClient = null;
    protected $_pageSize = 50;

    /**
     * @param $credentials
     */
    public function login($credentials)
    {
        $api = \ApiClient::factory(PROTOCOL_SOAP, VERSION_2011_03_01);
        $connectId = $credentials['connectid'];
        $secretKey = $credentials['secretkey'];
        $api->setConnectId($connectId);
        $api->setSecretKey($secretKey);
        $this->_apiClient = $api;

    }

    /**
     * @return array
     */
    public function getNeededCredentials()
    {
        $credentials = array();

        $parameter = array();
        $parameter["description"] = "Connect Id";
        $parameter["required"] = true;
        $parameter["name"] = "Connect Id";
        $credentials["connectId"] = $parameter;

        $parameter = array();
        $parameter["description"] = "Secret Key";
        $parameter["required"] = true;
        $parameter["name"] = "Secret Key";
        $credentials["secretKey"] = $parameter;

        return $credentials;
    }

    /**
     * @return bool
     */
    public function checkConnection()
    {
        $connection = true;
        try {
            $profile = $this->_apiClient->getProfile();
        } catch (\Exception $e) {
            $connection = false;
        }

        return $connection;
    }

    /**
     * @return array
     */
    public function getMerchantList()
    {
        $merchantList = array();

        $programApplicationList = $this->_apiClient->getProgramApplications(null, null, "confirmed", 0, $this->_pageSize);
        if ($programApplicationList->total > 0) {
            $iterationProgramApplicationList = self::calculeIterationNumber($programApplicationList->total, $this->_pageSize);
            for ($j = 0; $j < $iterationProgramApplicationList; $j++) {

                $programApplicationList = $this->_apiClient->getProgramApplications(null, null, "confirmed", $j, $this->_pageSize);
                foreach ($programApplicationList->programApplicationItems->programApplicationItem as $programApplication) {
                    if (!isset($merchantList[$programApplication->program->id])) {
                        $obj = array();
                        $obj['cid'] = $programApplication->program->id;
                        $obj['name'] = $programApplication->program->_;
                        $merchantList[$programApplication->program->id] = $obj;
                    }
                }
            }
        }
        return $merchantList;
    }

    /**
     * @param $rowAvailable
     * @param $rowsReturned
     * @return int
     */
    private function calculeIterationNumber($rowAvailable, $rowsReturned)
    {
        $iterationDouble = (double)($rowAvailable / $rowsReturned);
        $iterationInt = (int)($rowAvailable / $rowsReturned);
        if ($iterationDouble > $iterationInt) {
            $iterationInt++;
        }
        return $iterationInt;
    }

    /**
     * @param null $merchantList
     * @param \DateTime|null $dStartDate
     * @param \DateTime|null $dEndDate
     * @return array
     */
    public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null)
    {
        $totalTransactions = array();
        $diff = $dStartDate->diff($dEndDate)->days;

        $merchantIdList = \Oara\Utilities::getMerchantIdMapFromMerchantList($merchantList);

        $auxDate = clone $dStartDate;
        for ($i = 0; $i < $diff; $i++) {
            $totalAuxTransactions = array();
            $transactionList = $this->getSales($auxDate->format("Y-m-d"), 0, $this->_pageSize);

            if ($transactionList->total > 0) {
                $iteration = self::calculeIterationNumber($transactionList->total, $this->_pageSize);
                $totalAuxTransactions = \array_merge($totalAuxTransactions, $transactionList->saleItems->saleItem);
                for ($j = 1; $j < $iteration; $j++) {
                    $transactionList = $this->getSales($auxDate->format("Y-m-d"), $j, $this->_pageSize);
                    $totalAuxTransactions = \array_merge($totalAuxTransactions, $transactionList->saleItems->saleItem);
                    unset($transactionList);
                    \gc_collect_cycles();
                }

            }
            $leadList = $this->_apiClient->getLeads($auxDate->format("Y-m-d"), 'trackingDate', null, null, null, 0, $this->_pageSize);
            if ($leadList->total > 0) {
                $iteration = self::calculeIterationNumber($leadList->total, $this->_pageSize);
                $totalAuxTransactions = \array_merge($totalAuxTransactions, $leadList->leadItems->leadItem);
                for ($j = 1; $j < $iteration; $j++) {
                    $leadList = $this->_apiClient->getLeads($auxDate->format("Y-m-d"), 'trackingDate', null, null, null, $j, $this->_pageSize);
                    $totalAuxTransactions = \array_merge($totalAuxTransactions, $leadList->leadItems->leadItem);
                    unset($leadList);
                    \gc_collect_cycles();
                }
            }

            foreach ($totalAuxTransactions as $transaction) {

                if ($merchantList == null || isset($merchantIdList[$transaction->program->id])) {
                    $obj = array();
                    $obj['currency'] = $transaction->currency;
                    if ($transaction->reviewState == 'confirmed') {
                        $obj['status'] = \Oara\Utilities::STATUS_CONFIRMED;
                    } else
                        if ($transaction->reviewState == 'open' || $transaction->reviewState == 'approved') {
                            $obj['status'] = \Oara\Utilities::STATUS_PENDING;
                        } else
                            if ($transaction->reviewState == 'rejected') {
                                $obj['status'] = \Oara\Utilities::STATUS_DECLINED;
                            }
                    if (!isset($transaction->amount) || $transaction->amount == 0) {
                        $obj['amount'] = $transaction->commission;
                    } else {
                        $obj['amount'] = $transaction->amount;
                    }

                    if (isset($transaction->gpps) && $transaction->gpps != null) {
                        foreach ($transaction->gpps->gpp as $gpp) {
                            if ($gpp->id == "zpar0") {
                                if (\strlen($gpp->_) > 150) {
                                    $gpp->_ = \substr($gpp->_, 0, 150);
                                }
                                $obj['custom_id'] = $gpp->_;
                            }
                        }
                    }

                    if (isset($transaction->trackingCategory->_) && $transaction->trackingCategory->_ != null) {
                        $obj['title'] = $transaction->trackingCategory->_;
                    }

                    $obj['unique_id'] = $transaction->id;
                    $obj['commission'] = $transaction->commission;
                    $obj['date'] = $transaction->trackingDate;
                    $obj['merchantId'] = $transaction->program->id;
                    $obj['approved'] = $transaction->reviewState == 'approved' ? true : false;
                    $totalTransactions[] = $obj;
                }

            }
            unset($totalAuxTransactions);
            \gc_collect_cycles();

            $interval = new \DateInterval('P1D');
            $auxDate->add($interval);
        }
        return $totalTransactions;
    }

    protected function getSales($date, $page, $pageSize, $iteration = 0)
    {
        $transactionList = array();
        try {
            $transactionList = $this->_apiClient->getSales($date, 'trackingDate', null, null, null, $page, $pageSize, $iteration);
        } catch (\Exception $e) {
            $iteration++;
            if ($iteration < 5) {
                $transactionList = self::getSales($date, $page, $pageSize, $iteration);
            }

        }
        return $transactionList;

    }

    public function getProducts(array $params = [], $iteration = 0)
    {
        $productList = array();
        try {
            $productList = $this->_apiClient->searchProducts($params['query'],
                $params['searchType'],
                $params['region'],
                $params['categoryId'],
                $params['programId'],
                $params['hasImages'],
                $params['minPrice'],
                $params['maxPrice'],
                $params['adspaceId'],
                $params['page'],
                $params['items']);
        } catch (\Exception $e) {
            $iteration++;
            if ($iteration < 5) {
                $productList = self::getProducts($params, $iteration);
            }
        }
        return $productList;
    }
}
