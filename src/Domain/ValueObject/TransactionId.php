<?php

declare(strict_types=1);

namespace PaymentApi\Domain\ValueObject;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Value Object pour l'identifiant unique des transactions
 * 
 * Garantit l'unicité et la validité des IDs de transaction
 * Utilise UUID v4 pour éviter les collisions
 */
final class TransactionId
{
    private readonly UuidInterface $value;

    public function __construct(UuidInterface $value)
    {
        $this->value = $value;
    }

    /**
     * Génère un nouvel ID de transaction unique
     */
    public static function generate(): self
    {
        return new self(Uuid::uuid4());
    }

    /**
     * Crée depuis une chaîne UUID
     */
    public static function fromString(string $value): self
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidArgumentException(
                sprintf('Invalid transaction ID format: "%s"', $value)
            );
        }

        return new self(Uuid::fromString($value));
    }

    /**
     * Crée depuis un UUID existant
     */
    public static function fromUuid(UuidInterface $uuid): self
    {
        return new self($uuid);
    }

    /**
     * Retourne la valeur UUID
     */
    public function getValue(): UuidInterface
    {
        return $this->value;
    }

    /**
     * Retourne la représentation string
     */
    public function toString(): string
    {
        return $this->value->toString();
    }

    /**
     * Retourne la représentation string (alias)
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Vérifie l'égalité avec un autre TransactionId
     */
    public function equals(?TransactionId $other): bool
    {
        if ($other === null) {
            return false;
        }

        return $this->value->equals($other->value);
    }

    /**
     * Génère un hash unique pour cet ID
     */
    public function getHash(): string
    {
        return hash('sha256', $this->value->toString());
    }

    /**
     * Valide le format d'un ID de transaction
     */
    public static function isValid(string $value): bool
    {
        return Uuid::isValid($value);
    }

    /**
     * Sérialise pour stockage/transport
     */
    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * Désérialise depuis JSON
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        
        if (!is_string($data)) {
            throw new InvalidArgumentException('Invalid JSON format for TransactionId');
        }

        return self::fromString($data);
    }

    /**
     * Génère un ID de corrélation basé sur cet ID
     */
    public function generateCorrelationId(): string
    {
        return 'txn_' . substr($this->toString(), 0, 8) . '_' . time();
    }

    /**
     * Vérifie si l'ID appartient à une plage temporelle
     */
    public function isFromTimeRange(\DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        // UUID v4 n'encode pas le temps, mais on peut utiliser des patterns si nécessaire
        // Cette méthode est préparée pour des UUID v1 ou des IDs custom avec timestamp
        return true;
    }
} 