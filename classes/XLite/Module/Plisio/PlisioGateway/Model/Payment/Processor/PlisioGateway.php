<?php
/**
 * Copyright (c) 2011-present The right software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XLite\Module\Plisio\PlisioGateway\Model\Payment\Processor;

use XLite\Module\Plisio\PlisioGateway\Lib\PlisioClient;

/**
 * Plisio payment processor
 */
class PlisioGateway extends \XLite\Model\Payment\Base\WebBased
{

    public function isTestMode(\XLite\Model\Payment\Method $method)
    {
        return false;
    }

    /**
     * Process return
     *
     * @param \XLite\Model\Payment\Transaction $transaction Return-owner transaction
     *
     * @return void
     */
    public function processReturn(\XLite\Model\Payment\Transaction $transaction)
    {
        parent::processReturn($transaction);
        $status = $transaction::STATUS_PENDING;
        $this->transaction->setStatus($status);
    }

    /**
     * Process callback
     *
     * @param \XLite\Model\Payment\Transaction $transaction Callback-owner transaction
     *
     * @return void
     */
    public function processCallback(\XLite\Model\Payment\Transaction $transaction)
    {
        $request = $_POST;
        $api_key = $transaction->getPaymentMethod()->getSetting('api_key');
        if (!$this->verifyCallbackData($request, $api_key)) {
            parent::processCallback($transaction);
        } else {
            switch ($request['status']) {
                case 'new':
                case 'pending':
                    $transaction->registerTransactionInOrderHistory();
                    $transaction->setStatus($transaction::STATUS_PENDING);
                    break;
                case 'completed':
                case 'mismatch':
                    $transaction->setStatus($transaction::STATUS_SUCCESS);
                    break;
                case 'error':
                case 'expired':
                case 'cancelled':
                    $transaction->setStatus($transaction::STATUS_VOID);
                    break;
            }
            $transaction->update();
            $transaction->getOrder()->setPaymentStatusByTransaction($transaction);
        }
    }

    /**
     * @param $data
     * @return bool
     */
    public function verifyCallbackData($data, $api_key)
    {
        if (!isset($data['verify_hash'])) {
            return false;
        }

        $post = $data;
        $verifyHash = $post['verify_hash'];
        unset($post['verify_hash']);
        ksort($post);
        if (isset($post['expire_utc'])){
            $post['expire_utc'] = (string)$post['expire_utc'];
        }
        if (isset($post['tx_urls'])){
            $post['tx_urls'] = html_entity_decode($post['tx_urls']);
        }
        $postString = serialize($post);

        $checkKey = hash_hmac('sha1', $postString, $api_key);

        if ($checkKey != $verifyHash) {
            return false;
        }

        return true;
    }

    /**
     * Logging the data under Plisio
     * Available if developer_mode is on in the config file
     *
     * @param mixed $data Log data
     *
     * @return void
     */
    protected static function log($data)
    {
        if (LC_DEVELOPER_MODE) {
            \XLite\Logger::logCustom('Plisio', $data);
        }
    }

    /**
     * Get settings widget or template
     *
     * @return string Widget class name or template path
     */
    public function getSettingsWidget()
    {
        return 'modules/Plisio/PlisioGateway/config.twig';
    }
    /**
     * @return string
     */
    public function getFormMethod()
    {
        return static::FORM_METHOD_GET;
    }
    /**
     * Check - payment method is configured or not
     *
     * @param \XLite\Model\Payment\Method $method Payment method
     *
     * @return boolean
     */
    public function isConfigured(\XLite\Model\Payment\Method $method)
    {
        return parent::isConfigured($method)
            && $method->getSetting('api_key');
    }

    /**
     * Get payment method admin zone icon URL
     *
     * @param \XLite\Model\Payment\Method $method Payment method
     *
     * @return string
     */
    public function getAdminIconURL(\XLite\Model\Payment\Method $method)
    {
        return true;
    }

    /**
     * Returns the list of settings available for this payment processor
     *
     * @return array
     */
    public function getAvailableSettings()
    {
        return array('api_key');
    }

    /**
     * Get redirect form URL
     *
     * @return string
     */
    protected function getFormURL()
    {
        $plisio = new PlisioClient($this->transaction->getPaymentMethod()->getSetting('api_key'));

        $request = array(
            'source_currency' => $this->getCurrencyCode(),
            'source_amount' => $this->getFormattedPrice($this->transaction->getValue()),
            'email' => $this->transaction->getOrigProfile()->getEmail(),
            'order_number' => $this->transaction->getOrder()->getOrderId(),
            'order_name' => $this->getItemName(),
            'success_url' => $this->getReturnURL('transaction_id', true),
            'callback_url' => $this->getCallbackURL('transaction_id', true),
            'cancel_url' => $this->getReturnURL('transaction_id', true, true),
            'plugin' => 'X-Cart',
            'version' => '1.0.0',
            'api_key' => $this->transaction->getPaymentMethod()->getSetting('api_key')
        );

        $plOrder = $plisio->createTransaction($request);

        if ($plOrder && $plOrder['status'] !== 'error' && !empty($plOrder['data'])) {
            return $plOrder['data']['invoice_url'];
        } else {
            \XLite\Core\TopMessage::addError('Error occurred! ' . implode(',', json_decode($plOrder['data']['message'], true)));
            return '';
        }
    }

    /**
     * Get redirect form fields list
     *
     * @return array
     */
    protected function getFormFields()
    {
        return array();
    }

    protected function assembleFormBody($formFields)
    {
        return true;
    }

    /**
     * Get currency code
     *
     * @return string
     */
    protected function getCurrencyCode()
    {
        return strtoupper($this->transaction->getCurrency()->getCode());
    }

    /**
     * Return formatted price.
     *
     * @param float $price Price value
     *
     * @return string
     */
    protected function getFormattedPrice($price)
    {
        return sprintf('%.2f', round((double)($price) + 0.00000000001, 2));
    }

    /**
     * Return ITEM NAME for request
     *
     * @return string
     */
    protected function getItemName()
    {
        return 'Order #' . $this->getTransactionId();
    }

}
