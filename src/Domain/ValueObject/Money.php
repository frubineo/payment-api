<?php

declare(strict_types=1);

namespace PaymentApi\Domain\ValueObject;

use InvalidArgumentException;
use Money\Currency;
use Money\Money as BaseMoneyValue;
use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;
use Money\Formatter\DecimalMoneyFormatter;

/**
 * Value Object pour les montants monétaires
 * 
 * Utilise MoneyPHP pour éviter les erreurs de précision flottante
 * Supporte les opérations arithmétiques et conversions sécurisées
 */
final class Money
{
    private readonly BaseMoneyValue $money;
    private readonly Currency $currency;

    public function __construct(BaseMoneyValue $money)
    {
        $this->money = $money;
        $this->currency = $money->getCurrency();
    }

    /**
     * Crée un montant depuis un nombre et une devise
     */
    public static function fromAmount(string|int|float $amount, string $currencyCode): self
    {
        if (!self::isSupportedCurrency($currencyCode)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported currency: "%s"', $currencyCode)
            );
        }

        $currency = new Currency(strtoupper($currencyCode));
        $currencies = new ISOCurrencies();
        $parser = new DecimalMoneyParser($currencies);

        // Convertir en string pour éviter les problèmes de précision
        $amountString = is_string($amount) ? $amount : (string) $amount;
        
        if (!is_numeric($amountString)) {
            throw new InvalidArgumentException(
                sprintf('Invalid amount format: "%s"', $amountString)
            );
        }

        $money = $parser->parse($amountString, $currency);
        
