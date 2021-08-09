<?php
/**
 * Содержит события
 */

namespace Mindbox;

defined('ADMIN_MODULE_NAME') or define('ADMIN_MODULE_NAME', 'mindbox.marketing');

use Bitrix\Landing\Help;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;
use Bitrix\Sale;
use CUser;
use DateTime;
use DateTimeZone;
use Mindbox\DTO\DTO;
use Mindbox\DTO\V2\Requests\DiscountRequestDTO;
use Mindbox\DTO\V3\Requests\CustomerRequestDTO;
use Mindbox\DTO\V2\Requests\CustomerRequestDTO as CustomerRequestV2DTO;
use Mindbox\DTO\V2\Requests\LineRequestDTO;
use Mindbox\DTO\V2\Requests\OrderCreateRequestDTO;
use Mindbox\DTO\V2\Requests\OrderUpdateRequestDTO;
use Mindbox\DTO\V2\Requests\PreorderRequestDTO;

Loader::includeModule('catalog');
Loader::includeModule('sale');
Loader::includeModule('main');

/**
 * Class Event
 * @package Mindbox
 */
class Event
{
    protected $mindbox;

    const TRACKER_JS_FILENAME = "https://api.mindbox.ru/scripts/v1/tracker.js";

    /**
     * @bitrixModuleId main
     * @bitrixEventCode OnAfterUserAuthorize
     * @langEventName OnAfterUserAuthorize
     * @param $arUser
     * @return bool
     */
    public function OnAfterUserAuthorizeHandler($arUser)
    {

        if (!$arUser['user_fields']['ID']) {
            return true;
        }

        $userMindboxId = false;
        $rsUser = UserTable::getList(
            [
                'select' => [
                    'UF_MINDBOX_ID'
                ],
                'filter' => ['ID' => $arUser['user_fields']['ID']],
                'limit'  => 1
            ]
        )->fetch();
        if ($rsUser && isset($rsUser['UF_MINDBOX_ID']) && $rsUser['UF_MINDBOX_ID'] > 0) {
            $userMindboxId = $rsUser['UF_MINDBOX_ID'];
        }

        if (!isset($_REQUEST['AUTH_FORM']) && !isset($_REQUEST['TYPE']) || \Bitrix\Main\Context::getCurrent()->getRequest()->isAdminSection()) {
            return true;
        }

        if (empty($arUser['user_fields']['LAST_LOGIN']) && !$userMindboxId) {
            return true;
        }

        $mindbox = static::mindbox();
        if (!$mindbox) {
            return true;
        }

        if (isset($_SESSION['NEW_USER_MINDBOX']) && $_SESSION['NEW_USER_MINDBOX'] === true) {
            unset($_SESSION['NEW_USER_MINDBOX']);
            return true;
        }

        $mindboxId = Helper::getMindboxId($arUser['user_fields']['ID']);

        if (empty($mindboxId) && \COption::GetOptionString('mindbox.marketing', 'MODE') != 'standard') {
            $request = $mindbox->getClientV3()->prepareRequest(
                'POST',
                Options::getOperationName('getCustomerInfo'),
                new DTO([
                    'customer' => [
                        'ids' => [
                            Options::getModuleOption('WEBSITE_ID') => $arUser['user_fields']['ID']
                        ]
                    ]
                ])
            );

            try {
                $response = $request->sendRequest();
            } catch (Exceptions\MindboxClientException $e) {
                return false;
            }

            if ($response->getResult()->getCustomer()->getProcessingStatus() === 'Found') {
                $fields = [
                    'UF_EMAIL_CONFIRMED' => $response->getResult()->getCustomer()->getIsEmailConfirmed(),
                    'UF_MINDBOX_ID'      => $response->getResult()->getCustomer()->getId('mindboxId')
                ];

                $user = new CUser;
                $user->Update(
                    $arUser['user_fields']['ID'],
                    $fields
                );
                $dbUser['UF_MINDBOX_ID'] = $fields['UF_MINDBOX_ID'];
            } else {
                return true;
            }
        }

        $customer = new CustomerRequestDTO([
            'ids' => [
                Options::getModuleOption('WEBSITE_ID') => $arUser['user_fields']['ID']
            ]
        ]);

        $lastName = trim($arUser['user_fields']['LAST_NAME']);
        $firstName = trim($arUser['user_fields']['NAME']);
        $middleName = trim($arUser['user_fields']['SECOND_NAME']);
        $email = trim($arUser['user_fields']['EMAIL']);
        $mobilePhone = trim($arUser['user_fields']['PERSONAL_PHONE']);
        $phoneNumber = trim($arUser['user_fields']['PHONE_NUMBER']);

        if (!empty($phoneNumber)) {
            $mobilePhone = $phoneNumber;
        }

        if (!empty($lastName)) {
            $customer->setLastName($lastName);
        }

        if (!empty($firstName)) {
            $customer->setFirstName($firstName);
        }

        if (!empty($middleName)) {
            $customer->setMiddleName($middleName);
        }

        if (!empty($email)) {
            $customer->setEmail($email);
        }

        if (!empty($mobilePhone)) {
            $customer->setMobilePhone($mobilePhone);
        }

        try {
            $mindbox->customer()->authorize(
                $customer,
                Options::getOperationName('authorize')
            )->sendRequest();
        } catch (Exceptions\MindboxUnavailableException $e) {
            $lastResponse = $mindbox->customer()->getLastResponse();
            if ($lastResponse) {
                $request = $lastResponse->getRequest();
                QueueTable::push($request);
            }
        } catch (Exceptions\MindboxClientException $e) {
            return false;
        }

        return true;
    }

