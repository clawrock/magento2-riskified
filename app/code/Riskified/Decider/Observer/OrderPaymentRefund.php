<?php
namespace Riskified\Decider\Observer;

use Magento\Framework\Event\ObserverInterface;
use Riskified\Decider\Api\Api;

class OrderPaymentRefund implements ObserverInterface
{
    private $logger;
    private $apiOrderLayer;
    private $messageManager;
    private $registry;

    public function __construct(
        \Magento\Framework\Registry $registry,
        \Riskified\Decider\Api\Log $logger,
        \Riskified\Decider\Api\Order $orderApi,
        \Magento\Framework\Message\ManagerInterface $messageManager
    )
    {
        $this->registry = $registry;
        $this->logger = $logger;
        $this->apiOrderLayer = $orderApi;
        $this->messageManager = $messageManager;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $order = $observer->getPayment()->getOrder();
            $creditMemo = $observer->getEvent()->getCreditmemo();
            $this->saveMemoInRegistry($creditMemo);
            $this->apiOrderLayer->post($order, Api::ACTION_REFUND);
        } catch(\Exception $e) {
            $this->messageManager->addErrorMessage(
                __("Riskified API Respond : %1", $e->getMessage())
            );
            $this->logger->logException($e);
        }
    }
    public function saveMemoInRegistry($creditMemo)
    {
        $this->registry->register('creditMemo', $creditMemo);
    }
}