        return new self($money);
    }

    /**
     * Crée un montant depuis des centimes et une devise
     */
    public static function fromCents(int $cents, string $currencyCode): self
    {
        if (!self::isSupportedCurrency($currencyCode)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported currency: "%s"', $currencyCode)
            );
        }

        $currency = new Currency(strtoupper($currencyCode));
        $money = new BaseMoneyValue($cents, $currency);
        
        return new self($money);
    }

    /**
     * Crée un montant zéro dans une devise
     */
    public static function zero(string $currencyCode): self
    {
        return self::fromCents(0, $currencyCode);
    }

    /**
     * Retourne le montant en centimes
     */
    public function getAmountInCents(): int
    {
        return (int) $this->money->getAmount();
    }

    /**
     * Retourne le montant en unité principale (ex: euros)
     */
    public function getAmountInMajorUnit(): string
    {
        $currencies = new ISOCurrencies();
        $formatter = new DecimalMoneyFormatter($currencies);
        
        return $formatter->format($this->money);
    }

    /**
     * Retourne le code de devise
     */
    public function getCurrencyCode(): string
    {
        return $this->currency->getCode();
    }

    /**
     * Retourne l'objet Currency
     */
    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    /**
     * Retourne l'objet Money interne
     */
    public function getMoneyValue(): BaseMoneyValue
    {
        return $this->money;
    }

    /**
     * Additionne deux montants de même devise
     */
    public function add(Money $other): self
    {
        if (!$this->isSameCurrency($other)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot add different currencies: %s and %s',
                    $this->getCurrencyCode(),
                    $other->getCurrencyCode()
                )
            );
        }

        return new self($this->money->add($other->money));
    }

    /**
     * Soustrait deux montants de même devise
     */
    public function subtract(Money $other): self
    {
        if (!$this->isSameCurrency($other)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot subtract different currencies: %s and %s',
                    $this->getCurrencyCode(),
                    $other->getCurrencyCode()
                )
            );
        }

        return new self($this->money->subtract($other->money));
    }

    /**
     * Multiplie le montant par un facteur
     */
    public function multiply(string|int|float $multiplier): self
    {
        if (!is_numeric($multiplier)) {
            throw new InvalidArgumentException(
                sprintf('Invalid multiplier: "%s"', $multiplier)
            );
        }

        $multiplierString = is_string($multiplier) ? $multiplier : (string) $multiplier;
        
        return new self($this->money->multiply($multiplierString));
    }

    /**
     * Divise le montant par un diviseur
     */
    public function divide(string|int|float $divisor): self
    {
        if (!is_numeric($divisor) || (float) $divisor == 0) {
            throw new InvalidArgumentException(
                sprintf('Invalid divisor: "%s"', $divisor)
            );
        }

        $divisorString = is_string($divisor) ? $divisor : (string) $divisor;
        
        return new self($this->money->divide($divisorString));
    }

    /**
     * Vérifie si le montant est zéro
     */
    public function isZero(): bool
    {
        return $this->money->isZero();
    }

    /**
     * Vérifie si le montant est positif
     */
    public function isPositive(): bool
    {
        return $this->money->isPositive();
    }

    /**
     * Vérifie si le montant est négatif
     */
    public function isNegative(): bool
    {
        return $this->money->isNegative();
    }

    /**
     * Compare deux montants de même devise
     */
    public function compare(Money $other): int
    {
        if (!$this->isSameCurrency($other)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot compare different currencies: %s and %s',
                    $this->getCurrencyCode(),
                    $other->getCurrencyCode()
                )
            );
        }

        return $this->money->compare($other->money);
    }

    /**
     * Vérifie l'égalité avec un autre montant
     */
    public function equals(?Money $other): bool
    {
        if ($other === null) {
            return false;
        }

        return $this->money->equals($other->money);
    }

    /**
     * Vérifie si deux montants ont la même devise
     */
    public function isSameCurrency(Money $other): bool
    {
        return $this->currency->equals($other->currency);
    }

    /**
     * Retourne la valeur absolue
     */
    public function absolute(): self
    {
        return new self($this->money->absolute());
    }

    /**
     * Applique une commission en pourcentage
     */
    public function applyFeePercentage(string|float $feePercentage): self
    {
        if (!is_numeric($feePercentage) || (float) $feePercentage < 0) {
            throw new InvalidArgumentException(
                sprintf('Invalid fee percentage: "%s"', $feePercentage)
            );
        }

        $feeRate = (float) $feePercentage / 100;
        return $this->multiply((string) $feeRate);
    }

    /**
     * Calcule une commission fixe
     */
    public function applyFixedFee(Money $fee): self
    {
        return $this->add($fee);
    }

    /**
     * Vérifie si le montant dépasse un seuil
     */
    public function exceedsThreshold(Money $threshold): bool
    {
        return $this->compare($threshold) > 0;
    }

    /**
     * Formate le montant pour affichage
     */
    public function format(): string
    {
        return sprintf(
            '%s %s',
            $this->getAmountInMajorUnit(),
            $this->getCurrencyCode()
        );
    }

    /**
     * Sérialise pour stockage/transport
     */
    public function toArray(): array
    {
        return [
            'amount_cents' => $this->getAmountInCents(),
            'amount_major' => $this->getAmountInMajorUnit(),
            'currency' => $this->getCurrencyCode(),
        ];
    }

    /**
     * Désérialise depuis un array
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['amount_cents'], $data['currency'])) {
            throw new InvalidArgumentException('Invalid money data format');
        }

        return self::fromCents((int) $data['amount_cents'], $data['currency']);
    }

    /**
     * Sérialise pour JSON
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Représentation string
     */
    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * Vérifie si une devise est supportée
     */
    public static function isSupportedCurrency(string $currencyCode): bool
    {
        $supportedCurrencies = [
            'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'CNY',
            'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'BGN', 'RON',
            'HRK', 'RUB', 'TRY', 'BRL', 'MXN', 'INR', 'SGD', 'HKD',
            'NZD', 'ZAR', 'KRW', 'THB', 'MYR', 'IDR', 'PHP', 'VND'
        ];

        return in_array(strtoupper($currencyCode), $supportedCurrencies, true);
    }

    /**
     * Retourne la liste des devises supportées
     */
    public static function getSupportedCurrencies(): array
    {
        return [
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'CAD' => 'Canadian Dollar',
            'AUD' => 'Australian Dollar',
            'JPY' => 'Japanese Yen',
            'CHF' => 'Swiss Franc',
            'CNY' => 'Chinese Yuan',
            'SEK' => 'Swedish Krona',
            'NOK' => 'Norwegian Krone',
            'DKK' => 'Danish Krone',
            'PLN' => 'Polish Złoty',
            'CZK' => 'Czech Koruna',
            'HUF' => 'Hungarian Forint',
            'BGN' => 'Bulgarian Lev',
            'RON' => 'Romanian Leu',
            'HRK' => 'Croatian Kuna',
            'RUB' => 'Russian Ruble',
            'TRY' => 'Turkish Lira',
            'BRL' => 'Brazilian Real',
            'MXN' => 'Mexican Peso',
            'INR' => 'Indian Rupee',
            'SGD' => 'Singapore Dollar',
            'HKD' => 'Hong Kong Dollar',
            'NZD' => 'New Zealand Dollar',
            'ZAR' => 'South African Rand',
            'KRW' => 'South Korean Won',
            'THB' => 'Thai Baht',
            'MYR' => 'Malaysian Ringgit',
            'IDR' => 'Indonesian Rupiah',
            'PHP' => 'Philippine Peso',
            'VND' => 'Vietnamese Dong'
        ];
    }
} 