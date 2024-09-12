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
 * @category   Smg
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */
class Smg extends \Oara\Network
{
    private $_credentials = null;
    private $_accountSid = null;
    private $_authToken = null;

    /**
     * @param $credentials
     * @throws Exception
     * @throws \Exception
     * @throws \Oara\Curl\Exception
     */
    public function login($credentials)
    {

        $this->_credentials = $credentials;
        $this->_client = new \Oara\Curl\Access($credentials);

        $user = $this->_credentials['user'];
        $password = $this->_credentials['password'];
        $loginUrl = 'https://app.impact.com/secure/login.user';

        $valuesLogin = array(new \Oara\Curl\Parameter('j_username', $user),
            new \Oara\Curl\Parameter('j_password', $password)
        );

        $urls = array();
        $urls[] = new \Oara\Curl\Request($loginUrl, $valuesLogin);
        $this->_client->post($urls);


        $urls = array();
        $urls[] = new \Oara\Curl\Request('https://app.impact.com/secure/mediapartner/accountSettings/mp-wsapi-flow.ihtml?', array());
        $exportReport = $this->_client->get($urls);
        $dom = new \Laminas\Dom\Query($exportReport[0]);
        $results = $dom->execute('div .uitkFields');
        $count = \count($results);
        if ($count == 0) {

            $activeAPI = array(new \Oara\Curl\Parameter('_eventId', "activate"));
            $urls = array();
            $urls[] = new \Oara\Curl\Request('https://app.impact.com/secure/mediapartner/accountSettings/mp-wsapi-flow.ihtml?', $activeAPI);
            $this->_client->post($urls);

            $urls = array();
            $urls[] = new \Oara\Curl\Request('https://app.impact.com/secure/mediapartner/accountSettings/mp-wsapi-flow.ihtml?', array());
            $exportReport = $this->_client->get($urls);
            $dom = new \Laminas\Dom\Query($exportReport[0]);
            $results = $dom->execute('div .uitkFields');
            $count = \count($results); // get number of matches: 4
            if ($count == 0) {
                throw new \Exception ("No API credentials");
            }
        }
        $i = 0;
        foreach ($results as $result) {
            if ($i == 0) {
                $this->_accountSid = \str_replace(array("\n", "\t", " "), "", $result->nodeValue);
            } else if ($i == 1) {
                $this->_authToken = \str_replace(array("\n", "\t", " "), "", $result->nodeValue);
            }
            $i++;
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
        $connection = false;

        //Checking connection for the impact Radius website
        $urls = array();
        $urls[] = new \Oara\Curl\Request('https://app.impact.com/secure/mediapartner/home/pview.ihtml', array());
        $exportReport = $this->_client->get($urls);
        $newCheck = false;
        if (\preg_match('/\/logOut\.user/', $exportReport[0], $match)) {
            $newCheck = true;
        }

        $newApi = false;
        if ($newCheck && $this->_authToken != null && $this->_accountSid != null) {
            //Checking API connection from Impact Radius
            $uri = "https://" . $this->_accountSid . ":" . $this->_authToken . "@api.impactradius.com/2010-09-01/Mediapartners/" . $this->_accountSid . "/Campaigns.xml";
            $res = \simplexml_load_file($uri);
            if (isset($res->Campaigns)) {
                $newApi = true;
            }

        }

        if ($newCheck && $newApi) {
            $connection = true;
        }

        return $connection;
    }

    /**
     * @return array
     */
    public function getMerchantList()
    {
        $merchantReportList = self::getMerchantReportList();
        $merchants = Array();
        foreach ($merchantReportList as $key => $value) {
            $obj = Array();
            $obj['cid'] = $key;
            $obj['name'] = $value;
            $merchants[] = $obj;
        }

        return $merchants;
    }

    /**
     * It returns an array with the different merchants
     * @return array
     */
    private function getMerchantReportList()
    {
        $uri = "https://" . $this->_accountSid . ":" . $this->_authToken . "@api.impactradius.com/2010-09-01/Mediapartners/" . $this->_accountSid . "/Campaigns.xml";
        $res = \simplexml_load_file($uri);
        $currentPage = (int)$res->Campaigns->attributes()->page;
        $pageNumber = (int)$res->Campaigns->attributes()->numpages;
        while ($currentPage <= $pageNumber) {

            foreach ($res->Campaigns->Campaign as $campaign) {
                $campaignId = (int)$campaign->CampaignId;
                $campaignName = (string)$campaign->CampaignName;
                $merchantReportList[$campaignId] = $campaignName;
            }

            $currentPage++;
            $nextPageUri = (string)$res->Campaigns->attributes()->nextpageuri;
            if ($nextPageUri != null) {
                $res = \simplexml_load_file("https://" . $this->_accountSid . ":" . $this->_authToken . "@api.impactradius.com" . $nextPageUri);
            }
        }
        return $merchantReportList;
    }

    /**
     * @param null $merchantList
     * @param \DateTime|null $dStartDate
     * @param \DateTime|null $dEndDate
     * @return array
     */
    public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null)
    {
        $totalTransactions = Array();

        //New Interface
        $uri = "https://" . $this->_accountSid . ":" . $this->_authToken . "@api.impactradius.com/2010-09-01/Mediapartners/" . $this->_accountSid . "/Actions?ActionDateStart=" . $dStartDate->format('Y-m-d\TH:i:s') . "-00:00&ActionDateEnd=" . $dEndDate->format('Y-m-d\TH:i:s') . "-00:00";
        $res = \simplexml_load_file($uri);
        if ($res) {

            $currentPage = (int)$res->Actions->attributes()->page;
            $pageNumber = (int)$res->Actions->attributes()->numpages;
            while ($currentPage <= $pageNumber) {

                foreach ($res->Actions->Action as $action) {
                    $transaction = Array();
                    $transaction['merchantId'] = (int)$action->CampaignId;

                    $transactionDate = \DateTime::createFromFormat("Y-m-d\TH:i:s", \substr((string)$action->EventDate,0,19));
                    $transaction['date'] = $transactionDate->format("Y-m-d H:i:s");

                    $transaction['unique_id'] = (string)$action->Id;
                    if ((string)$action->SharedId != '') {
                        $transaction['custom_id'] = (string)$action->SharedId;
                    }
                    if ((string)$action->SubId1 != '') {
                        $transaction['custom_id'] = (string)$action->SubId1;
                    }

                    $status = (string)$action->State;
                    $statusArray[$status] = "";
                    if ($status == 'APPROVED' || $status == 'DEFAULT') {
                        $transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
                    } else {
                        if ($status == 'REVERSED' || $status == 'REJECTED') {
                            $transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
                        } else {
                            $transaction['status'] = \Oara\Utilities::STATUS_PENDING;
                        }
                    }

                    $transaction['amount'] = (double)$action->Amount;
                    $transaction['commission'] = (double)$action->Payout;
                    $totalTransactions[] = $transaction;
                }

                $currentPage++;
                $nextPageUri = (string)$res->Actions->attributes()->nextpageuri;
                if ($nextPageUri != null) {
                    $res = \simplexml_load_file("https://" . $this->_accountSid . ":" . $this->_authToken . "@api.impactradius.com" . $nextPageUri);
                }
            }
        }
        return $totalTransactions;

    }

    /**
     * @return array
     * @throws Exception
     */
    public function getPaymentHistory()
    {
        $paymentHistory = array();

        $urls = array();
        $urls[] = new \Oara\Curl\Request('https://app.impact.com/secure/nositemesh/accounting/getPayStubParamsCSV.csv', array());
        $exportReport = $this->_client->get($urls);
        $exportData = \str_getcsv($exportReport[0], "\n");

        $num = \count($exportData);
        for ($i = 1; $i < $num; $i++) {
            $paymentExportArray = \str_getcsv($exportData[$i], ",");
            $obj = array();
            $date = \DateTime::createFromFormat("M d, Y", $paymentExportArray[1]);
            $obj['date'] = $date->format("y-m-d H:i:s");
            $obj['pid'] = $paymentExportArray[0];
            $obj['method'] = 'BACS';
            $obj['value'] = \Oara\Utilities::parseDouble($paymentExportArray[6]);
            $paymentHistory[] = $obj;
        }
        return $paymentHistory;
    }

}
