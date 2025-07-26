<?php

declare(strict_types=1);

namespace PaymentApi\Application\DTO;

use PaymentApi\Domain\Entity\Transaction;
use PaymentApi\Domain\ValueObject\FraudScore;

/**
 * DTO pour les réponses de paiement
 * 
 * Transfère les résultats de traitement de paiement depuis la couche application
 * vers la couche présentation de manière type-safe
 */
final class PaymentResponse
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_FRAUD_DETECTED = 'fraud_detected';
    public const STATUS_REQUIRES_REVIEW = 'requires_review';
    public const STATUS_REQUIRES_ACTION = 'requires_action';

    public function __construct(
        private readonly string $status,
        private readonly ?string $transactionId = null,
        private readonly ?string $message = null,
        private readonly ?int $errorCode = null,
        private readonly array $transactionData = [],
        private readonly ?FraudScore $fraudScore = null,
        private readonly array $nextActions = [],
        private readonly array $paymentLinks = [],
        private readonly array $metadata = []
    ) {
    }

    /**
     * Crée une réponse de succès
     */
    public static function success(Transaction $transaction): self
    {
        return new self(
            status: self::STATUS_SUCCESS,
            transactionId: $transaction->getId()->toString(),
            message: 'Payment processed successfully',
            transactionData: $transaction->toArray(),
            metadata: [
                'processed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'status' => $transaction->getStatus()->getValue(),
                'amount' => $transaction->getAmount()->toArray(),
                'payment_method' => $transaction->getPaymentMethod()->getType()
            ]
        );
    }

    /**
     * Crée une réponse pour un paiement en attente
     */
    public static function pending(
        Transaction $transaction,
        array $nextActions = [],
        ?string $message = null
    ): self {
        return new self(
            status: self::STATUS_PENDING,
            transactionId: $transaction->getId()->toString(),
            message: $message ?? 'Payment is being processed',
            transactionData: $transaction->toArray(),
            nextActions: $nextActions,
            metadata: [
                'processed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'requires_action' => !empty($nextActions),
                'status' => $transaction->getStatus()->getValue()
            ]
        );
    }

    /**
     * Crée une réponse d'échec
     */
    public static function failed(string $message, int $errorCode = 400): self
    {
        return new self(
            status: self::STATUS_FAILED,
            message: $message,
            errorCode: $errorCode,
            metadata: [
                'failed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'error_code' => $errorCode
            ]
        );
    }

    /**
     * Crée une réponse pour fraude détectée
     */
    public static function fraudDetected(string $message, ?FraudScore $fraudScore = null): self
    {
        return new self(
            status: self::STATUS_FRAUD_DETECTED,
            message: $message,
            errorCode: 403,
            fraudScore: $fraudScore,
            metadata: [
                'fraud_detected_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'fraud_score' => $fraudScore?->toArray(),
                'risk_level' => $fraudScore?->getRiskLevel()
            ]
        );
    }

    /**
     * Crée une réponse nécessitant une révision manuelle
     */
    public static function requiresReview(
        Transaction $transaction,
        FraudScore $fraudScore,
        string $reason
    ): self {
        return new self(
            status: self::STATUS_REQUIRES_REVIEW,
            transactionId: $transaction->getId()->toString(),
            message: $reason,
            transactionData: $transaction->toArray(),
            fraudScore: $fraudScore,
            nextActions: [
                [
                    'type' => 'manual_review',
                    'description' => 'Transaction requires manual review',
                    'estimated_time' => '1-24 hours'
                ]
            ],
            metadata: [
                'review_required_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'fraud_score' => $fraudScore->toArray(),
                'review_reason' => $reason
            ]
        );
    }

    /**
     * Crée une réponse nécessitant une action utilisateur
     */
    public static function requiresAction(
        Transaction $transaction,
        array $nextActions,
        string $message = 'Additional action required'
    ): self {
        return new self(
            status: self::STATUS_REQUIRES_ACTION,
            transactionId: $transaction->getId()->toString(),
            message: $message,
            transactionData: $transaction->toArray(),
            nextActions: $nextActions,
            metadata: [
                'action_required_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'actions_count' => count($nextActions)
            ]
        );
    }

    // Getters
    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }

    public function getTransactionData(): array
    {
        return $this->transactionData;
    }

    public function getFraudScore(): ?FraudScore
    {
        return $this->fraudScore;
    }

    public function getNextActions(): array
    {
        return $this->nextActions;
    }

    public function getPaymentLinks(): array
    {
        return $this->paymentLinks;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    // Méthodes de vérification

    /**
     * Vérifie si le paiement a réussi
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Vérifie si le paiement est en attente
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Vérifie si le paiement a échoué
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Vérifie si une fraude a été détectée
     */
    public function isFraudDetected(): bool
    {
        return $this->status === self::STATUS_FRAUD_DETECTED;
    }

    /**
     * Vérifie si une révision manuelle est requise
     */
    public function requiresManualReview(): bool
    {
        return $this->status === self::STATUS_REQUIRES_REVIEW;
    }

    /**
     * Vérifie si une action utilisateur est requise
     */
    public function requiresUserAction(): bool
    {
        return $this->status === self::STATUS_REQUIRES_ACTION;
    }

    /**
     * Vérifie si la réponse contient des données de transaction
     */
    public function hasTransactionData(): bool
    {
        return !empty($this->transactionData);
    }

    /**
     * Vérifie si la réponse contient des actions suivantes
     */
    public function hasNextActions(): bool
    {
        return !empty($this->nextActions);
    }

    /**
     * Retourne le code de statut HTTP approprié
     */
    public function getHttpStatusCode(): int
    {
        return match($this->status) {
            self::STATUS_SUCCESS => 200,
            self::STATUS_PENDING => 202,
            self::STATUS_REQUIRES_ACTION => 202,
            self::STATUS_REQUIRES_REVIEW => 202,
            self::STATUS_FRAUD_DETECTED => 403,
            self::STATUS_FAILED => $this->errorCode ?? 400,
            default => 500
        };
    }

    /**
     * Ajoute des liens de paiement (pour redirections, webhooks, etc.)
     */
    public function withPaymentLinks(array $links): self
    {
        return new self(
            status: $this->status,
            transactionId: $this->transactionId,
            message: $this->message,
            errorCode: $this->errorCode,
            transactionData: $this->transactionData,
            fraudScore: $this->fraudScore,
            nextActions: $this->nextActions,
            paymentLinks: array_merge($this->paymentLinks, $links),
            metadata: $this->metadata
        );
    }

    /**
     * Ajoute des métadonnées supplémentaires
     */
    public function withMetadata(array $additionalMetadata): self
    {
        return new self(
            status: $this->status,
            transactionId: $this->transactionId,
            message: $this->message,
            errorCode: $this->errorCode,
            transactionData: $this->transactionData,
            fraudScore: $this->fraudScore,
            nextActions: $this->nextActions,
            paymentLinks: $this->paymentLinks,
            metadata: array_merge($this->metadata, $additionalMetadata)
        );
    }

    /**
     * Sérialise la réponse pour l'API
     */
    public function toArray(): array
    {
        $response = [
            'status' => $this->status,
            'message' => $this->message,
            'transaction_id' => $this->transactionId,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        ];

        if ($this->errorCode !== null) {
            $response['error_code'] = $this->errorCode;
        }

        if (!empty($this->transactionData)) {
            $response['transaction'] = $this->transactionData;
        }

        if ($this->fraudScore !== null) {
            $response['fraud_score'] = $this->fraudScore->toArray();
        }

        if (!empty($this->nextActions)) {
            $response['next_actions'] = $this->nextActions;
        }

        if (!empty($this->paymentLinks)) {
            $response['payment_links'] = $this->paymentLinks;
        }

        if (!empty($this->metadata)) {
            $response['metadata'] = $this->metadata;
        }

        return $response;
    }

    /**
     * Sérialise pour JSON avec gestion d'erreur
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Créé une version sécurisée sans données sensibles
     */
    public function toSecureArray(): array
    {
        $data = $this->toArray();

        // Masque les données sensibles dans les données de transaction
        if (isset($data['transaction'])) {
            $data['transaction'] = $this->maskSensitiveTransactionData($data['transaction']);
        }

        // Masque les données sensibles dans les métadonnées
        if (isset($data['metadata'])) {
            $data['metadata'] = $this->maskSensitiveMetadata($data['metadata']);
        }

        return $data;
    }

    /**
     * Retourne un résumé concis de la réponse
     */
    public function getSummary(): array
    {
        return [
            'status' => $this->status,
            'transaction_id' => $this->transactionId,
            'success' => $this->isSuccessful(),
            'requires_action' => $this->requiresUserAction(),
            'fraud_detected' => $this->isFraudDetected(),
            'http_status' => $this->getHttpStatusCode()
        ];
    }

    /**
     * Masque les données sensibles de la transaction
     */
    private function maskSensitiveTransactionData(array $transactionData): array
    {
        $masked = $transactionData;

        // Masque les données de méthode de paiement
        if (isset($masked['payment_method']['metadata'])) {
            $sensitiveKeys = ['card_number', 'cvv', 'expiry_date', 'iban'];
            foreach ($sensitiveKeys as $key) {
                if (isset($masked['payment_method']['metadata'][$key])) {
                    $masked['payment_method']['metadata'][$key] = '***MASKED***';
                }
            }
        }

        // Masque l'email client
        if (isset($masked['customer_email'])) {
            $masked['customer_email'] = $this->maskEmail($masked['customer_email']);
        }

        return $masked;
    }

    /**
     * Masque les données sensibles des métadonnées
     */
    private function maskSensitiveMetadata(array $metadata): array
    {
        $masked = $metadata;

        $sensitiveKeys = ['ip_address', 'user_agent', 'billing_address', 'shipping_address'];
        
        foreach ($sensitiveKeys as $key) {
            if (isset($masked[$key])) {
                $masked[$key] = '***MASKED***';
            }
        }

        return $masked;
    }

    /**
     * Masque une adresse email
     */
    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '***MASKED***';
        }

        [$local, $domain] = explode('@', $email, 2);
        
        if (strlen($local) <= 2) {
            return str_repeat('*', strlen($local)) . '@' . $domain;
        }

        return substr($local, 0, 1) . str_repeat('*', strlen($local) - 2) . substr($local, -1) . '@' . $domain;
    }
} 