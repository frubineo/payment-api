<?php

declare(strict_types=1);

namespace PaymentApi\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Value Object pour le statut des transactions
 * 
 * Représente de manière immutable l'état d'une transaction de paiement
 * Inclut les transitions d'état autorisées et la logique métier
 */
final class TransactionStatus
{
    // États principaux
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const AUTHORIZED = 'authorized';
    public const CAPTURED = 'captured';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    public const CHARGEBACK = 'chargeback';
    public const DISPUTED = 'disputed';

    // États d'attente
    public const AWAITING_CAPTURE = 'awaiting_capture';
    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const AWAITING_FUNDS = 'awaiting_funds';

    // États d'erreur
    public const DECLINED = 'declined';
    public const EXPIRED = 'expired';
    public const FRAUD_DETECTED = 'fraud_detected';
    public const INSUFFICIENT_FUNDS = 'insufficient_funds';
    public const INVALID_CARD = 'invalid_card';
    public const BLOCKED = 'blocked';

    private readonly string $value;
    private readonly \DateTimeImmutable $timestamp;
    private readonly ?string $reason;
    private readonly ?string $providerCode;
    private readonly array $metadata;

    public function __construct(
        string $value,
        ?\DateTimeImmutable $timestamp = null,
        ?string $reason = null,
        ?string $providerCode = null,
        array $metadata = []
    ) {
        $this->validateStatus($value);
        
        $this->value = strtolower($value);
        $this->timestamp = $timestamp ?? new \DateTimeImmutable();
        $this->reason = $reason;
        $this->providerCode = $providerCode;
        $this->metadata = $metadata;
    }

    /**
     * Crée un statut en attente
     */
    public static function pending(?string $reason = null): self
    {
        return new self(
            value: self::PENDING,
            reason: $reason ?? 'Transaction initiated and awaiting processing'
        );
    }

    /**
     * Crée un statut en cours de traitement
     */
    public static function processing(?string $reason = null): self
    {
        return new self(
            value: self::PROCESSING,
            reason: $reason ?? 'Transaction is being processed by payment provider'
        );
    }

    /**
     * Crée un statut autorisé
     */
    public static function authorized(?string $reason = null, ?string $providerCode = null): self
    {
        return new self(
            value: self::AUTHORIZED,
            reason: $reason ?? 'Payment authorized, awaiting capture',
            providerCode: $providerCode
        );
    }

    /**
     * Crée un statut capturé
     */
    public static function captured(?string $reason = null, ?string $providerCode = null): self
    {
        return new self(
            value: self::CAPTURED,
            reason: $reason ?? 'Payment captured successfully',
            providerCode: $providerCode
        );
    }

    /**
     * Crée un statut complété
     */
    public static function completed(?string $reason = null): self
    {
        return new self(
            value: self::COMPLETED,
            reason: $reason ?? 'Transaction completed successfully'
        );
    }

    /**
     * Crée un statut échoué
     */
    public static function failed(string $reason, ?string $providerCode = null): self
    {
        return new self(
            value: self::FAILED,
            reason: $reason,
            providerCode: $providerCode
        );
    }

    /**
     * Crée un statut annulé
     */
    public static function cancelled(string $reason): self
    {
        return new self(
            value: self::CANCELLED,
            reason: $reason
        );
    }

    /**
     * Crée un statut refundé
     */
    public static function refunded(?string $reason = null): self
    {
        return new self(
            value: self::REFUNDED,
            reason: $reason ?? 'Transaction refunded successfully'
        );
    }

    /**
     * Crée un statut partiellement refundé
     */
    public static function partiallyRefunded(string $reason): self
    {
        return new self(
            value: self::PARTIALLY_REFUNDED,
            reason: $reason
        );
    }

    /**
     * Crée un statut refus de la banque
     */
    public static function declined(string $reason, ?string $providerCode = null): self
    {
        return new self(
            value: self::DECLINED,
            reason: $reason,
            providerCode: $providerCode
        );
    }

    /**
     * Crée un statut fraude détectée
     */
    public static function fraudDetected(string $reason, array $fraudMetadata = []): self
    {
        return new self(
            value: self::FRAUD_DETECTED,
            reason: $reason,
            metadata: array_merge(['fraud_detection' => true], $fraudMetadata)
        );
    }

    /**
     * Crée un statut chargeback
     */
    public static function chargeback(string $reason, ?string $providerCode = null): self
    {
        return new self(
            value: self::CHARGEBACK,
            reason: $reason,
            providerCode: $providerCode
        );
    }