    /**
     * @bitrixModuleId main
     * @bitrixEventCode OnBeforeUserRegister
     * @langEventName OnBeforeUserRegister
     * @param $arFields
     * @return false
     */
    public function OnBeforeUserRegisterHandler(&$arFields)
    {
        if (\COption::GetOptionString('mindbox.marketing', 'MODE') == 'standard') {
            return $arFields;
        }

        global $APPLICATION, $USER;

        $mindbox = static::mindbox();
        if (!$mindbox) {
            return $arFields;
        }

        if (!isset($arFields['PERSONAL_PHONE'])) {
            $arFields['PERSONAL_PHONE'] = $arFields['PERSONAL_MOBILE'];
        }

        if (isset($arFields['PERSONAL_PHONE'])) {
            $arFields['PERSONAL_PHONE'] = Helper::formatPhone($arFields['PERSONAL_PHONE']);
        }

        if (isset($_SESSION['OFFLINE_REGISTER']) && $_SESSION['OFFLINE_REGISTER']) {
            return $arFields;
        }

        if (!$USER->CheckFields($arFields)) {
            $APPLICATION->ThrowException($USER->LAST_ERROR);
            return false;
        }

        $sex = substr(ucfirst($arFields['PERSONAL_GENDER']), 0, 1) ?: null;
        $fields = [
            'email'       => $arFields['EMAIL'],
            'lastName'    => $arFields['LAST_NAME'],
            'middleName'  => $arFields['SECOND_NAME'],
            'firstName'   => $arFields['NAME'],
            'mobilePhone' => $arFields['PERSONAL_PHONE'],
            'birthDate'   => Helper::formatDate($arFields['PERSONAL_BIRTHDAY']),
            'sex'         => $sex,
        ];

        $fields = array_filter($fields, function ($item) {
            return isset($item);
        });

        $customFields = Helper::getCustomFieldsForUser(0, $arFields);
        if (!empty($customFields)) {
            $fields['customFields'] = $customFields;
        }

        $customer = Helper::iconvDTO(new CustomerRequestDTO($fields));

        $isSubscribed = true;
        if ($arFields['UF_MB_IS_SUBSCRIBED'] === '0') {
            $isSubscribed = false;
        }
        $subscriptions = [
            'subscription' => [
                'brand'        => Options::getModuleOption('BRAND'),
                'isSubscribed' => $isSubscribed
            ]
        ];
        $customer = Helper::iconvDTO(new CustomerRequestDTO($fields));
        $customer->setSubscriptions($subscriptions);

        unset($fields);

        try {
            $registerResponse = $mindbox->customer()->register(
                $customer,
                Options::getOperationName('register'),
                true,
                Helper::isSync()
            )->sendRequest()->getResult();
        } catch (Exceptions\MindboxUnavailableException $e) {
            $APPLICATION->ThrowException(Loc::getMessage("MB_USER_REGISTER_LOYALTY_ERROR"));
            return false;
        } catch (Exceptions\MindboxClientException $e) {
            $APPLICATION->ThrowException(Loc::getMessage("MB_USER_REGISTER_LOYALTY_ERROR"));
            return false;
        }

        if ($registerResponse) {
            $registerResponse = Helper::iconvDTO($registerResponse, false);
            $status = $registerResponse->getStatus();


            if ($status === 'ValidationError') {
                try {
                    $fields = [
                        'email'       => $arFields['EMAIL'],
                        'mobilePhone' => $arFields['PERSONAL_PHONE'],
                    ];
                    $customer = Helper::iconvDTO(new CustomerRequestDTO($fields));

                    $checkCustomerResponse = $mindbox->customer()->CheckCustomer(
                        $customer,
                        Options::getOperationName('check'),
                        true,
                        Helper::isSync()
                    )->sendRequest()->getResult();
                } catch (\Exception $e) {
                    $APPLICATION->ThrowException(Loc::getMessage("MB_USER_REGISTER_LOYALTY_ERROR"));
                    return false;
                }

                $user = $checkCustomerResponse->getCustomer();
                $firstName = $user->getField('firstName');
                $lastName = $user->getField('lastName');
                $email = $user->getField('email');
                $context = \Bitrix\Main\Application::getInstance()->getContext();
                $siteId = $context->getSite();
                $password = randString(10);
                $mobilePhone = $user->getField('mobilePhone');
                $birthDate = $user->getField('birthDate');
                $sex = $user->getField('sex');

                if (empty($email)) {
                    $email = $mobilePhone . '@no-reply.com';
                }

                $arFields = [
                    "NAME"             => $firstName,
                    "LAST_NAME"        => $lastName,
                    "EMAIL"            => $email,
                    "LOGIN"            => $email,
                    'PERSONAL_PHONE'   => $mobilePhone,
                    'PHONE_NUMBER'     => $mobilePhone,
                    "LID"              => $siteId,
                    "ACTIVE"           => "Y",
                    "PASSWORD"         => $password,
                    "CONFIRM_PASSWORD" => $password,
                    'UF_MINDBOX_ID'    => $user->getId('mindboxId')
                ];

                if (!empty($birthDate)) {
                    $arFields['PERSONAL_BIRTHDAY'] = date('d.m.Y', strtotime($birthDate));
                }

                if (!empty($sex)) {
                    $arFields['PERSONAL_GENDER'] = (($sex == 'female') ? 'F' : 'M');
                }

                $USER->Add($arFields);

                $errors = $registerResponse->getValidationMessages();
                $APPLICATION->ThrowException(Helper::formatValidationMessages($errors));

                return false;
            }

            $customer = $registerResponse->getCustomer();


            if (!$customer) {
                return false;
            }

            $mindBoxId = $customer->getId('mindboxId');
            $_SESSION['NEW_USER_MB_ID'] = $mindBoxId;
            $_SESSION['NEW_USER_MINDBOX'] = true;
            $arFields['UF_MINDBOX_ID'] = $mindBoxId;
        }
    }

    /**
     * @bitrixModuleId sale
     * @bitrixEventCode OnBeforeSaleShipmentSetField
     * @langEventName OnBeforeSaleShipmentSetField
     * @notCompatible true
     * @param Main\Event $event
     * @return bool
     */
    public function OnBeforeSaleShipmentSetFieldHandler(Main\Event $event)
    {
        $orderEntity = $event->getParameter('ENTITY');
        $orderId = $orderEntity->getField('ORDER_ID');
        $statusValue = $event->getParameter('VALUE');

        if ($event->getParameter('NAME') === 'STATUS_ID') {
            Helper::updateMindboxOrderStatus($orderId, $statusValue);
        }
    }

    /**
     * @bitrixModuleId sale
     * @bitrixEventCode OnSaleStatusOrder
     * @langEventName OnSaleStatusOrder
     * @param Main\Event $event
     * @return bool
     */
    public function OnSaleStatusOrderHandler($orderId, $newOrderStatus)
    {
        Helper::updateMindboxOrderStatus($orderId, $newOrderStatus);
    }

    /**
     * @bitrixModuleId sale
     * @bitrixEventCode OnSaleCancelOrder
     * @langEventName OnSaleCancelOrder
     * @param $orderId
     * @param $cancelFlag
     * @param $cancelDesc
     * @return void
     */
    public function OnSaleCancelOrderHandler($orderId, $cancelFlag, $cancelDesc)
    {
        $statusCodeAlias = ($cancelFlag === 'Y') ? 'CANCEL' : 'CANCEL_ABORT';
        Helper::updateMindboxOrderStatus($orderId, $statusCodeAlias);
    }

    /**
     * @bitrixModuleId main
     * @bitrixEventCode OnAfterUserRegister
     * @langEventName OnAfterUserRegister
     * @param $arFields
     * @return bool
     */
    public function OnAfterUserRegisterHandler(&$arFields)
    {
        global $APPLICATION;
        $mindbox = static::mindbox();
        if (!$mindbox) {
            return $arFields;
        }
        // all for standard mode
        if (\COption::GetOptionString('mindbox.marketing', 'MODE') == 'standard') {
            $mindBoxId = $_SESSION['NEW_USER_MB_ID'];
            unset($_SESSION['NEW_USER_MB_ID']);

            $fields = [
                'UF_EMAIL_CONFIRMED' => '0',
                'UF_MINDBOX_ID'      => $mindBoxId
            ];

            $user = new CUser;
            $user->Update(
                $arFields['USER_ID'],
                $fields
            );

            if ($arFields['USER_ID']) {
                $sex = substr(ucfirst($arFields['PERSONAL_GENDER']), 0, 1) ?: null;
                $fields = [
                    'email'       => $arFields['EMAIL'],
                    'lastName'    => $arFields['LAST_NAME'],
                    'middleName'  => $arFields['SECOND_NAME'],
                    'firstName'   => $arFields['NAME'],
                    'mobilePhone' => $arFields['PERSONAL_PHONE'],
                    'birthDate'   => Helper::formatDate($arFields['PERSONAL_BIRTHDAY']),
                    'sex'         => $sex,
                    'ids'         => [Options::getModuleOption('WEBSITE_ID') => $arFields['USER_ID']]
                ];

                $fields = array_filter($fields, function ($item) {
                    return isset($item);
                });

                if (!isset($fields)) {
                    return true;
                }

                $customFields = Helper::getCustomFieldsForUser(0, $arFields);
                if (!empty($customFields)) {
                    $fields['customFields'] = $customFields;
                }

                $customer = new CustomerRequestDTO($fields);

                unset($fields);

                $isSubscribed = true;
                if ($arFields['UF_MB_IS_SUBSCRIBED'] === '0') {
                    $isSubscribed = false;
                }

                $subscriptions = [
                    'subscription' => [
                        'brand'          => Options::getModuleOption('BRAND'),
                        'isSubscribed'   => $isSubscribed
                    ]
                ];
                $customer->setSubscriptions($subscriptions);

                try {
                    $mindbox->customer()->register(
                        $customer,
                        Options::getOperationName('register'),
                        true,
                        Helper::isSync()
                    )->sendRequest();
                } catch (Exceptions\MindboxClientException $e) {
                    //$APPLICATION->ThrowException(Loc::getMessage('MB_USER_EDIT_ERROR'));
                    //return false;
                }
            }
        } else {
            if ($arFields['UF_MINDBOX_ID']) {
                $request = $mindbox->getClientV3()->prepareRequest(
                    'POST',
                    Options::getOperationName('getCustomerInfo'),
                    new DTO([
                        'customer' => [
                            'ids' => [
                                'mindboxId' => $arFields['UF_MINDBOX_ID']
                            ]
                        ]
                    ])
                );

                try {
                    $response = $request->sendRequest();
                } catch (Exceptions\MindboxClientException $e) {
                    $APPLICATION->ThrowException($e->getMessage());
                    return false;
                }

                if ($response->getResult()->getCustomer()->getProcessingStatus() === 'Found') {
                    $fields = [
                        'UF_EMAIL_CONFIRMED' => $response->getResult()->getCustomer()->getIsEmailConfirmed(),
                        'UF_MINDBOX_ID'      => $response->getResult()->getCustomer()->getId('mindboxId')
                    ];

                    $user = new CUser;
                    $user->Update(
                        $arFields['USER_ID'],
                        $fields
                    );
                    unset($_SESSION['NEW_USER_MB_ID']);
                } else {
                    return true;
                }
            }
        }

        return $arFields;
    }

