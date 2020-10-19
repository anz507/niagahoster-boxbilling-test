<?php
/**
 * BoxBilling Payment Adapter for Paylane Secure Form
 *
 * @author Ahmad Anshori <anz507@gmail.com>
 */

class Payment_Adapter_Paylane
{
    private $config = array();

    protected $di;

    /**
     * Default HTTP Prefix config
     */
    protected $httpPrefix = 'http://';

    /**
     * Default Install Domain Host config
     */
    protected $installDomain = 'www.install-domain.com';

    /**
     * Paylane Redirection Form Action URL
     */
    protected $paylaneFormActionUrl = 'https://secure.paylane.com/order/cart.html';

    /**
     * Paylane transaction type
     */
    protected $paylaneTransactionType = 'S';

    /**
     * Paylane form language
     */
    protected $paylaneLang = 'en';

    /**
     * Paylane description prefix
     */
    protected $paylaneDescriptionPrefix = 'NGHSTR-';

    public function __construct($config)
    {
        $this->config = $config;

        // Config Validations
        if(! isset($this->config['redirection_method'])) {
            throw new Payment_Exception('Payment gateway "Paylane" is not configured properly. Please fill Paywall Secure Form Redirect method');
        }

        $validRedirection = ['GET', 'POST'];

        if(! in_array($this->config['redirection_method'], $validRedirection)) {
            throw new Payment_Exception('Payment gateway "Paylane" is not configured properly. Please fill Paywall Secure Form Redirect method with GET or POST');
        }

        if(! isset($this->config['merchant_id'])) {
            throw new Payment_Exception('Payment gateway "Paylane" is not configured properly. Please fill Paywall Secure Form Merchant ID');
        }

        if(! isset($this->config['hash'])) {
            throw new Payment_Exception('Payment gateway "Paylane" is not configured properly. Please fill Paywall Secure Form Hash salt');
        }

        if(! isset($this->config['http_prefix'])) {
            $this->config['http_prefix'] = $this->httpPrefix;
        }

        if(! isset($this->config['install_domain'])) {
            $this->config['http_prefix'] = $this->installDomain;
        }

        if(! isset($this->config['return_url'])) {
            $this->config['return_url'] = sprintf('%s%s/invoice/hash', $this->config['http_prefix'], $this->config['install_domain']);
        }
        if(! isset($this->config['cancel_url'])) {
            $this->config['cancel_url'] = sprintf('%s%s/invoice/hash', $this->config['http_prefix'], $this->config['install_domain']);
        }
        if(! isset($this->config['notify_url'])) {
            $this->config['notify_url'] = sprintf('%s%s/bb-ipn.php', $this->config['http_prefix'], $this->config['install_domain']);
        }
        if(! isset($this->config['redirect_url'])) {
            $this->config['redirect_url'] = sprintf('%s%s/bb-ipn.php?bb_redirect=1&bb_invoice_hash=invoice_hash', $this->config['http_prefix'], $this->config['install_domain']);
        }
        if(! isset($this->config['continue_shopping_url'])) {
            $this->config['continue_shopping_url'] = sprintf('%s%s/order', $this->config['http_prefix'], $this->config['install_domain']);
        }
    }

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    public static function getConfig()
    {
        return array(
            'can_load_in_iframe'   =>  true,
            'supports_one_time_payments'   =>  true,
            // turning support for subscription off (as communicated on email with <bayu.putra@hostinger.com>, Mon, Oct 19, 2020)
            'supports_subscriptions'       =>  false,
            'description'     =>  'Custom payment gateway allows you to give instructions how can your client pay invoice. All system, client, order and invoice details can be printed. HTML and JavaScript code is supported.',
            'form'  => array(
                'http_prefix' => [
                    'select',
                    array(
                        'multiOptions' => array(
                            'http://' => 'http://',
                            'https://' => 'https://',
                        ),
                        'label' => 'Enter Install Domain HTTP Prefix',
                    )
                ],
                'install_domain' => [
                    'text',
                    array(
                        'label' => 'Enter Install Domain (eg: www.install-domain.com, www.install-domain.com:8000)',
                    )
                ],
                'redirection_method' => array(
                    'select',
                    array(
                        'multiOptions' => array(
                            'POST' => 'POST',
                            'GET' => 'GET',
                        ),
                        'label' => 'Enter Paywall Secure Form Redirect method',
                    )
                ),
                'merchant_id' => array(
                    'text',
                    array(
                        'label' => 'Enter Paywall Secure Form Merchant ID',
                    )
                ),
                'hash' => array(
                    'text',
                    array(
                        'label' => 'Enter Paywall Secure Form Hash salt',
                    )
                ),
            ),
        );
    }

    /**
     * Generate payment text
     * @param Api_Admin $api_admin
     * @param int $invoice_id
     * @param bool $subscription
     * @since BoxBilling v2.9.15
     * @return string - html form with auto submit javascript
     */
    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(array('id'=>$invoice_id));

        $paylaneData = new \stdClass();

        $paylaneData->description = '';

        foreach ($invoice['lines'] as $item) {
            $paylaneData->description .= $item['title'] . "\n";
        }

        $paylaneData->salt = $this->config['hash'];
        $paylaneData->amount = $invoice['total'];
        $paylaneData->currency = $invoice['currency'];
        $paylaneData->hash = $this->getHash(
                $paylaneData->salt,
                $paylaneData->description,
                $paylaneData->amount,
                $paylaneData->currency,
                $paylaneData->transactionType
            );