    /**
     * Retourne la valeur du statut
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Retourne le timestamp du statut
     */
    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    /**
     * Retourne la raison du statut
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Retourne le code du fournisseur
     */
    public function getProviderCode(): ?string
    {
        return $this->providerCode;
    }

    /**
     * Retourne les métadonnées
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Vérifie si la transaction est en attente
     */
    public function isPending(): bool
    {
        return in_array($this->value, [
            self::PENDING,
            self::AWAITING_CAPTURE,
            self::AWAITING_CONFIRMATION,
            self::AWAITING_FUNDS
        ], true);
    }

    /**
     * Vérifie si la transaction est en cours de traitement
     */
    public function isProcessing(): bool
    {
        return $this->value === self::PROCESSING;
    }

    /**
     * Vérifie si la transaction est réussie
     */
    public function isSuccessful(): bool
    {
        return in_array($this->value, [
            self::AUTHORIZED,
            self::CAPTURED,
            self::COMPLETED
        ], true);
    }

    /**
     * Vérifie si la transaction a échoué
     */
    public function isFailed(): bool
    {
        return in_array($this->value, [
            self::FAILED,
            self::DECLINED,
            self::EXPIRED,
            self::FRAUD_DETECTED,
            self::INSUFFICIENT_FUNDS,
            self::INVALID_CARD,
            self::BLOCKED
        ], true);
    }

    /**
     * Vérifie si la transaction est finalisée (ne peut plus changer)
     */
    public function isFinal(): bool
    {
        return in_array($this->value, [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::REFUNDED,
            self::CHARGEBACK,
            self::EXPIRED,
            self::FRAUD_DETECTED
        ], true);
    }

    /**
     * Vérifie si la transaction est refundée
     */
    public function isRefunded(): bool
    {
        return in_array($this->value, [
            self::REFUNDED,
            self::PARTIALLY_REFUNDED
        ], true);
    }

    /**
     * Vérifie si la transaction peut être capturée
     */
    public function canBeCapture(): bool
    {
        return in_array($this->value, [
            self::AUTHORIZED,
            self::AWAITING_CAPTURE
        ], true);
    }

    /**
     * Vérifie si la transaction peut être annulée
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->value, [
            self::PENDING,
            self::PROCESSING,
            self::AUTHORIZED,
            self::AWAITING_CAPTURE,
            self::AWAITING_CONFIRMATION
        ], true);
    }

    /**
     * Vérifie si la transaction peut être refundée
     */
    public function canBeRefunded(): bool
    {
        return in_array($this->value, [
            self::CAPTURED,
            self::COMPLETED,
            self::PARTIALLY_REFUNDED
        ], true);
    }

    /**
     * Vérifie si une transition vers un nouveau statut est autorisée
     */
    public function canTransitionTo(TransactionStatus $newStatus): bool
    {
        $allowedTransitions = $this->getAllowedTransitions();
        
        return in_array($newStatus->getValue(), $allowedTransitions, true);
    }

    /**
     * Retourne les transitions autorisées depuis ce statut
     */
    public function getAllowedTransitions(): array
    {
        return match($this->value) {
            self::PENDING => [
                self::PROCESSING,
                self::AUTHORIZED,
                self::CAPTURED,
                self::FAILED,
                self::CANCELLED,
                self::DECLINED,
                self::FRAUD_DETECTED
            ],
            self::PROCESSING => [
                self::AUTHORIZED,
                self::CAPTURED,
                self::COMPLETED,
                self::FAILED,
                self::DECLINED,
                self::FRAUD_DETECTED,
                self::AWAITING_CAPTURE,
                self::AWAITING_CONFIRMATION
            ],
            self::AUTHORIZED => [
                self::CAPTURED,
                self::CANCELLED,
                self::EXPIRED,
                self::FAILED
            ],
            self::CAPTURED => [
                self::COMPLETED,
                self::REFUNDED,
                self::PARTIALLY_REFUNDED,
                self::CHARGEBACK,
                self::DISPUTED
            ],
            self::COMPLETED => [
                self::REFUNDED,
                self::PARTIALLY_REFUNDED,
                self::CHARGEBACK,
                self::DISPUTED
            ],
            self::PARTIALLY_REFUNDED => [
                self::REFUNDED,
                self::CHARGEBACK,
                self::DISPUTED
            ],
            self::AWAITING_CAPTURE => [
                self::CAPTURED,
                self::CANCELLED,
                self::EXPIRED,
                self::FAILED
            ],
            self::AWAITING_CONFIRMATION => [
                self::AUTHORIZED,
                self::CAPTURED,
                self::COMPLETED,
                self::FAILED,
                self::CANCELLED
            ],
            self::AWAITING_FUNDS => [
                self::COMPLETED,
                self::FAILED,
                self::CANCELLED
            ],
            default => [] // États finaux ne permettent pas de transition
        };
    }

