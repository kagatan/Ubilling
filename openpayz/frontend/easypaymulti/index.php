<?php

/*
 * Фронтенд для взаимодействия с EasyPay для разных контрагентов (да, это которые в справочнике Предприниматели)
 * на базе протокола: http://store.nightfly.biz/st/1461842084/EasyPay.Provider.3.1.pdf реализовано взаимодействие,
 * которое описано в параграфе 8.1: передача Р/С, МФО и прочих данных, которые добываются из таблицы contrahens,
 * то бишь - атрибуты Предпринимателей.
 * Для использования "мульти" функционала нужно ОБЯЗАТЕЛЬНО ПРЕДВАРИТЕЛЬНО устаканить это с самой
 * платежной системой, чтобы платежка каким-либо образом знала и различала ваших Предпринимателей
 * и понимала кому и на какой Р/С отправлять деньги
 * 
 * Проверить можно тут: http://provider.easysoft.com.ua/
 */

// подключаем API OpenPayz
include ("../../libs/api.openpayz.php");

//debug mode
define('DEBUG_MODE', 0);

// использовать ИНН(ІПН) вместо ЕДРПО(ЄГРПО)
define('EP_USE_IPN', 0);
// процент НДС(обычно - 20). оставить 0, если НДС нам не нужен.
define('EP_NDS_VAL', 0);
// использовать файл paypurposes.ini для определения назнечения платежа для каждого Предпринимателя
define('EP_USE_PAY_PURPOSES_INI', 0);
// назначение платежа
define('EP_DEFAULT_PAY_PURPOSE', '');
// формат текущей даты приемлемый для функции date()
define('EP_FORMAT_DATES', 'd.m.Y');
// передавать ПС доплнительные поля адрес и баланс
define('EP_ADDITIONAL_ACC_INFO', 0);
//URL вашего работающего Ubilling
define('EP_API_URL', 'http://localhost/billing/');
//И его серийный номер
define('EP_API_KEY', 'UBxxxxxxxxxxxxxxxx');

//transaction storage path
define('TRANSACTION_PATH', './tmp/');

//Error codes
define('NO_SUCH_USER', '-1');
define('ERROR_PAYMENT', '-2');
define('TRANSACTION_EXISTS', '-3');
define('TRANSACTION_NOT_EXIST', '-4');
define('BAD_CHECKSUM', '-5');


/**
 * Gets user associated agent data JSON
 *
 * @param string $userlogin
 *
 * @return string
 */
function ep_getAgentData($userlogin) {
    $action = EP_API_URL . '?module=remoteapi&key=' . EP_API_KEY . '&action=getagentdata&param=' . $userlogin;
    @$result = file_get_contents($action);
    return ($result);
}

/**
 * Returns array of availble user address as login=>address
 *
 * @return array
 */
function ep_AddressGetFulladdresslist() {
//наглая заглушка
    $alterconf['ZERO_TOLERANCE'] = 0;
    $alterconf['CITY_DISPLAY'] = 0;
    $result = array();
    $query_full = "
        SELECT `address`.`login`,`city`.`cityname`,`street`.`streetname`,`build`.`buildnum`,`apt`.`apt` FROM `address`
        INNER JOIN `apt` ON `address`.`aptid`= `apt`.`id`
        INNER JOIN `build` ON `apt`.`buildid`=`build`.`id`
        INNER JOIN `street` ON `build`.`streetid`=`street`.`id`
        INNER JOIN `city` ON `street`.`cityid`=`city`.`id`";
    $full_adress = simple_queryall($query_full);
    if (!empty($full_adress)) {
        foreach ($full_adress as $ArrayData) {
            // zero apt handle
            if ($alterconf['ZERO_TOLERANCE']) {
                $apartment_filtered = ($ArrayData['apt'] == 0) ? '' : '/' . $ArrayData['apt'];
            } else {
                $apartment_filtered = '/' . $ArrayData['apt'];
            }
            if ($alterconf['CITY_DISPLAY']) {
                $result[$ArrayData['login']] = $ArrayData['cityname'] . ' ' . $ArrayData['streetname'] . ' ' . $ArrayData['buildnum'] . $apartment_filtered;
            } else {
                $result[$ArrayData['login']] = $ArrayData['streetname'] . ' ' . $ArrayData['buildnum'] . $apartment_filtered;
            }
        }
    }
    return($result);
}

