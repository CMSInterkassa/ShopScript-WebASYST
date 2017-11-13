<?php
return array(
    'test_mode'       => array(
        'value'        => false,
        'title'        => 'Режим отладки',
        'control_type' => waHtmlControl::CHECKBOX,
    ),
    'id_cashbox'    => array(
        'value'        => '',
        'title'        => 'Id кассы',
        'description'  => 'Идентификатор кассы, зарегистрированный в системе «INTERKASSA»',
        'control_type' => waHtmlControl::INPUT,
    ),
    'secret_key' => array(
        'value'        => '',
        'title'        => 'Секретный ключ',
        'description'  => 'Строка символов, добавляемая к реквизитам платежа, которые отправляются продавцу вместе с оповещением о новом платеже. Используется для повышения надежности идентификации оповещения и не должна быть известна третьим лицам!',
        'control_type' => waHtmlControl::PASSWORD,
    ),
    'test_key'   => array(
        'value'        => '',
        'title'        => 'Тестовый ключ',
        'control_type' => waHtmlControl::PASSWORD,
    ),
    'currency'   => array(
        'value'        => 'RUB',
        'title'        => 'Валюта оплаты',
        'description'  => 'Доступные валюты оплаты (должны быть подключены в настройках на сайте платежной системы)',
        'control_type' => waHtmlControl::RADIOGROUP.' interkassaPayment::availableCurrency',
    ),
    'api_enable'    => array(
        'value'        => false,
        'title'        => 'Включить API',
        'description'  => 'Идентификатор кассы, зарегистрированный в системе «INTERKASSA»',
        'control_type' => waHtmlControl::CHECKBOX,
    ),
    'api_id'    => array(
        'value'        => '',
        'title'        => 'Id API',
        'description'  => 'Идентификатор кассы, зарегистрированный в системе «INTERKASSA»',
        'control_type' => waHtmlControl::INPUT,
    ),
    'api_key'    => array(
        'value'        => '',
        'title'        => 'API ключ',
        'description'  => 'Идентификатор кассы, зарегистрированный в системе «INTERKASSA»',
        'control_type' => waHtmlControl::INPUT,
    ),
);