    /**
     * @bitrixModuleId main
     * @bitrixEventCode OnBeforeUserUpdate
     * @langEventName OnBeforeUserUpdate
     * @param $arFields
     * @return bool
     */
    public function OnBeforeUserUpdateHandler(&$arFields)
    {
        global $APPLICATION;

        if (isset($_REQUEST['c']) &&
            $_REQUEST['c'] === 'mindbox:auth.sms' &&
            isset($_REQUEST['action']) &&
            $_REQUEST['action'] === 'checkCode'
        ) {
            return $arFields;
        }

        $mindbox = static::mindbox();

        if (!$mindbox) {
            return $arFields;
        }

        $dbUser = UserTable::getList(
            [
                'select' => ['EMAIL', 'PERSONAL_PHONE', 'UF_MINDBOX_ID'],
                'filter' => ['ID' => $arFields['ID']]
            ]
        )->fetch();

        if (!$dbUser) {
            return false;
        }

        if (!isset($arFields['PERSONAL_PHONE'])) {
            $arFields['PERSONAL_PHONE'] = $arFields['PERSONAL_MOBILE'];
        }

        if (isset($arFields['EMAIL']) && $dbUser['EMAIL'] != $arFields['EMAIL']) {
            $arFields['UF_EMAIL_CONFIRMED'] = '0';
        }

        if (isset($arFields['PERSONAL_PHONE'])) {
            $arFields['PERSONAL_PHONE'] = Helper::formatPhone($arFields['PERSONAL_PHONE']);
        }

        if (isset($_SESSION['OFFLINE_REGISTER']) && $_SESSION['OFFLINE_REGISTER']) {
            unset($_SESSION['OFFLINE_REGISTER']);

            return true;
        }

        $userId = $arFields['ID'];
        $mindboxId = $dbUser['UF_MINDBOX_ID'];

        if (!empty($userId)) {
            $sex = substr(ucfirst($arFields['PERSONAL_GENDER']), 0, 1) ?: null;

            $fields = [
                'birthDate'   => Helper::formatDate($arFields["PERSONAL_BIRTHDAY"]),
                'firstName'   => $arFields['NAME'],
                'middleName'  => $arFields['SECOND_NAME'],
                'lastName'    => $arFields["LAST_NAME"],
                'mobilePhone' => $arFields['PERSONAL_PHONE'],
                'email'       => $arFields['EMAIL'],
                'sex'         => $sex
            ];

            $customFields = Helper::getCustomFieldsForUser($userId, $arFields);
            if (!empty($customFields)) {
                $fields['customFields'] = $customFields;
            }

            $fields = array_filter($fields, function ($item) {
                return isset($item);
            });

            if (!isset($fields)) {
                return true;
            }

            if (empty($mindboxId) && \COption::GetOptionString('mindbox.marketing', 'MODE') !== 'standard') {
                $request = $mindbox->getClientV3()->prepareRequest(
                    'POST',
                    Options::getOperationName('getCustomerInfo'),
                    new DTO([
                        'customer' => [
                            'ids' => [
                                Options::getModuleOption('WEBSITE_ID') => $arFields['ID']
                            ]
                        ]
                    ])
                );

                try {
                    $response = $request->sendRequest();
                } catch (Exceptions\MindboxClientException $e) {
                    $APPLICATION->ThrowException(Loc::getMessage('MB_USER_EDIT_ERROR'));
                    return false;
                }

                if ($response->getResult()->getCustomer()->getProcessingStatus() === 'Found') {
                    $mindboxId = $response->getResult()->getCustomer()->getId('mindboxId');
                    $arFields['UF_MINDBOX_ID'] = $mindboxId;
                }
            }

            if (\COption::GetOptionString('mindbox.marketing', 'MODE') === 'standard') {
                $fields['ids'][Options::getModuleOption('WEBSITE_ID')] = $userId;
            } else {
                $fields['ids']['mindboxId'] = $mindboxId;
            }

            $customer = new CustomerRequestDTO($fields);
            $customer = Helper::iconvDTO($customer);
            unset($fields);

            try {
                $updateResponse = $mindbox->customer()->edit(
                    $customer,
                    Options::getOperationName('edit'),
                    true
                )->sendRequest()->getResult();
            } catch (Exceptions\MindboxClientException $e) {
                $APPLICATION->ThrowException(Loc::getMessage('MB_USER_EDIT_ERROR'));
                return false;
            }

            $updateResponse = Helper::iconvDTO($updateResponse, false);
            $status = $updateResponse->getStatus();

            if ($status === 'ValidationError') {
                $errors = $updateResponse->getValidationMessages();
                $APPLICATION->ThrowException(Helper::formatValidationMessages($errors));

                return false;
            }
        }

        return true;
    }