/**
 * Returns all user RealNames
 * 
 * @return array
 */
function ep_UserGetAllRealnames() {
    $query_fio = "SELECT * from `realname`";
    $allfioz = simple_queryall($query_fio);
    $fioz = array();
    if (!empty($allfioz)) {
        foreach ($allfioz as $ia => $eachfio) {
            $fioz[$eachfio['login']] = $eachfio['realname'];
        }
    }
    return($fioz);
}

/**
 * Check is transaction unique?
 * 
 * @param $hash - hash string to check
 * @return bool/int
 */
function ep_GetTransaction($transactionIdRaw) {
    if (file_exists(TRANSACTION_PATH . $transactionIdRaw)) {
        return (true);
    } else {
        return (false);
    }
}

/**
 * Gets existing transaction date
 * 
 * @param $id - existing transaction id
 * @return bool/datetime
 */
function ep_GetTransactionTime($id) {
    $id = vf($id, 3);
    $query = "SELECT `date` from `op_transactions` WHERE `id`='" . $id . "'";
    $data = simple_query($query);
    if (!empty($data)) {
        return ($data['date']);
    } else {
        return (false);
    }
}

/**
 * Saves unprocessed transaction for further usage & returns its ID
 * 
 * @param array $data Request data
 * 
 * @return string
 */
function ep_SaveTransaction($data) {
    $result = false;
    $fileName = $data['Payment']['OrderId'];

    $toSave = serialize($data);
    if (file_exists(TRANSACTION_PATH)) {
        file_put_contents(TRANSACTION_PATH . $fileName, $toSave);
        if (file_exists(TRANSACTION_PATH . $fileName)) {
            $result = $fileName;
        } else {
            die('No write permissions to ' . TRANSACTION_PATH . ' directory');
        }
    } else {
        mkdir(TRANSACTION_PATH);
        if (!file_exists(TRANSACTION_PATH)) {
            die('No directory write permissions!');
        } else {
            file_put_contents(TRANSACTION_PATH . $fileName, $toSave);
            if (file_exists(TRANSACTION_PATH . $fileName)) {
                $result = $fileName;
            } else {
                die('No write permissions to ' . TRANSACTION_PATH . ' directory');
            }
        }
    }
    return ($result);
}

/**
 * Returns transaction data by its ID
 * 
 * @param string $transactionId
 * 
 * @return array
 */
function ep_GetTransactionData($transactionId) {
    $result = array();
    if (file_exists(TRANSACTION_PATH . $transactionId)) {
        $raw = file_get_contents(TRANSACTION_PATH . $transactionId);
        $result = unserialize($raw);
    }
    return ($result);
}

/**
 * Check is transaction unique?
 * 
 * @param $hash - hash string to check
 * @return bool/int
 */
function ep_CheckTransactionUnique($hash) {
    $hash = mysql_real_escape_string($hash);
    $query = "SELECT `id` from `op_transactions` WHERE `hash`='" . $hash . "'";
    $data = simple_query($query);
    if (empty($data)) {
        return (true);
    } else {
        return (false);
    }
}

//catch POST request
//deprecated in PHP 5.6
//$xml = $HTTP_RAW_POST_DATA;
$xml = file_get_contents('php://input');

$rawXml = xml2array($xml);

//debug mode logging
if (!empty($rawXml)) {
    if (DEBUG_MODE) {
        $debugSave = print_r($rawXml, true);
        file_put_contents('debug.log', $debugSave, FILE_APPEND | LOCK_EX);
    }
}

