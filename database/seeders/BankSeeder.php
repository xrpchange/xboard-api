<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\Country;
use App\Models\FiatCurrency;
use Illuminate\Database\Seeder;

/**
 * Seeds banks by country with allowed_domain (email receipt sender) and supported fiat currencies.
 * Run after CountrySeeder and CurrencySeeder.
 */
class BankSeeder extends Seeder
{
    /**
     * COUNTRY_CODE => list of [name, swift_code, allowed_domain, currencies[]]
     * swift_code nullable (e.g. Sparkasse). allowed_domain = domain for DKIM whitelist (email receipts).
     */
    private const BANKS = [
        'USA' => [
            ['name' => 'JPMorgan Chase', 'swift_code' => 'CHASUS33', 'allowed_domain' => 'chase.com', 'currencies' => ['USD', 'EUR', 'GBP']],
            ['name' => 'Bank of America', 'swift_code' => 'BOFAUS3N', 'allowed_domain' => 'bankofamerica.com', 'currencies' => ['USD', 'EUR', 'GBP']],
            ['name' => 'Citibank', 'swift_code' => 'CITIUS33', 'allowed_domain' => 'citi.com', 'currencies' => ['USD', 'EUR', 'GBP']],
            ['name' => 'Wells Fargo', 'swift_code' => 'WFBIUS6S', 'allowed_domain' => 'wellsfargo.com', 'currencies' => ['USD', 'EUR', 'GBP']],
        ],
        'GBR' => [
            ['name' => 'Barclays', 'swift_code' => 'BARCGB22', 'allowed_domain' => 'barclays.co.uk', 'currencies' => ['GBP', 'EUR', 'USD']],
            ['name' => 'HSBC', 'swift_code' => 'HSBCGB2L', 'allowed_domain' => 'hsbc.co.uk', 'currencies' => ['GBP', 'EUR', 'USD']],
            ['name' => 'Lloyds Bank', 'swift_code' => 'LOYDGB2L', 'allowed_domain' => 'lloydsbank.com', 'currencies' => ['GBP', 'EUR', 'USD']],
            ['name' => 'NatWest', 'swift_code' => 'NWBKGB2L', 'allowed_domain' => 'natwest.com', 'currencies' => ['GBP', 'EUR', 'USD']],
        ],
        'DEU' => [
            ['name' => 'Deutsche Bank', 'swift_code' => 'DEUTDEFF', 'allowed_domain' => 'deutsche-bank.de', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'Commerzbank', 'swift_code' => 'COBADEFF', 'allowed_domain' => 'commerzbank.de', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'Sparkasse', 'swift_code' => null, 'allowed_domain' => 'sparkasse.de', 'currencies' => ['EUR', 'USD']],
            ['name' => 'Postbank', 'swift_code' => 'PBNKDEFF', 'allowed_domain' => 'postbank.de', 'currencies' => ['EUR', 'USD']],
        ],
        'FRA' => [
            ['name' => 'BNP Paribas', 'swift_code' => 'BNPAFRPP', 'allowed_domain' => 'bnpparibas.fr', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'Societe Generale', 'swift_code' => 'SOGEFRPP', 'allowed_domain' => 'societegenerale.fr', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'Credit Agricole', 'swift_code' => 'CAGRFRPP', 'allowed_domain' => 'credit-agricole.fr', 'currencies' => ['EUR', 'USD']],
            ['name' => 'La Banque Postale', 'swift_code' => 'LAPOFRPP', 'allowed_domain' => 'banquepostale.fr', 'currencies' => ['EUR', 'USD']],
        ],
        'CHN' => [
            ['name' => 'Bank of China', 'swift_code' => 'BKCHCNBJ', 'allowed_domain' => 'boc.cn', 'currencies' => ['CNY', 'USD', 'EUR']],
            ['name' => 'Industrial and Commercial Bank of China', 'swift_code' => 'ICBKCNBJ', 'allowed_domain' => 'icbc.com.cn', 'currencies' => ['CNY', 'USD', 'EUR']],
            ['name' => 'China Construction Bank', 'swift_code' => 'CCBICNBJ', 'allowed_domain' => 'ccb.com', 'currencies' => ['CNY', 'USD', 'EUR']],
            ['name' => 'Agricultural Bank of China', 'swift_code' => 'ABOCCNBJ', 'allowed_domain' => 'abchina.com', 'currencies' => ['CNY', 'USD', 'EUR']],
        ],
        'JPN' => [
            ['name' => 'Mizuho Bank', 'swift_code' => 'MHBKJPJT', 'allowed_domain' => 'mizuhobank.co.jp', 'currencies' => ['JPY', 'USD', 'EUR']],
            ['name' => 'MUFG Bank', 'swift_code' => 'BOTKJPJT', 'allowed_domain' => 'mufg.jp', 'currencies' => ['JPY', 'USD', 'EUR']],
            ['name' => 'Sumitomo Mitsui Banking Corporation', 'swift_code' => 'SMBCJPJT', 'allowed_domain' => 'smbc.co.jp', 'currencies' => ['JPY', 'USD', 'EUR']],
            ['name' => 'Japan Post Bank', 'swift_code' => 'JPSTJPJT', 'allowed_domain' => 'postbank.jp', 'currencies' => ['JPY', 'USD']],
        ],
        'CAN' => [
            ['name' => 'Royal Bank of Canada', 'swift_code' => 'ROYCCAT2', 'allowed_domain' => 'rbc.com', 'currencies' => ['CAD', 'USD', 'EUR']],
            ['name' => 'TD Bank', 'swift_code' => 'TDOMCATT', 'allowed_domain' => 'td.com', 'currencies' => ['CAD', 'USD', 'EUR']],             ['name' => 'Bank of Montreal', 'swift_code' => 'BOFMCAT2', 'allowed_domain' => 'bmo.com', 'currencies' => ['CAD', 'USD', 'EUR']],
            ['name' => 'Scotiabank', 'swift_code' => 'NOSCCATT', 'allowed_domain' => 'scotiabank.com', 'currencies' => ['CAD', 'USD', 'EUR']],
        ],
        'AUS' => [
            ['name' => 'Commonwealth Bank', 'swift_code' => 'CTBAAU2S', 'allowed_domain' => 'commbank.com.au', 'currencies' => ['AUD', 'USD', 'EUR']],
            ['name' => 'Westpac', 'swift_code' => 'WBKPAU2S', 'allowed_domain' => 'westpac.com.au', 'currencies' => ['AUD', 'USD', 'EUR']],
            ['name' => 'National Australia Bank', 'swift_code' => 'NATAAU33', 'allowed_domain' => 'nab.com.au', 'currencies' => ['AUD', 'USD', 'EUR']],
            ['name' => 'ANZ', 'swift_code' => 'ANZBAU3M', 'allowed_domain' => 'anz.com', 'currencies' => ['AUD', 'USD', 'EUR']],
        ],
        'BRA' => [
            ['name' => 'Itaú', 'swift_code' => 'ITAUBRSP', 'allowed_domain' => 'itau.com.br', 'currencies' => ['BRL', 'USD', 'EUR']],
            ['name' => 'Bradesco', 'swift_code' => 'BBDCBRSP', 'allowed_domain' => 'bradesco.com.br', 'currencies' => ['BRL', 'USD', 'EUR']],
            ['name' => 'Banco do Brasil', 'swift_code' => 'BRASBRRJ', 'allowed_domain' => 'bancodobrasil.com.br', 'currencies' => ['BRL', 'USD', 'EUR']],
            ['name' => 'Caixa Econômica Federal', 'swift_code' => 'CEFBRRSP', 'allowed_domain' => 'caixa.gov.br', 'currencies' => ['BRL', 'USD']],
        ],
        'IND' => [
            ['name' => 'State Bank of India', 'swift_code' => 'SBININBB', 'allowed_domain' => 'sbi.co.in', 'currencies' => ['INR', 'USD', 'EUR']],
            ['name' => 'HDFC Bank', 'swift_code' => 'HDFCINBB', 'allowed_domain' => 'hdfcbank.com', 'currencies' => ['INR', 'USD', 'EUR']],
            ['name' => 'ICICI Bank', 'swift_code' => 'ICICINBB', 'allowed_domain' => 'icicibank.com', 'currencies' => ['INR', 'USD', 'EUR']],
            ['name' => 'Axis Bank', 'swift_code' => 'AXISINBB', 'allowed_domain' => 'axisbank.com', 'currencies' => ['INR', 'USD', 'EUR']],
        ],
        'ITA' => [
            ['name' => 'Intesa Sanpaolo', 'swift_code' => 'BCITITMM', 'allowed_domain' => 'intesasanpaolo.com', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'UniCredit', 'swift_code' => 'CRPPIT2P', 'allowed_domain' => 'unicredit.it', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'BNP Paribas Italy', 'swift_code' => 'BNPAITMM', 'allowed_domain' => 'bnpparibas.it', 'currencies' => ['EUR', 'USD']],
            ['name' => 'Banco BPM', 'swift_code' => 'BPMIITMM', 'allowed_domain' => 'bancobpm.it', 'currencies' => ['EUR', 'USD']],
        ],
        'ESP' => [
            ['name' => 'BBVA', 'swift_code' => 'BBVAESMM', 'allowed_domain' => 'bbva.es', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'Santander', 'swift_code' => 'BSCHESMM', 'allowed_domain' => 'santander.com', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'CaixaBank', 'swift_code' => 'CAIXESBB', 'allowed_domain' => 'caixabank.es', 'currencies' => ['EUR', 'USD']],
            ['name' => 'Bankinter', 'swift_code' => 'BKTRESMM', 'allowed_domain' => 'bankinter.com', 'currencies' => ['EUR', 'USD']],
        ],
        'NLD' => [
            ['name' => 'ING', 'swift_code' => 'INGBNL2A', 'allowed_domain' => 'ing.nl', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'ABN AMRO', 'swift_code' => 'ABNANL2A', 'allowed_domain' => 'abnamro.nl', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'Rabobank', 'swift_code' => 'RABONL2U', 'allowed_domain' => 'rabobank.nl', 'currencies' => ['EUR', 'USD', 'GBP']],
            ['name' => 'SNS Bank', 'swift_code' => 'SNSBNL2A', 'allowed_domain' => 'snsbank.nl', 'currencies' => ['EUR', 'USD']],
        ],
        'CHE' => [
            ['name' => 'UBS', 'swift_code' => 'UBSWCHZH', 'allowed_domain' => 'ubs.com', 'currencies' => ['CHF', 'USD', 'EUR']],
            ['name' => 'Credit Suisse', 'swift_code' => 'CSCHCHZZ', 'allowed_domain' => 'credit-suisse.com', 'currencies' => ['CHF', 'USD', 'EUR']],
            ['name' => 'Raiffeisen', 'swift_code' => 'RAIFCH22', 'allowed_domain' => 'raiffeisen.ch', 'currencies' => ['CHF', 'USD', 'EUR']],
            ['name' => 'PostFinance', 'swift_code' => 'PSCHCH22', 'allowed_domain' => 'postfinance.ch', 'currencies' => ['CHF', 'USD']],
        ],
        'RUS' => [
            ['name' => 'T-Bank', 'swift_code' => 'TCSBRUMM', 'allowed_domain' => 'tinkoff.ru', 'currencies' => ['RUB', 'USD', 'EUR']],
            ['name' => 'Alfa-Bank', 'swift_code' => 'ALFARUMM', 'allowed_domain' => 'alfabank.ru', 'currencies' => ['RUB', 'USD', 'EUR']],
            ['name' => 'VTB', 'swift_code' => 'VTBRRUMM', 'allowed_domain' => 'vtb.ru', 'currencies' => ['RUB', 'USD', 'EUR']],
            ['name' => 'Gazprombank', 'swift_code' => 'GAZPRUMM', 'allowed_domain' => 'gazprombank.ru', 'currencies' => ['RUB', 'USD', 'EUR']],
            ['name' => 'Raiffeisen Bank', 'swift_code' => 'RZBMRUMM', 'allowed_domain' => 'raiffeisen.ru', 'currencies' => ['RUB', 'USD', 'EUR']],
            ['name' => 'Rosbank', 'swift_code' => 'ROSRRUMM', 'allowed_domain' => 'rosbank.ru', 'currencies' => ['RUB', 'USD', 'EUR']],
            ['name' => 'OTP Bank', 'swift_code' => 'OTPVRUMM', 'allowed_domain' => 'otpbank.ru', 'currencies' => ['RUB', 'USD', 'EUR']],
            ['name' => 'Uralsib', 'swift_code' => 'URTLRUMM', 'allowed_domain' => 'uralsib.ru', 'currencies' => ['RUB', 'USD', 'EUR']],
        ],
        'KAZ' => [
            ['name' => 'Halyk Bank', 'swift_code' => 'HSBKKZKX', 'allowed_domain' => 'halykbank.kz', 'currencies' => ['KZT', 'USD', 'EUR']],
            ['name' => 'Kaspi Bank', 'swift_code' => 'KSGPKZ2X', 'allowed_domain' => 'kaspi.kz', 'currencies' => ['KZT', 'USD', 'EUR']],
            ['name' => 'ForteBank', 'swift_code' => 'IRTYKZKA', 'allowed_domain' => 'fortebank.com', 'currencies' => ['KZT', 'USD']],
        ],
        'UKR' => [
            ['name' => 'PrivatBank', 'swift_code' => 'PBANUA2X', 'allowed_domain' => 'privatbank.ua', 'currencies' => ['UAH', 'USD', 'EUR']],
            ['name' => 'Oschadbank', 'swift_code' => 'COSCUAUK', 'allowed_domain' => 'oschadbank.ua', 'currencies' => ['UAH', 'USD', 'EUR']],
            ['name' => 'Ukrgasbank', 'swift_code' => 'UGASUAUK', 'allowed_domain' => 'ukrgasbank.com', 'currencies' => ['UAH', 'USD']],
        ],
        'ZAF' => [
            ['name' => 'Standard Bank', 'swift_code' => 'SBZAZAJJ', 'allowed_domain' => 'standardbank.co.za', 'currencies' => ['ZAR', 'USD', 'EUR']],
            ['name' => 'Absa', 'swift_code' => 'ABSAZAJJ', 'allowed_domain' => 'absa.co.za', 'currencies' => ['ZAR', 'USD', 'EUR']],
            ['name' => 'Nedbank', 'swift_code' => 'NEDSZAJJ', 'allowed_domain' => 'nedbank.co.za', 'currencies' => ['ZAR', 'USD', 'EUR']],
            ['name' => 'FirstRand', 'swift_code' => 'FIRNZAJJ', 'allowed_domain' => 'firstrand.co.za', 'currencies' => ['ZAR', 'USD']],
        ],
        'MEX' => [
            ['name' => 'BBVA Mexico', 'swift_code' => 'BCMRMXMM', 'allowed_domain' => 'bbva.mx', 'currencies' => ['MXN', 'USD', 'EUR']],
            ['name' => 'Banorte', 'swift_code' => 'GFNOMXMM', 'allowed_domain' => 'banorte.com', 'currencies' => ['MXN', 'USD', 'EUR']],
            ['name' => 'Banamex', 'swift_code' => 'BNMXMXMM', 'allowed_domain' => 'citibanamex.com', 'currencies' => ['MXN', 'USD']], // Citibanamex (CITIUS33 = Citi USA)
            ['name' => 'Santander Mexico', 'swift_code' => 'BSMXMXMM', 'allowed_domain' => 'santander.com.mx', 'currencies' => ['MXN', 'USD']],
        ],
        'SWE' => [
            ['name' => 'Swedbank', 'swift_code' => 'SWEDSESS', 'allowed_domain' => 'swedbank.se', 'currencies' => ['SEK', 'EUR', 'USD']],
            ['name' => 'SEB', 'swift_code' => 'ESSESESS', 'allowed_domain' => 'skandinaviskaenskildabanken.se', 'currencies' => ['SEK', 'EUR', 'USD']],
            ['name' => 'Handelsbanken', 'swift_code' => 'HANDSESS', 'allowed_domain' => 'handelsbanken.se', 'currencies' => ['SEK', 'EUR', 'USD']],
            ['name' => 'Nordea', 'swift_code' => 'NDEASESS', 'allowed_domain' => 'nordea.se', 'currencies' => ['SEK', 'EUR', 'USD']],
        ],
        'NOR' => [
            ['name' => 'DNB', 'swift_code' => 'DNBANOKK', 'allowed_domain' => 'dnb.no', 'currencies' => ['NOK', 'EUR', 'USD']],
            ['name' => 'Nordea Norway', 'swift_code' => 'NDEANOKK', 'allowed_domain' => 'nordea.no', 'currencies' => ['NOK', 'EUR', 'USD']],
            ['name' => 'SpareBank 1', 'swift_code' => 'SPTRNO22', 'allowed_domain' => 'sparebank1.no', 'currencies' => ['NOK', 'EUR', 'USD']],
            ['name' => 'Handelsbanken Norway', 'swift_code' => 'HANDNOKK', 'allowed_domain' => 'handelsbanken.no', 'currencies' => ['NOK', 'EUR', 'USD']],
        ],
        'POL' => [
            ['name' => 'PKO Bank Polski', 'swift_code' => 'PKOPPLPW', 'allowed_domain' => 'pkobp.pl', 'currencies' => ['PLN', 'EUR', 'USD']],
            ['name' => 'mBank', 'swift_code' => 'MILLPLPW', 'allowed_domain' => 'mbank.pl', 'currencies' => ['PLN', 'EUR', 'USD']],
            ['name' => 'ING Bank Slaski', 'swift_code' => 'INGBPLPW', 'allowed_domain' => 'ing.pl', 'currencies' => ['PLN', 'EUR', 'USD']],
            ['name' => 'Bank Pekao', 'swift_code' => 'PEKAPLPW', 'allowed_domain' => 'pko.pl', 'currencies' => ['PLN', 'EUR', 'USD']],
        ],
        'TUR' => [
            ['name' => 'Garanti BBVA', 'swift_code' => 'GARBTRIS', 'allowed_domain' => 'garanti.com.tr', 'currencies' => ['TRY', 'USD', 'EUR']],
            ['name' => 'Ziraat Bank', 'swift_code' => 'TCZBTR2A', 'allowed_domain' => 'ziraatbank.com.tr', 'currencies' => ['TRY', 'USD', 'EUR']],
            ['name' => 'Yapi Kredi', 'swift_code' => 'YAPITRIS', 'allowed_domain' => 'yapikredi.com.tr', 'currencies' => ['TRY', 'USD', 'EUR']],
            ['name' => 'Akbank', 'swift_code' => 'AKBKTRIS', 'allowed_domain' => 'akbank.com.tr', 'currencies' => ['TRY', 'USD', 'EUR']],
        ],
        'SAU' => [
            ['name' => 'Al Rajhi Bank', 'swift_code' => 'RJHISARI', 'allowed_domain' => 'alrajhibank.com.sa', 'currencies' => ['SAR', 'USD', 'EUR']],
            ['name' => 'Saudi National Bank', 'swift_code' => 'NCBKSAJE', 'allowed_domain' => 'ncb.com.sa', 'currencies' => ['SAR', 'USD', 'EUR']],
            ['name' => 'Saudi British Bank', 'swift_code' => 'SABBSARI', 'allowed_domain' => 'sabb.com.sa', 'currencies' => ['SAR', 'USD', 'EUR']],
            ['name' => 'Riyad Bank', 'swift_code' => 'RIBLSAJE', 'allowed_domain' => 'riyadbank.com.sa', 'currencies' => ['SAR', 'USD', 'EUR']],
        ],
        'ARE' => [
            ['name' => 'Emirates NBD', 'swift_code' => 'EBILAEAD', 'allowed_domain' => 'emiratesnbd.com', 'currencies' => ['AED', 'USD', 'EUR']],
            ['name' => 'First Abu Dhabi Bank', 'swift_code' => 'FABAEAA', 'allowed_domain' => 'fab.com', 'currencies' => ['AED', 'USD', 'EUR']],
            ['name' => 'Abu Dhabi Commercial Bank', 'swift_code' => 'ADCBAAE', 'allowed_domain' => 'adcb.com', 'currencies' => ['AED', 'USD', 'EUR']],
            ['name' => 'Dubai Islamic Bank', 'swift_code' => 'DIBUAEAD', 'allowed_domain' => 'dib.ae', 'currencies' => ['AED', 'USD', 'EUR']],
        ],
        'SGP' => [
            ['name' => 'DBS', 'swift_code' => 'DBSSSGSG', 'allowed_domain' => 'dbs.com.sg', 'currencies' => ['SGD', 'USD', 'EUR']],
            ['name' => 'OCBC', 'swift_code' => 'OCBCSGSG', 'allowed_domain' => 'ocbc.com.sg', 'currencies' => ['SGD', 'USD', 'EUR']],
            ['name' => 'UOB', 'swift_code' => 'UOVBSGSG', 'allowed_domain' => 'uob.com.sg', 'currencies' => ['SGD', 'USD', 'EUR']],
            ['name' => 'Standard Chartered Singapore', 'swift_code' => 'SCBLSG22', 'allowed_domain' => 'standardchartered.com.sg', 'currencies' => ['SGD', 'USD', 'EUR']],
        ],
        'HKG' => [
            ['name' => 'HSBC Hong Kong', 'swift_code' => 'HSBCHKH', 'allowed_domain' => 'hsbc.com.hk', 'currencies' => ['HKD', 'USD', 'EUR']],
            ['name' => 'Bank of China Hong Kong', 'swift_code' => 'BKCHHKH', 'allowed_domain' => 'boc.com.hk', 'currencies' => ['HKD', 'USD', 'EUR']],
            ['name' => 'Standard Chartered Hong Kong', 'swift_code' => 'SCBLHKH', 'allowed_domain' => 'standardchartered.com.hk', 'currencies' => ['HKD', 'USD', 'EUR']],
            ['name' => 'Hang Seng Bank', 'swift_code' => 'HASEHKH', 'allowed_domain' => 'hangseng.com.hk', 'currencies' => ['HKD', 'USD', 'EUR']],
        ],
        'KOR' => [
            ['name' => 'Kookmin Bank', 'swift_code' => 'CZBILKRS', 'allowed_domain' => 'kookminbank.com', 'currencies' => ['KRW', 'USD', 'EUR']],
            ['name' => 'Shinhan Bank', 'swift_code' => 'SHBKKRSE', 'allowed_domain' => 'shinhan.com', 'currencies' => ['KRW', 'USD', 'EUR']],
            ['name' => 'Woori Bank', 'swift_code' => 'HVBKKRSE', 'allowed_domain' => 'wooribank.com', 'currencies' => ['KRW', 'USD', 'EUR']],
            ['name' => 'Korea Exchange Bank', 'swift_code' => 'KOEXKRSE', 'allowed_domain' => 'keb.co.kr', 'currencies' => ['KRW', 'USD', 'EUR']],
        ],
        'NGA' => [
            ['name' => 'Zenith Bank', 'swift_code' => 'ZENINGLA', 'allowed_domain' => 'zenithbank.com', 'currencies' => ['NGN', 'USD', 'EUR']],
            ['name' => 'Guaranty Trust Bank', 'swift_code' => 'GTBINGLA', 'allowed_domain' => 'gtbank.com', 'currencies' => ['NGN', 'USD', 'EUR']],
            ['name' => 'Access Bank', 'swift_code' => 'ABNGNLA', 'allowed_domain' => 'accessbankplc.com', 'currencies' => ['NGN', 'USD', 'EUR']],
            ['name' => 'United Bank for Africa', 'swift_code' => 'UNAFNGLA', 'allowed_domain' => 'ubagroup.com', 'currencies' => ['NGN', 'USD', 'EUR']],
        ],
        'EGY' => [
            ['name' => 'National Bank of Egypt', 'swift_code' => 'NBEGEGCX', 'allowed_domain' => 'nbe.com.eg', 'currencies' => ['EGP', 'USD', 'EUR']],
            ['name' => 'Banque Misr', 'swift_code' => 'BMISEGCX', 'allowed_domain' => 'banquemisr.com', 'currencies' => ['EGP', 'USD', 'EUR']],
            ['name' => 'Commercial International Bank', 'swift_code' => 'CIBEEGCX', 'allowed_domain' => 'cib.com.eg', 'currencies' => ['EGP', 'USD', 'EUR']],
            ['name' => 'QNB Alahli', 'swift_code' => 'QNBAEGCX', 'allowed_domain' => 'qnbalahli.com', 'currencies' => ['EGP', 'USD', 'EUR']],
        ],
        'ARG' => [
            ['name' => 'Banco Galicia', 'swift_code' => 'GFNBARBA', 'allowed_domain' => 'galicia.com.ar', 'currencies' => ['ARS', 'USD', 'EUR']],
            ['name' => 'BBVA Argentina', 'swift_code' => 'BFRPARBA', 'allowed_domain' => 'bbva.com.ar', 'currencies' => ['ARS', 'USD', 'EUR']],
            ['name' => 'Banco Santander Rio', 'swift_code' => 'BSARARBA', 'allowed_domain' => 'santander.com.ar', 'currencies' => ['ARS', 'USD', 'EUR']],
            ['name' => 'Banco Macro', 'swift_code' => 'BMAOARBA', 'allowed_domain' => 'macro.com.ar', 'currencies' => ['ARS', 'USD', 'EUR']],
            ['name' => 'HSBC Bank Argentina', 'swift_code' => 'HSBCARBA', 'allowed_domain' => 'hsbc.com.ar', 'currencies' => ['ARS', 'USD', 'EUR']],
        ],
        'CHL' => [
            ['name' => 'Banco de Chile', 'swift_code' => 'EDWACHCL', 'allowed_domain' => 'bancodechile.cl', 'currencies' => ['CLP', 'USD', 'EUR']],
            ['name' => 'Banco Santander Chile', 'swift_code' => 'BSCHCLRM', 'allowed_domain' => 'santander.cl', 'currencies' => ['CLP', 'USD', 'EUR']],
            ['name' => 'Banco de Credito e Inversiones', 'swift_code' => 'BICECLRM', 'allowed_domain' => 'bci.cl', 'currencies' => ['CLP', 'USD', 'EUR']],
            ['name' => 'BancoEstado', 'swift_code' => 'BESTCLRM', 'allowed_domain' => 'bancoestado.cl', 'currencies' => ['CLP', 'USD', 'EUR']],
            ['name' => 'Scotiabank Chile', 'swift_code' => 'NSUDCLRM', 'allowed_domain' => 'scotiabank.cl', 'currencies' => ['CLP', 'USD', 'EUR']],
        ],
        'COL' => [
            ['name' => 'Bancolombia', 'swift_code' => 'COLOCOBM', 'allowed_domain' => 'bancolombia.com', 'currencies' => ['COP', 'USD', 'EUR']],
            ['name' => 'Banco de Bogota', 'swift_code' => 'BBOGCOBB', 'allowed_domain' => 'bancodebogota.com', 'currencies' => ['COP', 'USD', 'EUR']],
            ['name' => 'BBVA Colombia', 'swift_code' => 'GEROCOBB', 'allowed_domain' => 'bbva.com.co', 'currencies' => ['COP', 'USD', 'EUR']],
            ['name' => 'Davivienda', 'swift_code' => 'CAFECOBB', 'allowed_domain' => 'davivienda.com', 'currencies' => ['COP', 'USD', 'EUR']],
        ],
        'PER' => [
            ['name' => 'Banco de Credito del Peru', 'swift_code' => 'BCPLPEPL', 'allowed_domain' => 'bcp.com.pe', 'currencies' => ['PEN', 'USD', 'EUR']],
            ['name' => 'Interbank', 'swift_code' => 'IBKPEPL', 'allowed_domain' => 'interbank.pe', 'currencies' => ['PEN', 'USD', 'EUR']],
            ['name' => 'BBVA Continental', 'swift_code' => 'BCONPEPL', 'allowed_domain' => 'bbva.pe', 'currencies' => ['PEN', 'USD', 'EUR']],
            ['name' => 'Scotiabank Peru', 'swift_code' => 'BSUDPEPL', 'allowed_domain' => 'scotiabank.com.pe', 'currencies' => ['PEN', 'USD', 'EUR']],
        ],
        'IDN' => [
            ['name' => 'Bank Mandiri', 'swift_code' => 'BMRIIDJA', 'allowed_domain' => 'mandiri.co.id', 'currencies' => ['IDR', 'USD', 'EUR']],
            ['name' => 'Bank Rakyat Indonesia', 'swift_code' => 'BRINIDJA', 'allowed_domain' => 'bri.co.id', 'currencies' => ['IDR', 'USD', 'EUR']],
            ['name' => 'Bank Central Asia', 'swift_code' => 'BCAIIDJA', 'allowed_domain' => 'bca.co.id', 'currencies' => ['IDR', 'USD', 'EUR']],
            ['name' => 'Bank Negara Indonesia', 'swift_code' => 'BNINIDJA', 'allowed_domain' => 'bni.co.id', 'currencies' => ['IDR', 'USD', 'EUR']],
        ],
        'MYS' => [
            ['name' => 'Maybank', 'swift_code' => 'MBBEMYKL', 'allowed_domain' => 'maybank.com.my', 'currencies' => ['MYR', 'USD', 'EUR']],
            ['name' => 'CIMB Bank', 'swift_code' => 'CIBBMYKL', 'allowed_domain' => 'cimb.com.my', 'currencies' => ['MYR', 'USD', 'EUR']],
            ['name' => 'Public Bank', 'swift_code' => 'PBBEMYKL', 'allowed_domain' => 'publicbank.com.my', 'currencies' => ['MYR', 'USD', 'EUR']],
            ['name' => 'RHB Bank', 'swift_code' => 'RHBBMYKL', 'allowed_domain' => 'rhbgroup.com', 'currencies' => ['MYR', 'USD', 'EUR']],
        ],
        'THA' => [
            ['name' => 'Bangkok Bank', 'swift_code' => 'BKKBTHBK', 'allowed_domain' => 'bangkokbank.com', 'currencies' => ['THB', 'USD', 'EUR']],
            ['name' => 'Kasikorn Bank', 'swift_code' => 'KASITHBK', 'allowed_domain' => 'kasikornbank.com', 'currencies' => ['THB', 'USD', 'EUR']],
            ['name' => 'Siam Commercial Bank', 'swift_code' => 'SCBLTHBX', 'allowed_domain' => 'scb.co.th', 'currencies' => ['THB', 'USD', 'EUR']],
            ['name' => 'Krung Thai Bank', 'swift_code' => 'KRTHTHBK', 'allowed_domain' => 'ktb.co.th', 'currencies' => ['THB', 'USD', 'EUR']],
        ],
        'VNM' => [
            ['name' => 'Vietcombank', 'swift_code' => 'BFTVVNVX', 'allowed_domain' => 'vietcombank.com.vn', 'currencies' => ['VND', 'USD', 'EUR']],
            ['name' => 'BIDV', 'swift_code' => 'BIDVVNVX', 'allowed_domain' => 'bidv.com.vn', 'currencies' => ['VND', 'USD', 'EUR']],
            ['name' => 'VietinBank', 'swift_code' => 'ICBVVNVX', 'allowed_domain' => 'vietinbank.vn', 'currencies' => ['VND', 'USD', 'EUR']],
            ['name' => 'Techcombank', 'swift_code' => 'VTCBVNVX', 'allowed_domain' => 'techcombank.com.vn', 'currencies' => ['VND', 'USD', 'EUR']],
        ],
        'PAK' => [
            ['name' => 'Habib Bank Limited', 'swift_code' => 'HABBPKKA', 'allowed_domain' => 'hbl.com', 'currencies' => ['PKR', 'USD', 'EUR']],
            ['name' => 'National Bank of Pakistan', 'swift_code' => 'NBPAPKKA', 'allowed_domain' => 'nbp.com.pk', 'currencies' => ['PKR', 'USD', 'EUR']],
            ['name' => 'United Bank Limited', 'swift_code' => 'UNILPKKA', 'allowed_domain' => 'ubl.com.pk', 'currencies' => ['PKR', 'USD', 'EUR']],
            ['name' => 'MCB Bank', 'swift_code' => 'MCBAPKKA', 'allowed_domain' => 'mcb.com.pk', 'currencies' => ['PKR', 'USD', 'EUR']],
        ],
        'KEN' => [
            ['name' => 'KCB Bank', 'swift_code' => 'KCBOKENX', 'allowed_domain' => 'kcbbankgroup.com', 'currencies' => ['KES', 'USD', 'EUR']],
            ['name' => 'Equity Bank', 'swift_code' => 'EQBLKENA', 'allowed_domain' => 'equitybankgroup.com', 'currencies' => ['KES', 'USD', 'EUR']],
            ['name' => 'Co-operative Bank of Kenya', 'swift_code' => 'KOOPKENX', 'allowed_domain' => 'co-opbank.co.ke', 'currencies' => ['KES', 'USD', 'EUR']],
            ['name' => 'Standard Chartered Bank Kenya', 'swift_code' => 'SCBLKENX', 'allowed_domain' => 'standardchartered.co.ke', 'currencies' => ['KES', 'USD', 'EUR']],
        ],
        'MAR' => [
            ['name' => 'Attijariwafa Bank', 'swift_code' => 'BCMAMAMC', 'allowed_domain' => 'attijariwafabank.com', 'currencies' => ['MAD', 'USD', 'EUR']],
            ['name' => 'Banque Populaire', 'swift_code' => 'BCPOMAMC', 'allowed_domain' => 'banquepopulaire.ma', 'currencies' => ['MAD', 'USD', 'EUR']],
            ['name' => 'Bank of Africa', 'swift_code' => 'BMCEMAMC', 'allowed_domain' => 'bmcebank.ma', 'currencies' => ['MAD', 'USD', 'EUR']],
            ['name' => 'Credit Agricole du Maroc', 'swift_code' => 'CADMMAMC', 'allowed_domain' => 'creditagricole.ma', 'currencies' => ['MAD', 'USD', 'EUR']],
        ],
    ];

