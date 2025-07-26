<?php

declare(strict_types=1);

namespace PaymentApi\Domain\Entity;

use PaymentApi\Domain\ValueObject\TransactionId;
use PaymentApi\Domain\ValueObject\TransactionStatus;
use PaymentApi\Domain\ValueObject\Money;

/**
 * Entité TransactionEvent pour tracer l'historique des transactions
 * 
 * Capture tous les événements qui se produisent pendant le cycle de vie d'une transaction
 * Respecte les principes DDD avec des Value Objects immutables
 */
class TransactionEvent
{
    // Types d'événements
    public const TYPE_CREATED = 'transaction.created';
    public const TYPE_VALIDATED = 'transaction.validated';
    public const TYPE_AUTHORIZED = 'transaction.authorized';
    public const TYPE_CAPTURED = 'transaction.captured';
    public const TYPE_COMPLETED = 'transaction.completed';
    public const TYPE_FAILED = 'transaction.failed';
    public const TYPE_CANCELLED = 'transaction.cancelled';
    public const TYPE_REFUNDED = 'transaction.refunded';
    public const TYPE_PARTIALLY_REFUNDED = 'transaction.partially_refunded';
    public const TYPE_CHARGEBACK = 'transaction.chargeback';
    public const TYPE_FRAUD_DETECTED = 'transaction.fraud_detected';
    public const TYPE_STATUS_CHANGED = 'transaction.status_changed';
    public const TYPE_AMOUNT_CHANGED = 'transaction.amount_changed';
    public const TYPE_METADATA_UPDATED = 'transaction.metadata_updated';
    public const TYPE_WEBHOOK_RECEIVED = 'transaction.webhook_received';
    public const TYPE_MANUAL_REVIEW = 'transaction.manual_review';

    private int $id;
    private TransactionId $transactionId;
    private string $eventType;
    private TransactionStatus $previousStatus;
    private TransactionStatus $newStatus;
    private ?Money $amount;
    private array $eventData;
    private ?string $source;
    private ?string $sourceId;
    private ?string $userAgent;
    private ?string $ipAddress;
    private \DateTimeImmutable $occurredAt;
    private ?\DateTimeImmutable $processedAt;
    private bool $isProcessed;
    private ?string $processingError;
    private int $version;

    public function __construct(
        TransactionId $transactionId,
        string $eventType,
        TransactionStatus $previousStatus,
        TransactionStatus $newStatus,
        ?Money $amount = null,
        array $eventData = [],
        ?string $source = null,
        ?string $sourceId = null,
        ?string $userAgent = null,
        ?string $ipAddress = null
    ) {
        $this->validateEventType($eventType);
        
        $this->transactionId = $transactionId;
        $this->eventType = $eventType;
        $this->previousStatus = $previousStatus;
        $this->newStatus = $newStatus;
        $this->amount = $amount;
        $this->eventData = $eventData;
        $this->source = $source;
        $this->sourceId = $sourceId;
        $this->userAgent = $userAgent;
        $this->ipAddress = $ipAddress;
        $this->occurredAt = new \DateTimeImmutable();
        $this->processedAt = null;
        $this->isProcessed = false;
        $this->processingError = null;
        $this->version = 1;
    }

    /**
     * Crée un événement de création de transaction
     */
    public static function created(
        TransactionId $transactionId,
        TransactionStatus $status,
        Money $amount,
        array $metadata = [],
        ?string $source = null
    ): self {
        return new self(
            transactionId: $transactionId,
            eventType: self::TYPE_CREATED,
            previousStatus: TransactionStatus::pending(),
            newStatus: $status,
            amount: $amount,
            eventData: array_merge($metadata, [
                'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'event_source' => $source ?? 'payment-api'
            ]),
            source: $source
        );
    }

    /**
     * Crée un événement de changement de statut
     */
    public static function statusChanged(
        TransactionId $transactionId,
        TransactionStatus $previousStatus,
        TransactionStatus $newStatus,
        array $changeMetadata = [],
        ?string $source = null,
        ?string $sourceId = null
    ): self {
        return new self(
            transactionId: $transactionId,
            eventType: self::TYPE_STATUS_CHANGED,
            previousStatus: $previousStatus,
            newStatus: $newStatus,
            eventData: array_merge($changeMetadata, [
                'status_change' => [
                    'from' => $previousStatus->getValue(),
                    'to' => $newStatus->getValue(),
                    'reason' => $newStatus->getReason(),
                    'provider_code' => $newStatus->getProviderCode()
                ]
            ]),
            source: $source,
            sourceId: $sourceId
        );
    }