    /**
     * @bitrixModuleId sale
     * @bitrixEventCode OnSaleOrderBeforeSaved
     * @langEventName OnSaleOrderBeforeSaved
     * @notCompatible true
     * @param $event
     * @return Main\EventResult
     */
    public function OnSaleOrderBeforeSavedHandler($event)
    {
        $order = $event->getParameter("ENTITY");
        $values = $event->getParameter("VALUES");

        $isNewOrder = Helper::isNewOrder($values);

        if (!$isNewOrder) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        $standardMode = \COption::GetOptionString('mindbox.marketing', 'MODE') === 'standard';

        if ($standardMode) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        global $USER;

        if (!$USER || is_string($USER)) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        $mindbox = static::mindbox();
        if (!$mindbox) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        $basket = $order->getBasket();
        global $USER;

        $delivery = $order->getDeliverySystemId();
        $delivery = current($delivery);

        $payments = [];
        $paymentCollection = $order->getPaymentCollection();
        foreach ($paymentCollection as $payment) {
            $payments[] = [
                'type'   => $payment->getPaymentSystemName(),
                'amount' => $payment->getSum()
            ];
        }

        $rsUser = \CUser::GetByID($order->getUserId());
        $arUser = $rsUser->Fetch();

        $orderDTO = new \Mindbox\DTO\V3\Requests\OrderCreateRequestDTO();
        $basketItems = $basket->getBasketItems();
        $lines = [];
        $i = 1;
        foreach ($basketItems as $basketItem) {
            if ($basketItem->getField('CAN_BUY') == 'N') {
                continue;
            }

            $propertyCollection = $order->getPropertyCollection();
            $ar = $propertyCollection->getArray();
            foreach ($ar['properties'] as $arProperty) {
                $arProperty['CODE'] = Helper::sanitizeNamesForMindbox($arProperty['CODE']);
                $arOrderProperty[$arProperty['CODE']] = current($arProperty['VALUE']);
            }
            $productBasePrice = Helper::getBasePrice($basketItem);
            $requestedPromotions = Helper::getRequestedPromotions($basketItem, $order);

            $arLine = [
                'lineNumber'       => $i++,
                'basePricePerItem' => $productBasePrice,
                'quantity'         => $basketItem->getQuantity(),
                'lineId'           => $basketItem->getId(),
                'product'          => [
                    'ids' => [
                        Options::getModuleOption('EXTERNAL_SYSTEM') => Helper::getElementCode($basketItem->getProductId())
                    ]
                ],
                'status'           => [
                    'ids' => [
                        'externalId' => 'CheckedOut'
                    ]
                ]
            ];

            if (!empty($requestedPromotions)) {
                $arLine['requestedPromotions'] = $requestedPromotions;
            }

            $lines[] = $arLine;
        }

        if (empty($lines)) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        $arCoupons = [];
        if ($_SESSION['PROMO_CODE'] && !empty($_SESSION['PROMO_CODE'])) {
            $arCoupons['ids']['code'] = $_SESSION['PROMO_CODE'];
        }

        unset($_SESSION[ 'MINDBOX_TRANSACTION_ID' ]);

        $arOrder = [
            'ids'          => [
                Options::getModuleOption('TRANSACTION_ID') => ''
            ],
            'lines'        => $lines,
            'transaction'  => [
                'ids' => [
                    'externalId' => Helper::getTransactionId()
                ]
            ],
            'payments'     => $payments,
            'deliveryCost' => $order->getDeliveryPrice(),
            'totalPrice'   => $_SESSION['TOTAL_PRICE'] + $order->getDeliveryPrice()
        ];

        if (!empty($arCoupons)) {
            $arOrder['coupons'] = [$arCoupons];
        }

        $bonuses = $_SESSION['PAY_BONUSES'] ?: 0;
        if ($bonuses && is_object($USER) && $USER->IsAuthorized()) {
            $bonusPoints = [
                'amount' => $bonuses
            ];
            $arOrder['bonusPoints'] = [
                $bonusPoints
            ];
        }

        $customer = new CustomerRequestV2DTO();

        if (is_object($USER) && $USER->IsAuthorized()) {
            $mindboxId = Helper::getMindboxId($USER->GetID());
        }

        $customFields = [];
        $propertyCollection = $order->getPropertyCollection();
        $ar = $propertyCollection->getArray();
        foreach ($ar['properties'] as $arProperty) {
            $arProperty['CODE'] = Helper::sanitizeNamesForMindbox($arProperty['CODE']);
            if (count($arProperty['VALUE']) === 1) {
                $value = current($arProperty['VALUE']);
            } else {
                $value = $arProperty['VALUE'];
            }
            $arOrderProperty[$arProperty['CODE']] = array_pop($arProperty['VALUE']);
            if (!empty($customName = Helper::getMatchByCode($arProperty['CODE']))) {
                $customFields[$customName] = $value;
            }
        }

        $customFields['deliveryType'] = $delivery;

        $arOrder['customFields'] = $customFields;

        if (!empty($arOrderProperty['EMAIL'])) {
            $customer->setEmail($arOrderProperty['EMAIL']);
            $arOrder['email'] = $arOrderProperty['EMAIL'];
        }
        /*
        if(!empty($arOrderProperty[ 'FIO' ])) {
            $customer->setLastName($arOrderProperty[ 'FIO' ]);
        }
        if(!empty($arOrderProperty[ 'NAME' ])){
            $customer->setFirstName($arOrderProperty[ 'NAME' ]);
        }
        */
        if (!empty($arOrderProperty['PHONE'])) {
            $customer->setMobilePhone($arOrderProperty['PHONE']);
            $arOrder['mobilePhone'] = $arOrderProperty['PHONE'];
        }

        $orderDTO->setField('order', $arOrder);

        if (!(\Mindbox\Helper::isUnAuthorizedOrder($arUser) || (is_object($USER) && !$USER->IsAuthorized()))) {
            $customer->setId('mindboxId', $mindboxId);
        }

        if (is_object($USER) && $USER->IsAuthorized() && \Mindbox\Helper::isUnAuthorizedOrder($arUser)) {
            $customer->setId(Options::getModuleOption('WEBSITE_ID'), $USER->GetID());
        }

        $orderDTO->setCustomer($customer);

        try {
            if (\Mindbox\Helper::isUnAuthorizedOrder($arUser) || (is_object($USER) && !$USER->IsAuthorized())) {
                $createOrderResult = $mindbox->order()->beginUnauthorizedOrderTransaction(
                    $orderDTO,
                    Options::getOperationName('beginUnauthorizedOrderTransaction')
                )->sendRequest();
            } else {
                $createOrderResult = $mindbox->order()->beginAuthorizedOrderTransaction(
                    $orderDTO,
                    Options::getOperationName('beginAuthorizedOrderTransaction')
                )->sendRequest();
            }

            if ($createOrderResult->getValidationErrors()) {
                $strValidationError = '';
                $validationErrors = $createOrderResult->getValidationErrors();
                $arValidationError = $validationErrors->getFieldsAsArray();
                foreach ($arValidationError as $validationError) {
                    $strValidationError .= $validationError['message'];
                }
                try {
                    $orderDTO = new OrderCreateRequestDTO();
                    $orderDTO->setField('order', [
                        'transaction' => [
                            "ids" => [
                                "externalId" => Helper::getTransactionId()
                            ]
                        ]
                    ]);
                    $createOrderResult = $mindbox->order()->rollbackOrderTransaction(
                        $orderDTO,
                        Options::getOperationName('rollbackOrderTransaction')
                    )->sendRequest();

                    unset($_SESSION['TOTAL_PRICE']);

                    return new \Bitrix\Main\EventResult(
                        \Bitrix\Main\EventResult::ERROR,
                        new \Bitrix\Sale\ResultError($strValidationError, 'SALE_EVENT_WRONG_ORDER'),
                        'sale'
                    );
                } catch (Exceptions\MindboxClientErrorException $e) {
                    return new Main\EventResult(Main\EventResult::ERROR);
                } catch (Exceptions\MindboxUnavailableException $e) {
                    return new Main\EventResult(Main\EventResult::SUCCESS);
                } catch (Exceptions\MindboxClientException $e) {
                    $request = $mindbox->order()->getRequest();
                    if ($request) {
                        QueueTable::push($request);
                    }
                }
            } elseif ($createOrderResult->getResult()->getOrder()->getField('processingStatus') === 'PriceHasBeenChanged') {
                return new \Bitrix\Main\EventResult(
                    \Bitrix\Main\EventResult::ERROR,
                    new \Bitrix\Sale\ResultError(Loc::getMessage("MB_ORDER_PROCESSING_STATUS_CHANGED"), 'SALE_EVENT_WRONG_ORDER'),
                    'sale'
                );
            } else {
                $createOrderResult = $createOrderResult->getResult()->getField('order');
                $_SESSION[ 'MINDBOX_ORDER' ] = $createOrderResult ? $createOrderResult->getId('mindboxId') : false;
                return new Main\EventResult(Main\EventResult::SUCCESS);
            }
            $createOrderResult = $createOrderResult->getResult()->getField('order');
            $_SESSION['MINDBOX_ORDER'] = $createOrderResult ? $createOrderResult->getId('mindboxId') : false;
        } catch (Exceptions\MindboxClientErrorException $e) {
            $orderDTO = new OrderCreateRequestDTO();
            $orderDTO->setField('order', [
                'transaction' => [
                    "ids" => [
                        "externalId" => Helper::getTransactionId()
                    ]
                ]
            ]);
            $mindbox->order()->rollbackOrderTransaction(
                $orderDTO,
                Options::getOperationName('rollbackOrderTransaction')
            )->sendRequest();

            unset($_SESSION['TOTAL_PRICE']);
            unset($_SESSION[ 'MINDBOX_TRANSACTION_ID' ]);

            return new \Bitrix\Main\EventResult(
                \Bitrix\Main\EventResult::ERROR,
                new \Bitrix\Sale\ResultError($e->getMessage(), 'SALE_EVENT_WRONG_ORDER'),
                'sale'
            );
        } catch (Exceptions\MindboxUnavailableException $e) {
            unset($_SESSION[ 'MINDBOX_TRANSACTION_ID' ]);
            return new Main\EventResult(Main\EventResult::SUCCESS);
        } catch (Exceptions\MindboxClientException $e) {
            unset($_SESSION[ 'MINDBOX_TRANSACTION_ID' ]);
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        return new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS);
    }

