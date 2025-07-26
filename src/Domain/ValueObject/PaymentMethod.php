<?php

declare(strict_types=1);

namespace PaymentApi\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Value Object pour les méthodes de paiement
 * 
 * Représente de manière immutable les différents moyens de paiement supportés
 * Inclut la validation et les métadonnées spécifiques à chaque type
 */
final class PaymentMethod
{
    public const TYPE_CREDIT_CARD = 'credit_card';
    public const TYPE_DEBIT_CARD = 'debit_card';
    public const TYPE_BANK_TRANSFER = 'bank_transfer';
    public const TYPE_PAYPAL = 'paypal';
    public const TYPE_STRIPE = 'stripe';
    public const TYPE_APPLE_PAY = 'apple_pay';
    public const TYPE_GOOGLE_PAY = 'google_pay';
    public const TYPE_SEPA = 'sepa';
    public const TYPE_ACH = 'ach';
    public const TYPE_WIRE = 'wire';
    public const TYPE_CRYPTO = 'crypto';
    public const TYPE_WALLET = 'digital_wallet';
    public const TYPE_MOLLIE = 'mollie';
    public const TYPE_ADYEN = 'adyen';
    public const TYPE_KLARNA = 'klarna';
    public const TYPE_AFTERPAY = 'afterpay';

    private readonly string $type;
    private readonly string $provider;
    private readonly array $metadata;
    private readonly bool $requiresVerification;
    private readonly array $supportedCurrencies;
    private readonly array $supportedCountries;

    public function __construct(
        string $type,
        string $provider,
        array $metadata = [],
        bool $requiresVerification = false,
        array $supportedCurrencies = [],
        array $supportedCountries = []
    ) {
        $this->validateType($type);
        $this->validateProvider($provider);
        $this->validateMetadata($type, $metadata);

        $this->type = strtolower($type);
        $this->provider = strtolower($provider);
        $this->metadata = $metadata;
        $this->requiresVerification = $requiresVerification;
        $this->supportedCurrencies = array_map('strtoupper', $supportedCurrencies);
        $this->supportedCountries = array_map('strtoupper', $supportedCountries);
    }

    /**
     * Crée une méthode de paiement carte de crédit
     */
    public static function creditCard(
        string $provider = 'stripe',
        ?string $cardBrand = null,
        ?string $lastFourDigits = null,
        ?string $expiryMonth = null,
        ?string $expiryYear = null
    ): self {
        $metadata = array_filter([
            'card_brand' => $cardBrand,
            'last_four' => $lastFourDigits,
            'expiry_month' => $expiryMonth,
            'expiry_year' => $expiryYear,
            'card_type' => 'credit'
        ]);

        return new self(
            type: self::TYPE_CREDIT_CARD,
            provider: $provider,
            metadata: $metadata,
            requiresVerification: true,
            supportedCurrencies: ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
            supportedCountries: ['US', 'CA', 'GB', 'FR', 'DE', 'AU', 'ES', 'IT']
        );
    }

    /**
     * Crée une méthode de paiement carte de débit
     */
    public static function debitCard(
        string $provider = 'stripe',
        ?string $cardBrand = null,
        ?string $lastFourDigits = null
    ): self {
        $metadata = array_filter([
            'card_brand' => $cardBrand,
            'last_four' => $lastFourDigits,
            'card_type' => 'debit'
        ]);

        return new self(
            type: self::TYPE_DEBIT_CARD,
            provider: $provider,
            metadata: $metadata,
            requiresVerification: true,
            supportedCurrencies: ['USD', 'EUR', 'GBP'],
            supportedCountries: ['US', 'CA', 'GB', 'FR', 'DE']
        );
    }

    /**
     * Crée une méthode de paiement PayPal
     */
    public static function paypal(?string $email = null): self
    {
        $metadata = array_filter([
            'email' => $email,
            'payment_method' => 'paypal_account'
        ]);

        return new self(
            type: self::TYPE_PAYPAL,
            provider: 'paypal',
            metadata: $metadata,
            requiresVerification: false,
            supportedCurrencies: ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY'],
            supportedCountries: ['US', 'CA', 'GB', 'FR', 'DE', 'AU', 'ES', 'IT', 'JP']
        );
    }

    /**
     * Crée une méthode de paiement Apple Pay
     */
    public static function applePay(?string $deviceId = null): self
    {
        $metadata = array_filter([
            'device_id' => $deviceId,
            'wallet_type' => 'apple_pay'
        ]);

        return new self(
            type: self::TYPE_APPLE_PAY,
            provider: 'apple',
            metadata: $metadata,
            requiresVerification: true,
            supportedCurrencies: ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
            supportedCountries: ['US', 'CA', 'GB', 'FR', 'DE', 'AU']
        );
    }

    /**
     * Crée une méthode de paiement Google Pay
     */
    public static function googlePay(?string $accountId = null): self
    {
        $metadata = array_filter([
            'account_id' => $accountId,
            'wallet_type' => 'google_pay'
        ]);

        return new self(
            type: self::TYPE_GOOGLE_PAY,
            provider: 'google',
            metadata: $metadata,
            requiresVerification: true,
            supportedCurrencies: ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
            supportedCountries: ['US', 'CA', 'GB', 'FR', 'DE', 'AU']
        );
    }

