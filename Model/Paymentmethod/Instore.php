<?php
/**
 * Copyright © 2017 Pay.nl All rights reserved.
 */

namespace Paynl\Payment\Model\Paymentmethod;

use Magento\Checkout\Model\Session;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Paynl\Payment\Model\Config;
use Paynl\Result\Transaction\Transaction;

/**
 * Description of Instore
 *
 * @author Andy Pieters <andy@pay.nl>
 */
class Instore extends PaymentMethod
{
    protected $_code = 'paynl_payment_instore';

    public function startTransaction(Order $order, UrlInterface $url, \Magento\Checkout\Model\Session $session)
    {

        $additionalData = $order->getPayment()->getAdditionalInformation();
        $bankId = null;
        if (isset($additionalData['bank_id'])) {
            $bankId = $additionalData['bank_id'];
        }
        unset($additionalData['bank_id']);

        $order->getPayment()->setAdditionalInformation($additionalData);

        $transaction = $this->doStartTransaction($order, $url);

        $instorePayment = \Paynl\Instore::payment([
            'transactionId' => $transaction->getTransactionId(),
            'terminalId' => $bankId
        ]);

        for ($i = 0; $i < 60; $i++) {
            $status = \Paynl\Instore::status([
                'hash' => $instorePayment->getHash()
            ]);
            switch ($status->getTransactionState()) {
                case 'approved':
                    $info = \Paynl\Transaction::get($transaction->getTransactionId());
                    $this->registerPayment($order, $info);
                    return "checkout/onepage/success";
                    break;
                case 'cancelled':
                case 'expired':
                case 'error':
                    $session->restoreQuote();
                    return "checkout";
                    break;

            }
            sleep(1);
        }

    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);

        if (is_array($data)) {
            $this->getInfoInstance()->setAdditionalInformation('bank_id', $data['bank_id']);
        } elseif ($data instanceof \Magento\Framework\DataObject) {
            $additional_data = $data->getAdditionalData();
            if (isset($additional_data['bank_id'])) {
                $bankId = $additional_data['bank_id'];
                $this->getInfoInstance()->setAdditionalInformation('bank_id', $bankId);
            }
        }
        return $this;
    }

    public function getBanks()
    {
//        $show_banks = $this->_scopeConfig->getValue('payment/' . $this->_code . '/bank_selection', 'store');
//        if (!$show_banks) return [];

        $cache = $this->getCache();
        $cacheName = 'paynl_terminals_' . $this->getPaymentOptionId();

        $banksJson = $cache->load($cacheName);
        if ($banksJson) {
            $banks = json_decode($banksJson);
        } else {

            $config = new Config($this->_scopeConfig);

            $config->configureSDK();

            $terminals = \Paynl\Instore::getAllTerminals();
            $terminals = $terminals->getList();
            $banks = [];
            foreach ($terminals as $terminal) {
                $terminal['visibleName'] = $terminal['name'];
                array_push($banks, $terminal);
            }
            $cache->save(json_encode($banks), $cacheName);
        }
        array_unshift($banks, array(
            'id' => '',
            'name' => __('Choose the pin terminal'),
            'visibleName' => __('Choose the pin terminal')
        ));
        return $banks;
    }

    /**
     * @return \Magento\Framework\App\CacheInterface
     */
    private function getCache()
    {
        /** @var \Magento\Framework\ObjectManagerInterface $om */
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var \Magento\Framework\App\CacheInterface $cache */
        $cache = $om->get('Magento\Framework\App\CacheInterface');
        return $cache;
    }

    private function registerPayment(Order $order, Transaction $transaction)
    {
        $skipFraudDetection = false;
        $payment = $order->getPayment();
        $payment->setTransactionId(
            $transaction->getId()
        );

        $payment->setPreparedMessage('Pay.nl - ');
        $payment->setIsTransactionClosed(
            0
        );
        $payment->registerCaptureNotification(
            $transaction->getPaidCurrencyAmount(), $skipFraudDetection
        );
        $order->save();

    }
}