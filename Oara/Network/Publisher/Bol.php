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
 * @category   Bol
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */
class Bol extends \Oara\Network
{

    private $_client = null;

    /**
     * @param $credentials
     */
    public function login($credentials)
    {
        $user = $credentials['user'];
        $password = $credentials['password'];

        $this->_client = new \Oara\Curl\Access($credentials);

        $valuesLogin = array(
            new \Oara\Curl\Parameter('j_username', $user),
            new \Oara\Curl\Parameter('j_password', $password)
        );

        $loginUrl = 'https://partnerprogramma.bol.com/partner/j_security_check';
        $urls = array();
        $urls[] = new \Oara\Curl\Request($loginUrl, $valuesLogin);
        $this->_client->post($urls);

    }

    /**
     * @return bool
     */
    public function checkConnection()
    {
        //If not login properly the construct launch an exception
        $connection = false;
        $urls = array();
        $urls[] = new \Oara\Curl\Request('https://partnerprogramma.bol.com/partner/index.do?', array());
        $exportReport = $this->_client->get($urls);

        if (\preg_match('/partner\/logout\.do/', $exportReport[0], $match)) {
            $connection = true;
        }
        return $connection;
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
     * @return array
     */
    public function getMerchantList()
    {
        $merchants = array();

        $obj = array();
        $obj['cid'] = "1";
        $obj['name'] = "Bol.com";
        $merchants[] = $obj;

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
        $folder = \realpath(\dirname(COOKIES_BASE_DIR)) . '/pdf/';
        $totalTransactions = array();
        $valuesFromExport = array();
        $valuesFromExport[] = new \Oara\Curl\Parameter('id', "-1");
        $valuesFromExport[] = new \Oara\Curl\Parameter('yearStart', $dStartDate->format("Y"));
        $valuesFromExport[] = new \Oara\Curl\Parameter('monthStart', $dStartDate->format("m"));
        $valuesFromExport[] = new \Oara\Curl\Parameter('dayStart', $dStartDate->format("d"));
        $valuesFromExport[] = new \Oara\Curl\Parameter('yearEnd', $dEndDate->format("Y"));
        $valuesFromExport[] = new \Oara\Curl\Parameter('monthEnd', $dEndDate->format("m"));
        $valuesFromExport[] = new \Oara\Curl\Parameter('dayEnd', $dEndDate->format("d"));

        $urls = array();
        $urls[] = new \Oara\Curl\Request('https://partnerprogramma.bol.com/partner/s/excelReport/orders?', $valuesFromExport);
        $exportReport = $this->_client->get($urls);

        $my_file = $folder . \mt_rand() . '.xlsx';
        $handle = \fopen($my_file, 'w') or die('Cannot open file:  ' . $my_file);
        $data = $exportReport[0];
        \fwrite($handle, $data);
        \fclose($handle);

        $objReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Excel2007');
        $objReader->setReadDataOnly(true);
        $objPHPExcel = $objReader->load($my_file);
        $objWorksheet = $objPHPExcel->getActiveSheet();
        $highestRow = $objWorksheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; ++$row) {


            $transaction = Array();
            $transaction['unique_id'] = $objWorksheet->getCellByColumnAndRow(0, $row)->getValue() . "_" . $objWorksheet->getCellByColumnAndRow(1, $row)->getValue();
            $transaction['merchantId'] = "1";
            $transactionDate = \DateTime::createFromFormat("d-m-Y", $objWorksheet->getCellByColumnAndRow(2, $row)->getValue());
            $transaction['date'] = $transactionDate->format("Y-m-d 00:00:00");
            $transaction['custom_id'] = $objWorksheet->getCellByColumnAndRow(8, $row)->getValue();
            if ($objWorksheet->getCellByColumnAndRow(14, $row)->getValue() == 'geaccepteerd') {
                $transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
            } else
                if ($objWorksheet->getCellByColumnAndRow(14, $row)->getValue() == 'in behandeling') {
                    $transaction['status'] = \Oara\Utilities::STATUS_PENDING;
                } else
                    if ($objWorksheet->getCellByColumnAndRow(14, $row)->getValue() == 'geweigerd: klik te oud' || $objWorksheet->getCellByColumnAndRow(14, $row)->getValue() == 'geweigerd') {
                        $transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
                    } else {
                        throw new \Exception("new status " . $objWorksheet->getCellByColumnAndRow(14, $row)->getValue());
                    }
            $transaction['amount'] = \Oara\Utilities::parseDouble(round($objWorksheet->getCellByColumnAndRow(11, $row)->getValue(), 2));
            $transaction['commission'] = \Oara\Utilities::parseDouble(round($objWorksheet->getCellByColumnAndRow(12, $row)->getValue(), 2));
            $totalTransactions[] = $transaction;

        }
        unlink($my_file);

        return $totalTransactions;
    }

}
