<?php

declare(strict_types=1);

namespace PaymentApi\Application\DTO;

use PaymentApi\Domain\ValueObject\Money;
use PaymentApi\Domain\ValueObject\PaymentMethod;

/**
 * DTO pour les requêtes de paiement
 * 
 * Transfère les données de paiement depuis la couche présentation
 * vers la couche application de manière type-safe
 */
final class PaymentRequest
{
    public function __construct(
        private readonly Money $amount,
        private readonly PaymentMethod $paymentMethod,
        private readonly string $customerId,
        private readonly ?string $customerEmail = null,
        private readonly ?string $description = null,
        private readonly array $metadata = [],
        private readonly ?string $ipAddress = null,
        private readonly ?string $userAgent = null,
        private readonly ?string $countryCode = null,
        private readonly bool $captureImmediately = true,
        private readonly ?string $webhookUrl = null,
        private readonly ?string $returnUrl = null,
        private readonly ?string $cancelUrl = null,
        private readonly array $billingAddress = [],
        private readonly array $shippingAddress = [],
        private readonly ?string $statementDescriptor = null,
        private readonly array $fraudPreventionData = []
    ) {
    }

    /**
     * Crée une requête depuis un array de données
     */
    public static function fromArray(array $data): self
    {
        // Validation des champs requis
        if (!isset($data['amount'], $data['currency'], $data['customer_id'], $data['payment_method'])) {
            throw new \InvalidArgumentException('Missing required payment data');
        }

        // Création du montant
        $amount = Money::fromAmount($data['amount'], $data['currency']);

        // Création de la méthode de paiement
        $paymentMethod = self::createPaymentMethodFromData($data['payment_method']);

        return new self(
            amount: $amount,
            paymentMethod: $paymentMethod,
            customerId: $data['customer_id'],
            customerEmail: $data['customer_email'] ?? null,
            description: $data['description'] ?? null,
            metadata: $data['metadata'] ?? [],
            ipAddress: $data['ip_address'] ?? null,
            userAgent: $data['user_agent'] ?? null,
            countryCode: $data['country_code'] ?? null,
            captureImmediately: $data['capture_immediately'] ?? true,
            webhookUrl: $data['webhook_url'] ?? null,
            returnUrl: $data['return_url'] ?? null,
            cancelUrl: $data['cancel_url'] ?? null,
            billingAddress: $data['billing_address'] ?? [],
            shippingAddress: $data['shipping_address'] ?? [],
            statementDescriptor: $data['statement_descriptor'] ?? null,
            fraudPreventionData: $data['fraud_prevention'] ?? []
        );
    }

