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
 * @category   AvantLink
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */
class AvantLink extends \Oara\Network
{

    protected $_domain = null;
    private $_id = null;
    private $_apikey = null;

    /**
     * Constructor and Login
     * @param $credentials
     * @return ShareASale
     */
    public function login($credentials)
    {

        $user = $credentials ['user'];
        $password = $credentials ['password'];
        $this->_client = new \Oara\Curl\Access ($credentials);

        $valuesLogin = array(
            new \Oara\Curl\Parameter ('strLoginType', 'affiliate'),
            new \Oara\Curl\Parameter ('cmdLogin', 'Login'),
            new \Oara\Curl\Parameter ('loginre', ''),
            new \Oara\Curl\Parameter ('email', $user),
            new \Oara\Curl\Parameter ('password', $password),
            new \Oara\Curl\Parameter ('intScreenResWidth', '1920'),
            new \Oara\Curl\Parameter ('intScreenResHeight', '1080')
        );
        $urls = array();
        $urls [] = new \Oara\Curl\Request ("https://www.".$this->_domain . "/signin", $valuesLogin);
        $this->_client->post($urls);

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
     * Check the connection
     */
    public function checkConnection()
    {
        $connection = false;

        $urls = array();
        $urls [] = new \Oara\Curl\Request ("https://classic.".$this->_domain .'/affiliate/view_edit_auth_key.php', array());
        $result = $this->_client->get($urls);
        if (\preg_match("/<p><strong>Affiliate ID:<\/strong> (.*)?<\/p>/", $result [0], $matches)) {
            $this->_id = $matches[1];
            if (\preg_match("/<p><strong>API Authorization Key:<\/strong> (.*)?<\/p>/", $result [0], $matches)) {
                $this->_apikey = $matches[1];
                $connection = true;
            }

        }
        return $connection;
    }

    /**
     * @return array
     */
    public function getMerchantList()
    {

        $merchants = array();

        $params = array(
            new \Oara\Curl\Parameter ('cmdDownload', 'Download All Active Merchants'),
            new \Oara\Curl\Parameter ('strRelationStatus', 'active')
        );

        $urls = array();
        $urls [] = new \Oara\Curl\Request ("https://classic.".$this->_domain . '/affiliate/merchants.php', $params);
        $result = $this->_client->post($urls);
        $folder = \realpath(\dirname(COOKIES_BASE_DIR)) . '/pdf/';
        $my_file = $folder . \mt_rand() . '.xls';

        $handle = \fopen($my_file, 'w') or die('Cannot open file:  ' . $my_file);
        $data = $result[0];
        \fwrite($handle, $data);
        \fclose($handle);

        $objReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Excel5');
        $objReader->setReadDataOnly(true);
        $objPHPExcel = $objReader->load($my_file);
        $objWorksheet = $objPHPExcel->getActiveSheet();
        $highestRow = $objWorksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; ++$row) {
            $obj = Array();
            $obj['cid'] = $objWorksheet->getCellByColumnAndRow(0, $row)->getValue();
            $obj['name'] = $objWorksheet->getCellByColumnAndRow(1, $row)->getValue();
            $merchants[] = $obj;
        }
        unlink($my_file);

        return $merchants;
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
        $affiliate_id = $this->_id;
        $auth_key = $this->_apikey;

        $merchantIdList = \Oara\Utilities::getMerchantIdMapFromMerchantList($merchantList);

        $strUrl = "https://classic.".$this->_domain .'/api.php';
        $strUrl .= "?affiliate_id=$affiliate_id";
        $strUrl .= "&auth_key=$auth_key";
        $strUrl .= "&module=AffiliateReport";
        $strUrl .= "&output=" . \urlencode('csv');
        $strUrl .= "&report_id=8";
        $strUrl .= "&date_begin=" . \urlencode($dStartDate->format("Y-m-d H:i:s"));
        $strUrl .= "&date_end=" . \urlencode($dEndDate->format("Y-m-d H:i:s"));
        $strUrl .= "&include_inactive_merchants=0";
        $strUrl .= "&search_results_include_cpc=0";

        $returnResult = self::makeCall($strUrl);
        $exportData = \str_getcsv($returnResult, "\r\n");
        $num = \count($exportData);
        for ($i = 1; $i < $num; $i++) {
            $transactionExportArray = \str_getcsv($exportData[$i], ",");
            if (\count($transactionExportArray) > 1 && isset($merchantIdList[(int)$transactionExportArray[17]])  ) {
                $transaction = Array();
                $merchantId = (int)$transactionExportArray[17];
                $transaction['merchantId'] = $merchantId;
                $transactionDate = \DateTime::createFromFormat("m-d-Y H:i:s", $transactionExportArray[11]);
                $transaction['date'] = $transactionDate->format("Y-m-d H:i:s");
                $transaction['unique_id'] = (int)$transactionExportArray[5];

                if ($transactionExportArray[4] != null) {
                    $transaction['custom_id'] = $transactionExportArray[4];
                }

                $transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
                $transaction['amount'] = \Oara\Utilities::parseDouble($transactionExportArray[6]);
                $transaction['commission'] = \Oara\Utilities::parseDouble($transactionExportArray[7]);
                $totalTransactions[] = $transaction;
            }
        }
        return $totalTransactions;
    }

    /**
     * @param $strUrl
     * @return mixed
     */
    private function makeCall($strUrl)
    {

        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $strUrl);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        $returnResult = \curl_exec($ch);
        \curl_close($ch);
        return $returnResult;
    }
}