    /**
     * Crée un événement de détection de fraude
     */
    public static function fraudDetected(
        TransactionId $transactionId,
        TransactionStatus $previousStatus,
        array $fraudData = [],
        ?string $source = null
    ): self {
        $fraudStatus = TransactionStatus::fraudDetected(
            'Fraud detection triggered',
            $fraudData
        );

        return new self(
            transactionId: $transactionId,
            eventType: self::TYPE_FRAUD_DETECTED,
            previousStatus: $previousStatus,
            newStatus: $fraudStatus,
            eventData: array_merge($fraudData, [
                'fraud_detection' => [
                    'triggered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'detection_source' => $source ?? 'fraud_engine',
                    'risk_level' => $fraudData['risk_level'] ?? 'high'
                ]
            ]),
            source: $source
        );
    }

    /**
     * Crée un événement de remboursement
     */
    public static function refunded(
        TransactionId $transactionId,
        TransactionStatus $previousStatus,
        Money $refundAmount,
        array $refundData = [],
        ?string $source = null
    ): self {
        $isPartial = isset($refundData['original_amount']) && 
                    !$refundAmount->equals(Money::fromArray($refundData['original_amount']));

        $status = $isPartial 
            ? TransactionStatus::partiallyRefunded('Partial refund processed')
            : TransactionStatus::refunded('Full refund processed');

        return new self(
            transactionId: $transactionId,
            eventType: $isPartial ? self::TYPE_PARTIALLY_REFUNDED : self::TYPE_REFUNDED,
            previousStatus: $previousStatus,
            newStatus: $status,
            amount: $refundAmount,
            eventData: array_merge($refundData, [
                'refund_details' => [
                    'amount' => $refundAmount->toArray(),
                    'is_partial' => $isPartial,
                    'refund_id' => $refundData['refund_id'] ?? null,
                    'reason' => $refundData['reason'] ?? 'Customer request'
                ]
            ]),
            source: $source
        );
    }

    /**
     * Crée un événement de webhook reçu
     */
    public static function webhookReceived(
        TransactionId $transactionId,
        TransactionStatus $currentStatus,
        array $webhookData = [],
        ?string $provider = null
    ): self {
        return new self(
            transactionId: $transactionId,
            eventType: self::TYPE_WEBHOOK_RECEIVED,
            previousStatus: $currentStatus,
            newStatus: $currentStatus, // Statut reste le même
            eventData: array_merge($webhookData, [
                'webhook_info' => [
                    'provider' => $provider,
                    'received_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'event_id' => $webhookData['event_id'] ?? null,
                    'signature_valid' => $webhookData['signature_valid'] ?? false
                ]
            ]),
            source: $provider,
            sourceId: $webhookData['event_id'] ?? null
        );
    }

    // Getters
    public function getId(): ?int
    {
        return $this->id ?? null;
    }