    // Getters
    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customerEmail;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function shouldCaptureImmediately(): bool
    {
        return $this->captureImmediately;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function getReturnUrl(): ?string
    {
        return $this->returnUrl;
    }

    public function getCancelUrl(): ?string
    {
        return $this->cancelUrl;
    }

    public function getBillingAddress(): array
    {
        return $this->billingAddress;
    }

    public function getShippingAddress(): array
    {
        return $this->shippingAddress;
    }

    public function getStatementDescriptor(): ?string
    {
        return $this->statementDescriptor;
    }

    public function getFraudPreventionData(): array
    {
        return $this->fraudPreventionData;
    }

    /**
     * Vérifie si la requête contient une adresse de facturation
     */
    public function hasBillingAddress(): bool
    {
        return !empty($this->billingAddress);
    }

    /**
     * Vérifie si la requête contient une adresse de livraison
     */
    public function hasShippingAddress(): bool
    {
        return !empty($this->shippingAddress);
    }

    /**
     * Vérifie si des données de prévention de fraude sont fournies
     */
    public function hasFraudPreventionData(): bool
    {
        return !empty($this->fraudPreventionData);
    }

    /**
     * Retourne un identifiant unique pour cette requête
     */
    public function generateRequestId(): string
    {
        $data = [
            'customer_id' => $this->customerId,
            'amount' => $this->amount->toArray(),
            'payment_method' => $this->paymentMethod->generateFingerprint(),
            'timestamp' => time()
        ];

        return hash('sha256', json_encode($data, JSON_SORT_KEYS));
    }

    /**
     * Sérialise la requête pour logging/audit
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount->toArray(),
            'payment_method' => $this->paymentMethod->toArray(),
            'customer_id' => $this->customerId,
            'customer_email' => $this->customerEmail,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'country_code' => $this->countryCode,
            'capture_immediately' => $this->captureImmediately,
            'webhook_url' => $this->webhookUrl,
            'return_url' => $this->returnUrl,
            'cancel_url' => $this->cancelUrl,
            'billing_address' => $this->billingAddress,
            'shipping_address' => $this->shippingAddress,
            'statement_descriptor' => $this->statementDescriptor,
            'fraud_prevention' => $this->fraudPreventionData,
            'request_id' => $this->generateRequestId()
        ];
    }

    /**
     * Sérialise pour logging sans données sensibles
     */
    public function toSecureArray(): array
    {
        $data = $this->toArray();
        
        // Masque les données sensibles
        if (isset($data['payment_method']['metadata'])) {
            $data['payment_method']['metadata'] = $this->maskSensitiveData($data['payment_method']['metadata']);
        }

        // Masque l'email partiellement
        if ($data['customer_email']) {
            $data['customer_email'] = $this->maskEmail($data['customer_email']);
        }

        // Masque les adresses
        $data['billing_address'] = $this->maskAddress($data['billing_address']);
        $data['shipping_address'] = $this->maskAddress($data['shipping_address']);

        return $data;
    }

    /**
     * Crée une méthode de paiement depuis les données
     */
    private static function createPaymentMethodFromData(array $paymentMethodData): PaymentMethod
    {
        $type = $paymentMethodData['type'] ?? '';
        $provider = $paymentMethodData['provider'] ?? '';
        $metadata = $paymentMethodData['metadata'] ?? [];

        return match($type) {
            PaymentMethod::TYPE_CREDIT_CARD, PaymentMethod::TYPE_DEBIT_CARD => 
                self::createCardPaymentMethod($type, $provider, $metadata),
            PaymentMethod::TYPE_PAYPAL => 
                PaymentMethod::paypal($metadata['email'] ?? null),
            PaymentMethod::TYPE_APPLE_PAY => 
                PaymentMethod::applePay($metadata['device_id'] ?? null),
            PaymentMethod::TYPE_GOOGLE_PAY => 
                PaymentMethod::googlePay($metadata['account_id'] ?? null),
            PaymentMethod::TYPE_BANK_TRANSFER => 
                PaymentMethod::bankTransfer($provider, $metadata['bank_name'] ?? null, $metadata['account_mask'] ?? null),
            PaymentMethod::TYPE_SEPA => 
                PaymentMethod::sepa($metadata['iban'] ?? null),
            PaymentMethod::TYPE_CRYPTO => 
                PaymentMethod::crypto($metadata['cryptocurrency'] ?? 'BTC', $metadata['wallet_address'] ?? null),
            default => 
                PaymentMethod::fromArray($paymentMethodData)
        };
    }

    /**
     * Crée une méthode de paiement par carte
     */
    private static function createCardPaymentMethod(string $type, string $provider, array $metadata): PaymentMethod
    {
        if ($type === PaymentMethod::TYPE_CREDIT_CARD) {
            return PaymentMethod::creditCard(
                provider: $provider,
                cardBrand: $metadata['card_brand'] ?? null,
                lastFourDigits: $metadata['last_four'] ?? null,
                expiryMonth: $metadata['expiry_month'] ?? null,
                expiryYear: $metadata['expiry_year'] ?? null
            );
        }

        return PaymentMethod::debitCard(
            provider: $provider,
            cardBrand: $metadata['card_brand'] ?? null,
            lastFourDigits: $metadata['last_four'] ?? null
        );
    }

    /**
     * Masque les données sensibles
     */
    private function maskSensitiveData(array $data): array
    {
        $masked = $data;
        
        $sensitiveKeys = ['card_number', 'cvv', 'expiry_date', 'iban', 'wallet_address'];
        
        foreach ($sensitiveKeys as $key) {
            if (isset($masked[$key])) {
                $masked[$key] = $this->maskString($masked[$key]);
            }
        }

        return $masked;
    }

    /**
     * Masque une chaîne
     */
    private function maskString(string $value): string
    {
        if (strlen($value) <= 4) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 2) . str_repeat('*', strlen($value) - 4) . substr($value, -2);
    }

    /**
     * Masque une adresse email
     */
    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $this->maskString($email);
        }

        [$local, $domain] = explode('@', $email, 2);
        
        if (strlen($local) <= 2) {
            return str_repeat('*', strlen($local)) . '@' . $domain;
        }

        return substr($local, 0, 1) . str_repeat('*', strlen($local) - 2) . substr($local, -1) . '@' . $domain;
    }

    /**
     * Masque une adresse
     */
    private function maskAddress(array $address): array
    {
        if (empty($address)) {
            return $address;
        }

        $masked = $address;
        
        $sensitiveFields = ['street', 'address_line_1', 'address_line_2'];
        
        foreach ($sensitiveFields as $field) {
            if (isset($masked[$field])) {
                $masked[$field] = $this->maskString($masked[$field]);
            }
        }

        return $masked;
    }
} 