    /**
     * Crée une méthode de paiement virement bancaire
     */
    public static function bankTransfer(
        string $provider = 'stripe',
        ?string $bankName = null,
        ?string $accountMask = null
    ): self {
        $metadata = array_filter([
            'bank_name' => $bankName,
            'account_mask' => $accountMask,
            'transfer_type' => 'bank_transfer'
        ]);

        return new self(
            type: self::TYPE_BANK_TRANSFER,
            provider: $provider,
            metadata: $metadata,
            requiresVerification: true,
            supportedCurrencies: ['USD', 'EUR', 'GBP'],
            supportedCountries: ['US', 'CA', 'GB', 'FR', 'DE']
        );
    }

    /**
     * Crée une méthode de paiement SEPA
     */
    public static function sepa(?string $iban = null): self
    {
        $metadata = array_filter([
            'iban_mask' => $iban ? self::maskIban($iban) : null,
            'transfer_type' => 'sepa_direct_debit'
        ]);

        return new self(
            type: self::TYPE_SEPA,
            provider: 'sepa',
            metadata: $metadata,
            requiresVerification: true,
            supportedCurrencies: ['EUR'],
            supportedCountries: ['FR', 'DE', 'ES', 'IT', 'NL', 'BE', 'AT', 'PT']
        );
    }

    /**
     * Crée une méthode de paiement crypto
     */
    public static function crypto(
        string $cryptocurrency,
        ?string $walletAddress = null
    ): self {
        $metadata = array_filter([
            'cryptocurrency' => strtoupper($cryptocurrency),
            'wallet_address_mask' => $walletAddress ? self::maskWalletAddress($walletAddress) : null,
            'payment_type' => 'cryptocurrency'
        ]);

        return new self(
            type: self::TYPE_CRYPTO,
            provider: 'crypto_gateway',
            metadata: $metadata,
            requiresVerification: true,
            supportedCurrencies: ['BTC', 'ETH', 'LTC', 'BCH'],
            supportedCountries: ['US', 'CA', 'GB', 'FR', 'DE']
        );
    }

    /**
     * Retourne le type de méthode de paiement
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Retourne le fournisseur
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Retourne les métadonnées
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Retourne une métadonnée spécifique
     */
    public function getMetadataValue(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }

    /**
     * Vérifie si une vérification est requise
     */
    public function requiresVerification(): bool
    {
        return $this->requiresVerification;
    }

