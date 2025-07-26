<?php

declare(strict_types=1);

namespace PaymentApi\Domain\Entity;

use PaymentApi\Domain\ValueObject\TransactionId;
use PaymentApi\Domain\ValueObject\Money;
use PaymentApi\Domain\ValueObject\PaymentMethod;
use PaymentApi\Domain\ValueObject\TransactionStatus;
use PaymentApi\Domain\ValueObject\FraudScore;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entité Transaction centrale
 * 
 * Hub pour tous les paiements de l'écosystème
 */
#[ORM\Entity]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(columns: ['user_id'], name: 'idx_transaction_user')]
#[ORM\Index(columns: ['external_id'], name: 'idx_transaction_external')]
#[ORM\Index(columns: ['status'], name: 'idx_transaction_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_transaction_created')]
#[ORM\Index(columns: ['provider'], name: 'idx_transaction_provider')]
class Transaction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private TransactionId $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $userId;

    #[ORM\Embedded(class: Money::class)]
    private Money $amount;

    #[ORM\Embedded(class: PaymentMethod::class)]
    private PaymentMethod $paymentMethod;

    #[ORM\Embedded(class: TransactionStatus::class)]
    private TransactionStatus $status;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $provider; // stripe, paypal, apple_pay, etc.

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $externalId = null; // ID chez le provider

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $externalReference = null; // Référence client

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $settledAt = null;

    #[ORM\Column(type: 'string', length: 45)]
    private string $ipAddress;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Embedded(class: FraudScore::class)]
    private FraudScore $fraudScore;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $providerData = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextRetryAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fees = null; // Frais par provider

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $parentTransactionId = null; // Pour refunds

    #[ORM\Column(type: 'string', length: 50)]
    private string $type = 'payment'; // payment, refund, chargeback

    #[ORM\OneToMany(mappedBy: 'transaction', targetEntity: TransactionEvent::class, cascade: ['persist'])]
    private Collection $events;

    #[ORM\OneToMany(mappedBy: 'parentTransaction', targetEntity: Transaction::class)]
    private Collection $childTransactions;

    public function __construct(
        TransactionId $id,
        string $userId,
        Money $amount,
        PaymentMethod $paymentMethod,
        string $provider,
        string $ipAddress,
        ?string $description = null,
        ?string $externalReference = null,
        ?string $userAgent = null,
        ?array $metadata = null
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->amount = $amount;
        $this->paymentMethod = $paymentMethod;
        $this->status = TransactionStatus::pending();
        $this->provider = $provider;
        $this->description = $description;
        $this->externalReference = $externalReference;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
        $this->fraudScore = FraudScore::unknown();
        $this->events = new ArrayCollection();
        $this->childTransactions = new ArrayCollection();
    }

    // Getters
    public function getId(): TransactionId
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getStatus(): TransactionStatus
    {
        return $this->status;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getFraudScore(): FraudScore
    {
        return $this->fraudScore;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return Collection<int, TransactionEvent>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    // Business Methods
    public function process(string $externalId, ?array $providerData = null): void
    {
        if (!$this->status->isPending()) {
            throw new \DomainException('Cannot process non-pending transaction');
        }

        $this->status = TransactionStatus::processing();
        $this->externalId = $externalId;
        $this->providerData = $providerData;
        $this->processedAt = new \DateTimeImmutable();
        
        $this->addEvent('transaction.processing', [
            'external_id' => $externalId,
            'provider' => $this->provider
        ]);
    }

    public function succeed(?array $providerData = null): void
    {
        if (!$this->status->canSucceed()) {
            throw new \DomainException('Cannot succeed transaction in current status');
        }

        $this->status = TransactionStatus::succeeded();
        if ($providerData) {
            $this->providerData = array_merge($this->providerData ?? [], $providerData);
        }
        $this->settledAt = new \DateTimeImmutable();

        $this->addEvent('transaction.succeeded', [
            'amount' => $this->amount->toArray(),
            'provider' => $this->provider
        ]);
    }

    public function fail(string $reason, ?string $code = null, ?array $providerData = null): void
    {
        $this->status = TransactionStatus::failed();
        $this->failureReason = $reason;
        $this->failureCode = $code;
        if ($providerData) {
            $this->providerData = array_merge($this->providerData ?? [], $providerData);
        }

        $this->addEvent('transaction.failed', [
            'reason' => $reason,
            'code' => $code,
            'provider' => $this->provider
        ]);
    }

    public function cancel(string $reason = 'Manual cancellation'): void
    {
        if (!$this->status->canCancel()) {
            throw new \DomainException('Cannot cancel transaction in current status');
        }

        $this->status = TransactionStatus::cancelled();
        $this->failureReason = $reason;

        $this->addEvent('transaction.cancelled', [
            'reason' => $reason
        ]);
    }

    public function scheduleRetry(\DateTimeImmutable $retryAt): void
    {
        if (!$this->status->canRetry()) {
            throw new \DomainException('Cannot retry transaction in current status');
        }

        $this->retryCount++;
        $this->nextRetryAt = $retryAt;
        $this->status = TransactionStatus::retrying();

        $this->addEvent('transaction.retry_scheduled', [
            'retry_count' => $this->retryCount,
            'retry_at' => $retryAt->format(\DateTimeInterface::ATOM)
        ]);
    }

    public function updateFraudScore(FraudScore $score): void
    {
        $oldScore = $this->fraudScore;
        $this->fraudScore = $score;

        if ($score->isHigh() && !$oldScore->isHigh()) {
            $this->addEvent('transaction.fraud_detected', [
                'old_score' => $oldScore->getValue(),
                'new_score' => $score->getValue()
            ]);

            // Auto-cancel si score très élevé
            if ($score->isCritical() && $this->status->isPending()) {
                $this->cancel('Cancelled due to high fraud risk');
            }
        }
    }

    public function addRefund(Transaction $refundTransaction): void
    {
        if (!$this->status->isSucceeded()) {
            throw new \DomainException('Cannot refund non-succeeded transaction');
        }

        $refundTransaction->parentTransactionId = $this->id->toString();
        $this->childTransactions->add($refundTransaction);

        $this->addEvent('transaction.refund_created', [
            'refund_id' => $refundTransaction->getId()->toString(),
            'refund_amount' => $refundTransaction->getAmount()->toArray()
        ]);
    }

    public function isPayment(): bool
    {
        return $this->type === 'payment';
    }

    public function isRefund(): bool
    {
        return $this->type === 'refund';
    }

    public function isChargeback(): bool
    {
        return $this->type === 'chargeback';
    }

    public function canRetry(): bool
    {
        return $this->status->canRetry() && $this->retryCount < 3;
    }

    public function hasHighFraudRisk(): bool
    {
        return $this->fraudScore->isHigh();
    }

    public function getTotalRefunded(): Money
    {
        $refunded = Money::zero($this->amount->getCurrency());
        
        foreach ($this->childTransactions as $child) {
            if ($child->isRefund() && $child->getStatus()->isSucceeded()) {
                $refunded = $refunded->add($child->getAmount());
            }
        }

        return $refunded;
    }

    public function getNetAmount(): Money
    {
        return $this->amount->subtract($this->getTotalRefunded());
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function addMetadata(string $key, mixed $value): void
    {
        if ($this->metadata === null) {
            $this->metadata = [];
        }
        $this->metadata[$key] = $value;
    }

    private function addEvent(string $type, array $data = []): void
    {
        $event = new TransactionEvent($this, $type, $data);
        $this->events->add($event);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'user_id' => $this->userId,
            'amount' => $this->amount->toArray(),
            'payment_method' => $this->paymentMethod->toArray(),
            'status' => $this->status->toString(),
            'description' => $this->description,
            'provider' => $this->provider,
            'external_id' => $this->externalId,
            'external_reference' => $this->externalReference,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'processed_at' => $this->processedAt?->format(\DateTimeInterface::ATOM),
            'settled_at' => $this->settledAt?->format(\DateTimeInterface::ATOM),
            'fraud_score' => $this->fraudScore->getValue(),
            'retry_count' => $this->retryCount,
            'type' => $this->type,
            'metadata' => $this->metadata,
            'failure_reason' => $this->failureReason,
            'failure_code' => $this->failureCode,
            'total_refunded' => $this->getTotalRefunded()->toArray(),
            'net_amount' => $this->getNetAmount()->toArray(),
        ];
    }
} 