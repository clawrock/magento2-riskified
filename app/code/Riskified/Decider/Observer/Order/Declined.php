<?php

namespace Riskified\Decider\Observer\Order;

use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Escaper;
use Magento\Sales\Model\Service\InvoiceService;
use Riskified\Decider\Api\Config;
use Riskified\Decider\Api\Order as OrderApi;
use Riskified\Decider\Api\Order\Log;
use Riskified\Decider\Logger\Order;


class Declined implements ObserverInterface {
    /**
     * Module main logger class.
     *
     * @var Order
     */
    protected $logger;

    /**
     * Module api class.
     *
     * @var OrderApi
     */
    protected $apiOrder;

    /**
     * Api logger.
     *
     * @var Log
     */
    protected $apiOrderLogger;

    /**
     * Module config.
     *
     * @var Config
     */
    protected $apiConfig;


    /**
     * Magento's invoice service.
     *
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * Context class.
     *
     * @var Context
     */
    protected $context;

    /**
     * State class used to emulate admin scope during invoice creation.
     *
     * @var State
     */
    protected $state;

    /**
     * @var \Magento\Framework\Mail\Template\TransportBuilder
     */
    private $transportBuilder;

    /**
     * @var \Magento\Framework\Translate\Inline\StateInterface
     */
    private $inlineTranslation;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Framework\Escaper
     */
    private $escaper;

    /**
     * AutoInvoice constructor.
     *
     * @param Log                  $apiOrderLogger
     * @param Order                $logger
     * @param Config               $apiConfig
     * @param OrderApi             $orderApi
     * @param InvoiceService       $invoiceService
     * @param Context              $context
     */
    public function __construct(
        Log $apiOrderLogger,
        Order $logger,
        Config $apiConfig,
        OrderApi $orderApi,
        InvoiceService $invoiceService,
        Context $context,
        TransportBuilder $transportBuilder,
        StateInterface $inlineTranslation,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        Escaper $escaper
    ) {
        $this->logger = $logger;
        $this->context = $context;
        $this->apiOrder = $orderApi;
        $this->apiConfig = $apiConfig;
        $this->apiOrderLogger = $apiOrderLogger;
        $this->invoiceService = $invoiceService;
        $this->state = $context->getAppState();
        $this->transportBuilder = $transportBuilder;
        $this->inlineTranslation = $inlineTranslation;
        $this->storeManager = $storeManager;
        $this->escaper = $escaper;
    }

    /**
     * Observer execute
     * 
     * @param Observer $observer
     * @return $this
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getOrder();
        $storeId = $order->getStoreId();

        if (!$this->apiConfig->isDeclineNotificationEnabled($storeId)) {
            return $this;
        }

        $subject = $this->apiConfig->getDeclineNotificationSubject($storeId);
        $content = $this->apiConfig->getDeclineNotificationContent($storeId);

        $shortCodes = [
            "{{customer_name}}",
            "{{customer_firstname}}",
            "{{order_increment_id}}",
            "{{order_view_url}}",
            "{{products}}",
            "{{store_name}}",
        ];
        $formattedPayload = $this->getFormattedData($order);

        foreach ($shortCodes as $key => $value) {
            $subject = str_replace($value, $formattedPayload[$key], $subject);
            $content = str_replace($value, $formattedPayload[$key], $content);
        }

        try {
            if ($content == "") {
                throw new \Exception("Email content is empty");
            }

            if ($subject == "") {
                throw new \Exception("Email subject is empty");
            }

            $this->inlineTranslation->suspend();

            $transport = $this->transportBuilder
                ->setTemplateIdentifier('riskified_order_declined') // this code we have mentioned in the email_templates.xml
                ->setTemplateOptions(
                    [
                        'area' => \Magento\Framework\App\Area::AREA_FRONTEND, // this is using frontend area to get the template file
                        'store' => $order->getStoreId(),
                    ]
                )
                ->setTemplateVars(
                    [
                        "content" => $content,
                        "subject" => $subject,
                    ]
                )
                ->setFrom(
                    [
                        "email" => $this->apiConfig->getDeclineNotificationSenderEmail(),
                        "name" => $this->apiConfig->getDeclineNotificationSenderName(),
                    ]
                )
                ->addTo($order->getCustomerEmail(), $order->getCustomerName())
                ->getTransport();

            $transport->sendMessage();
            $this->inlineTranslation->resume();

            $fileLog = sprintf(
                __("Declination email was sent to customer %s (%s) for order #%s"),
                $order->getCustomerName(),
                $order->getCustomerEmail(),
                $order->getIncrementId()
            );

            $orderComment = sprintf(
                __("Declination email was sent to customer %s (%s)"),
                $order->getCustomerName(),
                $order->getCustomerEmail()
            );

            $this->logger->info($fileLog);

            $order
                ->addStatusHistoryComment($orderComment)
                ->setIsCustomerNotified(true);
            $order->save();
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
        }
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return array
     */
    private function getFormattedData($order)
    {
        $products = [];

        foreach ($order->getAllItems() as $item) {
            $products[] = $item->getName();
        }

        $name = $order->getCustomerName();

        if (!$name) {
            if ($order->getCustomerFirstname() && $order->getCustomerLastname()) {
                $name = sprintf("%s %s", $order->getCustomerFirstname(), $order->getCustomerLastname());
            }
        }

        if (!$name) {
            if ($order->getBillingAddress()) {
                $name = $order->getBillingAddress()->getName();

                if (!$name) {
                    $name = sprintf(
                        "%s %s",
                        $order->getBillingAddress()->getFirstname(),
                        $order->getBillingAddress()->getLastname()
                    );
                }
            }
        }

        $firstName = $order->getCustomerFirstname();

        if (!$firstName) {
            $firstName = $order->getBillingAddress()->getFirstname();
        }

        $data = [
            $name,
            $firstName,
            $order->getIncrementId(),
            $this->storeManager->getStore()->getUrl(
                "sales/order/view",
                [
                    "order_id" => $order->getId(),
                    "_secure" => true
                ]
            ),
            join(', ', $products),
            $this->storeManager->getStore()->getName()
        ];

        return $data;
    }
}