    public function run(): void
    {
        // Only banks with allowed_domain are used (DKIM/email receipt whitelist). Remove others.
        Bank::whereNull('allowed_domain')
            ->orWhere('allowed_domain', '')
            ->delete();

        $currenciesByCode = FiatCurrency::all()->keyBy('code');

        foreach (self::BANKS as $countryCode => $banks) {
            $country = Country::where('code', $countryCode)->first();
            if ($country === null) {
                continue;
            }

            foreach ($banks as $row) {
                $allowedDomain = trim((string) ($row['allowed_domain'] ?? ''));
                if ($allowedDomain === '') {
                    continue; // only seed banks with allowed_domain
                }
                $attributes = [
                    'country_id' => $country->id,
                    'name' => $row['name'],
                    'swift_code' => $row['swift_code'],
                    'allowed_domain' => $allowedDomain,
                    'supports_individuals' => true,
                    'supports_legal_entities' => true,
                ];

                // One bank per allowed_domain. Prefer existing row with same swift to avoid unique constraint when updating.
                if (!empty($row['swift_code'])) {
                    $existing = Bank::where('country_id', $country->id)
                        ->where('allowed_domain', $allowedDomain)
                        ->get();
                    if ($existing->isEmpty()) {
                        $bank = Bank::create($attributes);
                    } else {
                        $bank = $existing->first(fn ($b) => $b->swift_code === $row['swift_code']) ?? $existing->first();
                        $bank->update($attributes);
                        Bank::where('country_id', $country->id)
                            ->where('allowed_domain', $allowedDomain)
                            ->where('id', '!=', $bank->id)
                            ->delete();
                    }
                } else {
                    $bank = Bank::updateOrCreate(
                        [
                            'country_id' => $country->id,
                            'name' => $row['name'],
                        ],
                        $attributes
                    );
                }

                $currencyCodes = $row['currencies'] ?? [];
                $currencyIds = [];
                foreach ($currencyCodes as $code) {
                    $fc = $currenciesByCode->get($code);
                    if ($fc !== null) {
                        $currencyIds[] = $fc->id;
                    }
                }
                if ($currencyIds !== []) {
                    $bank->fiatCurrencies()->sync($currencyIds);
                }
            }
        }
    }
}