    /**
     * Retourne les devises supportées
     */
    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }

    /**
     * Retourne les pays supportés
     */
    public function getSupportedCountries(): array
    {
        return $this->supportedCountries;
    }

    /**
     * Vérifie si une devise est supportée
     */
    public function supportsCurrency(string $currencyCode): bool
    {
        if (empty($this->supportedCurrencies)) {
            return true; // Supporte toutes les devises si aucune restriction
        }

        return in_array(strtoupper($currencyCode), $this->supportedCurrencies, true);
    }

    /**
     * Vérifie si un pays est supporté
     */
    public function supportsCountry(string $countryCode): bool
    {
        if (empty($this->supportedCountries)) {
            return true; // Supporte tous les pays si aucune restriction
        }

        return in_array(strtoupper($countryCode), $this->supportedCountries, true);
    }

    /**
     * Vérifie si c'est une méthode de paiement par carte
     */
    public function isCard(): bool
    {
        return in_array($this->type, [self::TYPE_CREDIT_CARD, self::TYPE_DEBIT_CARD], true);
    }

    /**
     * Vérifie si c'est un wallet digital
     */
    public function isDigitalWallet(): bool
    {
        return in_array($this->type, [
            self::TYPE_APPLE_PAY,
            self::TYPE_GOOGLE_PAY,
            self::TYPE_PAYPAL,
            self::TYPE_WALLET
        ], true);
    }

    /**
     * Vérifie si c'est un virement bancaire
     */
    public function isBankTransfer(): bool
    {
        return in_array($this->type, [
            self::TYPE_BANK_TRANSFER,
            self::TYPE_SEPA,
            self::TYPE_ACH,
            self::TYPE_WIRE
        ], true);
    }

    /**
     * Vérifie si c'est une crypto-monnaie
     */
    public function isCryptocurrency(): bool
    {
        return $this->type === self::TYPE_CRYPTO;
    }

    /**
     * Retourne une représentation lisible
     */
    public function getDisplayName(): string
    {
        return match($this->type) {
            self::TYPE_CREDIT_CARD => 'Carte de Crédit',
            self::TYPE_DEBIT_CARD => 'Carte de Débit',
            self::TYPE_BANK_TRANSFER => 'Virement Bancaire',
            self::TYPE_PAYPAL => 'PayPal',
            self::TYPE_STRIPE => 'Stripe',
            self::TYPE_APPLE_PAY => 'Apple Pay',
            self::TYPE_GOOGLE_PAY => 'Google Pay',
            self::TYPE_SEPA => 'SEPA',
            self::TYPE_ACH => 'ACH',
            self::TYPE_WIRE => 'Virement',
            self::TYPE_CRYPTO => 'Crypto-monnaie',
            self::TYPE_WALLET => 'Portefeuille Digital',
            self::TYPE_MOLLIE => 'Mollie',
            self::TYPE_ADYEN => 'Adyen',
            self::TYPE_KLARNA => 'Klarna',
            self::TYPE_AFTERPAY => 'Afterpay',
            default => ucfirst(str_replace('_', ' ', $this->type))
        };
    }

    /**
     * Génère un identifiant unique pour cette méthode
     */
    public function generateFingerprint(): string
    {
        $data = [
            'type' => $this->type,
            'provider' => $this->provider,
            'metadata' => $this->metadata
        ];

        return hash('sha256', json_encode($data, JSON_SORT_KEYS));
    }

    /**
     * Vérifie l'égalité avec une autre méthode de paiement
     */
    public function equals(?PaymentMethod $other): bool
    {
        if ($other === null) {
            return false;
        }

        return $this->generateFingerprint() === $other->generateFingerprint();
    }

    /**
     * Sérialise pour stockage/transport
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'provider' => $this->provider,
            'metadata' => $this->metadata,
            'requires_verification' => $this->requiresVerification,
            'supported_currencies' => $this->supportedCurrencies,
            'supported_countries' => $this->supportedCountries,
            'display_name' => $this->getDisplayName(),
            'fingerprint' => $this->generateFingerprint()
        ];
    }

    /**
     * Désérialise depuis un array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? '',
            provider: $data['provider'] ?? '',
            metadata: $data['metadata'] ?? [],
            requiresVerification: $data['requires_verification'] ?? false,
            supportedCurrencies: $data['supported_currencies'] ?? [],
            supportedCountries: $data['supported_countries'] ?? []
        );
    }

    /**
     * Représentation string
     */
    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->getDisplayName(), $this->provider);
    }

    /**
     * Retourne les types de méthodes supportés
     */
    public static function getSupportedTypes(): array
    {
        return [
            self::TYPE_CREDIT_CARD,
            self::TYPE_DEBIT_CARD,
            self::TYPE_BANK_TRANSFER,
            self::TYPE_PAYPAL,
            self::TYPE_STRIPE,
            self::TYPE_APPLE_PAY,
            self::TYPE_GOOGLE_PAY,
            self::TYPE_SEPA,
            self::TYPE_ACH,
            self::TYPE_WIRE,
            self::TYPE_CRYPTO,
            self::TYPE_WALLET,
            self::TYPE_MOLLIE,
            self::TYPE_ADYEN,
            self::TYPE_KLARNA,
            self::TYPE_AFTERPAY
        ];
    }

    /**
     * Valide le type de méthode de paiement
     */
    private function validateType(string $type): void
    {
        if (empty($type)) {
            throw new InvalidArgumentException('Payment method type cannot be empty');
        }

        if (!in_array(strtolower($type), self::getSupportedTypes(), true)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported payment method type: "%s"', $type)
            );
        }
    }

    /**
     * Valide le fournisseur
     */
    private function validateProvider(string $provider): void
    {
        if (empty($provider)) {
            throw new InvalidArgumentException('Payment provider cannot be empty');
        }

        if (strlen($provider) > 50) {
            throw new InvalidArgumentException(
                sprintf('Payment provider too long: "%s"', $provider)
            );
        }
    }

    /**
     * Valide les métadonnées selon le type
     */
    private function validateMetadata(string $type, array $metadata): void
    {
        // Validation spécifique selon le type
        switch (strtolower($type)) {
            case self::TYPE_CREDIT_CARD:
            case self::TYPE_DEBIT_CARD:
                if (isset($metadata['last_four']) && !preg_match('/^\d{4}$/', $metadata['last_four'])) {
                    throw new InvalidArgumentException('Invalid last_four format for card');
                }
                break;

            case self::TYPE_CRYPTO:
                if (isset($metadata['cryptocurrency']) && empty($metadata['cryptocurrency'])) {
                    throw new InvalidArgumentException('Cryptocurrency type is required for crypto payments');
                }
                break;
        }
    }

    /**
     * Masque un IBAN pour l'affichage
     */
    private static function maskIban(string $iban): string
    {
        $cleaned = preg_replace('/\s+/', '', $iban);
        if (strlen($cleaned) < 8) {
            return $iban;
        }

        return substr($cleaned, 0, 4) . str_repeat('*', strlen($cleaned) - 8) . substr($cleaned, -4);
    }

    /**
     * Masque une adresse de wallet crypto
     */
    private static function maskWalletAddress(string $address): string
    {
        if (strlen($address) < 12) {
            return $address;
        }

        return substr($address, 0, 6) . str_repeat('*', max(0, strlen($address) - 12)) . substr($address, -6);
    }
} 