<?php


namespace Mindbox\Components;


use Mindbox\DTO\DTO;
use Mindbox\Helper;
use Mindbox\Options;

class CalculateProductData
{
    const CACHE_TIME = 600;
    private $productData = [];
    private static $instance = null;
    protected $mindbox = null;
    protected $optionExternalSystem = '';
    protected $operationUnauthorized = '';


    private function __construct()
    {
        $this->mindbox = Options::getConfig();
        $this->optionExternalSystem = Options::getModuleOption('EXTERNAL_SYSTEM');
        $this->operationUnauthorized = Options::getOperationName('calculateUnauthorizedProduct');
    }

    static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function setProduct($productData)
    {
        if (!empty($productData)) {

            $this->productData[] = $productData;
        }
    }

    public function setProductList($productList)
    {
        if (!empty($productList) && is_array($productList)) {
            $setProductList = [];

            foreach ($productList as $item) {
                if (!empty($item['XML_ID'])) {
                    $setProductList[$item['XML_ID']] = $item;
                }
            }

            $this->productData = $setProductList;
        }
    }

    public function getProducts()
    {
        return $this->productData;
    }

    public function getIntegrationData()
    {
        $mindboxProductData = $this->receiveProductData();
        $integrationProductList = $this->productData;

        if (!empty($mindboxProductData)) {
            foreach ($integrationProductList as &$item) {
                if (array_key_exists($item['XML_ID'], $mindboxProductData)) {
                    $item['CALCULATE'] = $mindboxProductData[$item['XML_ID']];
                }
            }
        }

        return $integrationProductList;
    }

    public function receiveProductData()
    {
        // @todo тут нужно будет добавить массив переданых твоаров + добавить кол-во на проверку
        $return = [];
        // тут реализовать получения чанками

        $products = $this->getProducts();
        $return = $this->requestOperation($products);
        return $return;
    }

    protected function prepareDtoData($productList)
    {
        $return = [
            'productList' => [
                'items' => []
            ]
        ];

        foreach ($productList as $item) {

            if (!empty($item['XML_ID']) && !empty($item['PRICE'])) {
                $return['productList']['items'][] = [
                    'product' => [
                        'ids' => [
                            $this->optionExternalSystem => $item['XML_ID']
                        ]
                    ],
                    'basePricePerItem' => $item['PRICE']
                ];
            }
        }

        return $return;
    }

    protected function requestOperation($items)
    {
        $return = [];
        $prepareDtoData = $this->prepareDtoData($items);
        $dto = new DTO($prepareDtoData);

        try {
            $response = $this->mindbox->getClientV3()
                ->prepareRequest('POST', $this->operationUnauthorized, $dto, '', [], true)
                ->sendRequest()->getResult();
            if ($response) {
                $iconvResponse = Helper::iconvDTO($response, false);
                $responseStatus = $iconvResponse->getStatus();

                if ($responseStatus === 'Success') {
                    $responseProductList = $iconvResponse->getProductList()->getFieldsAsArray()[1];

                    foreach ($responseProductList as $item) {
                        $return[$item['product']['ids'][$this->optionExternalSystem]] = $item;
                    }
                }

            }

            return $return;
        } catch (\Exception $e) {
            //var_dump($e->getMessage());
        }
    }

    protected function __clone()
    {
    }

    public function __destruct()
    {
    }
}