    /**
     * Retourne une représentation lisible du statut
     */
    public function getDisplayName(): string
    {
        return match($this->value) {
            self::PENDING => 'En attente',
            self::PROCESSING => 'En cours de traitement',
            self::AUTHORIZED => 'Autorisé',
            self::CAPTURED => 'Capturé',
            self::COMPLETED => 'Terminé',
            self::FAILED => 'Échoué',
            self::CANCELLED => 'Annulé',
            self::REFUNDED => 'Remboursé',
            self::PARTIALLY_REFUNDED => 'Partiellement remboursé',
            self::CHARGEBACK => 'Chargeback',
            self::DISPUTED => 'Contesté',
            self::AWAITING_CAPTURE => 'En attente de capture',
            self::AWAITING_CONFIRMATION => 'En attente de confirmation',
            self::AWAITING_FUNDS => 'En attente de fonds',
            self::DECLINED => 'Refusé',
            self::EXPIRED => 'Expiré',
            self::FRAUD_DETECTED => 'Fraude détectée',
            self::INSUFFICIENT_FUNDS => 'Fonds insuffisants',
            self::INVALID_CARD => 'Carte invalide',
            self::BLOCKED => 'Bloqué',
            default => ucfirst(str_replace('_', ' ', $this->value))
        };
    }

    /**
     * Retourne la priorité du statut pour tri
     */
    public function getPriority(): int
    {
        return match($this->value) {
            self::FRAUD_DETECTED => 100,
            self::CHARGEBACK => 90,
            self::DISPUTED => 85,
            self::FAILED, self::DECLINED => 80,
            self::CANCELLED => 70,
            self::REFUNDED => 60,
            self::PARTIALLY_REFUNDED => 55,
            self::COMPLETED => 50,
            self::CAPTURED => 40,
            self::AUTHORIZED => 30,
            self::PROCESSING => 20,
            self::PENDING => 10,
            default => 0
        };
    }

    /**
     * Vérifie l'égalité avec un autre statut
     */
    public function equals(?TransactionStatus $other): bool
    {
        if ($other === null) {
            return false;
        }

        return $this->value === $other->value;
    }

    /**
     * Sérialise pour stockage/transport
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
            'reason' => $this->reason,
            'provider_code' => $this->providerCode,
            'metadata' => $this->metadata,
            'display_name' => $this->getDisplayName(),
            'priority' => $this->getPriority(),
            'is_final' => $this->isFinal(),
            'is_successful' => $this->isSuccessful(),
            'allowed_transitions' => $this->getAllowedTransitions()
        ];
    }

    /**
     * Désérialise depuis un array
     */
    public static function fromArray(array $data): self
    {
        $timestamp = isset($data['timestamp']) 
            ? new \DateTimeImmutable($data['timestamp'])
            : null;

        return new self(
            value: $data['value'] ?? '',
            timestamp: $timestamp,
            reason: $data['reason'] ?? null,
            providerCode: $data['provider_code'] ?? null,
            metadata: $data['metadata'] ?? []
        );
    }

    /**
     * Représentation string
     */
    public function __toString(): string
    {
        return $this->getDisplayName();
    }

    /**
     * Retourne tous les statuts supportés
     */
    public static function getAllStatuses(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::AUTHORIZED,
            self::CAPTURED,
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::REFUNDED,
            self::PARTIALLY_REFUNDED,
            self::CHARGEBACK,
            self::DISPUTED,
            self::AWAITING_CAPTURE,
            self::AWAITING_CONFIRMATION,
            self::AWAITING_FUNDS,
            self::DECLINED,
            self::EXPIRED,
            self::FRAUD_DETECTED,
            self::INSUFFICIENT_FUNDS,
            self::INVALID_CARD,
            self::BLOCKED
        ];
    }

    /**
     * Valide un statut
     */
    private function validateStatus(string $value): void
    {
        if (empty($value)) {
            throw new InvalidArgumentException('Transaction status cannot be empty');
        }

        if (!in_array(strtolower($value), self::getAllStatuses(), true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid transaction status: "%s"', $value)
            );
        }
    }
} 