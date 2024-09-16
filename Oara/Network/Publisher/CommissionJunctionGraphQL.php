<?php
namespace Oara\Network\Publisher;

use http\Exception\RuntimeException;

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
 * @author     Paolo Nardini (based on class by Carlos Morillo Merino)
 * @date       2019-05-05
 * @category   Cj
 * @version    Release: 01.00
 *
 */
class CommissionJunctionGraphQL extends \Oara\Network
{
    private $_client = null;
    private $_memberId = null;
    private $_accountId = null;
    private $_apiPassword = null;
    private $_requestor_cid = null;
    private $_connected = false;

    protected $_sitesAllowed = array ();

    /*
     * ATTENTION - IMPORTANT UPDATES - 2019-05-05 by <PN>
     * CJ REST API is now DEPRECATED and removed on June 1, 2019
     * CJ now allow only GraphQL API calls
     *
     * You need to generate a "PERSONAL ACCESS TOKEN" to be used in headers as "Authorization: Bearer XXXXXXX ... " (passed by 'apipassword' in credentials)
     * You also need a MANDATORY PARAMETER called "requestor-cid" that represent the COMPANY ID in the CJ account dashboard (passed by 'id_site' in credentials)
     *
     * See: https://developers.cj.com for more instructions
     */

    /**
     * @param $credentials
     */
    public function login($credentials)
    {
        $this->_apiPassword = @$credentials['apipassword'];
        $this->_requestor_cid = @$credentials['id_site'];
    }

