<?php


namespace Mindbox\Components;


use Mindbox\DTO\DTO;
use Mindbox\Options;

class CalculateProductData
{
    private $productData = [];
    private static $instance = null;
    private $mindbox = null;

    private function __construct()
    {
        $this->mindbox = Options::getConfig();
    }

    public function setProduct($productData)
    {
        if (!empty($productData) && isset($productData['id'])) {

            $this->productData[$productData['id']] = $productData;
        }
    }

    public function getData()
    {
        return $this->productData;
    }

    protected function __clone()
    {
    }

    static function getInstance()
    {
        if (is_null(self::$instance))
        {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function sendRequest()
    {
        $dto = new DTO([
            'productList' => [
                'items' => []
            ]
        ]);

        $response = $this->mindbox->getClientV3()
            ->prepareRequest('POST', 'Website.CalculateUnauthorizedProduct', $dto, '', [], true)
            ->sendRequest();

        var_dump($response);
    }

    public function __destruct()
    {
    }
}