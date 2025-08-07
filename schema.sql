-- MySQL dump 10.13  Distrib 5.7.44, for Linux (x86_64)
--
-- Host: localhost    Database: xboard
-- ------------------------------------------------------
-- Server version	5.7.44-log-cll-lve

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `banks`
--

DROP TABLE IF EXISTS `banks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `banks` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `country_id` bigint(20) NOT NULL COMMENT 'Ссылка на страну',
  `name` varchar(100) NOT NULL COMMENT 'Название банка',
  `swift_code` varchar(11) DEFAULT NULL COMMENT 'SWIFT-код банка',
  `supports_individuals` tinyint(1) DEFAULT '1' COMMENT 'Работает с физлицами',
  `supports_legal_entities` tinyint(1) DEFAULT '1' COMMENT 'Работает с юрлицами',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Флаг активности',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_banks_swift_code` (`swift_code`),
  KEY `idx_banks_country_id` (`country_id`),
  CONSTRAINT `banks_ibfk_1` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Справочник банков';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `banks`
--

LOCK TABLES `banks` WRITE;
/*!40000 ALTER TABLE `banks` DISABLE KEYS */;
/*!40000 ALTER TABLE `banks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `buy_triggers`
--

DROP TABLE IF EXISTS `buy_triggers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buy_triggers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `collateral_crypto_id` bigint(20) NOT NULL COMMENT 'ID криптовалюты залогового токена',
  `collateral_wallet` varchar(50) NOT NULL COMMENT 'Адрес залогового кошелька',
  `buyer_wallet` varchar(50) DEFAULT NULL COMMENT 'Адрес кошелька для отправки криптовалюты (если отличается от залогового)',
  `buy_currency_type` enum('fiat','crypto') NOT NULL COMMENT 'Тип покупаемой валюты',
  `buy_currency_id` bigint(20) NOT NULL COMMENT 'ID покупаемой валюты',
  `buy_amount` decimal(20,8) NOT NULL COMMENT 'Количество покупаемой валюты',
  `sell_currency_type` enum('fiat','crypto') NOT NULL COMMENT 'Тип продаваемой валюты',
  `sell_currency_id` bigint(20) NOT NULL COMMENT 'ID продаваемой валюты',
  `max_sell_amount` decimal(20,8) DEFAULT NULL COMMENT 'Максимальное количество продаваемой валюты',
  `country_id` bigint(20) DEFAULT NULL COMMENT 'Страна для фиатных операций',
  `bank_id` bigint(20) DEFAULT NULL COMMENT 'Банк для фиатных операций',
  `is_for_individuals` tinyint(1) DEFAULT '1' COMMENT 'Для физлиц',
  `is_for_legal_entities` tinyint(1) DEFAULT '0' COMMENT 'Для юрлиц',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Активен ли триггер',
  `is_test` tinyint(1) DEFAULT '0' COMMENT 'Тестовый триггер',
  `test_group` varchar(50) DEFAULT NULL COMMENT 'Группа тестового режима',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `country_id` (`country_id`),
  KEY `bank_id` (`bank_id`),
  KEY `idx_buy_triggers_collateral` (`collateral_crypto_id`,`collateral_wallet`),
  KEY `idx_buy_triggers_buy_currency` (`buy_currency_type`,`buy_currency_id`),
  KEY `idx_buy_triggers_sell_currency` (`sell_currency_type`,`sell_currency_id`),
  KEY `idx_buy_triggers_test_mode` (`is_test`,`test_group`),
  CONSTRAINT `buy_triggers_ibfk_1` FOREIGN KEY (`collateral_crypto_id`) REFERENCES `crypto_currencies` (`id`),
  CONSTRAINT `buy_triggers_ibfk_2` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`),
  CONSTRAINT `buy_triggers_ibfk_3` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Триггеры на покупку валюты';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `buy_triggers`
--

LOCK TABLES `buy_triggers` WRITE;
/*!40000 ALTER TABLE `buy_triggers` DISABLE KEYS */;
/*!40000 ALTER TABLE `buy_triggers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `countries`
--

DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `countries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Название страны',
  `code` varchar(3) NOT NULL COMMENT 'ISO 3166-1 alpha-3 код страны',
  `phone_code` varchar(10) DEFAULT NULL COMMENT 'Телефонный код страны',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Флаг активности',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_countries_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Справочник стран';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `countries`
--

LOCK TABLES `countries` WRITE;
/*!40000 ALTER TABLE `countries` DISABLE KEYS */;
/*!40000 ALTER TABLE `countries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `crypto_currencies`
--

DROP TABLE IF EXISTS `crypto_currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `crypto_currencies` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL COMMENT 'Тикер криптовалюты',
  `name` varchar(50) NOT NULL COMMENT 'Название криптовалюты',
  `is_stablecoin` tinyint(1) DEFAULT '0' COMMENT 'Является стейблкоином',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Флаг активности',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_crypto_currencies_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Справочник криптовалют';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `crypto_currencies`
--

LOCK TABLES `crypto_currencies` WRITE;
/*!40000 ALTER TABLE `crypto_currencies` DISABLE KEYS */;
/*!40000 ALTER TABLE `crypto_currencies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exchange_lots`
--

DROP TABLE IF EXISTS `exchange_lots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exchange_lots` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `collateral_crypto_id` bigint(20) NOT NULL COMMENT 'ID криптовалюты залогового токена',
  `collateral_wallet` varchar(50) NOT NULL COMMENT 'Адрес залогового кошелька',
  `seller_wallet` varchar(50) DEFAULT NULL COMMENT 'Адрес кошелька для получения криптовалюты (если отличается от залогового)',
  `sell_currency_type` enum('fiat','crypto') NOT NULL COMMENT 'Тип продаваемой валюты',
  `sell_currency_id` bigint(20) NOT NULL COMMENT 'ID продаваемой валюты',
  `sell_amount` decimal(20,8) NOT NULL COMMENT 'Количество продаваемой валюты',
  `buy_currency_type` enum('fiat','crypto') NOT NULL COMMENT 'Тип покупаемой валюты',
  `buy_currency_id` bigint(20) NOT NULL COMMENT 'ID покупаемой валюты',
  `buy_amount` decimal(20,8) NOT NULL COMMENT 'Количество покупаемой валюты',
  `country_id` bigint(20) DEFAULT NULL COMMENT 'Страна для фиатных операций',
  `bank_id` bigint(20) DEFAULT NULL COMMENT 'Банк для фиатных операций',
  `bank_account` varchar(100) DEFAULT NULL COMMENT 'Банковский счет',
  `payment_purpose` varchar(255) DEFAULT NULL COMMENT 'Назначение платежа',
  `contract_url` varchar(255) DEFAULT NULL COMMENT 'URL договора',
  `is_for_individuals` tinyint(1) DEFAULT '1' COMMENT 'Доступно для физлиц',
  `is_for_legal_entities` tinyint(1) DEFAULT '0' COMMENT 'Доступно для юрлиц',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Активен ли лот',
  `is_test` tinyint(1) DEFAULT '0' COMMENT 'Тестовый лот',
  `test_group` varchar(50) DEFAULT NULL COMMENT 'Группа тестового режима',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Срок действия лота',
  PRIMARY KEY (`id`),
  KEY `country_id` (`country_id`),
  KEY `bank_id` (`bank_id`),
  KEY `idx_exchange_lots_collateral` (`collateral_crypto_id`,`collateral_wallet`),
  KEY `idx_exchange_lots_sell_currency` (`sell_currency_type`,`sell_currency_id`),
  KEY `idx_exchange_lots_buy_currency` (`buy_currency_type`,`buy_currency_id`),
  KEY `idx_exchange_lots_test_mode` (`is_test`,`test_group`),
  CONSTRAINT `exchange_lots_ibfk_1` FOREIGN KEY (`collateral_crypto_id`) REFERENCES `crypto_currencies` (`id`),
  CONSTRAINT `exchange_lots_ibfk_2` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`),
  CONSTRAINT `exchange_lots_ibfk_3` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Лоты обмена валют';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exchange_lots`
--

LOCK TABLES `exchange_lots` WRITE;
/*!40000 ALTER TABLE `exchange_lots` DISABLE KEYS */;
/*!40000 ALTER TABLE `exchange_lots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fiat_currencies`
--

DROP TABLE IF EXISTS `fiat_currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fiat_currencies` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `code` varchar(3) NOT NULL COMMENT 'ISO 4217 код валюты',
  `name` varchar(50) NOT NULL COMMENT 'Название валюты',
  `symbol` varchar(5) DEFAULT NULL COMMENT 'Символ валюты',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Флаг активности',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_fiat_currencies_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Справочник фиатных валют';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fiat_currencies`
--

LOCK TABLES `fiat_currencies` WRITE;
/*!40000 ALTER TABLE `fiat_currencies` DISABLE KEYS */;
/*!40000 ALTER TABLE `fiat_currencies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `push_tokens`
--

DROP TABLE IF EXISTS `push_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` varchar(255) NOT NULL COMMENT 'Уникальный ID устройства',
  `token` text NOT NULL COMMENT 'FCM токен',
  `platform` enum('ios','android','web') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `push_tokens_device_id_unique` (`device_id`),
  UNIQUE KEY `push_tokens_token_unique` (`token`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `push_tokens`
--

LOCK TABLES `push_tokens` WRITE;
/*!40000 ALTER TABLE `push_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `push_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_tokens`
--

DROP TABLE IF EXISTS `user_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL COMMENT 'Хэш Bearer-токена',
  `device_id` varchar(255) NOT NULL COMMENT 'Уникальный ID устройства',
  `collateral_token` text COMMENT 'Залоговый токен',
  `ip_address` varchar(45) NOT NULL COMMENT 'IP создания токена',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_tokens_token_unique` (`token`),
  UNIQUE KEY `user_tokens_device_id_unique` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_tokens`
--

LOCK TABLES `user_tokens` WRITE;
/*!40000 ALTER TABLE `user_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_tokens` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-08-05 14:14:17