    /**
     * @param string $idSite
     */
    public function addAllowedSite(string $idSite){
        if (!in_array($idSite, $this->_sitesAllowed)){
            $this->_sitesAllowed[]=$idSite;
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
        $parameter["description"] = "API Password ";
        $parameter["required"] = true;
        $parameter["name"] = "API";
        $credentials["apipassword"] = $parameter;

        return $credentials;
    }

    /**
     * @return bool
     */
    public function checkConnection()
    {
        if ($this->_connected) {
            // Avoid multiple checks
            return $this->_connected;
        }

        // Get only commission counts to check for a valid connection
        // PN - 2023-01-27 Added a time-frame to avoid infinite waiting!
        $query = '{ publisherCommissions(forPublishers: ["#cid#"] sinceEventDate:"2021-01-01T00:00:00Z",beforeEventDate:"2021-01-02T00:00:00Z"){count} }';

        $result = self::grapQLApiCall($query);
        if (isset($result->errors) && count($result->errors) > 0) {
            $error_message = $result->errors[0]->message;
            $this->_connected = false;
            throw new \Exception("Error checking connection: " . $error_message);
        }
        $this->_connected = true;
        return $this->_connected;
    }

    /**
     * @return array
     */
    public function getMerchantList()
    {
        $merchants = array();
        $merchantsExport = self::getMerchantExport();
        foreach ($merchantsExport as $merchantData) {
            $obj = Array();
            $obj['cid'] = $merchantData[0];
            $obj['name'] = $merchantData[1];
            // Added more info - 2018-04-23 <PN>
            $obj['status'] = $merchantData[2];
            $obj['relationship_status'] = $merchantData[3];
            $obj['url'] = $merchantData[4];
            $merchants[] = $obj;
        }
        return $merchants;
    }

    /**
     * @return array
     */
    private function getMerchantExport()
    {
        $merchantReportList = array();
        // Make two loops, first for "joined" and second for "notjoined prograns - 2020-08-22 <PN>
        for ($loop=1; $loop<=2; $loop++) {
            $page = 1;
            $per_page = 100;
            $total_pages = 99;
            $start = time();
            $per_minute = 0;
            do {
                if ($page > $total_pages) {
                    exit;
                }
                if ($per_minute++ > 25 && (time() - $start) < 60) {
                    // Don't go above the 25 calls/minute
                    while ((time() - $start) < 60) {
                        sleep(1);
                    }
                    $per_minute = 0;
                    $start = time();
                }
                // Get All programs even if not active - 2018-04-23 <PN>
                if ($loop == 1) {
                    // Joined Only - 2020-08-22 <PN>
                    $response = self::apiCall('https://advertiser-lookup.api.cj.com/v3/advertiser-lookup?advertiser-ids=joined&records-per-page=' . $per_page . '&page-number=' . $page);
                }
                else {
                    // Not Joined Only  - 2020-08-22 <PN>
                    $response = self::apiCall('https://advertiser-lookup.api.cj.com/v3/advertiser-lookup?advertiser-ids=notjoined&records-per-page=' . $per_page . '&page-number=' . $page);
                }
                $xml = \simplexml_load_string($response, null, LIBXML_NOERROR | LIBXML_NOWARNING);
                if (!isset($xml->advertisers)) {
                    break;
                }
                $total_adv = (int)$xml->advertisers[0]['total-matched'];
                $total_pages = ceil($total_adv / $per_page);

                foreach ($xml->advertisers->advertiser as $adv) {

                    $adv_id = '';
                    $adv_name = '';

                    foreach ($adv->children() as $key => $value) {

                        if ($key == 'advertiser-id') {
                            $adv_id = (string)$value;
                        }
                        if ($key == 'advertiser-name') {
                            $adv_name = (string)$value;
                        }
                        // Added more info - 2018-04-23 <PN>
                        if ($key == 'account-status') {
                            $adv_status = (string)$value;
                        }
                        if ($key == 'relationship-status') {
                            $adv_relationship_status = (string)$value;
                        }
                        if ($key == 'program-url') {
                            $adv_url = (string)$value;
                        }
                    }
                    if (trim($adv_id) != '' && trim($adv_name) != '') {
                        $merchantReportList[] = [
                            $adv_id,
                            $adv_name,
                            $adv_status,
                            $adv_relationship_status,
                            $adv_url,
                        ];
                    }

                }
                $page++;
            } while ($total_pages >= $page);
        }
        return $merchantReportList;
    }

    /**
     * @param null $merchantList
     * @param \DateTime|null $dStartDate
     * @param \DateTime|null $dEndDate
     * @return array
     * @throws Exception
     */
    public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null)
    {
        $totalTransactions = Array();
        $byMerchant = false;

        // Example of $merchantList parameter to filter only some merchants
        /*
        $merchantList = [
            ['cid' => '1234567', 'name' => 'Merchant xyz'],
            ['cid' => '7654321', 'name' => 'Merchant tzw']
        ];
        */


        if (!is_null($merchantList) && is_array($merchantList) && count($merchantList) > 0) {
            $a_merchants = \array_keys(\Oara\Utilities::getMerchantIdMapFromMerchantList($merchantList));
            $byMerchant = true;
        }

        if (!is_null($dStartDate) && !is_null($dEndDate)) {
            $validDates = true;
            $dStartDate->setTime(0, 0, 0, 0);
            $dEndDate->setTime(0, 0, 0, 0);

            $dStartDate->setTimezone(new \DateTimeZone('UTC'));
            $sinceDateISO = $dStartDate->format(DATE_ISO8601);
            $sinceDateISO = str_replace('+0000', 'Z', $sinceDateISO);
            $beforeDateISO = ((clone $dEndDate)->add(new \DateInterval('P1D')))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ISO8601);
            $beforeDateISO = str_replace('+0000', 'Z', $beforeDateISO);
        }
        else {
            // Get default dates
            $validDates = false;
        }

        $payloadComplete = false;
        $sinceCommissionId = null;
        $a_transactions = array();

