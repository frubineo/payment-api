<?php

declare(strict_types=1);

namespace PaymentApi\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Value Object pour le score de risque de fraude
 * 
 * Représente de manière immutable le niveau de risque associé à une transaction
 * Inclut la logique de classification et les seuils de décision
 */
final class FraudScore
{
    // Niveaux de risque
    public const RISK_VERY_LOW = 'very_low';
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';
    public const RISK_VERY_HIGH = 'very_high';
    public const RISK_CRITICAL = 'critical';

    // Seuils de score (0-1000)
    private const SCORE_MIN = 0;
    private const SCORE_MAX = 1000;
    
    private const THRESHOLD_VERY_LOW = 50;
    private const THRESHOLD_LOW = 150;
    private const THRESHOLD_MEDIUM = 300;
    private const THRESHOLD_HIGH = 600;
    private const THRESHOLD_VERY_HIGH = 800;
    private const THRESHOLD_CRITICAL = 900;

    private readonly int $value;
    private readonly string $riskLevel;
    private readonly array $factors;
    private readonly array $rules;
    private readonly \DateTimeImmutable $computedAt;
    private readonly string $version;

    public function __construct(
        int $value,
        array $factors = [],
        array $rules = [],
        ?\DateTimeImmutable $computedAt = null,
        string $version = '1.0'
    ) {
        $this->validateScore($value);
        $this->validateFactors($factors);
        
        $this->value = $value;
        $this->riskLevel = $this->calculateRiskLevel($value);
        $this->factors = $factors;
        $this->rules = $rules;
        $this->computedAt = $computedAt ?? new \DateTimeImmutable();
        $this->version = $version;
    }

    /**
     * Crée un score de fraude très bas
     */
    public static function veryLow(array $factors = []): self
    {
        return new self(
            value: random_int(0, self::THRESHOLD_VERY_LOW),
            factors: $factors
        );
    }

    /**
     * Crée un score de fraude bas
     */
    public static function low(array $factors = []): self
    {
        return new self(
            value: random_int(self::THRESHOLD_VERY_LOW + 1, self::THRESHOLD_LOW),
            factors: $factors
        );
    }

    /**
     * Crée un score de fraude moyen
     */
    public static function medium(array $factors = []): self
    {
        return new self(
            value: random_int(self::THRESHOLD_LOW + 1, self::THRESHOLD_MEDIUM),
            factors: $factors
        );
    }

    /**
     * Crée un score de fraude élevé
     */
    public static function high(array $factors = []): self
    {
        return new self(
            value: random_int(self::THRESHOLD_MEDIUM + 1, self::THRESHOLD_HIGH),
            factors: $factors
        );
    }

    /**
     * Crée un score de fraude très élevé
     */
    public static function veryHigh(array $factors = []): self
    {
        return new self(
            value: random_int(self::THRESHOLD_HIGH + 1, self::THRESHOLD_VERY_HIGH),
            factors: $factors
        );
    }

    /**
     * Crée un score de fraude critique
     */
    public static function critical(array $factors = []): self
    {
        return new self(
            value: random_int(self::THRESHOLD_VERY_HIGH + 1, self::SCORE_MAX),
            factors: $factors
        );
    }

    /**
     * Crée un score depuis une valeur numérique
     */
    public static function fromValue(
        int $value,
        array $factors = [],
        array $rules = []
    ): self {
        return new self(
            value: $value,
            factors: $factors,
            rules: $rules
        );
    }

    /**
     * Calcule un score composite depuis plusieurs facteurs
     */
    public static function calculate(array $factors, array $weights = []): self
    {
        if (empty($factors)) {
            return self::veryLow();
        }

        $score = 0;
        $totalWeight = 0;
        $rulesTriggers = [];

        // Facteurs prédéfinis avec leurs poids
        $defaultWeights = [
            'velocity_score' => 0.25,        // Fréquence des transactions
            'geolocation_score' => 0.20,     // Géolocalisation suspecte
            'device_score' => 0.15,          // Score de l'appareil
            'behavioral_score' => 0.15,      // Analyse comportementale
            'payment_method_score' => 0.10,  // Méthode de paiement
            'amount_score' => 0.10,          // Montant de la transaction
            'time_score' => 0.05,            // Heure de la transaction
        ];

        $allWeights = array_merge($defaultWeights, $weights);

        foreach ($factors as $factor => $value) {
            $weight = $allWeights[$factor] ?? 0.05; // Poids par défaut minimal
            $normalizedValue = min(max((float) $value, 0), 1); // Normalise entre 0 et 1
            
            $score += $normalizedValue * $weight;
            $totalWeight += $weight;

            // Enregistre les règles déclenchées
            if ($normalizedValue > 0.7) {
                $rulesTriggers[] = [
                    'rule' => $factor,
                    'value' => $normalizedValue,
                    'weight' => $weight,
                    'triggered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
                ];
            }
        }

        // Normalise le score final sur 1000
        $finalScore = $totalWeight > 0 ? (int) (($score / $totalWeight) * self::SCORE_MAX) : 0;

        return new self(
            value: min($finalScore, self::SCORE_MAX),
            factors: $factors,
            rules: $rulesTriggers
        );
    }

