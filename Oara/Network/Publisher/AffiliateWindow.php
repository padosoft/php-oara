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
 * @category   AffiliateWindow
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */
class AffiliateWindow extends \Oara\Network
{
    /**
     * Soap client.
     */
    private $_apiClient = null;
    private $_exportClient = null;
    private $_pageSize = 100;
    private $_currency = null;
    private $_userId = null;
    private $_timeZone = "Europe/Berlin"; // <slawn>
    public $_sitesAllowed = array();
    public $_credentials = array();

    /**
     * @param $credentials
     * @throws \Exception
     * @throws \Oara\Curl\Exception
     */
    public function login($credentials)
    {
        ini_set('default_socket_timeout', '120');

        $this->_credentials = $credentials;

        $this->_userId = $credentials['accountid'];
        $password = $credentials['apipassword'];
        $this->_timeZone = (isset($credentials ['timeZone'])) ? $credentials ['timeZone'] : "Europe/Berlin";
        $this->_exportClient = new \Oara\Curl\Access($credentials);
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
        $parameter["description"] = "User Password";
        $parameter["required"] = true;
        $parameter["name"] = "Password";
        $credentials["password"] = $parameter;

        $parameter = array();
        $parameter["description"] = "PublisherService API password";
        $parameter["required"] = true;
        $parameter["name"] = "API password";
        $credentials["apipassword"] = $parameter;

        $parameter = array();
        $parameter["description"] = "Currency code for reporting";
        $parameter["required"] = false;
        $parameter["name"] = "Currency";
        $credentials["currency"] = $parameter;

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
     * Get list of Merchants (with a relationship i.e. approved)
     * @return array
     */
    public function getMerchantList()
    {
        $merchants = array();

        try {
            $id = $this->_credentials["accountid"];
            $pwd = $this->_credentials["apipassword"];
            $a_status = ['notjoined','joined','pending','suspended','rejected'];
            foreach($a_status as $status) {
                // Get single status programs
                $merchantList = array();
                $urls = [];
                $urls[] = new \Oara\Curl\Request('https://api.awin.com/publishers/' . $id . '/programmes/?relationship=' . $status . '&accessToken=' . $pwd, array());
                $result = $this->_exportClient->get($urls);
                if ($result === false || !is_array($result)) {
                    throw new \Exception("php-oara AffiliateWindow - file_get_contents error");
                } else {
                    $content = mb_convert_encoding($result[0], 'UTF-8', 'ISO-8859-1');
                    $merchantList = \json_decode($content);
                }
                foreach ($merchantList as $merchant) {
                    $obj = array();
                    $obj['cid'] = $merchant->id;
                    $obj['name'] = $merchant->name;
                    $obj['url'] = $merchant->displayUrl;
                    $obj['status'] = $status;
                    $merchants[] = $obj;
                }
            }
        } catch (\Exception $e) {
            echo "oara step5 :".$e->getMessage()."\n ";
            throw new \Exception($e);
        }
        return $merchants;
    }

    /**
     * Get list of Vouchers / Coupons / Offers
     * @param $apiKey   Api Key is needed to access data feed
     * @return array
     */
    public function getVouchers($apiKey)
    {
        $vouchers = array();

        try {

            $id = $this->_credentials["accountid"];
            $params = array(
                new \Oara\Curl\Parameter('promotionType', 'voucher'),
                new \Oara\Curl\Parameter('categoryIds', ''),
                new \Oara\Curl\Parameter('regionIds', ''),
                new \Oara\Curl\Parameter('advertiserIds', ''),
                new \Oara\Curl\Parameter('membershipStatus', ''),
                new \Oara\Curl\Parameter('promotionStatus', ''),
            );

            $urls[] = new \Oara\Curl\Request('https://ui.awin.com/export-promotions/' . $id . '/' . $apiKey . '?', $params);
            $result = $this->_exportClient->get($urls);
            if ($result === false || !is_array($result))
            {
                throw new \Exception("php-oara AffiliateWindow getVouchers - http error");
            } else {
                $vouchers = \str_getcsv($result[0], "\n");
            }
        } catch (\Exception $e) {
            echo "AffiliateWindow getVouchers error:".$e->getMessage()."\n ";
            throw new \Exception($e);
        }
        return $vouchers;
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

        try {
            $id = $this->_credentials["accountid"];
            $pwd = $this->_credentials["apipassword"];
            $dStartDate_ = $dStartDate->format("Y-m-d");
            $dStartTime_ = "00:00:00";
	        $dEndDate_ = $dEndDate->format("Y-m-d");
	        if ($dEndDate_ == date("Y-m-d")){
		        $dEndTime_ = date("H:i:s");
	        }
	        else{
		        $dEndTime_ = "23:59:59";
	        }
            $dEndDate = urlencode($dEndDate_ . "T" . $dEndTime_);
            $dStartDate = urlencode($dStartDate_ . "T" . $dStartTime_);
            $timezone = urlencode($this->_timeZone);
            //$url = 'https://api.awin.com/publishers/'.$id.'/transactions/?accessToken='.$pwd.'&startDate=2017-02-20T00%3A00%3A00&endDate=2017-02-21T01%3A59%3A59&timezone=Europe/Berlin';
            $url = 'https://api.awin.com/publishers/' . $id . '/transactions/?accessToken=' . $pwd . '&startDate=' . $dStartDate . '&endDate=' . $dEndDate . '&timezone=' . $timezone;
            $result = \file_get_contents($url);
            //var_dump($result);
            if ($result === false)
            {
                //echo "oara step2<br> ";
                throw new \Exception("php-oara AffiliateWindow - file_get_contents is false");
            } else {
                //echo "oara step3<br> ";
                $content = mb_convert_encoding($result, 'UTF-8', 'ISO-8859-1');
                $transactionObjectFull = \json_decode($content);
                // <slawn> 2018-10-18
                foreach($transactionObjectFull as $transactionObject){
                    $transaction = Array();
                    $transaction['unique_id'] = $transactionObject->id;
                    $transaction['merchantId'] = $transactionObject->advertiserId;
                    $date = new \DateTime($transactionObject->transactionDate);
                    $transaction['date'] = $date->format("Y-m-d H:i:s");
                    $transaction['custom_id'] = '';
                    if (is_object($transactionObject->clickRefs)) {
                        if (property_exists($transactionObject->clickRefs,'clickRef') && $transactionObject->clickRefs->clickRef != null && $transactionObject->clickRefs->clickRef != 0)
                            $transaction['custom_id'] = $transactionObject->clickRefs->clickRef;
                        else if (property_exists($transactionObject->clickRefs,'clickRef2') && $transactionObject->clickRefs->clickRef2 != null && $transactionObject->clickRefs->clickRef2 != 0)
                            $transaction['custom_id'] = $transactionObject->clickRefs->clickRef2;
                        else if (property_exists($transactionObject->clickRefs,'clickRef3') && $transactionObject->clickRefs->clickRef3 != null && $transactionObject->clickRefs->clickRef3 != 0)
                            $transaction['custom_id'] = $transactionObject->clickRefs->clickRef3;
                        else if (property_exists($transactionObject->clickRefs,'clickRef4') && $transactionObject->clickRefs->clickRef4 != null && $transactionObject->clickRefs->clickRef4 != 0)
                            $transaction['custom_id'] = $transactionObject->clickRefs->clickRef4;
                        else if (property_exists($transactionObject->clickRefs,'clickRef5') && $transactionObject->clickRefs->clickRef5 != null && $transactionObject->clickRefs->clickRef5 != 0)
                            $transaction['custom_id'] = $transactionObject->clickRefs->clickRef5;
                        else if (property_exists($transactionObject->clickRefs,'clickRef6') && $transactionObject->clickRefs->clickRef6 != null && $transactionObject->clickRefs->clickRef6 != 0)
                            $transaction['custom_id'] = $transactionObject->clickRefs->clickRef6;
                    }
                    $transaction['type'] = $transactionObject->type;
                    $transaction['status'] = \Oara\Utilities::STATUS_PENDING;
                    if ($transactionObject->commissionStatus == 'approved') {
                        $transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
                    } else if ($transactionObject->commissionStatus == 'pending') {
                        $transaction['status'] = \Oara\Utilities::STATUS_PENDING;
                    } else if ($transactionObject->commissionStatus == 'declined') {
                        $transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
                    }
                    $transaction['amount'] = \Oara\Utilities::parseDouble($transactionObject->saleAmount->amount);
                    $transaction['commission'] = \Oara\Utilities::parseDouble($transactionObject->commissionAmount->amount);
                    $transaction['currency'] = $transactionObject->commissionAmount->currency;

                    $totalTransactions[] = $transaction;
                }                
            }
        } catch (\Exception $e) {
            echo "oara step5 :".$e->getMessage()."\n ";
            throw new \Exception($e);
        }
        return $totalTransactions;
    }

    /**
     * @param $rowAvailable
     * @param $rowsReturned
     * @return int
     */
    private function getIterationNumber($rowAvailable, $rowsReturned)
    {
        $iterationDouble = (double)($rowAvailable / $rowsReturned);
        $iterationInt = (int)($rowAvailable / $rowsReturned);
        if ($iterationDouble > $iterationInt) {
            $iterationInt++;
        }
        return $iterationInt;
    }

    /**
     * @return array
     */
    public function getPaymentHistory()
    {
        $paymentHistory = array();

        $urls = array();
        $urls[] = new \Oara\Curl\Request("https://darwin.affiliatewindow.com/awin/affiliate/" . $this->_userId . "/payments/history?", array());
        $exportReport = $this->_exportClient->get($urls);


        $doc = new \DOMDocument();
        @$doc->loadHTML($exportReport[0]);
        $xpath = new \DOMXPath($doc);
        $results = $xpath->query('//table/tbody/tr');

        $finished = false;
        while (!$finished) {
            foreach ($results as $result) {
                $linkList = $result->getElementsByTagName('a');
                if ($linkList->length > 0) {
                    $obj = array();
                    $date = \DateTime::createFromFormat('j M Y', $linkList->item(0)->nodeValue);
                    $date->setTime(0, 0);
                    $obj['date'] = $date->format("Y-m-d H:i:s");
                    $attrs = $linkList->item(0)->attributes;
                    foreach ($attrs as $attrName => $attrNode) {
                        if ($attrName = 'href') {
                            $parseUrl = \trim($attrNode->nodeValue);
                            if (\preg_match("/\/paymentId\/(.+)/", $parseUrl, $matches)) {
                                $obj['pid'] = $matches[1];
                            }
                        }
                    }

                    $obj['value'] = \Oara\Utilities::parseDouble($linkList->item(3)->nodeValue);
                    $obj['method'] = trim($linkList->item(2)->nodeValue);
                    $paymentHistory[] = $obj;
                }
            }

            $results = $xpath->query("//span[@id='nextPage']");
            if ($results->length > 0) {
                foreach ($results as $nextPageLink) {
                    $linkList = $nextPageLink->getElementsByTagName('a');
                    $attrs = $linkList->item(0)->attributes;
                    $nextPageUrl = null;
                    foreach ($attrs as $attrName => $attrNode) {
                        if ($attrName = 'href') {
                            $nextPageUrl = trim($attrNode->nodeValue);
                        }
                    }
                    $urls = array();
                    $urls[] = new \Oara\Curl\Request("https://darwin.affiliatewindow.com" . $nextPageUrl, array());
                    $exportReport = $this->_exportClient->get($urls);
                    $doc = new \DOMDocument();
                    @$doc->loadHTML($exportReport[0]);
                    $xpath = new \DOMXPath($doc);
                    $results = $xpath->query('//table/tbody/tr');
                }
            } else {
                $finished = true;
            }
        }
        return $paymentHistory;
    }

    /**
     * @param $paymentId
     * @return array
     */
    public function paymentTransactions($paymentId)
    {
        $transactionList = array();
        $urls = array();
        $urls[] = new \Oara\Curl\Request("https://darwin.affiliatewindow.com/awin/affiliate/" . $this->_userId . "/payments/download/paymentId/" . $paymentId, array());
        $exportReport = $this->_exportClient->get($urls);
        $exportData = \str_getcsv($exportReport[0], "\n");
        $num = \count($exportData);
        $header = \str_getcsv($exportData[0], ",");
        $index = \array_search("Transaction ID", $header);
        for ($j = 1; $j < $num; $j++) {
            $transactionArray = \str_getcsv($exportData[$j], ",");
            $transactionList[] = $transactionArray[$index];
        }
        return $transactionList;
    }

    /**
     * Get list of Advertisers
     * @param $apiKey   Api Key is needed to access data feed
     * @return array
     */
    public function getAdvertisers($apiKey)
    {
        $advList = array();

        try {
            $urls[] = new \Oara\Curl\Request('https://productdata.awin.com/datafeed/list/apikey/' . $apiKey, array());
            $result = $this->_exportClient->get($urls);
            if ($result === false || !is_array($result))
            {
                throw new \Exception("php-oara AffiliateWindow getAdvertisers - http error");
            } else {
                $content = mb_convert_encoding($result[0], 'UTF-8', 'ISO-8859-1');;
                $advList = \str_getcsv($content, "\n");
            }
        } catch (\Exception $e) {
            echo "AffiliateWindow getAdvertisers error:".$e->getMessage()."\n ";
            throw new \Exception($e);
        }
        return $advList;
    }

    public function getProducts($apiKey, $feedId)
    {
        $products = array();

        if (empty($apiKey) || !is_numeric($feedId)) {
            return $products;
        }

        try {
            $options = $this->_exportClient->getOptions();
            $options[CURLOPT_ENCODING] = 'GZIP';
            $this->_exportClient->setOptions($options);

            $urls[] = new \Oara\Curl\Request('http://datafeed.api.productserve.com/datafeed/download/apikey/' . $apiKey . '/fid/' . $feedId . '/format/csv/language/en/delimiter/%2C/compression/gzip/adultcontent/1/columns/aw_deep_link%2Cproduct_name%2Caw_product_id%2Cmerchant_product_id%2Cmerchant_image_url%2Cdescription%2Cmerchant_category%2Csearch_price%2Cmerchant_name%2Cmerchant_id%2Ccategory_name%2Ccategory_id%2Caw_image_url%2Ccurrency%2Cstore_price%2Cdelivery_cost%2Cmerchant_deep_link%2Clanguage%2Clast_updated%2Cbrand_name%2Cbrand_id%2Ccolour%2Cproduct_short_description%2Cspecifications%2Ccondition%2Cproduct_model%2Cmodel_number%2Cdimensions%2Ckeywords%2Cpromotional_text%2Cproduct_type%2Ccommission_group%2Cmerchant_product_category_path%2Cmerchant_product_second_category%2Cmerchant_product_third_category%2Crrp_price%2Csaving%2Csavings_percent%2Cbase_price%2Cbase_price_amount%2Cbase_price_text%2Cproduct_price_old%2Cdelivery_restrictions%2Cdelivery_weight%2Cwarranty%2Cterms_of_contract%2Cdelivery_time%2Cin_stock%2Cstock_quantity%2Cvalid_from%2Cvalid_to%2Cis_for_sale%2Cweb_offer%2Cpre_order%2Cstock_status%2Csize_stock_status%2Csize_stock_amount%2Cmerchant_thumb_url%2Clarge_image%2Calternate_image%2Caw_thumb_url%2Calternate_image_two%2Calternate_image_three%2Creviews%2Caverage_rating%2Crating%2Cnumber_stars%2Cnumber_available%2Ccustom_1%2Ccustom_2%2Ccustom_3%2Ccustom_4%2Ccustom_5%2Ccustom_6%2Ccustom_7%2Ccustom_8%2Ccustom_9%2Cean%2Cisbn%2Cupc%2Cmpn%2Cparent_product_id%2Cproduct_GTIN%2Cbasket_link/', array());
            $result = $this->_exportClient->get($urls);
            if ($result === false || !is_array($result))
            {
                throw new \Exception("php-oara AffiliateWindow getProducts - http error");
            } else {
                $content = gzinflate(substr($result[0],10));
                $products = \str_getcsv($content, "\n");
            }
        } catch (\Exception $e) {
            echo "php-oara AffiliateWindow getProducts error:".$e->getMessage()."\n ";
            throw new \Exception($e);
        }
        return $products;
    }

}