    /**
     * @bitrixModuleId sale
     * @bitrixEventCode OnSaleOrderSaved
     * @langEventName OnSaleOrderSaved
     * @notCompatible true
     * @param $event
     * @return Main\EventResult
     */
    public function OnSaleOrderSavedHandler($event)
    {
        $order = $event->getParameter("ENTITY");
        $oldValues = $event->getParameter("VALUES");
        $isNew = $event->getParameter("IS_NEW");

        if (!$isNew) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        $mindbox = static::mindbox();
        if (!$mindbox) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        $payments = [];
        $paymentCollection = $order->getPaymentCollection();
        foreach ($paymentCollection as $payment) {
            $payments[] = [
                'type'   => $payment->getPaymentSystemName(),
                'amount' => $payment->getSum()
            ];
        }

        if (\COption::GetOptionString('mindbox.marketing', 'MODE') == 'loyalty') {

            /** @var \Bitrix\Sale\Basket $basket */
            $basket = $order->getBasket();
            global $USER;

            $delivery = $order->getDeliverySystemId();
            $delivery = current($delivery);

            $rsUser = \CUser::GetByID($order->getUserId());
            $arUser = $rsUser->Fetch();

            $offlineOrderDTO = new \Mindbox\DTO\V3\Requests\OrderCreateRequestDTO();
            $basketItems = $basket->getBasketItems();
            $lines = [];
            $i = 1;
            foreach ($basketItems as $basketItem) {
                if ($basketItem->getField('CAN_BUY') == 'N') {
                    continue;
                }
                $productBasePrice = $basketItem->getBasePrice();
                $requestedPromotions = Helper::getRequestedPromotions($basketItem, $order);

                $propertyCollection = $order->getPropertyCollection();
                $ar = $propertyCollection->getArray();
                foreach ($ar['properties'] as $arProperty) {
                    $arProperty['CODE'] = Helper::sanitizeNamesForMindbox($arProperty['CODE']);
                    $arOrderProperty[$arProperty['CODE']] = current($arProperty['VALUE']);
                }

                $arLine = [
                    'lineNumber'       => $i++,
                    'basePricePerItem' => $productBasePrice,
                    'quantity'         => $basketItem->getQuantity(),
                    'lineId'           => $basketItem->getId(),
                    'product'          => [
                        'ids' => [
                            Options::getModuleOption('EXTERNAL_SYSTEM') => Helper::getElementCode($basketItem->getProductId())
                        ]
                    ],
                    'status'           => [
                        'ids' => [
                            'externalId' => 'CheckedOut'
                        ]
                    ]
                ];

                if (!empty($requestedPromotions)) {
                    $arLine['requestedPromotions'] = $requestedPromotions;
                }

                $lines[] = $arLine;
            }

            if (empty($lines)) {
                return new Main\EventResult(Main\EventResult::SUCCESS);
            }

            $arCoupons = [];
            if ($_SESSION['PROMO_CODE'] && !empty($_SESSION['PROMO_CODE'])) {
                $arCoupons['ids']['code'] = $_SESSION['PROMO_CODE'];
            }

            $arOrder = [
                'ids'          => [
                    Options::getModuleOption('TRANSACTION_ID') => $order->getId(),
                    //'mindboxId' =>  $_SESSION['MINDBOX_ORDER']
                ],
                'lines'        => $lines,
                'deliveryCost' => $order->getDeliveryPrice()
            ];

            if (!empty($arCoupons)) {
                $arOrder['coupons'] = [$arCoupons];
            }

            $bonuses = $_SESSION['PAY_BONUSES'] ?: 0;
            if ($bonuses && is_object($USER) && $USER->IsAuthorized()) {
                $bonusPoints = [
                    'amount' => $bonuses
                ];
                $arOrder['bonusPoints'] = [
                    $bonusPoints
                ];
            }

            $customer = new CustomerRequestV2DTO();

            if (is_object($USER) && $USER->IsAuthorized()) {
                $mindboxId = Helper::getMindboxId($USER->GetID());
            }

            $customFields = [];
            $propertyCollection = $order->getPropertyCollection();
            $ar = $propertyCollection->getArray();
            foreach ($ar['properties'] as $arProperty) {
                $arProperty['CODE'] = Helper::sanitizeNamesForMindbox($arProperty['CODE']);
                if (count($arProperty['VALUE']) === 1) {
                    $value = current($arProperty['VALUE']);
                } else {
                    $value = $arProperty['VALUE'];
                }
                $arOrderProperty[$arProperty['CODE']] = current($arProperty['VALUE']);
                if (!empty($customName = Helper::getMatchByCode($arProperty['CODE']))) {
                    $customFields[$customName] = $value;
                }
            }

            $customFields['deliveryType'] = $delivery;

            if (!empty($arOrderProperty['EMAIL'])) {
                $customer->setEmail($arOrderProperty['EMAIL']);
                $arOrder['email'] = $arOrderProperty['EMAIL'];
            }
            /*
            if(!empty($arOrderProperty[ 'FIO' ])) {
                $customer->setLastName($arOrderProperty[ 'FIO' ]);
            }
            if(!empty($arOrderProperty[ 'NAME' ])){
                $customer->setFirstName($arOrderProperty[ 'NAME' ]);
            }
            */
            if (!empty($arOrderProperty['PHONE'])) {
                $customer->setMobilePhone($arOrderProperty['PHONE']);
                $arOrder['mobilePhone'] = $arOrderProperty['PHONE'];
            }

            $offlineOrderDTO->setField('order', $arOrder);
            //$customer->setId('websiteId', $USER->GetID());

            $offlineOrderDTO->setCustomer($customer);

            try {
                unset($_SESSION['PROMO_CODE_AMOUNT'], $_SESSION['PROMO_CODE']);

                $orderDTO = new OrderCreateRequestDTO();
                $orderDTO->setField('order', [
                    'ids'         => [
                        Options::getModuleOption('TRANSACTION_ID') => $order->getId(),
                        'mindboxId'                                => $_SESSION['MINDBOX_ORDER']
                    ],
                    'transaction' => [
                        "ids" => [
                            "externalId" => Helper::getTransactionId()
                        ]
                    ]
                ]);
                $createOrderResult = $mindbox->order()->commitOrderTransaction(
                    $orderDTO,
                    Options::getOperationName('commitOrderTransaction')
                )->sendRequest();
                unset($_SESSION['MINDBOX_TRANSACTION_ID']);
                unset($_SESSION['PAY_BONUSES']);
                unset($_SESSION['TOTAL_PRICE']);
            } catch (Exceptions\MindboxClientErrorException $e) {
                unset($_SESSION['PAY_BONUSES']);
                unset($_SESSION['TOTAL_PRICE']);

                return new Main\EventResult(Main\EventResult::ERROR);
            } catch (Exceptions\MindboxUnavailableException $e) {
                try {
                    $mindbox->order()->saveOfflineOrder(
                        $offlineOrderDTO,
                        Options::getOperationName('saveOfflineOrder')
                    )->sendRequest();
                } catch (Exceptions\MindboxUnavailableException $e) {
                    $lastResponse = $mindbox->order()->getLastResponse();
                    if ($lastResponse) {
                        $request = $lastResponse->getRequest();
                        QueueTable::push($request);
                    }

                    return new Main\EventResult(Main\EventResult::SUCCESS);
                } catch (Exceptions\MindboxClientException $e) {
                    $request = $mindbox->order()->getRequest();
                    if ($request) {
                        QueueTable::push($request);
                    }
                }
                unset($_SESSION['PAY_BONUSES']);
                unset($_SESSION['TOTAL_PRICE']);

                return new Main\EventResult(Main\EventResult::SUCCESS);
            } catch (Exceptions\MindboxClientException $e) {
                try {
                    $mindbox->order()->saveOfflineOrder(
                        $offlineOrderDTO,
                        Options::getOperationName('saveOfflineOrder')
                    )->sendRequest();
                } catch (Exceptions\MindboxUnavailableException $e) {
                    $lastResponse = $mindbox->order()->getLastResponse();
                    if ($lastResponse) {
                        $request = $lastResponse->getRequest();
                        QueueTable::push($request);
                    }

                    return new Main\EventResult(Main\EventResult::SUCCESS);
                } catch (Exceptions\MindboxClientException $e) {
                    $request = $mindbox->order()->getRequest();
                    if ($request) {
                        QueueTable::push($request);
                    }
                }
                unset($_SESSION['PAY_BONUSES']);
                unset($_SESSION['TOTAL_PRICE']);

                return new Main\EventResult(Main\EventResult::SUCCESS);
            }
        } else {    //  standard mode

            /** @var \Bitrix\Sale\Basket $basket */
            $basket = $order->getBasket();
            $delivery = $order->getDeliverySystemId();
            $delivery = current($delivery);
            global $USER;

            if (!$USER || is_string($USER)) {
                return new Main\EventResult(Main\EventResult::SUCCESS);
            }

            $rsUser = \CUser::GetByID($order->getUserId());
            $arUser = $rsUser->Fetch();


            $orderDTO = new OrderCreateRequestDTO();
            $basketItems = $basket->getBasketItems();
            $lines = [];

            foreach ($basketItems as $basketItem) {
                if ($basketItem->getField('CAN_BUY') == 'N') {
                    continue;
                }

                $line = new LineRequestDTO();
                $catalogPrice = \CPrice::GetBasePrice($basketItem->getProductId());
                $catalogPrice = $catalogPrice['PRICE'] ?: 0;
                $lines[] = [
                    'basePricePerItem' => $catalogPrice,
                    'quantity'         => $basketItem->getQuantity(),
                    'lineId'           => $basketItem->getId(),
                    'product'          => [
                        'ids' => [
                            Options::getModuleOption('EXTERNAL_SYSTEM') => Helper::getElementCode($basketItem->getProductId())
                        ]
                    ]
                ];
            }

            if (empty($lines)) {
                return new Main\EventResult(Main\EventResult::SUCCESS);
            }

            $customer = new CustomerRequestV2DTO();
            $mindboxId = Helper::getMindboxId($order->getUserId());
            $customFields = [];
            $propertyCollection = $order->getPropertyCollection();
            $ar = $propertyCollection->getArray();
            foreach ($ar['properties'] as $arProperty) {
                $arProperty['CODE'] = Helper::sanitizeNamesForMindbox($arProperty['CODE']);
                if (count($arProperty['VALUE']) === 1) {
                    $value = current($arProperty['VALUE']);
                } else {
                    $value = $arProperty['VALUE'];
                }
                $arOrderProperty[$arProperty['CODE']] = current($arProperty['VALUE']);
                if (!empty($customName = Helper::getMatchByCode($arProperty['CODE']))) {
                    $customFields[$customName] = $value;
                }
            }

            $customFields['deliveryType'] = $delivery;

            $orderDTO->setField('order', [
                'ids'          => [
                    Options::getModuleOption('TRANSACTION_ID') => $order->getId()
                ],
                'lines'        => $lines,
                'email'        => $arOrderProperty['EMAIL'],
                'mobilePhone'  => $arOrderProperty['PHONE'],
                'payments'     => $payments,
                'customFields' => $customFields
            ]);

            $customer->setEmail($arOrderProperty['EMAIL']);
            $customer->setLastName($arOrderProperty['FIO']);
            $customer->setFirstName($arOrderProperty['NAME']);
            $customer->setMobilePhone($arOrderProperty['PHONE']);

            if (\Mindbox\Helper::isUnAuthorizedOrder($arUser) || (is_object($USER) && !$USER->IsAuthorized())) {
                //  unauthorized user
            } else {
                //  authorized user
                $customer->setId(Options::getModuleOption('WEBSITE_ID'), $order->getUserId());
            }

            $isSubscribed = true;
            if ($arOrderProperty['UF_MB_IS_SUBSCRIBED'] === 'N') {
                $isSubscribed = false;
            }
            $subscriptions = [
                'subscription' => [
                    'brand'          => Options::getModuleOption('BRAND'),
                    'isSubscribed'   => $isSubscribed
                ]
            ];
            $customer->setSubscriptions($subscriptions);
            $orderDTO->setCustomer($customer);

            $discounts = [];
            $bonuses = $_SESSION['PAY_BONUSES'];
            if (!empty($bonuses)) {
                $discounts[] = new DiscountRequestDTO([
                    'type'        => 'balance',
                    'amount'      => $bonuses,
                    'balanceType' => [
                        'ids' => ['systemName' => 'Main']
                    ]
                ]);
            }

            $code = $_SESSION['PROMO_CODE'];
            if ($code) {
                $discounts[] = new DiscountRequestDTO([
                    'type'   => 'promoCode',
                    'id'     => $code,
                    'amount' => $_SESSION['PROMO_CODE_AMOUNT'] ?: 0
                ]);
            }

            if (!empty($discounts)) {
                $orderDTO->setDiscounts($discounts);
            }

            try {
                if (\Mindbox\Helper::isUnAuthorizedOrder($arUser) || (is_object($USER) && !$USER->IsAuthorized())) {
                    $createOrderResult = $mindbox->order()->CreateUnauthorizedOrder(
                        $orderDTO,
                        Options::getOperationName('createUnauthorizedOrder')
                    )->sendRequest();
                } else {
                    $createOrderResult = $mindbox->order()->CreateAuthorizedOrder(
                        $orderDTO,
                        Options::getOperationName('createAuthorizedOrder')
                    )->sendRequest();
                }

                if ($createOrderResult->getValidationErrors()) {
                    return new Main\EventResult(Main\EventResult::ERROR);
                }

                $createOrderResult = $createOrderResult->getResult()->getField('order');
                $_SESSION['MINDBOX_ORDER'] = $createOrderResult ? $createOrderResult->getId('mindbox') : false;
            } catch (Exceptions\MindboxClientErrorException $e) {
                return new Main\EventResult(Main\EventResult::ERROR);
            } catch (Exceptions\MindboxUnavailableException $e) {
                $orderDTO = new OrderUpdateRequestDTO();

                $_SESSION['PAY_BONUSES'] = 0;
                unset($_SESSION['PROMO_CODE']);
                unset($_SESSION['PROMO_CODE_AMOUNT']);
                unset($_SESSION['TOTAL_PRICE']);

                if ($_SESSION['MINDBOX_ORDER']) {
                    $orderDTO->setId('mindbox', $_SESSION['MINDBOX_ORDER']);
                }

                $now = new DateTime();
                $now = $now->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s");
                $orderDTO->setUpdatedDateTimeUtc($now);

                $customer = new CustomerRequestV2DTO();
                $mindboxId = Helper::getMindboxId($order->getUserId());

                if ($mindboxId) {
                    $customer->setId('mindbox', $mindboxId);
                }

                if (is_object($USER)) {
                    $customer->setEmail($USER->GetEmail());
                }

                $phone = $_SESSION['ANONYM']['PHONE'];
                unset($_SESSION['ANONYM']['PHONE']);

                if ($phone) {
                    $customer->setMobilePhone(Helper::formatPhone($phone));
                }
                if (is_object($USER) && $USER->IsAuthorized()) {
                    $customer->setField('isAuthorized', true);
                } else {
                    $customer->setField('isAuthorized', false);
                }

                $orderDTO->setCustomer($customer);
                $orderDTO->setPointOfContact(Options::getModuleOption('POINT_OF_CONTACT'));

                $lines = [];
                foreach ($basketItems as $basketItem) {
                    if ($basketItem->getField('CAN_BUY') == 'N') {
                        continue;
                    }

                    $basketItem->setField('CUSTOM_PRICE', 'N');
                    $basketItem->save();
                    $line = new LineRequestDTO();
                    $line->setField('lineId', $basketItem->getId());
                    $line->setQuantity($basketItem->getQuantity());
                    $catalogPrice = \CPrice::GetBasePrice($basketItem->getProductId())['PRICE'];
                    $line->setProduct([
                        'productId'        => Helper::getElementCode($basketItem->getProductId()),
                        'basePricePerItem' => $catalogPrice
                    ]);
                    $lines[] = $line;
                }

                $orderDTO->setLines($lines);
                $orderDTO->setField('totalPrice', $basket->getPrice());

                $_SESSION['OFFLINE_ORDER'] = [
                    'DTO' => $orderDTO,
                ];

                return new Main\EventResult(Main\EventResult::SUCCESS);
            } catch (Exceptions\MindboxClientException $e) {
                $request = $mindbox->order()->getRequest();
                if ($request) {
                    QueueTable::push($request);
                }
            }
        }

        return new Main\EventResult(Main\EventResult::SUCCESS);
    }

