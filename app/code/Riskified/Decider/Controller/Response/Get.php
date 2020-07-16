<?php

namespace Riskified\Decider\Controller\Response;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Riskified\Decider\Api\Api;
use Riskified\Decider\Api\Log;
use Riskified\Decider\Api\Order;
use \Riskified\DecisionNotification;
use Riskified\DecisionNotification\Exception\AuthorizationException;
use Riskified\DecisionNotification\Exception\BadPostJsonException;

class Get extends Action
{
    const STATUS_OK = 200;
    const STATUS_BAD = 400;
    const STATUS_UNAUTHORIZED = 401;
    const STATUS_INTERNAL_SERVER = 500;

    /**
     * @var Order
     */
    private $apiOrderLayer;

    /**
     * @var Api
     */
    private $api;

    /**
     * @var Log
     */
    private $apiLogger;

    /**
     * @param Context $context
     * @param Api $api
     * @param Order $apiOrder
     * @param Log $apiLogger
     */
    public function __construct(Context $context, Api $api, Order $apiOrder, Log $apiLogger)
    {
        $this->api = $api;
        $this->apiLogger = $apiLogger;
        $this->apiOrderLayer = $apiOrder;

        // CsrfAwareAction Magento2.3 compatibility
        if (interface_exists("\Magento\Framework\App\CsrfAwareActionInterface")) {
            $request = $context->getRequest();
            if ($request instanceof HttpRequest && $request->isPost()) {
                $request->setParam('isAjax', true);
                $headers = $request->getHeaders();
                $headers->addHeaderLine('X_REQUESTED_WITH', 'XMLHttpRequest');
                $request->setHeaders($headers);
            }
        }

        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|ResultInterface|void
     */
    public function execute()
    {
        $request = $this->getRequest();
        $response = $this->getResponse();
        $logger = $this->apiLogger;

        $logger->log("Start execute");

        $id = null;
        $msg = null;
        $logger->log("Start Try");

        try {
            $this->api->initSdk();
            $notification = $this->api->parseRequest($request);
            $id = $notification->id;

            if ($notification->status == 'test' && $id == 0) {
                $statusCode = self::STATUS_OK;
                $msg = 'Test notification received successfully';
                $logger->log("Test Notification received: ", serialize($notification));
            } else {
                $logger->log("Notification received: ", serialize($notification));
                $order = $this->apiOrderLayer->loadOrderByOrigId($id);

                if (!$order || !$order->getId()) {
                    $logger->log("ERROR: Unable to load order (" . $id . ")");
                    $statusCode = self::STATUS_BAD;
                    $msg = 'Could not find order to update.';
                } else {
                    $this->apiOrderLayer->update(
                        $order,
                        $notification->status,
                        $notification->oldStatus,
                        $notification->description
                    );
                    $statusCode = self::STATUS_OK;
                    $msg = 'Order-Update event triggered.';
                }
            }
        } catch (AuthorizationException $e) {
            $logger->logException($e);
            $statusCode = self::STATUS_UNAUTHORIZED;
            $msg = 'Authentication Failed.';
        } catch (BadPostJsonException $e) {
            $logger->logException($e);
            $statusCode = self::STATUS_BAD;
            $msg = "JSON Parsing Error.";
        } catch (\Exception $e) {
            $logger->log("ERROR: while processing notification for order $id");
            $logger->logException($e);
            $statusCode = self::STATUS_INTERNAL_SERVER;
            $msg = "Internal Error";
        }

        $logger->log($msg);
        $response->setHttpResponseCode($statusCode);
        $response->setHeader('Content-Type', 'application/json');
        $response->setBody('{ "order" : { "id" : "' . $id . '", "description" : "' . $msg . '" } }');
        $response->sendResponse();
        exit;
    }
}
