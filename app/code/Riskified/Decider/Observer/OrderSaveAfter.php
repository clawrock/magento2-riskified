<?php
namespace Riskified\Decider\Observer;

use Magento\Framework\Event\ObserverInterface;
use Riskified\Decider\Api\Api;
use Magento\Sales\Model\Order;

class OrderSaveAfter implements ObserverInterface
{
    /**
     * @var \Riskified\Decider\Logger\Order
     */
    private $_logger;
    /**
     * @var \Riskified\Decider\Api\Order
     */
    private $_orderApi;
    /**
     * @var \Magento\Framework\Registry
     */
    protected $_registry;

    /**
     * OrderSaveAfter constructor.
     * @param \Riskified\Decider\Logger\Order $logger
     * @param \Riskified\Decider\Api\Order $orderApi
     * @param \Magento\Framework\Registry $registry
     */
    public function __construct(
        \Riskified\Decider\Logger\Order $logger,
        \Riskified\Decider\Api\Order $orderApi,
        \Magento\Framework\Registry $registry
    ) {
        $this->_logger = $logger;
        $this->_orderApi = $orderApi;
        $this->_registry = $registry;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getOrder();
        if (!$order) {
            return;
        }

        $newState = $order->getState();

        if((int)$order->dataHasChangedFor('state') === 1) {
            $oldState = $order->getOrigData('state');

            if ($oldState == Order::STATE_HOLDED and $newState == Order::STATE_PROCESSING) {
                $this->_logger->debug(__("Order : " . $order->getId() . " not notifying on unhold action"));
                return;
            }

            $this->_logger->debug(__("Order: " . $order->getId() . " state changed from: " . $oldState . " to: " . $newState));

            // if we posted we should not re post
            if ($this->_registry->registry("riskified-order")) {
                $this->_logger->debug(__("Order : " . $order->getId() . " is already riskifiedInSave"));
                return;
            }

            try {
                if(!$this->_registry->registry("riskified-order")) {
                    $this->_registry->register("riskified-order", $order);
                }
                $this->_orderApi->post($order, Api::ACTION_UPDATE);

                $this->_registry->unregister("riskified-order");
            } catch (\Exception $e) {
                // There is no need to do anything here. The exception has already been handled and a retry scheduled.
                // We catch this exception so that the order is still saved in Magento.
            }

        } else {
            $this->_logger->debug(
                sprintf(
                    __("Order: %s state didn't change on save - not posting again: %s"),
                    $order->getIncrementId(),
                    $newState
                )
            );
        }
    }
}