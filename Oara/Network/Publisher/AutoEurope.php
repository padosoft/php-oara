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
 * @category   AutoEurope
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */
class AutoEurope extends \Oara\Network
{
    /**
     * @var null
     */
    private $_client = null;


    /**
     * @param $credentials
     */
    public function login($credentials)
    {
        $user = $credentials ['user'];
        $password = $credentials ['password'];
        $this->_client = new \Oara\Curl\Access ($credentials);

        $loginUrl = 'https://www.autoeurope.co.uk/afftools/index.cfm';
        $valuesLogin = array(
            new \Oara\Curl\Parameter ('action', 'runreport'),
            new \Oara\Curl\Parameter ('alldates', 'all'),
            new \Oara\Curl\Parameter ('membername', $user),
            new \Oara\Curl\Parameter ('affpass', $password),
            new \Oara\Curl\Parameter ('Post', 'Log-in')
        );

        $urls = array();
        $urls [] = new \Oara\Curl\Request ($loginUrl, array());
        $this->_client->get($urls);

        $urls = array();
        $urls [] = new \Oara\Curl\Request ($loginUrl, $valuesLogin);
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
     * @return bool
     */
    public function checkConnection()
    {
        $connection = false;
        $urls = array();
        $urls [] = new \Oara\Curl\Request ('https://www.autoeurope.co.uk/afftools/index.cfm', array());
        $exportReport = $this->_client->get($urls);
        if (\preg_match('/logout\.cfm/', $exportReport [0], $matches)) {
            $connection = true;
        }
        return $connection;
    }

    /**
     * @return array
     */
    public function getMerchantList()
    {
        $merchants = Array();
        $obj = Array();
        $obj ['cid'] = 1;
        $obj ['name'] = 'Auto Europe';
        $obj ['url'] = 'https://www.autoeurope.co.uk';
        $merchants [] = $obj;

        return $merchants;
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

        $dEndDate->add(new \DateInterval('P1D'));
        $valuesFormExport = array();
        $valuesFormExport [] = new \Oara\Curl\Parameter ('pDB', 'UK');
        $valuesFormExport [] = new \Oara\Curl\Parameter ('content', 'PDF');
        $valuesFormExport [] = new \Oara\Curl\Parameter ('pDate1', $dStartDate->format("m/j/Y"));
        $valuesFormExport [] = new \Oara\Curl\Parameter ('pDate2', $dEndDate->format("m/j/Y"));
        $urls = array();
        $urls [] = new \Oara\Curl\Request ('https://www.autoeurope.co.uk/afftools/iatareport_popup.cfm?', $valuesFormExport);
        $exportReport = $this->_client->post($urls);
        $xmlTransactionList = self::readTransactions($exportReport [0]);

        foreach ($xmlTransactionList as $xmlTransaction) {
            $transaction = array();
            $transaction ['merchantId'] = 1;
            $date = \DateTime::createFromFormat("m/d/Y", $xmlTransaction ['Booked']);
            $transaction ['date'] = $date->format("Y-m-d 00:00:00");
            $transaction ['amount'] = \Oara\Utilities::parseDouble(( double )$xmlTransaction ['commissionValue']);
            $transaction ['commission'] = \Oara\Utilities::parseDouble(( double )$xmlTransaction ['commission']);
            $transaction ['status'] = \Oara\Utilities::STATUS_CONFIRMED;
            $transaction ['unique_id'] = $xmlTransaction ['Res #'];
            if (isset ($xmlTransaction ['Affiliate1']) && isset ($xmlTransaction ['Affiliate2'])) {
                $customId = ( string )$xmlTransaction ['Affiliate1'] . ( string )$xmlTransaction ['Affiliate2'];
                $transaction ['custom_id'] = $customId;
            }
            $totalTransactions [] = $transaction;
        }
        return $totalTransactions;
    }


    /**
     * Read the html table in the report
     *
     * @param string $htmlReport
     * @param Zend_Date $startDate
     * @param Zend_Date $endDate
     * @param int $iteration
     * @return array:
     */
    public function readTransactions($htmlReport)
    {
        $pdfContent = '';
        $dom = new \Laminas\Dom\Query($htmlReport);
        $links = $dom->execute('.text a');
        $pdfUrl = null;
        foreach ($links as $link) {
            $pdfUrl = $link->getAttribute('href');
        }
        $urls = array();
        $urls [] = new \Oara\Curl\Request ($pdfUrl, array());
        $exportReport = $this->_client->get($urls);
        // writing temp pdf
        $exportReportUrl = \explode('/', $pdfUrl);
        $exportReportUrl = $exportReportUrl [\count($exportReportUrl) - 1];
        $dir = \realpath(\dirname(COOKIES_BASE_DIR)) . '/pdf/';
        $fh = \fopen($dir . $exportReportUrl, 'w');
        \fwrite($fh, $exportReport [0]);
        \fclose($fh);
        // parsing the pdf
        $pipes = null;
        $descriptorspec = array(
            0 => array(
                'pipe',
                'r'
            ),
            1 => array(
                'pipe',
                'w'
            ),
            2 => array(
                'pipe',
                'w'
            )
        );
        $pdfReader = \proc_open("pdftohtml -xml -stdout " . $dir . $exportReportUrl, $descriptorspec, $pipes, null, null);
        if (\is_resource($pdfReader)) {
            $pdfContent = '';
            $error = '';
            $stdin = $pipes [0];
            $stdout = $pipes [1];
            $stderr = $pipes [2];
            while (!\feof($stdout)) {
                $pdfContent .= \fgets($stdout);
            }
            while (!\feof($stderr)) {
                $error .= \fgets($stderr);
            }
            \fclose($stdin);
            \fclose($stdout);
            \fclose($stderr);
            \proc_close($pdfReader);
        }
        \unlink($dir . $exportReportUrl);
        $xml = new \SimpleXMLElement ($pdfContent);
        $list = $xml->xpath("page");
        $numberPages = count($list);
        $transationList = array();
        for ($page = 1; $page <= $numberPages; $page++) {
            $topHeader = null;
            $top = null;
            $list = $xml->xpath("page[@number=$page]/text[@font=0 and b = \"Agent\"]");
            if (\count($list) > 0) {
                $header = \current($list);
                $attributes = $header->attributes();
                $top = ( int )$attributes ['top'];
            } else {
                throw new \Exception ("No Header Found");
            }
            if ($top == null) {
                throw new \Exception ("No Top Found");
            }
            $fromTop = $top - 3;
            $toTop = $top + 3;
            $list = $xml->xpath("page[@number=$page]/text[@top>$fromTop and @top<$toTop and @font=0]");
            $headerList = array();
            foreach ($list as $header) {
                $xmlHeader = new \stdClass ();
                $attributes = $header->attributes();
                $xmlHeader->top = ( int )$attributes ['top'];
                $xmlHeader->left = ( int )$attributes ['left'];
                $xmlHeader->width = ( int )$attributes ['width'];
                foreach ($header->children() as $child) {
                    $xmlHeader->name = ( string )$child;
                }
                if (\strpos($xmlHeader->name, "commission") === false) {
                    $headerList [(int)$xmlHeader->left] = $xmlHeader;
                } else {
                    $xmlHeaderCommissionValue = new \stdClass ();
                    $xmlHeaderCommissionValue->top = $xmlHeader->top;
                    $xmlHeaderCommissionValue->left = $xmlHeader->left;
                    $xmlHeaderCommissionValue->width = 100;
                    $xmlHeaderCommissionValue->name = ( string )"commissionValue";
                    $xmlHeaderCommission = new \stdClass ();
                    $xmlHeaderCommission->top = $xmlHeader->top;
                    $xmlHeaderCommission->left = $xmlHeader->left + $xmlHeaderCommissionValue->width;
                    $xmlHeaderCommission->width = 150;
                    $xmlHeaderCommission->name = ( string )"commission";
                    $headerList [(int)$xmlHeaderCommissionValue->left] = $xmlHeaderCommissionValue;
                    $headerList [(int)$xmlHeaderCommission->left] = $xmlHeaderCommission;
                }
            }
            \ksort($headerList);
            $list = $xml->xpath("page[@number=$page]/text[@font=2]");
            $rowList = array();
            foreach ($list as $row) {
                $attributes = $row->attributes();
                $top = ( int )$attributes ['top'];
                if (!\in_array($top, $rowList)) {
                    $rowList [] = $top;
                }
            }
            foreach ($rowList as $top) {
                $transaction = array();
                $list = $xml->xpath("page[@number=$page]/text[@top=$top and @font=2]");
                foreach ($list as $value) {
                    $attributes = $value->attributes();
                    $fromLeft = ( int )$attributes ['left'];
                    $toLeft = ( int )($attributes ['left'] + $attributes ['width']);
                    foreach ($headerList as $header) {
                        $headerFromLeft = $header->left;
                        $headerToLeft = $header->left + $header->width;
                        $between1 = self::between((int)$headerFromLeft, (int)$headerToLeft, (int)$fromLeft);
                        $between2 = self::between((int)$headerFromLeft, (int)$headerToLeft, (int)$toLeft);
                        $between3 = self::between((int)$fromLeft, (int)$toLeft, (int)$headerFromLeft);
                        $between4 = self::between((int)$fromLeft, (int)$toLeft, (int)$headerToLeft);
                        if ($between1 || $between2 || $between3 || $between4) {
                            $transaction [$header->name] = ( string )$value;
                            break;
                        }
                    }
                }
                $transationList [] = $transaction;
            }
        }
        return $transationList;
    }

    function between($from, $to, $value)
    {
        return $from <= $value && $value <= $to;
    }
}