    /**
     * @bitrixModuleId sale
     * @bitrixEventCode OnSaleBasketSaved
     * @langEventName OnSaleBasketSaved
     * @param $basket
     * @return Main\EventResult|false
     */
    public function OnSaleBasketSavedHandler($basket)
    {
        $mindbox = static::mindbox();
        if (!$mindbox) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }
        $basketItems = $basket->getBasketItems();
        Helper::setCartMindbox($basketItems);
        if (empty($basketItems)) {
            $_SESSION['MB_CLEAR_CART'] = 'Y';
        }

        return new Main\EventResult(Main\EventResult::SUCCESS);
    }

    /**
     * @bitrixModuleId sale
     * @bitrixEventCode OnBeforeSaleOrderFinalAction
     * @langEventName OnBeforeSaleOrderFinalAction
     * @param $basket
     * @return Main\EventResult|false
     */
    public function OnBeforeSaleOrderFinalActionHandler($order, $has, $basket)
    {

        if (Helper::isStandardMode()) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        global $USER;

        if (!$USER || is_string($USER)) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        $mindbox = static::mindbox();
        if (!$mindbox) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        if ($_REQUEST['soa-action'] === 'saveOrderAjax' &&
            $_REQUEST['save'] === 'Y'
        ) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        $preorder = new PreorderRequestDTO();

        $basketItems = $basket->getBasketItems();
        $lines = [];
        $bitrixBasket = [];
        $preorder = new \Mindbox\DTO\V3\Requests\PreorderRequestDTO();

        foreach ($basketItems as $basketItem) {
            if (!$basketItem->getId()) {
                continue;
            }

            if ($basketItem->getField('CAN_BUY') == 'N') {
                continue;
            }

            $requestedPromotions = Helper::getRequestedPromotions($basketItem, $order);
            $bitrixBasket[$basketItem->getId()] = $basketItem;
            $catalogPrice = Helper::getBasePrice($basketItem);

            $arLine = [
                'basePricePerItem' => $catalogPrice,
                'quantity'         => $basketItem->getQuantity(),
                'lineId'           => $basketItem->getId(),
                'product'          => [
                    'ids' => [
                        Options::getModuleOption('EXTERNAL_SYSTEM') => Helper::getElementCode($basketItem->getProductId())
                    ]
                ],
                'status'           => [
                    'ids' => [
                        'externalId' => 'CheckedOut'
                    ]
                ]
            ];

            if (!empty($requestedPromotions)) {
                $arLine['requestedPromotions'] = $requestedPromotions;
            }

            $lines[] = $arLine;
        }

        if (empty($lines)) {
            return false;
        }

        $arCoupons = [];
        if ($_SESSION['PROMO_CODE'] && !empty($_SESSION['PROMO_CODE'])) {
            $arCoupons['ids']['code'] = $_SESSION['PROMO_CODE'];
        }

        $arOrder = [
            'ids'   => [
                Options::getModuleOption('TRANSACTION_ID') => '',
            ],
            'lines' => $lines
        ];

        if (!empty($arCoupons)) {
            $arOrder['coupons'] = [$arCoupons];
        }

        $bonuses = $_SESSION['PAY_BONUSES'] ?: 0;
        if ($bonuses && $USER->IsAuthorized()) {
            $bonusPoints = [
                'amount' => $bonuses
            ];
            $arOrder['bonusPoints'] = [
                $bonusPoints
            ];
        }

        $preorder->setField('order', $arOrder);

        $customer = new CustomerRequestDTO();
        if ($USER->IsAuthorized()) {
            $mindboxId = Helper::getMindboxId($USER->GetID());
            if (!$mindboxId) {
                return new Main\EventResult(Main\EventResult::SUCCESS);
            }
            $customer->setId('mindboxId', $mindboxId);
            $preorder->setCustomer($customer);
        }

        try {
            if ($USER->IsAuthorized()) {
                $preorderInfo = $mindbox->order()->calculateAuthorizedCart(
                    $preorder,
                    Options::getOperationName('calculateAuthorizedCart')
                )->sendRequest()->getResult()->getField('order');
            } else {
                $preorderInfo = $mindbox->order()->calculateUnauthorizedCart(
                    $preorder,
                    Options::getOperationName('calculateUnauthorizedCart')
                )->sendRequest()->getResult()->getField('order');
            }

            if (!$preorderInfo) {
                return new Main\EventResult(Main\EventResult::SUCCESS);
            }

            $_SESSION['TOTAL_PRICE'] = $preorderInfo->getField('totalPrice');

            $discounts = $preorderInfo->getDiscountsInfo();
            foreach ($discounts as $discount) {
                if ($discount->getType() === 'balance') {
                    $balance = $discount->getField('balance');
                    if ($balance['balanceType']['ids']['systemName'] === 'Main') {
                        $_SESSION['ORDER_AVAILABLE_BONUSES'] = $discount->getField('availableAmountForCurrentOrder');
                    }
                }

                if ($discount->getType() === 'promoCode') {
                    $_SESSION['PROMO_CODE_AMOUNT'] = $discount['availableAmountForCurrentOrder'];
                }
            }

                $lines = $preorderInfo->getLines();

                $mindboxBasket = [];
                $mindboxAdditional = [];
                $context = $basket->getContext();

            foreach ($lines as $line) {
                $lineId = $line->getField('lineId');
                $bitrixProduct = $bitrixBasket[$lineId];

                if (isset($mindboxBasket[$lineId])) {
                    $mindboxAdditional[] = [
                        'PRODUCT_ID'             => $bitrixProduct->getProductId(),
                        'PRICE'                  => floatval($line->getDiscountedPrice()) / floatval($line->getQuantity()),
                        'CUSTOM_PRICE'           => 'Y',
                        'QUANTITY'               => $line->getQuantity(),
                        'CURRENCY'               => $context['CURRENCY'],
                        'NAME'                   => $bitrixProduct->getField('NAME'),
                        'LID'                    => SITE_ID,
                        'DETAIL_PAGE_URL'        => $bitrixProduct->getField('DETAIL_PAGE_URL'),
                        'CATALOG_XML_ID'         => $bitrixProduct->getField('CATALOG_XML_ID'),
                        'PRODUCT_XML_ID'         => $bitrixProduct->getField('PRODUCT_XML_ID'),
                        'PRODUCT_PROVIDER_CLASS' => $bitrixProduct->getProviderName(),
                        'CAN_BUY'                => 'Y'
                    ];
                    foreach ($mindboxAdditional as $product) {
                        $item = $basket->createItem("catalog", $product["PRODUCT_ID"]);
                        unset($product["PRODUCT_ID"]);
                        $item->setFields($product);
                    }
                } else {
                    $mindboxPrice = floatval($line->getDiscountedPrice()) / floatval($line->getQuantity());
                    $mindboxBasket[$lineId] = $bitrixProduct;
                    Helper::processHlbBasketRule($lineId, $mindboxPrice);
                }
            }
        } catch (Exceptions\MindboxClientException $e) {
            return new Main\EventResult(Main\EventResult::SUCCESS);
        }

        return new Main\EventResult(Main\EventResult::SUCCESS);
    }

    /**
     * @bitrixModuleId sale
     * @bitrixEventCode OnSaleBasketItemRefreshData
     * @langEventName OnSaleBasketItemRefreshData
     * @param $event
     * @return \Bitrix\Main\EventResult
     */
    public function OnSaleBasketItemRefreshDataHandler($event)
    {
        $basketItem = $event;

        $basket = Sale\Basket::loadItemsForFUser(Sale\Fuser::getId(), Main\Context::getCurrent()->getSite());
        $basketItems = $basket->getBasketItems();
        if (empty($basketItems)) {
            $_SESSION['MB_CLEAR_CART'] = 'Y';
        } else {
            unset($_SESSION['MB_CLEAR_CART']);
        }

        if ($basketItem->getField('DELAY') === 'Y') {
            $_SESSION['MB_WISHLIST'][$basketItem->getProductId()] = $basketItem;
        } else if ($basketItem->getField('DELAY') === 'N' && array_key_exists($basketItem->getProductId(), $_SESSION['MB_WISHLIST'])) {
            unset($_SESSION['MB_WISHLIST'][$basketItem->getProductId()]);
        }

        if (!empty($_SESSION['MB_WISHLIST']) && count($_SESSION['MB_WISHLIST']) !== $_SESSION['MB_WISHLIST_COUNT']) {
            Helper::setWishList();
        }

        if (empty($_SESSION['MB_WISHLIST']) && isset($_SESSION['MB_WISHLIST_COUNT'])) {
            Helper::clearWishList();
        }

        return new Main\EventResult(Main\EventResult::SUCCESS);
    }

    /**
     * @bitrixModuleId main
     * @bitrixEventCode OnBeforeUserAdd
     * @langEventName OnBeforeUserAdd
     * @param $arFields
     * @return false
     */
    public function OnBeforeUserAddHandler(&$arFields)
    {
        if (\COption::GetOptionString('mindbox.marketing', 'MODE') == 'standard') {
            return $arFields;
        }

        if ($_REQUEST['mode'] == 'class' && $_REQUEST['c'] == 'mindbox:auth.sms' && $_REQUEST['action'] == 'checkCode') {
            return $arFields;
        }

        global $APPLICATION, $USER;

        if (!$USER || is_string($USER)) {
            return $arFields;
        }

        if (isset($_REQUEST['REGISTER']) ||
            $_REQUEST['register'] == 'yes' ||
            $_REQUEST['TYPE'] == 'REGISTRATION'
        ) {
            return $arFields;
        }

        $mindbox = static::mindbox();
        if (!$mindbox) {
            return $arFields;
        }

        if (!isset($arFields['PERSONAL_PHONE'])) {
            $arFields['PERSONAL_PHONE'] = $arFields['PERSONAL_MOBILE'];
        }

        if (isset($arFields['PERSONAL_PHONE'])) {
            $arFields['PERSONAL_PHONE'] = Helper::formatPhone($arFields['PERSONAL_PHONE']);
        }

        /*
        if (isset($_SESSION[ 'OFFLINE_REGISTER' ]) && $_SESSION[ 'OFFLINE_REGISTER' ]) {
            return $arFields;
        }
        */

        /*
        if (!$USER->CheckFields($arFields)) {
            $APPLICATION->ThrowException($USER->LAST_ERROR);
            return false;
        }
        */

        $sex = substr(ucfirst($arFields['PERSONAL_GENDER']), 0, 1) ?: null;
        $fields = [
            'email'       => $arFields['EMAIL'],
            'lastName'    => $arFields['LAST_NAME'],
            'middleName'  => $arFields['SECOND_NAME'],
            'firstName'   => $arFields['NAME'],
            'mobilePhone' => $arFields['PERSONAL_PHONE'],
            'birthDate'   => Helper::formatDate($arFields['PERSONAL_BIRTHDAY']),
            'sex'         => $sex,
        ];

        $fields = array_filter($fields, function ($item) {
            return isset($item);
        });

        $customFields = [];
        $ufFields = array_filter($arFields, function ($value, $key) {
            return strpos($key, 'UF_') !== false;
        }, ARRAY_FILTER_USE_BOTH);

        foreach ($ufFields as $code => $value) {
            if (!empty($customName = Helper::getMatchByCode($code, Helper::getUserFieldsMatch()))) {
                $customFields[Helper::sanitizeNamesForMindbox($customName)] = $value;
            }
        }

        if (!empty($customFields)) {
            $fields['customFields'] = $customFields;
        }

        $isSubscribed = true;
        if ($arFields['UF_MB_IS_SUBSCRIBED'] === '0') {
            $isSubscribed = false;
        }
        $fields['subscriptions'] = [
            [
                'brand'        => Options::getModuleOption('BRAND'),
                'isSubscribed' => $isSubscribed
            ]
        ];

        $customer = Helper::iconvDTO(new CustomerRequestDTO($fields));

        unset($fields);

        try {
            $registerResponse = $mindbox->customer()->register(
                $customer,
                Options::getOperationName('register'),
                true,
                Helper::isSync()
            )->sendRequest()->getResult();
        } catch (Exceptions\MindboxUnavailableException $e) {
            return $arFields;
        } catch (Exceptions\MindboxClientException $e) {
            return $arFields;
        } catch (\Exception $e) {
            return $arFields;
        }

        if ($registerResponse) {
            $registerResponse = Helper::iconvDTO($registerResponse, false);
            $status = $registerResponse->getStatus();

            if ($status === 'ValidationError') {
                $errors = $registerResponse->getValidationMessages();
                $APPLICATION->ThrowException(Helper::formatValidationMessages($errors));
                return false;
            } else {
                $customer = $registerResponse->getCustomer();
                $mindBoxId = $customer->getId('mindboxId');
                $arFields['UF_MINDBOX_ID'] = $mindBoxId;
                $_SESSION['NEW_USER_MB_ID'] = $mindBoxId;
                $_SESSION['NEW_USER_MINDBOX'] = true;
            }
        }

        return $arFields;
    }

    /**
     * @bitrixModuleId main
     * @bitrixEventCode OnAfterUserAdd
     * @langEventName OnAfterUserAdd
     * @param $arFields
     * @return false
     */
    public function OnAfterUserAddHandler(&$arFields)
    {
        $mindBoxId = $_SESSION['NEW_USER_MB_ID'];
        $mindbox = static::mindbox();

        if (!$mindbox) {
            return $arFields;
        }

        global $APPLICATION;

        if ($mindBoxId) {
            $request = $mindbox->getClientV3()->prepareRequest(
                'POST',
                Options::getOperationName('getCustomerInfo'),
                new DTO([
                    'customer' => [
                        'ids' => [
                            'mindboxId' => $mindBoxId
                        ]
                    ]
                ])
            );

            try {
                $response = $request->sendRequest();
            } catch (Exceptions\MindboxClientException $e) {
                $APPLICATION->ThrowException($e->getMessage());
                return false;
            }

            if ($response->getResult()->getCustomer()->getProcessingStatus() === 'Found') {
                $fields = [
                    'UF_EMAIL_CONFIRMED' => $response->getResult()->getCustomer()->getIsEmailConfirmed(),
                    'UF_MINDBOX_ID'      => $response->getResult()->getCustomer()->getId('mindboxId')
                ];

                $user = new CUser;
                $user->Update(
                    $arFields['ID'],
                    $fields
                );
                unset($_SESSION['NEW_USER_MB_ID']);
            }
        }
    }

    /**
     * @bitrixModuleId main
     * @bitrixEventCode OnProlog
     * @langEventName OnProlog
     * @param $arFields
     * @return false
     */
    public function OnPrologHandler()
    {
        $defaultOptions = \Bitrix\Main\Config\Option::getDefaults("mindbox.marketing");
        $jsString = "<script data-skip-moving=\"true\">\r\n" . file_get_contents($_SERVER['DOCUMENT_ROOT'] . $defaultOptions['TRACKER_JS_FILENAME']) . "</script>\r\n";
        $jsString .= '<script data-skip-moving="true" src="' . self::TRACKER_JS_FILENAME . '" async></script>';
        Asset::getInstance()->addString($jsString);
    }


    /**
     * @bitrixModuleId sale
     * @bitrixEventCode OnSalePropertyValueSetField
     * @langEventName OnSalePropertyValueSetField
     * @notCompatible true
     * @param Main\Event $event
     * @return bool
     */
    public function OnSalePropertyValueSetFieldHandler(Main\Event $event)
    {
        $orderMatchList = Helper::getOrderFieldsMatch();

        $getEntity = $event->getParameter('ENTITY');
        $value = $event->getParameter('VALUE');
        $order = $getEntity->getCollection()->getOrder();
        $propertyData = $getEntity->getProperty();

        if (!empty($order) && $order instanceof \Bitrix\Sale\Order) {
            $additionFields = [
                'customFields' => [$orderMatchList[$propertyData['CODE']] => $value],
            ];

            Helper::updateMindboxOrderItems($order, $additionFields);
        }
    }

    /**
     * @return Mindbox
     */
    private static function mindbox()
    {
        $mindbox = Options::getConfig();
        return $mindbox;
    }
}