    public function getTransactionId(): TransactionId
    {
        return $this->transactionId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPreviousStatus(): TransactionStatus
    {
        return $this->previousStatus;
    }

    public function getNewStatus(): TransactionStatus
    {
        return $this->newStatus;
    }

    public function getAmount(): ?Money
    {
        return $this->amount;
    }

    public function getEventData(): array
    {
        return $this->eventData;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function isProcessed(): bool
    {
        return $this->isProcessed;
    }

    public function getProcessingError(): ?string
    {
        return $this->processingError;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    // Méthodes métier

    /**
     * Marque l'événement comme traité
     */
    public function markAsProcessed(): void
    {
        if ($this->isProcessed) {
            return;
        }

        $this->isProcessed = true;
        $this->processedAt = new \DateTimeImmutable();
        $this->processingError = null;
        $this->version++;
    }

    /**
     * Marque l'événement comme ayant échoué lors du traitement
     */
    public function markAsProcessingFailed(string $error): void
    {
        $this->isProcessed = false;
        $this->processedAt = null;
        $this->processingError = $error;
        $this->version++;
    }

    /**
     * Ajoute des données supplémentaires à l'événement
     */
    public function addEventData(string $key, mixed $value): void
    {
        $this->eventData[$key] = $value;
        $this->version++;
    }

    /**
     * Vérifie si l'événement représente un changement de statut
     */
    public function isStatusChange(): bool
    {
        return !$this->previousStatus->equals($this->newStatus);
    }

    /**
     * Vérifie si l'événement est critique (nécessite une attention immédiate)
     */
    public function isCritical(): bool
    {
        return in_array($this->eventType, [
            self::TYPE_FRAUD_DETECTED,
            self::TYPE_CHARGEBACK,
            self::TYPE_FAILED
        ], true);
    }

    /**
     * Vérifie si l'événement est lié à un remboursement
     */
    public function isRefundEvent(): bool
    {
        return in_array($this->eventType, [
            self::TYPE_REFUNDED,
            self::TYPE_PARTIALLY_REFUNDED
        ], true);
    }

    /**
     * Vérifie si l'événement provient d'un webhook externe
     */
    public function isExternalEvent(): bool
    {
        return $this->eventType === self::TYPE_WEBHOOK_RECEIVED ||
               ($this->source && $this->source !== 'payment-api');
    }

    /**
     * Calcule le délai depuis l'occurrence
     */
    public function getAgeDuration(): \DateInterval
    {
        $now = new \DateTimeImmutable();
        return $this->occurredAt->diff($now);
    }

    /**
     * Vérifie si l'événement est récent (< 1 heure)
     */
    public function isRecent(): bool
    {
        $hourAgo = new \DateTimeImmutable('-1 hour');
        return $this->occurredAt > $hourAgo;
    }

    /**
     * Retourne une représentation lisible de l'événement
     */
    public function getDescription(): string
    {
        return match($this->eventType) {
            self::TYPE_CREATED => 'Transaction créée',
            self::TYPE_VALIDATED => 'Transaction validée',
            self::TYPE_AUTHORIZED => 'Transaction autorisée',
            self::TYPE_CAPTURED => 'Transaction capturée',
            self::TYPE_COMPLETED => 'Transaction terminée',
            self::TYPE_FAILED => 'Transaction échouée',
            self::TYPE_CANCELLED => 'Transaction annulée',
            self::TYPE_REFUNDED => 'Transaction remboursée',
            self::TYPE_PARTIALLY_REFUNDED => 'Transaction partiellement remboursée',
            self::TYPE_CHARGEBACK => 'Chargeback initié',
            self::TYPE_FRAUD_DETECTED => 'Fraude détectée',
            self::TYPE_STATUS_CHANGED => 'Statut modifié',
            self::TYPE_AMOUNT_CHANGED => 'Montant modifié',
            self::TYPE_METADATA_UPDATED => 'Métadonnées mises à jour',
            self::TYPE_WEBHOOK_RECEIVED => 'Webhook reçu',
            self::TYPE_MANUAL_REVIEW => 'Révision manuelle',
            default => 'Événement inconnu'
        };
    }

    /**
     * Sérialise l'événement pour audit/logging
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'transaction_id' => $this->transactionId->toString(),
            'event_type' => $this->eventType,
            'description' => $this->getDescription(),
            'previous_status' => $this->previousStatus->toArray(),
            'new_status' => $this->newStatus->toArray(),
            'amount' => $this->amount?->toArray(),
            'event_data' => $this->eventData,
            'source' => $this->source,
            'source_id' => $this->sourceId,
            'user_agent' => $this->userAgent,
            'ip_address' => $this->ipAddress,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'processed_at' => $this->processedAt?->format(\DateTimeInterface::ATOM),
            'is_processed' => $this->isProcessed,
            'processing_error' => $this->processingError,
            'version' => $this->version,
            'flags' => [
                'is_status_change' => $this->isStatusChange(),
                'is_critical' => $this->isCritical(),
                'is_refund_event' => $this->isRefundEvent(),
                'is_external_event' => $this->isExternalEvent(),
                'is_recent' => $this->isRecent()
            ]
        ];
    }

    /**
     * Retourne tous les types d'événements supportés
     */
    public static function getAllEventTypes(): array
    {
        return [
            self::TYPE_CREATED,
            self::TYPE_VALIDATED,
            self::TYPE_AUTHORIZED,
            self::TYPE_CAPTURED,
            self::TYPE_COMPLETED,
            self::TYPE_FAILED,
            self::TYPE_CANCELLED,
            self::TYPE_REFUNDED,
            self::TYPE_PARTIALLY_REFUNDED,
            self::TYPE_CHARGEBACK,
            self::TYPE_FRAUD_DETECTED,
            self::TYPE_STATUS_CHANGED,
            self::TYPE_AMOUNT_CHANGED,
            self::TYPE_METADATA_UPDATED,
            self::TYPE_WEBHOOK_RECEIVED,
            self::TYPE_MANUAL_REVIEW
        ];
    }

    /**
     * Valide le type d'événement
     */
    private function validateEventType(string $eventType): void
    {
        if (empty($eventType)) {
            throw new \InvalidArgumentException('Event type cannot be empty');
        }

        if (!in_array($eventType, self::getAllEventTypes(), true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid event type: "%s"', $eventType)
            );
        }
    }
} 