        while(!$payloadComplete) {

            $query_header = 'publisherCommissions (forPublishers: ["#cid#"]';
            if (!empty($sinceCommissionId)) {
                // Get another data chunk
                $query_header .= ',sinceCommissionId: "' . $sinceCommissionId . '"';
            }
            if ($byMerchant) {
                $query_header .= ',advertiserIds: [';
                $separator = '';
                foreach($a_merchants as $cid) {
                    $query_header .= $separator . '"' . $cid . '"';
                    $separator = ',';
                }
                $query_header .= ']';
            }
            if ($validDates) {
                $query_header .= ',
                    sinceEventDate:"' . $sinceDateISO . '",
                    beforeEventDate:"' . $beforeDateISO . '"';
            }
            $query = '{' . $query_header . '){
                count 
                payloadComplete 
                records {
                    actionStatus actionTrackerId actionTrackerName actionType advertiserId advertiserName aid clickDate clickReferringURL commissionId concludingBrowser concludingDeviceName concludingDeviceType country coupon eventDate initiatingBrowser initiatingDeviceName initiatingDeviceType	isCrossDevice lockingDate orderDiscountAdvCurrency orderDiscountOrigCurrency orderDiscountPubCurrency orderId original originalActionId postingDate pubCommissionAmountPubCurrency pubCommissionAmountUsd publisherId publisherName reviewedStatus saleAmountPubCurrency shopperId siteToStoreOffer source websiteId websiteName 
                    items {
                        commissionItemId discountAdvCurrency discountPubCurrency discountUsd itemListId perItemSaleAmountAdvCurrency perItemSaleAmountPubCurrency perItemSaleAmountUsd quantity sku totalCommissionAdvCurrency totalCommissionPubCurrency totalCommissionUsd 
                    }
                }
            }}';

            // Execute the GrapQL Query and get json response
            $response = self::grapQLApiCall($query);

            if (isset($response->errors) && count($response->errors) > 0) {
                $error_message = $response->errors[0]->message;
                throw new \Exception("Error querying PublisherCommissions: " . $error_message);
            }
            if (isset($response->data)) {
                $publisherCommissions = $response->data->publisherCommissions;
                $count = $publisherCommissions->count;
                $payloadComplete = $publisherCommissions->payloadComplete;
                $sinceCommissionId = null;
                if ($payloadComplete != true) {
                    if (isset($publisherCommissions->maxCommissionId)) {
                        // Incomplete data ... set cursor start for next chunk
                        $sinceCommissionId = $publisherCommissions->maxCommissionId;
                    }
                }
                $records = $publisherCommissions->records;

                if ($count == 0) {
                    return $a_transactions;
                }

                // Scan records and get attributes
                for ($t = 0; $t < $count; $t++) {
                    $record = $records[$t];

                    $transaction = Array();
					$transaction['unique_id'] = $record->commissionId;
	                /**
	                 * Ref. to https://developers.cj.com/graphql/reference/Commission%20Detail
	                 * ActionType possible values: bonus, click, imp, item_lead, item_sale, perf_inc, sim_lead, sim_sale
	                 */
	                $transaction['action'] = $record->actionType;
	                if ($record->actionType == 'bonus') {
		                $transaction['action'] = \Oara\Utilities::TYPE_BONUS;
	                }
	                else if ($record->actionType == 'item_sale' || $record->actionType == 'sim_sale') {
		                $transaction['action'] = \Oara\Utilities::TYPE_SALE;
	                }
	                else if ($record->actionType == 'sim_lead' || $record->actionType == 'item_lead') {
		                $transaction['action'] = \Oara\Utilities::TYPE_LEAD;
	                }
	                else if ($record->actionType == 'click') {
		                $transaction['action'] = \Oara\Utilities::TYPE_CLICK;
	                }
	                else if ($record->actionType == 'imp') {
		                $transaction['action'] = \Oara\Utilities::TYPE_IMPRESSION;
	                }
	                else if ($record->actionType == 'perf_inc') {
		                $transaction['action'] = \Oara\Utilities::TYPE_PERFORMANCE_INCREASE;
	                }

                    $transaction['merchantId'] = $record->advertiserId;
                    //event-date - The associated event date for the item in UTC time zone.
                    $transactionDate = \DateTime::createFromFormat("Y-m-d\TH:i:sO", $record->eventDate);
                    $transaction['date'] = $transactionDate->format("Y-m-d H:i:sO");

                    $clickDate = \DateTime::createFromFormat("Y-m-d\TH:i:sO", $record->clickDate);
                    $transaction['click_date'] = $clickDate->format("Y-m-d H:i:sO");

                    $transaction['custom_id'] = '';
                    if (isset($record->shopperId)) {
                        $transaction['custom_id'] = $record->shopperId;
                    }
                    $transaction['amount'] = \Oara\Utilities::parseDouble($record->saleAmountPubCurrency);
                    $transaction['commission'] = \Oara\Utilities::parseDouble($record->pubCommissionAmountPubCurrency);

                    if ($record->actionStatus == 'locked' || $record->actionStatus == 'closed') {
                        $transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
                    } else if ($record->actionStatus == 'extended' || $record->actionStatus == 'new') {
                        $transaction['status'] = \Oara\Utilities::STATUS_PENDING;
                    } else if ($record->actionStatus == 'corrected') {
                        $transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
                    }

                    if ($transaction['commission'] == 0) {
                        $transaction['status'] = \Oara\Utilities::STATUS_PENDING;
                    }

                    /*
                    // Negative commission must be subtracted by original commission identified by the same 'original-action-id' field - 2018-07-13 <PN>
                    // Only if result is zero the commission could be set DECLINED. This logic must be implemented by the caller!
                    if ($transaction['amount'] < 0 || $transaction['commission'] < 0) {
                        $transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
                        $transaction['amount'] = \abs($transaction['amount']);
                        $transaction['commission'] = \abs($transaction['commission']);
                    }
                    */
                    $transaction['aid'] = $record->aid;
                    $transaction['order-id'] = $record->orderId;
                    $transaction['original'] = ($record->original == true);
                    // 'original-action-id' is used as reference field between original commission and adjust/correction commission - 2018-07-13 <PN>
                    $transaction['original-action-id'] = $record->originalActionId;
                    $transaction['voucher_code'] = $record->coupon;

                    // Add new record to return array
                    $a_transactions[] = $transaction;
                }
            } else {
                $payloadComplete = true;
            }
        }
        return $a_transactions;
    }

    /**
     * Execute e GrapQL API call and return json results
     * @param string $query
     * @return mixed
     */
    private function grapQLApiCall(string $query)
    {
        $url = "https://commissions.api.cj.com/query";
        $ch = curl_init();

        if (stripos($query, '#cid#') !== false) {
            // Replace placeholder with requestor cid
            $query = str_ireplace('#cid#', $this->_requestor_cid, $query);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, FALSE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer " . $this->_apiPassword));

        $curl_results = curl_exec($ch);
        curl_close($ch);
        return json_decode($curl_results);
    }

    /**
     * API Rest call
     * @param $url
     * @return bool|string
     */
    private function apiCall($url)
    {
        $ch = curl_init();
        if (!empty($this->_requestor_cid)) {
            if (strpos($url, 'requestor-cid') === false) {
                /**
                 * 2019-03-22 <PN>
                 * For new created accounts you cannot generate a new developer key, but only a PERSONAL ACCESS TOKEN.
                 * REST API could use the Personal Access Tokens by sending it in the header as "Authorization: Bearer XXXXXXX ... "
                 * The api call need a NEW MANDATORY PARAMETER called "requestor-cid" that represent the COMPANY ID in the CJ account dashboard.
                 */
                // Add cid parameter to url
                $pos = strpos($url, '?');
                if ($pos === false) {
                    // The only parameter
                    $url = $url . '?requestor-cid=' . $this->_requestor_cid;
                }
                else {
                    // Prepend to first parameter
                    $url = substr($url,0, $pos+1) . 'requestor-cid=' . $this->_requestor_cid . '&' . substr($url,$pos + 1);
                }
            }
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if (!empty($this->_requestor_cid)) {
            // 2019-03-22 <PN> see notes above
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer " . $this->_apiPassword));
        }
        else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: " . $this->_apiPassword));
        }
        $curl_results = curl_exec($ch);
        curl_close($ch);
        return $curl_results;
    }

    /**
     * @param $pid
     * @param null $merchantList
     * @param null $startDate
     * @return array
     */
    public function paymentTransactions($pid)
    {
        $transactionList = array();
        $invoices = $this->getPaymentHistory();
        for ($i = 0; $i < \count($invoices); $i++) {
            if ($invoices[$i]['pid'] == $pid) {
                $endDate = $invoices[$i]['date'];
                if (isset($invoices[$i + 1])) {
                    $startDate = $invoices[$i + 1]['date'];
                } else {
                    $startDate = \date("Y-m-d", \strtotime($invoices[i]['date']) - (90 * 60 * 60 * 24));
                }
                break;
            }
        }
        $startDate = \date("Y-m-d", \strtotime($startDate));
        $endDate = \date("Y-m-d", \strtotime($endDate));
        $exportReport = $this->_client->get(array(new \Oara\Curl\Request('https://members.cj.com/member/publisher/' . $this->_accountId . '/transactionReport.json?startDate=' . $startDate . '&endDate=' . $endDate . '&allowAllDateRanges=true&columnSort=amount%09DESC&startRow=1&endRow=1000', array())));
        $advertiserPaymentIds = array();
        foreach (\json_decode($exportReport[0])->{'records'}->{'record'} as $advertiser) {
            if (($advertiser->{'advertiserId'} != '-3') && (!in_array($advertiser->{'txnId'}, $advertiserPaymentIds))) {
                $advertiserPaymentIds[] = $advertiser->{'txnId'};
            }
        }
        foreach ($advertiserPaymentIds as $id) {
            $exportReport = $this->_client->get(array(new \Oara\Curl\Request('https://members.cj.com/member/publisher/' . $this->_accountId . '/commissionReport/detailForTransactionId.json?allowAllDateRanges=true&txnId=' . $id . '&columnSort=publisherCommission%09DESC&startRow=1&endRow=1000', array())));
            $transactions = \json_decode($exportReport[0])->{'records'}->{'record'};
            if (!isset($transactions->{'advertiserId'})) {
                foreach ($transactions as $transaction) {
                    $transactionList[] = $transaction->{'commissionId'};
                }
            } else {
                $transactionList[] = $transactions->{'commissionId'};
            }
        }
        return $transactionList;
    }

    /**
     * @return array
     */
    public function getPaymentHistory()
    {
        $paymentHistory = array();
        $urls = array();
        $urls[] = new \Oara\Curl\Request('https://members.cj.com/member/cj/publisher/paymentStatus', array());
        $exportReport = $this->_client->get($urls);
        if (\preg_match('/\/publisher\/getpublisherpaymenthistory\.do/', $exportReport[0], $matches)) {
            $urls = array();
            $valuesFromExport = array(new \Oara\Curl\Parameter('startRow', '0'),
                new \Oara\Curl\Parameter('sortKey', ''),
                new \Oara\Curl\Parameter('sortOrder', ''),
                new \Oara\Curl\Parameter('format', '6'),
                new \Oara\Curl\Parameter('button', 'Go')
            );
            $urls[] = new \Oara\Curl\Request('https://members.cj.com/member/' . $this->_memberId . '/publisher/getpublisherpaymenthistory.do?', $valuesFromExport);
            $exportReport = $this->_client->get($urls);
            $exportData = \str_getcsv($exportReport[0], "\n");
            $num = \count($exportData);
            for ($j = 1; $j < $num; $j++) {
                $paymentData = \str_getcsv($exportData[$j], ",");
                $obj = array();
                $date = \DateTime::createFromFormat("d-M-Y H:i \P\S\T", $paymentData[0]);
                if (!$date) {
                    $date = \DateTime::createFromFormat("d-M-Y H:i \P\D\T", $paymentData[0]);
                }
                $obj['date'] = $date->format("Y-m-d H:i:s");
                $obj['value'] = \Oara\Utilities::parseDouble($paymentData[1]);
                $obj['method'] = $paymentData[2];
                $obj['pid'] = $paymentData[6];
                $paymentHistory[] = $obj;
            }
        }
        return $paymentHistory;
    }
}