//check request validity
if (!empty($rawXml)) {
    if (isset($rawXml['Request'])) {
        //определяем тип запроса
        if (isset($rawXml['Request']['Check'])) {
            //
            // проверка пользователя
            //
            $allcustomers = op_CustomersGetAll();
            $checkCustomerId = $rawXml['Request']['Check']['Account'];
            if (isset($allcustomers[$checkCustomerId])) {
                @$userlogin = $allcustomers[$checkCustomerId];
                $allrealnames = ep_UserGetAllRealnames();
                $agentData = ep_getAgentData($userlogin);

                if (!empty($agentData)) {
                    $agentData = json_decode($agentData, true);

                    if (!empty($agentData)) {
                        $agentINNEDRPO = (EP_USE_IPN) ? $agentData['ipn'] : $agentData['edrpo'];
                        $agentName = $agentData['contrname'];
                        $agentBankName = $agentData['bankname'];
                        $agentMFO = $agentData['bankcode'];
                        $agentAcc = $agentData['bankacc'];
                        $nds = EP_NDS_VAL;
                        $payPurpose = EP_DEFAULT_PAY_PURPOSE;

                        if (EP_USE_PAY_PURPOSES_INI) {
                            $paymentPorposes = parse_ini_file('paypurposes.ini');

                            if (isset($paymentPorposes[$agentData['id']])) {
                                $payPurpose = $paymentPorposes[$agentData['id']];
                            }
                        }

                        $formatDates = EP_FORMAT_DATES;
                        $payPurpose = str_ireplace('{CURDATE}', date($formatDates), $payPurpose);

                        $companyData = '<BankingDetails>
                                            <Payee>
                                                <Id>' . $agentINNEDRPO . '</Id>
                                                <Name>' . $agentName . '</Name>
                                                <Bank>
                                                    <Name>' . $agentBankName . '</Name>
                                                    <Mfo>' . $agentMFO . '</Mfo>
                                                    <Account>' . $agentAcc . '</Account>
                                                </Bank>
                                            </Payee>
                                            <Payer/>
                                            <Narrative>
                                                <Name>' . $payPurpose . '</Name>
                                                <Vat>' . $nds . '</Vat>
                                            </Narrative>
                                        </BankingDetails>';
                    } else {
                        die('ERROR:WRONG_API_CONNECTION');
                    }
                } else {
                    $companyData = '';
                }

                if (EP_ADDITIONAL_ACC_INFO) {
                    $allAddress = ep_AddressGetFulladdresslist();
                    $userData = simple_query("SELECT * from `users` WHERE `login`='" . $userlogin . "'");
                    $addtionalAccInfo = '
                                        <Address>' . @$allAddress[$userlogin] . '</Address>
                                        <Balance>' . @$userData['Cash'] . '</Balance>    
                                        ';
                } else {
                    $addtionalAccInfo = '';
                }


                $reply = '
                <Response>
                <StatusCode>0</StatusCode>
                <StatusDetail>Ok</StatusDetail>
                <DateTime>' . date("Y-m-d\TH:i:s") . '</DateTime>
                 <AccountInfo>
                    <Name>' . @$allrealnames[$userlogin] . '</Name>' . $addtionalAccInfo . '
                 </AccountInfo>
                 ' . $companyData . '
                </Response>
                ';
                $reply = trim($reply);
                die($reply);
            } else {
                $reply = '
                <Response>
                <StatusCode>' . NO_SUCH_USER . '</StatusCode>
                <StatusDetail>User not found</StatusDetail>
                </Response>
                ';
                $reply = trim($reply);
                die($reply);
            }
        }

        //
        // creating unprocessed transaction in TRANSACTION_PATH
        //
        if (isset($rawXml['Request']['Payment'])) {
            $allcustomers = op_CustomersGetAll();
            $paymentCustomerId = mysql_real_escape_string($rawXml['Request']['Payment']['Account']);
            $transactionIdRaw = $rawXml['Request']['Payment']['OrderId'];

            if (isset($allcustomers[$paymentCustomerId])) {
                if (!ep_GetTransaction($transactionIdRaw)) {

                    $newTransactionId = ep_SaveTransaction($rawXml['Request']);

                    $reply = '
                <Response>
                <StatusCode>0</StatusCode>
                <StatusDetail>Order Created</StatusDetail>
                <DateTime>' . date("Y-m-d\TH:i:s") . '</DateTime>
                <PaymentId>' . $newTransactionId . '</PaymentId>
                </Response>
                ';
                    $reply = trim($reply);
                    die($reply);
                } else {
                    //уже есть такая транзакцийка
                    $reply = '
                     <Response>
                        <StatusCode>' . TRANSACTION_EXISTS . '</StatusCode>
                        <StatusDetail>Duplicate transaction</StatusDetail>
                        <DateTime>' . date("Y-m-d\TH:i:s") . '</DateTime>
                        <PaymentId>' . ep_GetTransaction($transactionIdRaw) . '</PaymentId>
                     </Response>
                    ';
                    $reply = trim($reply);
                    die($reply);
                }
            } else {
                $reply = '
                     <Response>
                        <StatusCode>' . NO_SUCH_USER . '</StatusCode>
                        <StatusDetail>No such user</StatusDetail>
                        <DateTime>' . date("Y-m-d\TH:i:s") . '</DateTime>
                        <PaymentId>' . ep_GetTransaction($transactionIdRaw) . '</PaymentId>
                     </Response>
                    ';
                $reply = trim($reply);
                die($reply);
            }
        }

        //
        // Transaction confirmation
        //
        if (isset($rawXml['Request']['Confirm'])) {
            $checkPaymentID = mysql_real_escape_string($rawXml['Request']['Confirm']['PaymentId']);
            $transactionDataRaw = ep_GetTransactionData($checkPaymentID);

            @$transactionDate = $transactionDataRaw['DateTime'];

            if ($transactionDate) {
                $hashClean = mysql_real_escape_string($transactionDataRaw['Payment']['OrderId']);
                $hash = 'EP_' . $hashClean;
                $summ = mysql_real_escape_string($transactionDataRaw['Payment']['Amount']);
                $paymentCustomerId = mysql_real_escape_string($transactionDataRaw['Payment']['Account']);

                if (ep_CheckTransactionUnique($hash)) {
                    //really processin cash & openpayz transaction
                    op_TransactionAdd($hash, $summ, $paymentCustomerId, 'EASYPAY', 'no debug info');
                    op_ProcessHandlers();
                }

                $reply = '
                    <Response>
                      <StatusCode>0</StatusCode>
                      <StatusDetail>Transaction Ok</StatusDetail>
                      <DateTime>' . date("Y-m-d\TH:i:s") . '</DateTime>
                      <OrderDate>' . $transactionDate . '</OrderDate>
                    </Response>
                    ';
                $reply = trim($reply);
                die($reply);
            } else {
                //нету такой транзакции
                $reply = '
                     <Response>
                        <StatusCode>' . TRANSACTION_NOT_EXIST . '</StatusCode>
                        <StatusDetail>No existing transaction</StatusDetail>
                        <DateTime>' . date("Y-m-d\TH:i:s") . '</DateTime>
                        <PaymentId>' . $checkPaymentID . '</PaymentId>
                     </Response>
                    ';
                $reply = trim($reply);
                die($reply);
            }
        }
    }

    //
    // Transaction cancel dummy reply
    //
    
    if (isset($rawXml['Request']['Cancel'])) {
        $curDate = date("Y-m-d\TH:i:s");
        $reply = '<Response>
                <StatusCode>' . ERROR_PAYMENT . '</StatusCode>
                    <StatusDetail>Transaction destroyed</StatusDetail>
                    <DateTime>' . $curDate . '</DateTime>
                    <CancelDate>' . $curDate . '</CancelDate>
                </Response>';
        $reply = trim($reply);
        die($reply);
    }
} else {
    die('EMPTY_POST_DATA');
}
?>
