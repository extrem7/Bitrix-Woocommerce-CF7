<?php

class Bitrix
{
    private $domain;
    private $login;
    private $webhook;
    private $password;

    public function __construct(string $domain, string $login, string $webhook = null, string $password = null)
    {
        $this->domain = $domain;
        $this->login = $login;
        $this->webhook = $webhook;
        $this->password = $password;

        add_action('wpcf7_mail_sent', [$this, 'cf7']);
        add_action('woocommerce_thankyou', [$this, 'woocommerce']);
    }

    /**
     * WebHook based lead integration. WebHooks Bitrix24 - a mechanism that allows you to use almost all the rich
     * functionality of the Rest API Bitrix24, but with minimal knowledge and effort.
     * Using WebHooks it is safest way to transfer information
     * @param array $fields
     * @return array with status and error text in bad case
     */
    private function sendViaWebhook(array $fields)
    {
        $url = "https://$this->domain.bitrix24.ru/rest/$this->login/$this->webhook/crm.lead.add.json";

        $data = http_build_query([
            'fields' => $fields,
            'params' => ["REGISTER_SONET_EVENT" => "N"] //произвести регистрацию события добавления лида в живой ленте
        ]);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_POST => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $data,
        ]);
        $result = curl_exec($curl);
        curl_close($curl);
        $result = json_decode($result, 1);

        if (array_key_exists('error', $result)) {
            return [
                'status' => 'error',
                'text' => $result['error_description']
            ];
        } else {
            return ['status' => 'ok'];
        }
    }

    private function sendViaRest(array $fields)
    {
        $CRM_HOST = "$this->domain.bitrix24.ru";
        $CRM_PATH = '/crm/configs/import/lead.php';

        $data = array_merge([
            'LOGIN' => $this->login,
            'PASSWORD' => $this->password,
        ], $fields);

        $fp = fsockopen("ssl://$CRM_HOST", 443, $errno, $errstr, 30);
        if ($fp) {
            $data = http_build_query($data);

            $str = "POST $CRM_PATH HTTP/1.0\r\n";
            $str .= "Host: $CRM_HOST\r\n";
            $str .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $str .= "Content-Length: " . strlen($data) . "\r\n";
            $str .= "Connection: close\r\n\r\n";

            $str .= $data;

            fwrite($fp, $str);

            $result = '';
            while (!feof($fp)) {
                $result .= fgets($fp, 128);
            }
            fclose($fp);

            $response = explode("\r\n\r\n", $result);

            return $response[1];
        }
    }

    private function send(array $fields)
    {
        if ($this->webhook) {
            $this->sendViaWebhook($fields);
        } else {
            $this->sendViaRest($fields);
        }
    }

    /**
     * Prepare phones as bitrix24 phones array
     * @param string|array $phone
     * @return array
     */
    private function phone($phone): array
    {
        if (!is_array($phone)) $phone = [$phone];

        $phones = [];
        for ($i = 0; $i < count($phone); $i++) $phones["n$i"] = [
            "VALUE" => $phone[$i],
            "VALUE_TYPE" => "MOBILE",
        ];
        return $phones;
    }

    /**
     * Prepare emails as bitrix24 emails array
     * @param string|array $email
     * @return array
     */
    private function email($email): array
    {
        if (!is_array($email)) $email = [$email];

        $emails = [];
        for ($i = 0; $i < count($email); $i++) $emails["n$i"] = [
            "VALUE" => $email[$i],
            "VALUE_TYPE" => "HOME",
        ];
        return $emails;
    }

    /**
     * CF7 adapter. Hooked by "wpcf7_mail_sent".
     * @param WPCF7_ContactForm $form
     */
    public function cf7(WPCF7_ContactForm $form)
    {
        $id = $form->id;
        $formData = $form->posted_data;

        $submission = WPCF7_Submission::get_instance();
        $data = $submission->get_posted_data();

        $this->send([
            'TITLE' => 'Contact form 7',
            'NAME' => $data['name'],
            'PHONE' => $this->phone($data['phone']),
            'EMAIL' => $this->email($data['email']),
            'COMMENTS' => $data['details']
        ]);
    }

    /**
     * Woocommerce adapter. Hooked by "woocommerce_thankyou".
     * @param int $orderId
     */
    public function woocommerce(int $orderId)
    {
        $order = wc_get_order($orderId);
        $data = $order->get_data();
        $billing = $data['billing'];
        $shipping = $data['shipping'];

        $info = [
            'Общая информация по заказу' => [
                'ID заказа' => $orderId,
                'Валюта заказа' => $data['currency'],
                'Метода оплаты' => $data['payment_method_title'],
                'Стоимость доставки' => $data['shipping_total'],
                'Итого с доставкой' => $data['total'],
                'Примечание к заказу' => $data['customer_note']
            ],
            'Информация по клиенту' => [
                'ID клиента' => $data['customer_id'],
                'IP адрес клиента' => $data['customer_ip_address'],
                'Имя клиента' => $billing['first_name'],
                'Фамилия клиента' => $billing['last_name'],
                'Email клиента' => $billing['email'],
                'Телефон клиента' => $billing['phone']
            ],
            'Информация по доставке' => [
                'Страна доставки' => $shipping['country'],
                'Регион доставки' => $shipping['state'],
                'Город доставки' => $shipping['city'],
                'Индекс' => $shipping['postcode'],
                'Адрес доставки 1' => $shipping['address_1'],
                'Адрес доставки 2' => $shipping['address_2'],
            ]
        ];

        $products = [];

        foreach ($order->get_items() as $item) {
            $product = $order->get_product_from_item($item);
            $products[] = [
                'Название' => $product->get_name(),
                'ID товара' => $product->get_id(),
                'Артикул' => $product->get_sku(),
                'Заказали (шт.)' => $item['qty'],
                'Наличие (шт.)' => $product->get_stock_quantity() ?? 'есть',
                'Сумма заказа (без учета доставки)' => $order->get_line_total($item, true, true)
            ];
        }

        $infoString = "";
        foreach ($info as $header => $fields) {
            $infoString .= "<hr><strong>$header</strong><br>";
            foreach ($fields as $key => $value) {
                $infoString .= "$key: $value<br>";
            }
        }

        $productsString = "<hr><strong>Товары</strong><br>";
        foreach ($products as $fields) {
            foreach ($fields as $key => $value) {
                $productsString .= "$key: $value<br>";
            }
            $productsString .= '<hr>';
        }

        $this->send([
            'TITLE' => "Woocommerce order #$orderId",
            'NAME' => $billing['first_name'],
            'LAST_NAME' => $billing['last_name'],
            'PHONE' => $this->phone($billing['phone']),
            'EMAIL' => $this->email($billing['email']),
            'ADDRESS' => $shipping['address_1'],
            'ADDRESS_2' => $shipping['address_2'],
            'ADDRESS_CITY' => $shipping['city'],
            'ADDRESS_COUNTRY' => $shipping['country'],
            'ADDRESS_POSTAL_CODE' => $shipping['postcode'],
            'ADDRESS_PROVINCE' => $shipping['state'],
            'OPPORTUNITY' => $data['total'],
            'CURRENCY_ID' => $data['currency'],
            'COMMENTS' => $infoString . $productsString
        ]);
    }
}