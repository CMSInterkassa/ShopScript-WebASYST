<?php
/**
 * @version 2:0.9.5
 * @property-read string $id_cashbox Cashbox ID
 * @property-read string $secret_key Secret key
 * @property-read array $currency transaction currency
 * @property-read bool $test_mode is testing mode
 */
class interkassaPayment extends waPayment
{
    public $url_payment = 'https://sci.interkassa.com';

    const ikUrlSCI = 'https://sci.interkassa.com/';
    const ikUrlAPI = 'https://api.interkassa.com/v1/';

    public function allowedCurrency()
    {
        return (is_string($this->currency) ? $this->currency : array_keys(array_filter($this->currency)));
    }

    public static function availableCurrency()
    {
        $allowed = array(
            'EUR',
            'USD',
            'UAH',
            'RUB',
            'BYR',
            'XAU', //Золото (одна тройская унция)
        );

        $available = array();
        $app_config = wa()->getConfig();
        if (method_exists($app_config, 'getCurrencies')) {
            $currencies = $app_config->getCurrencies();
            foreach ($currencies as $code => $c) {
                if (in_array($code, $allowed)) {
                    $available[] = array(
                        'value'       => $code,
                        'title'       => sprintf('%s %s', $c['code'], $c['title']),
                        'description' => $c['sign'],
                    );
                }
            }
        }
        return $available;
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $order = waOrder::factory($order_data);

        $FormData = array();

        $FormData['ik_am'] = number_format($order->total, 2, '.', '');
        $FormData['ik_pm_no'] = $order->id;
        $FormData['ik_desc'] = 'Payment for order ' . $order->id;
        $FormData['ik_cur'] = $order->currency;
        $FormData['ik_co_id'] = $this->id_cashbox;

        $FormData['ik_suc_u'] = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS);
        $FormData['ik_fal_u'] = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL);
        $FormData['ik_pnd_u'] = $FormData['ik_suc_u'];

        $FormData['ik_ia_u'] = $this->getRelayUrl() . '?' . http_build_query(array(
                'wa_id' => $this->id,
                'wa_app_id' => $this->app_id,
                'wa_merchant_id' => $this->merchant_id,
        ));

        $FormData['ik_sign'] = self::IkSignFormation($FormData, $this -> secret_key);

        $FormData['wa_id'] = $this->id;
        $FormData['wa_app_id'] = $this->app_id;
        $FormData['wa_merchant_id'] = $this->merchant_id;

        $_SESSION['SCI_INTERKASSA']['wa_id'] = $this->id;
        $_SESSION['SCI_INTERKASSA']['wa_app_id'] = $this->app_id;
        $_SESSION['SCI_INTERKASSA']['wa_merchant_id'] = $this->merchant_id;

        $view = wa()->getView();
        $view->assign('hidden_fields', $FormData);
        $view->assign('url_request', $this->getRelayUrl() . '?paysys');
        $view->assign('auto_submit', $auto_submit);
        $view->assign('interkassa', $this);
        $view->assign('path_modal_tpl', $this->path.'/templates/modal_ps.tpl');

        return $view->fetch($this->path.'/templates/payment.tpl');
    }

    protected function callbackInit($request)
    {
        $SCI_INTERKASSA = wa()->getStorage()->read('SCI_INTERKASSA');

        $wa_app_id = !empty($request['wa_app_id'])? $request['wa_app_id'] : $SCI_INTERKASSA['wa_app_id'];
        $wa_merchant_id = !empty($request['wa_merchant_id'])? $request['wa_merchant_id'] : $SCI_INTERKASSA['wa_merchant_id'];

        if (!empty($request['ik_pm_no']) && !empty($wa_app_id) && !empty($wa_merchant_id)) {
            $this->app_id = $wa_app_id;
            $this->merchant_id = $wa_merchant_id;
        }
        else {
            self::log($this->id, array('error' => 'empty required field(s)'));
            throw new waPaymentException('Empty required field(s)');
        }

        return parent::callbackInit($request);
    }

    protected function callbackHandler($request)
    {
        if(isset($request['paysys'])) {
            if (isset($request['ik_act']) && $request['ik_act'] == 'process'){
                $request['ik_sign'] = self::IkSignFormation($request, $this -> secret_key);
                $data = self::getAnswerFromAPI($request);
            }
            else
                $data = self::IkSignFormation($request, $this -> secret_key);

            echo $data;
            exit;
        }

        $URL_redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL);

        if(!$this -> checkIP()){
            self::log($this->id, array('error' => 'Bad request!!! Wrong ip ' . $_SERVER['REMOTE_ADDR']));
            throw new waPaymentException('Bad request!!!');
        }

        $transaction_data = $this->formalizeData($request);

        $secret_key = $this -> secret_key;
        if (isset($request['ik_pw_via']) && $request['ik_pw_via'] == 'test_interkassa_test_xts')
            $secret_key = $this->test_key;

        $check_sign = self::IkSignFormation($request, $secret_key);

        if ($request['ik_sign'] == $check_sign && ($this->id_cashbox == $request['ik_co_id'])) {
            switch ($transaction_data['state']) {
                case self::STATE_CAPTURED:
                    $transaction_data = $this->saveTransaction($transaction_data, $request);
                    $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
                    $URL_redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS);
                    break;
                default:
                    $transaction_data = $this->saveTransaction($transaction_data, $request);
                    $this->execAppCallback(self::CALLBACK_DECLINE, $transaction_data);
                    break;
            }

            wa()->getStorage()->remove('SCI_INTERKASSA');
        }

        return array(
            'redirect' => $URL_redirect, //требуемый URL, на который нужно перенаправить покупателя
        );
    }

    protected function formalizeData($transaction_raw_data)
    {
        $transaction_data = parent::formalizeData($transaction_raw_data);
        $view_data = array();

        $fields = array(
            'ik_pw_via'     => 'Способ оплаты',
            'ik_inv_id'     => 'Идентификатор',
            'ik_inv_crt'    => 'Время создания платежа',
            'ik_inv_prc'    => 'Время проведения',
        );

        $map = array(
            'ik_pw_via' => array(
                'privatterm_liqpay_merchant_uah'       => 'Терминалы Приватбанка - LiqPay - Мерчант',
                'anelik_w1_merchant_rub'               => 'Anelik - Единый кошелек - Мерчант',
                'beeline_w1_merchant_rub'              => 'Билайн - Единый кошелек - Мерчант',
                'contact_w1_merchant_rub'              => 'CONTACT - Единый кошелек - Мерчант',
                'lider_w1_merchant_rub'                => 'ЛИДЕР - Единый кошелек - Мерчант',
                'megafon_w1_merchant_rub'              => 'Мегафон - Единый кошелек - Мерчант',
                'mobileretails_w1_merchant_rub'        => 'Салоны связи - Единый кошелек - Мерчант',
                'mts_w1_merchant_rub'                  => 'МТС - Единый кошелек - Мер чант',
                'qiwiwallet_w1_merchant_rub'           => 'Qiwi Кошелек - Единый кошелек - Мерчант',
                'ruspost_w1_merchant_rub'              => 'Почта Росси - Единый кошелек - Мерчант',
                'rusterminal_w1_merchant_rub'          => 'Терминалы Росси - Единый кошелек - Мерчант',
                'unistream_w1_merchant_rub'            => 'Юнистрим - Единый кошелек - Мерчант',
                'webmoney_merchant_wmb'                => 'WebMoney - Мерчант',
                'webmoney_merchant_wme'                => 'WebMoney - Мерчант',
                'webmoney_merchant_wmg'                => 'WebMoney - Мерчант',
                'webmoney_merchant_wmr'                => 'WebMoney - Мерчант',
                'webmoney_merchant_wmu'                => 'WebMoney - Мерчант',
                'webmoney_merchant_wmz'                => 'WebMoney - Мерчант',
                'nsmep_smartpay_invoice_uah'           => 'НСМЕП - SmartPay - Выставление счета',
                'yandexmoney_merchant_rub'             => 'Yandex.Money - Мерчант',
                'zpayment_merchant_rub'                => 'Z-payment - Мерчант',
                'sbrf_rusbank_receipt_rub'             => 'Сбербанк РФ - Российский банк - Квитанция',
                'webmoney_invoice_wmz'                 => 'WebMoney - Выставление счета',
                'webmoney_invoice_wmu'                 => 'WebMoney - Выставление счета',
                'webmoney_invoice_wmr'                 => 'WebMoney - Выставление счета',
                'webmoney_invoice_wmg'                 => 'WebMoney - Выставление счета',
                'webmoney_invoice_wme'                 => 'WebMoney - Выставление счета',
                'webmoney_invoice_wmb'                 => 'WebMoney - Выставление счета',
                'webcreds_merchant_rub'                => 'WebCreds - Мерчант',
                'w1_merchant_usdw1_w1_merchant_usd'    => 'Единый кошелек - Мерчант',
                'w1_merchant_uahw1_w1_merchant_uah'    => 'Единый кошелек - Мерчант',
                'w1_merchant_rubw1_w1_merchant_rub'    => 'Единый кошелек - Мерчант',
                'w1_merchant_eurw1_w1_merchant_eur'    => 'Единый кошелек - Мерчант',
                'visa_liqpay_merchant_usd'             => 'Visa - LiqPay - Мерчант',
                'visa_liqpay_merchant_rub'             => 'Visa - LiqPay - Мерчант',
                'visa_liqpay_merchant_eur'             => 'Visa - LiqPay - Мерчант',
                'ukrbank_receipt_uah'                  => 'Украинский банк - Квитанция',
                'ukash_w1_merchant_usd'                => 'Ukash - Единый кошелек - Мерчант',
                'rusbank_receipt_rub'                  => 'Российский банк - Квитанция',
                'rbkmoney_merchant_rub'                => 'RBK Money - Мерчант',
                'privat24_merchant_usd'                => 'Privat24 - Мерчант',
                'privat24_merchant_uah'                => 'Privat24 - Мерчант',
                'privat24_merchant_eur'                => 'Privat24 - Мерчант',
                'perfectmoney_merchant_usd'            => 'PerfectMoney - Мерчант',
                'perfectmoney_merchant_eur'            => 'PerfectMoney - Мерчант',
                'moneymail_merchant_usd'               => 'MoneyMail - Мерчант',
                'moneymail_merchant_rub'               => 'MoneyMail - Мерчант',
                'moneymail_merchant_eur'               => 'MoneyMail - Мерчант',
                'monexy_merchant_usd'                  => 'MoneXy - Мерчант',
                'monexy_merchant_uah'                  => 'MoneXy - Мерчант',
                'monexy_merchant_rub'                  => 'MoneXy - Мерчант',
                'monexy_merchant_eur'                  => 'MoneXy - Мерчант',
                'mastercard_liqpay_merchant_usd'       => 'Mastercard - LiqPay - Мерчант',
                'mastercard_liqpay_merchant_rub'       => 'Mastercard - LiqPay - Мерчант',
                'mastercard_liqpay_merchant_eur'       => 'Mastercard - LiqPay - Мерчант',
                'liqpay_merchant_usd'                  => 'LiqPay - Мерчант',
                'liqpay_merchant_uah'                  => 'LiqPay - Мерчант',
                'liqpay_merchant_rub'                  => 'LiqPay - Мерчант',
                'liqpay_merchant_eur'                  => 'LiqPay - Мерчант',
                'libertyreserve_merchant_usd'          => 'Liberty Reserve - Мерчант',
                'libertyreserve_merchant_eur'          => 'Liberty Reserve - Мерчант',
                'eurobank_receipt_usd'                 => 'Wire Transfer - Квитанция',
                'paypal_merchant_usd'                  => 'Paypal - Мерчант',
                'alfaclick_w1_merchant_rub'            => 'Альфаклик (Альфабанк) - Единый кошелек - Мерчант',
                'interkassa_voucher_usd'               => 'Интеркасса - Ваучер',
                'visa_liqpay_merchant_uah'             => 'Visa - LiqPay - Мерчант',
                'mastercard_liqpay_merchant_uah'       => 'Mastercard - LiqPay - Мерчант',
                'rbkmoney_merchantx_rub'               => 'RBK Money - Мерчант',
                'telemoney_merchant_rub'               => 'Telemoney - Мерчант',
                'ukrterminal_webmoneyuga_terminal_uah' => 'Терминалы Украины - Webmoney UGA - Терминал',
                'test_interkassa_test_xts'             => 'Тестовая платежная система - Интеркасса - Тест',
            ),
        );
        foreach ($fields as $field => $description) {
            if (ifset($transaction_raw_data[$field])) {
                if (isset($map[$field][$transaction_raw_data[$field]])) {
                    $view_data[] = $description.': '.$map[$field][$transaction_raw_data[$field]];
                } else {
                    $view_data[] = $description.': '.$transaction_raw_data[$field];
                }
            }
        }

        $transaction_data = array_merge($transaction_data, array(
            'type'        => null,
            'native_id'   => ifset($transaction_raw_data['ik_trn_id']),
            'amount'      => ifset($transaction_raw_data['ik_am']),
            'currency_id' => ifset($transaction_raw_data['ik_cur']),
            'order_id'    => $transaction_raw_data['ik_pm_no'],
            'view_data'   => implode("\n", $view_data),
        ));

        switch (ifset($transaction_raw_data['ik_inv_st'])) {
            case 'success':
                $transaction_data['state'] = self::STATE_CAPTURED;
                $transaction_data['type'] = self::OPERATION_AUTH_CAPTURE;
                break;
            case 'fail':
            case 'canceled':
                $transaction_data['state'] = self::STATE_CANCELED;
                $transaction_data['type'] = self::OPERATION_CANCEL;
                break;
        }
        return $transaction_data;
    }

    private static function IkSignFormation($data, $secret_key)
    {
        $dataSet = array();
        foreach ($data as $key => $value) {
            if (preg_match('/ik_/i', $key) && $key != 'ik_sign')
                $dataSet[$key] = $value;
        }

        ksort($dataSet, SORT_STRING);
        array_push($dataSet, $secret_key);

        $arg = implode(':', $dataSet);
        $ik_sign = base64_encode(md5($arg, true));

        return $ik_sign;
    }

    public static function getAnswerFromAPI($data)
    {
        $ch = curl_init(self::ikUrlSCI);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        return $result;
    }

    public function getPaymentSystems()
    {
        $username = $this -> api_id;
        $password = $this -> api_key;
        $remote_url = self::ikUrlAPI . 'paysystem-input-payway?checkoutId=' . $this -> id_cashbox;

        $businessAcc = $this->getIkBusinessAcc($username, $password);

        $ikHeaders = [];
        $ikHeaders[] = "Authorization: Basic " . base64_encode("$username:$password");
        if(!empty($businessAcc)) {
            $ikHeaders[] = "Ik-Api-Account-Id: " . $businessAcc;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remote_url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $ikHeaders);
        $response = curl_exec($ch);

        if(empty($response))
            return '<strong style="color:red;">Error!!! System response empty!</strong>';

        $json_data = json_decode($response);
        if ($json_data->status != 'error') {
            $payment_systems = array();
            if(!empty($json_data->data)){
                foreach ($json_data->data as $ps => $info) {
                    $payment_system = $info->ser;
                    if (!array_key_exists($payment_system, $payment_systems)) {
                        $payment_systems[$payment_system] = array();
                        foreach ($info->name as $name) {
                            if ($name->l == 'en') {
                                $payment_systems[$payment_system]['title'] = ucfirst($name->v);
                            }
                            $payment_systems[$payment_system]['name'][$name->l] = $name->v;
                        }
                    }
                    $payment_systems[$payment_system]['currency'][strtoupper($info->curAls)] = $info->als;
                }
            }

            return !empty($payment_systems)? $payment_systems : '<strong style="color:red;">API connection error or system response empty!</strong>';
        } else {
            if(!empty($json_data->message))
                return '<strong style="color:red;">API connection error!<br>' . $json_data->message . '</strong>';
            else
                return '<strong style="color:red;">API connection error or system response empty!</strong>';
        }
    }

    public function getIkBusinessAcc($username = '', $password = '')
    {
        $tmpLocationFile = __DIR__ . '/tmpLocalStorageBusinessAcc.ini';
        $dataBusinessAcc = function_exists('file_get_contents')? file_get_contents($tmpLocationFile) : '{}';
        $dataBusinessAcc = json_decode($dataBusinessAcc, 1);
        $businessAcc = is_string($dataBusinessAcc['businessAcc'])? trim($dataBusinessAcc['businessAcc']) : '';
        if(empty($businessAcc) || sha1($username . $password) !== $dataBusinessAcc['hash']) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, self::ikUrlAPI . 'account');
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Basic " . base64_encode("$username:$password")]);
            $response = curl_exec($curl);

            if (!empty($response['data'])) {
                foreach ($response['data'] as $id => $data) {
                    if ($data['tp'] == 'b') {
                        $businessAcc = $id;
                        break;
                    }
                }
            }

            if(function_exists('file_put_contents')){
                $updData = [
                    'businessAcc' => $businessAcc,
                    'hash' => sha1($username . $password)
                ];
                file_put_contents($tmpLocationFile, json_encode($updData, JSON_PRETTY_PRINT));
            }

            return $businessAcc;
        }

        return $businessAcc;
    }

    public function checkIP(){
        $ip_stack = array(
            'ip_begin'=>'151.80.190.97',
            'ip_end'=>'35.233.69.55'
        );

        $ip = !empty($_SERVER['HTTP_CF_CONNECTING_IP'])? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR'];
        $ip = ip2long($ip) ? ip2long($ip) : !ip2long($ip);

        if(($ip >= ip2long($ip_stack['ip_begin'])) && ($ip <= ip2long($ip_stack['ip_end']))){
            return true;
        }
        return false;
    }
}