        $paylaneData->backUrl = $this->config['redirect_url'];
        $paylaneData->merchantId = $this->config['merchant_id'];
        $paylaneData->redirectionMethod = $this->config['redirection_method'];

        $form = '
            <form action="' . $this->paylaneFormActionUrl . '" method="' . $paylaneData->redirectionMethod . '">
                <input type="hidden" name="amount" value="' . $paylaneData->amount . '" />
                <input type="hidden" name="currency" value="' . $paylaneData->currency . '" />
                <input type="hidden" name="merchant_id" value="' . $paylaneData->merchantId . '" />
                <input type="hidden" name="description" value="' . $this->paylaneDescriptionPrefix . $invoice_id . '" />
                <input type="hidden" name="transaction_description" value="' . $paylaneData->description . '" />
                <input type="hidden" name="transaction_type" value="' . $this->paylaneTransactionType . '" />
                <input type="hidden" name="back_url" value="' . $paylaneData->backUrl . '" />
                <input type="hidden" name="language" value="' . $this->paylaneLang . '" />
                <input type="hidden" name="hash" value="' . $paylaneData->hash . '" />
                <button type="submit" class="btn btn-primary btn-small">Pay with PayLane >></button>
            </form>
        ';

        return $form;
    }

    /**
     * Generate Hash required by Paylane Secure Form
     *
     * @param string $paylaneSalt
     * @param string $description (Invoice ID)
     * @param double $amount
     * @param string $currency (ISO 4217 currency symbol, eg: 'EUR', 'USD', 'IDR')
     * @param string $transactionType
     * @return string
     */
    private function getHash($paylaneSalt, $description, $amount, $currency, $transactionType)
    {
        return sha1($paylaneSalt . '|' . $description . '|' . $amount . '|' . $currency . '|' . $transactionType);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $invoice = $api_admin->invoice_get(array('id'=>$data['get']['bb_invoice_id']));
        $clientId = $invoice['client']['id'];

        $ipn = $data['post'];
        $tx = $api_admin->invoice_transaction_get(array('id'=>$id));

        if ($this->checkResponseHash(
            $this->config['hash'],
            $this->paylaneDescriptionPrefix . $invoice['id'],
            $invoice['total'],
            $invoice['currency'],
            $this->paylaneTransactionType,
            $ipn
        )){
            throw new Payment_Exception('Invalid Payment, Mismatched Response Hash');
        }

        if ($this->isIpnDuplicate($ipn)){
            throw new Payment_Exception('IPN is duplicate');
        }

        if(!$tx['invoice_id']) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'invoice_id'=>$data['get']['bb_invoice_id']));
        }
        if(!$tx['txn_id'] && isset($ipn['id_sale'])) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'txn_id'=>$ipn['id_sale']));
        }
        if(!$tx['txn_status'] && isset($ipn['status'])) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'txn_status'=>$ipn['status']));
        }
        if(!$tx['amount'] && isset($ipn['amount'])) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'amount'=>$ipn['mc_gross']));
        }
        if(!$tx['currency'] && isset($ipn['currency'])) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'currency'=>$ipn['currency']));
        }

        if ($ipn['status'] == 'PERFORMED') {
            $bd = array(
                'id'            =>  $clientId,
                'amount'        =>  $ipn['amount'],
                'description'   =>  'Paylane transaction '.$ipn['id_sale'],
                'type'          =>  'Paylane',
                'rel_id'        =>  $ipn['id_sale'],
            );
            if ($this->isIpnDuplicate($ipn)){
                throw new Payment_Exception('IPN is duplicate');
            }
            $api_admin->client_balance_add_funds($bd);
            if($tx['invoice_id']) {
                $api_admin->invoice_pay_with_credits(array('id'=>$tx['invoice_id']));
            }
            $api_admin->invoice_batch_pay_with_credits(array('client_id'=>$clientId));
        }
    }

    /**
     * Check if response is a dupe
     * @param array $ipn - Instant Payment Notification post param
     * @return boolean
     */
    public function isIpnDuplicate(array $ipn)
    {
        $sql = 'SELECT id
                FROM transaction
                WHERE txn_id = :transaction_id
                  AND txn_status = :transaction_status
                  AND amount = :transaction_amount
                LIMIT 2';

        $bindings = array(
            ':transaction_id' => $ipn['id_sale'],
            ':transaction_status' => $ipn['status'],
            ':transaction_amount' => $ipn['amount'],
        );

        $rows = $this->di['db']->getAll($sql, $bindings);
        if (count($rows) > 1){
            return true;
        }


        return false;
    }

    /**
     * Check if response hash is the same as request hash
     * @param string $paylaneSalt
     * @param string $description (Invoice ID)
     * @param double $amount
     * @param string $currency (ISO 4217 currency symbol, eg: 'EUR', 'USD', 'IDR')
     * @param string $transactionType
     * @param array $ipn - Instant Payment Notification post param
     * @return boolean
     */
    private function checkResponseHash($paylaneSalt, $description, $amount, $currency, $transactionType, $ipn)
    {
        if ($this->getHash($paylaneSalt, $description, $amount, $currency, $transactionType) === $ipn['hash']) {
            return true;
        }

        return false;
    }
}