    /**
     * Retourne la valeur numérique du score
     */
    public function getValue(): int
    {
        return $this->value;
    }

    /**
     * Retourne le niveau de risque
     */
    public function getRiskLevel(): string
    {
        return $this->riskLevel;
    }

    /**
     * Retourne les facteurs de calcul
     */
    public function getFactors(): array
    {
        return $this->factors;
    }

    /**
     * Retourne les règles déclenchées
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Retourne la date de calcul
     */
    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }

    /**
     * Retourne la version de l'algorithme
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Retourne le score en pourcentage
     */
    public function getPercentage(): float
    {
        return round(($this->value / self::SCORE_MAX) * 100, 2);
    }

    /**
     * Vérifie si le score est acceptable pour traitement automatique
     */
    public function isAcceptable(): bool
    {
        return $this->value <= self::THRESHOLD_MEDIUM;
    }

    /**
     * Vérifie si le score nécessite une revue manuelle
     */
    public function requiresReview(): bool
    {
        return $this->value > self::THRESHOLD_MEDIUM && $this->value <= self::THRESHOLD_HIGH;
    }

    /**
     * Vérifie si le score doit être bloqué automatiquement
     */
    public function shouldBlock(): bool
    {
        return $this->value > self::THRESHOLD_HIGH;
    }

    /**
     * Vérifie si c'est un risque critique
     */
    public function isCritical(): bool
    {
        return $this->value >= self::THRESHOLD_CRITICAL;
    }

    /**
     * Retourne l'action recommandée
     */
    public function getRecommendedAction(): string
    {
        return match($this->riskLevel) {
            self::RISK_VERY_LOW, self::RISK_LOW => 'approve',
            self::RISK_MEDIUM => 'review',
            self::RISK_HIGH => 'challenge', // MFA/3DS
            self::RISK_VERY_HIGH => 'manual_review',
            self::RISK_CRITICAL => 'block',
            default => 'review'
        };
    }

    /**
     * Retourne le délai de rétention recommandé (en jours)
     */
    public function getRetentionDays(): int
    {
        return match($this->riskLevel) {
            self::RISK_VERY_LOW => 30,
            self::RISK_LOW => 60,
            self::RISK_MEDIUM => 180,
            self::RISK_HIGH => 365,
            self::RISK_VERY_HIGH, self::RISK_CRITICAL => 1095, // 3 ans
            default => 180
        };
    }

    /**
     * Combine avec un autre score de fraude
     */
    public function combineWith(FraudScore $other, float $weight = 0.5): self
    {
        if ($weight < 0 || $weight > 1) {
            throw new InvalidArgumentException('Weight must be between 0 and 1');
        }

        $combinedValue = (int) (($this->value * $weight) + ($other->getValue() * (1 - $weight)));
        
        $combinedFactors = array_merge($this->factors, [
            'combined_with' => [
                'score' => $other->getValue(),
                'risk_level' => $other->getRiskLevel(),
                'weight' => $weight,
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
            ]
        ]);

        $combinedRules = array_merge($this->rules, $other->getRules());

        return new self(
            value: $combinedValue,
            factors: $combinedFactors,
            rules: $combinedRules
        );
    }

    /**
     * Applique une pénalité au score
     */
    public function applyPenalty(int $penalty, string $reason): self
    {
        $newValue = min($this->value + $penalty, self::SCORE_MAX);
        
        $newFactors = array_merge($this->factors, [
            'penalty_applied' => [
                'amount' => $penalty,
                'reason' => $reason,
                'original_score' => $this->value,
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
            ]
        ]);

        return new self(
            value: $newValue,
            factors: $newFactors,
            rules: $this->rules
        );
    }

    /**
     * Applique un bonus au score
     */
    public function applyBonus(int $bonus, string $reason): self
    {
        $newValue = max($this->value - $bonus, self::SCORE_MIN);
        
        $newFactors = array_merge($this->factors, [
            'bonus_applied' => [
                'amount' => $bonus,
                'reason' => $reason,
                'original_score' => $this->value,
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
            ]
        ]);

        return new self(
            value: $newValue,
            factors: $newFactors,
            rules: $this->rules
        );
    }

    /**
     * Retourne une représentation lisible du niveau de risque
     */
    public function getDisplayName(): string
    {
        return match($this->riskLevel) {
            self::RISK_VERY_LOW => 'Très Faible',
            self::RISK_LOW => 'Faible',
            self::RISK_MEDIUM => 'Moyen',
            self::RISK_HIGH => 'Élevé',
            self::RISK_VERY_HIGH => 'Très Élevé',
            self::RISK_CRITICAL => 'Critique',
            default => 'Inconnu'
        };
    }

    /**
     * Retourne la couleur associée au niveau de risque
     */
    public function getColor(): string
    {
        return match($this->riskLevel) {
            self::RISK_VERY_LOW => '#22c55e', // Vert
            self::RISK_LOW => '#84cc16',      // Vert clair
            self::RISK_MEDIUM => '#eab308',   // Jaune
            self::RISK_HIGH => '#f97316',     // Orange
            self::RISK_VERY_HIGH => '#ef4444', // Rouge
            self::RISK_CRITICAL => '#7c2d12', // Rouge foncé
            default => '#6b7280'             // Gris
        };
    }

    /**
     * Vérifie l'égalité avec un autre score
     */
    public function equals(?FraudScore $other): bool
    {
        if ($other === null) {
            return false;
        }

        return $this->value === $other->value && $this->riskLevel === $other->riskLevel;
    }

    /**
     * Compare avec un autre score
     */
    public function compare(FraudScore $other): int
    {
        return $this->value <=> $other->getValue();
    }

    /**
     * Sérialise pour stockage/transport
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'percentage' => $this->getPercentage(),
            'risk_level' => $this->riskLevel,
            'display_name' => $this->getDisplayName(),
            'color' => $this->getColor(),
            'factors' => $this->factors,
            'rules' => $this->rules,
            'computed_at' => $this->computedAt->format(\DateTimeInterface::ATOM),
            'version' => $this->version,
            'recommended_action' => $this->getRecommendedAction(),
            'retention_days' => $this->getRetentionDays(),
            'flags' => [
                'is_acceptable' => $this->isAcceptable(),
                'requires_review' => $this->requiresReview(),
                'should_block' => $this->shouldBlock(),
                'is_critical' => $this->isCritical()
            ]
        ];
    }

    /**
     * Désérialise depuis un array
     */
    public static function fromArray(array $data): self
    {
        $computedAt = isset($data['computed_at']) 
            ? new \DateTimeImmutable($data['computed_at'])
            : null;

        return new self(
            value: (int) ($data['value'] ?? 0),
            factors: $data['factors'] ?? [],
            rules: $data['rules'] ?? [],
            computedAt: $computedAt,
            version: $data['version'] ?? '1.0'
        );
    }

    /**
     * Représentation string
     */
    public function __toString(): string
    {
        return sprintf(
            '%s (%d/1000 - %.1f%%)',
            $this->getDisplayName(),
            $this->value,
            $this->getPercentage()
        );
    }

    /**
     * Retourne les seuils de classification
     */
    public static function getThresholds(): array
    {
        return [
            'very_low' => self::THRESHOLD_VERY_LOW,
            'low' => self::THRESHOLD_LOW,
            'medium' => self::THRESHOLD_MEDIUM,
            'high' => self::THRESHOLD_HIGH,
            'very_high' => self::THRESHOLD_VERY_HIGH,
            'critical' => self::THRESHOLD_CRITICAL
        ];
    }

    /**
     * Calcule le niveau de risque basé sur la valeur
     */
    private function calculateRiskLevel(int $value): string
    {
        return match(true) {
            $value <= self::THRESHOLD_VERY_LOW => self::RISK_VERY_LOW,
            $value <= self::THRESHOLD_LOW => self::RISK_LOW,
            $value <= self::THRESHOLD_MEDIUM => self::RISK_MEDIUM,
            $value <= self::THRESHOLD_HIGH => self::RISK_HIGH,
            $value <= self::THRESHOLD_VERY_HIGH => self::RISK_VERY_HIGH,
            default => self::RISK_CRITICAL
        };
    }

    /**
     * Valide la valeur du score
     */
    private function validateScore(int $value): void
    {
        if ($value < self::SCORE_MIN || $value > self::SCORE_MAX) {
            throw new InvalidArgumentException(
                sprintf(
                    'Fraud score must be between %d and %d, got %d',
                    self::SCORE_MIN,
                    self::SCORE_MAX,
                    $value
                )
            );
        }
    }

    /**
     * Valide les facteurs
     */
    private function validateFactors(array $factors): void
    {
        foreach ($factors as $factor => $value) {
            if (!is_string($factor) || empty($factor)) {
                throw new InvalidArgumentException('Factor name must be a non-empty string');
            }

            if (!is_numeric($value) && !is_array($value)) {
                throw new InvalidArgumentException(
                    sprintf('Factor "%s" must be numeric or array, got %s', $factor, gettype($value))
                );
            }
        }
    }
} 