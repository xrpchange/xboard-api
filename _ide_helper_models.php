<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @OA\Schema (
 *     schema="Bank",
 *     title="Bank",
 *     description="Bank model"
 * )
 * @property int $id Уникальный идентификатор банка
 * @property int $country_id
 * @property string $name Название банка
 * @property string|null $swift_code SWIFT-код банка
 * @property bool $supports_individuals Работает с физическими лицами
 * @property bool $supports_legal_entities Работает с юридическими лицами
 * @property bool $is_active Флаг активности банка в системе
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Country $country
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank forIndividuals()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank forLegalEntities()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank whereCountryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank whereSupportsIndividuals($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank whereSupportsLegalEntities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank whereSwiftCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bank whereUpdatedAt($value)
 */
	class Bank extends \Eloquent {}
}

namespace App\Models{
/**
 * @OA\Schema (
 *     schema="BuyTrigger",
 *     title="BuyTrigger",
 *     description="Buy trigger model"
 * )
 * @property int $id Уникальный идентификатор триггера
 * @property int $crypto_currency_id
 * @property string $wallet_address Адрес кошелька залогового токена
 * @property string $buy_currency_type Тип покупаемой валюты (фиат/крипто)
 * @property int $buy_currency_id ID покупаемой валюты (из fiat_currencies или crypto_currencies)
 * @property numeric $buy_amount Количество покупаемой валюты
 * @property string $sell_currency_type Тип продаваемой валюты (фиат/крипто)
 * @property int $sell_currency_id ID продаваемой валюты (из fiat_currencies или crypto_currencies)
 * @property numeric|null $max_sell_amount Максимальное количество продаваемой валюты
 * @property int|null $country_id
 * @property int|null $bank_id
 * @property bool $is_for_individuals Доступно для физических лиц
 * @property bool $is_for_legal_entities Доступно для юридических лиц
 * @property bool $is_active Активен ли триггер
 * @property bool $is_test Тестовый триггер для песочницы
 * @property string|null $test_group Группа тестового режима
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bank|null $bank
 * @property-read \App\Models\Country|null $country
 * @property-read \App\Models\CryptoCurrency $cryptoCurrency
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger forIndividuals()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger forLegalEntities()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger test($testGroup = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereBankId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereBuyAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereBuyCurrencyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereBuyCurrencyType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereCountryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereCryptoCurrencyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereIsForIndividuals($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereIsForLegalEntities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereIsTest($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereMaxSellAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereSellCurrencyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereSellCurrencyType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereTestGroup($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BuyTrigger whereWalletAddress($value)
 */
	class BuyTrigger extends \Eloquent {}
}

namespace App\Models{
/**
 * @OA\Schema (
 *     schema="Country",
 *     title="Country",
 *     description="Country model"
 * )
 * @property int $id Уникальный идентификатор страны
 * @property string $name Название страны
 * @property string $code ISO 3166-1 alpha-3 код страны (3 буквы)
 * @property string|null $phone_code Телефонный код страны
 * @property bool $is_active Флаг активности страны в системе
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Bank> $banks
 * @property-read int|null $banks_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country wherePhoneCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Country whereUpdatedAt($value)
 */
	class Country extends \Eloquent {}
}

namespace App\Models{
/**
 * @OA\Schema (
 *     schema="CryptoCurrency",
 *     title="CryptoCurrency",
 *     description="Cryptocurrency model"
 * )
 * @property int $id Уникальный идентификатор криптовалюты
 * @property string $code Тикер криптовалюты (BTC, ETH и т.д.)
 * @property string $name Название криптовалюты
 * @property bool $is_stablecoin Флаг стейблкоина
 * @property bool $is_active Флаг активности криптовалюты в системе
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BuyTrigger> $buyTriggers
 * @property-read int|null $buy_triggers_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ExchangeLot> $exchangeLots
 * @property-read int|null $exchange_lots_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency stablecoins()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency whereIsStablecoin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CryptoCurrency whereUpdatedAt($value)
 */
	class CryptoCurrency extends \Eloquent {}
}

