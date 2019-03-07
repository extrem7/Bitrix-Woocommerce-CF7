# Bitrix-Woocommerce-CF7

> Bitrix24 integration with Woocommerce and Contact Form 7 for Wordpress projects.

> wordpress, woocommerce, cf7, bitrix24, php7.0+

### Installation

- Clone this repo to your local machine using `https://github.com/extrem7/Bitrix-Woocommerce-CF7.git`

### Setup
```php
<?php
require_once "Bitrix.php";
$bx = new Bitrix( 'b24-yourdomain', 'admin@email.com', 'webhookkey', 'password' );
```