namespace App\Models{
/**
 * @OA\Schema (
 *     schema="ExchangeLot",
 *     title="ExchangeLot",
 *     description="Exchange lot model"
 * )
 * @property int $id Уникальный идентификатор лота
 * @property int $crypto_currency_id
 * @property string $wallet_address Адрес кошелька залогового токена
 * @property string $sell_currency_type Тип продаваемой валюты (фиат/крипто)
 * @property int $sell_currency_id ID продаваемой валюты (из fiat_currencies или crypto_currencies)
 * @property numeric $sell_amount Количество продаваемой валюты
 * @property string $buy_currency_type Тип покупаемой валюты (фиат/крипто)
 * @property int $buy_currency_id ID покупаемой валюты (из fiat_currencies или crypto_currencies)
 * @property numeric $buy_amount Количество покупаемой валюты
 * @property int|null $country_id
 * @property int|null $bank_id
 * @property string|null $bank_account Банковский счет для фиатных операций
 * @property string|null $payment_purpose Назначение платежа для фиатных операций
 * @property string|null $contract_url URL договора для юридических лиц
 * @property bool $is_for_individuals Доступно для физических лиц
 * @property bool $is_for_legal_entities Доступно для юридических лиц
 * @property bool $is_active Активен ли лот
 * @property bool $is_test Тестовый лот для песочницы
 * @property string|null $test_group Группа тестового режима
 * @property \Illuminate\Support\Carbon|null $expires_at Дата истечения срока действия лота
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bank|null $bank
 * @property-read \App\Models\Country|null $country
 * @property-read \App\Models\CryptoCurrency $cryptoCurrency
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Transaction> $transactions
 * @property-read int|null $transactions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot forIndividuals()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot forLegalEntities()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot test($testGroup = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereBankAccount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereBankId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereBuyAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereBuyCurrencyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereBuyCurrencyType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereContractUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereCountryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereCryptoCurrencyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereIsForIndividuals($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereIsForLegalEntities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereIsTest($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot wherePaymentPurpose($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereSellAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereSellCurrencyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereSellCurrencyType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereTestGroup($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeLot whereWalletAddress($value)
 */
	class ExchangeLot extends \Eloquent {}
}

namespace App\Models{
/**
 * @OA\Schema (
 *     schema="FiatCurrency",
 *     title="FiatCurrency",
 *     description="Fiat currency model"
 * )
 * @property int $id Уникальный идентификатор валюты
 * @property string $code ISO 4217 код валюты (3 буквы)
 * @property string $name Название валюты
 * @property string|null $symbol Символ валюты ($, € и т.д.)
 * @property bool $is_active Флаг активности валюты в системе
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency whereSymbol($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FiatCurrency whereUpdatedAt($value)
 */
	class FiatCurrency extends \Eloquent {}
}

namespace App\Models{
/**
 * XBoard Node Model
 * 
 * Представляет узел в системе XBoard - это может быть:
 * - XBoard API (доска объявлений P2P обменов)
 * - Core API (ядро для работы с блокчейном)
 * 
 * Узлы работают как независимые серверы, подобные почтовым серверам,
 * а клиент XBoard - это почтовый клиент, умеющий работать с несколькими узлами
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Node newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Node newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Node query()
 */
	class Node extends \Eloquent {}
}

namespace App\Models{
/**
 * @OA\Schema (
 *     schema="Transaction",
 *     title="Transaction",
 *     description="Transaction model"
 * )
 * @property int $id Уникальный идентификатор транзакции
 * @property string $tx_hash Хэш транзакции
 * @property string $tx_type Тип транзакции
 * @property string $from_address Адрес отправителя
 * @property string $to_address Адрес получателя
 * @property numeric $amount Сумма транзакции
 * @property string|null $token Залоговый токен
 * @property int|null $exchange_lot_id
 * @property string $status Статус транзакции
 * @property bool $is_test Тестовая транзакция для песочницы
 * @property string|null $test_group Группа тестового режима
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ExchangeLot|null $exchangeLot
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction byAddress($address)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction byType($type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction test($testGroup = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereExchangeLotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereFromAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereIsTest($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereTestGroup($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereToAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereTxHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereTxType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transaction whereUpdatedAt($value)
 */
	class Transaction extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 */
	class User extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id Уникальный идентификатор токена
 * @property string $token Хэш Bearer-токена
 * @property string $device_id Уникальный ID устройства
 * @property string|null $collateral_token Залоговый токен
 * @property string $ip_address IP создания токена
 * @property \Illuminate\Support\Carbon|null $last_used_at Время последнего использования
 * @property \Illuminate\Support\Carbon|null $expires_at Время истечения токена
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ExchangeLot|null $exchangeLot
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken whereCollateralToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserToken whereUpdatedAt($value)
 */
	class UserToken extends \Eloquent {}
}

