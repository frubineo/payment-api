<?php

declare(strict_types=1);

namespace PaymentApi\Application\UseCase;

use PaymentApi\Domain\Entity\Transaction;
use PaymentApi\Domain\Entity\TransactionEvent;
use PaymentApi\Domain\ValueObject\TransactionId;
use PaymentApi\Domain\ValueObject\Money;
use PaymentApi\Domain\ValueObject\PaymentMethod;
use PaymentApi\Domain\ValueObject\TransactionStatus;
use PaymentApi\Domain\ValueObject\FraudScore;
use PaymentApi\Application\Port\TransactionRepositoryInterface;
use PaymentApi\Application\Port\PaymentGatewayInterface;
use PaymentApi\Application\Port\FraudDetectionInterface;
use PaymentApi\Application\Port\NotificationServiceInterface;
use PaymentApi\Application\DTO\PaymentRequest;
use PaymentApi\Application\DTO\PaymentResponse;
use PaymentApi\Application\Exception\PaymentProcessingException;
use PaymentApi\Application\Exception\FraudException;
use PaymentApi\Application\Exception\InvalidPaymentDataException;
use Psr\Log\LoggerInterface;

/**
 * Use Case : Traitement des paiements
 * 
 * Orchestre l'ensemble du processus de paiement :
 * - Validation des données
 * - Détection de fraude
 * - Autorisation de paiement
 * - Capture si nécessaire
 * - Gestion des événements
 */
final class ProcessPayment
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly PaymentGatewayInterface $paymentGateway,
        private readonly FraudDetectionInterface $fraudDetection,
        private readonly NotificationServiceInterface $notificationService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Traite un paiement de bout en bout
     */
    public function execute(PaymentRequest $request): PaymentResponse
    {
        $this->logger->info('Démarrage du traitement de paiement', [
            'customer_id' => $request->getCustomerId(),
            'amount' => $request->getAmount()->toArray(),
            'payment_method' => $request->getPaymentMethod()->getType()
        ]);

        try {
            // 1. Validation des données de paiement
            $this->validatePaymentRequest($request);

            // 2. Création de la transaction
            $transaction = $this->createTransaction($request);

            // 3. Détection de fraude
            $fraudScore = $this->performFraudDetection($transaction, $request);
            $transaction->updateFraudScore($fraudScore);

            // 4. Vérification des seuils de fraude
            $this->handleFraudResult($transaction, $fraudScore);

            // 5. Autorisation du paiement
            $authorizationResult = $this->authorizePayment($transaction);

            // 6. Capture immédiate si configuré
            if ($request->shouldCaptureImmediately() && $transaction->getStatus()->canBeCapture()) {
                $this->capturePayment($transaction);
            }

            // 7. Sauvegarde finale
            $this->transactionRepository->save($transaction);

            // 8. Notifications asynchrones
            $this->sendNotifications($transaction);

            $this->logger->info('Paiement traité avec succès', [
                'transaction_id' => $transaction->getId()->toString(),
                'status' => $transaction->getStatus()->getValue(),
                'fraud_score' => $fraudScore->getValue()
            ]);

            return PaymentResponse::success($transaction);

        } catch (FraudException $e) {
            $this->logger->warning('Paiement bloqué pour fraude', [
                'customer_id' => $request->getCustomerId(),
                'fraud_reason' => $e->getMessage(),
                'fraud_score' => $e->getFraudScore()?->getValue()
            ]);

            return PaymentResponse::fraudDetected($e->getMessage(), $e->getFraudScore());

        } catch (PaymentProcessingException $e) {
            $this->logger->error('Erreur lors du traitement du paiement', [
                'customer_id' => $request->getCustomerId(),
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ]);

            return PaymentResponse::failed($e->getMessage(), $e->getCode());

        } catch (\Exception $e) {
            $this->logger->error('Erreur inattendue lors du traitement du paiement', [
                'customer_id' => $request->getCustomerId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return PaymentResponse::failed('Internal server error', 500);
        }
    }

    /**
     * Valide les données de la requête de paiement
     */
    private function validatePaymentRequest(PaymentRequest $request): void
    {
        if (empty($request->getCustomerId())) {
            throw new InvalidPaymentDataException('Customer ID is required');
        }

        if ($request->getAmount()->isZero()) {
            throw new InvalidPaymentDataException('Payment amount cannot be zero');
        }

        if ($request->getAmount()->isNegative()) {
            throw new InvalidPaymentDataException('Payment amount cannot be negative');
        }

        // Vérification des limites de montant
        $maxAmount = Money::fromAmount('10000', $request->getAmount()->getCurrencyCode());
        if ($request->getAmount()->exceedsThreshold($maxAmount)) {
            throw new InvalidPaymentDataException('Payment amount exceeds maximum allowed');
        }

        // Validation de la méthode de paiement
        if (!$request->getPaymentMethod()->supportsCurrency($request->getAmount()->getCurrencyCode())) {
            throw new InvalidPaymentDataException(
                sprintf(
                    'Payment method %s does not support currency %s',
                    $request->getPaymentMethod()->getType(),
                    $request->getAmount()->getCurrencyCode()
                )
            );
        }

        // Validation pays si fourni
        if ($request->getCountryCode() && 
            !$request->getPaymentMethod()->supportsCountry($request->getCountryCode())) {
            throw new InvalidPaymentDataException(
                sprintf(
                    'Payment method %s is not available in country %s',
                    $request->getPaymentMethod()->getType(),
                    $request->getCountryCode()
                )
            );
        }
    }

    /**
     * Crée une nouvelle transaction
     */
    private function createTransaction(PaymentRequest $request): Transaction
    {
        $transactionId = TransactionId::generate();
        
        $transaction = new Transaction(
            id: $transactionId,
            amount: $request->getAmount(),
            paymentMethod: $request->getPaymentMethod(),
            customerId: $request->getCustomerId(),
            customerEmail: $request->getCustomerEmail(),
            description: $request->getDescription(),
            metadata: $request->getMetadata(),
            ipAddress: $request->getIpAddress(),
            userAgent: $request->getUserAgent()
        );

        // Ajout de l'événement de création
        $creationEvent = TransactionEvent::created(
            transactionId: $transactionId,
            status: $transaction->getStatus(),
            amount: $request->getAmount(),
            metadata: [
                'customer_id' => $request->getCustomerId(),
                'payment_method' => $request->getPaymentMethod()->toArray(),
                'request_ip' => $request->getIpAddress(),
                'user_agent' => $request->getUserAgent()
            ],
            source: 'payment-api'
        );

        $transaction->addEvent($creationEvent);

        return $transaction;
    }

    /**
     * Effectue la détection de fraude
     */
    private function performFraudDetection(Transaction $transaction, PaymentRequest $request): FraudScore
    {
        $fraudFactors = [
            'amount_score' => $this->calculateAmountRiskScore($request->getAmount()),
            'velocity_score' => $this->calculateVelocityScore($request->getCustomerId()),
            'geolocation_score' => $this->calculateGeolocationScore($request->getIpAddress()),
            'payment_method_score' => $this->calculatePaymentMethodScore($request->getPaymentMethod()),
            'time_score' => $this->calculateTimeScore(),
            'customer_score' => $this->calculateCustomerScore($request->getCustomerId())
        ];

        $fraudScore = $this->fraudDetection->analyzeTransaction($transaction, $fraudFactors);

        $this->logger->info('Analyse de fraude terminée', [
            'transaction_id' => $transaction->getId()->toString(),
            'fraud_score' => $fraudScore->getValue(),
            'risk_level' => $fraudScore->getRiskLevel(),
            'factors' => $fraudFactors
        ]);

        return $fraudScore;
    }

    /**
     * Gère le résultat de la détection de fraude
     */
    private function handleFraudResult(Transaction $transaction, FraudScore $fraudScore): void
    {
        if ($fraudScore->shouldBlock()) {
            // Ajout de l'événement de fraude détectée
            $fraudEvent = TransactionEvent::fraudDetected(
                transactionId: $transaction->getId(),
                previousStatus: $transaction->getStatus(),
                fraudData: [
                    'fraud_score' => $fraudScore->toArray(),
                    'blocked_reason' => 'Score de fraude critique',
                    'action_taken' => 'automatic_block'
                ],
                source: 'fraud_engine'
            );

            $transaction->addEvent($fraudEvent);
            $transaction->updateStatus(TransactionStatus::fraudDetected('Critical fraud score detected'));

            throw new FraudException(
                'Transaction blocked due to high fraud risk',
                $fraudScore
            );
        }

        if ($fraudScore->requiresReview()) {
            $transaction->updateStatus(
                TransactionStatus::pending('Manual review required due to elevated fraud score')
            );

            // Notification pour révision manuelle
            $this->notificationService->sendFraudAlert($transaction, $fraudScore);
        }
    }

    /**
     * Autorise le paiement via la gateway
     */
    private function authorizePayment(Transaction $transaction): array
    {
        try {
            $authorizationResult = $this->paymentGateway->authorize($transaction);

            if ($authorizationResult['success']) {
                $transaction->updateStatus(
                    TransactionStatus::authorized(
                        'Payment authorized successfully',
                        $authorizationResult['provider_transaction_id'] ?? null
                    )
                );

                // Mise à jour des IDs externes
                if (isset($authorizationResult['provider_transaction_id'])) {
                    $transaction->setExternalTransactionId($authorizationResult['provider_transaction_id']);
                }

                if (isset($authorizationResult['provider_data'])) {
                    $transaction->updateProviderData($authorizationResult['provider_data']);
                }

            } else {
                $errorMessage = $authorizationResult['error_message'] ?? 'Authorization failed';
                $errorCode = $authorizationResult['error_code'] ?? 'AUTHORIZATION_FAILED';

                $transaction->updateStatus(
                    TransactionStatus::failed($errorMessage, $errorCode)
                );

                throw new PaymentProcessingException($errorMessage, (int) $errorCode);
            }

            return $authorizationResult;

        } catch (\Exception $e) {
            $transaction->updateStatus(
                TransactionStatus::failed('Gateway communication error: ' . $e->getMessage())
            );

            throw new PaymentProcessingException(
                'Payment gateway error: ' . $e->getMessage(),
                $e->getCode()
            );
        }
    }

    /**
     * Capture le paiement
     */
    private function capturePayment(Transaction $transaction): void
    {
        try {
            $captureResult = $this->paymentGateway->capture($transaction);

            if ($captureResult['success']) {
                $transaction->updateStatus(
                    TransactionStatus::captured(
                        'Payment captured successfully',
                        $captureResult['provider_transaction_id'] ?? null
                    )
                );

                // Si capture réussie et aucune autre action requise, marquer comme complétée
                if (!$transaction->requiresAdditionalProcessing()) {
                    $transaction->updateStatus(TransactionStatus::completed());
                }

            } else {
                $this->logger->error('Capture failed', [
                    'transaction_id' => $transaction->getId()->toString(),
                    'error' => $captureResult['error_message'] ?? 'Unknown error'
                ]);

                // La capture a échoué mais l'autorisation reste valide
                // La transaction reste en statut "authorized"
            }

        } catch (\Exception $e) {
            $this->logger->error('Capture exception', [
                'transaction_id' => $transaction->getId()->toString(),
                'error' => $e->getMessage()
            ]);

            // En cas d'exception, la transaction reste autorisée
            // Un processus batch peut réessayer la capture plus tard
        }
    }

    /**
     * Envoie les notifications
     */
    private function sendNotifications(Transaction $transaction): void
    {
        try {
            $this->notificationService->sendPaymentConfirmation($transaction);
            
            // Notifications spécifiques selon le statut
            if ($transaction->getStatus()->isSuccessful()) {
                $this->notificationService->sendSuccessNotification($transaction);
            } elseif ($transaction->getStatus()->isFailed()) {
                $this->notificationService->sendFailureNotification($transaction);
            }

        } catch (\Exception $e) {
            // Les erreurs de notification ne doivent pas faire échouer le paiement
            $this->logger->error('Notification error', [
                'transaction_id' => $transaction->getId()->toString(),
                'error' => $e->getMessage()
            ]);
        }
    }

    // Méthodes de calcul des scores de risque

    private function calculateAmountRiskScore(Money $amount): float
    {
        // Score basé sur le montant (plus c'est élevé, plus c'est risqué)
        $amountInCents = $amount->getAmountInCents();
        
        return match(true) {
            $amountInCents >= 100000 => 0.9, // > 1000 EUR
            $amountInCents >= 50000 => 0.7,  // > 500 EUR
            $amountInCents >= 20000 => 0.5,  // > 200 EUR
            $amountInCents >= 10000 => 0.3,  // > 100 EUR
            default => 0.1
        };
    }

    private function calculateVelocityScore(string $customerId): float
    {
        // Récupère le nombre de transactions récentes pour ce client
        $recentTransactionsCount = $this->transactionRepository
            ->countRecentTransactionsByCustomer($customerId, new \DateTimeImmutable('-1 hour'));

        return match(true) {
            $recentTransactionsCount >= 10 => 0.9,
            $recentTransactionsCount >= 5 => 0.7,
            $recentTransactionsCount >= 3 => 0.5,
            $recentTransactionsCount >= 2 => 0.3,
            default => 0.1
        };
    }

    private function calculateGeolocationScore(?string $ipAddress): float
    {
        if (!$ipAddress) {
            return 0.5; // Score moyen si pas d'IP
        }

        // Logique de géolocalisation (pays à risque, proxy, etc.)
        // Ici on simule avec une logique basique
        return 0.2; // Score faible par défaut
    }

    private function calculatePaymentMethodScore(PaymentMethod $paymentMethod): float
    {
        // Score basé sur le type de méthode de paiement
        return match($paymentMethod->getType()) {
            PaymentMethod::TYPE_CRYPTO => 0.8,
            PaymentMethod::TYPE_PAYPAL => 0.2,
            PaymentMethod::TYPE_APPLE_PAY, PaymentMethod::TYPE_GOOGLE_PAY => 0.1,
            PaymentMethod::TYPE_CREDIT_CARD, PaymentMethod::TYPE_DEBIT_CARD => 0.3,
            default => 0.4
        };
    }

    private function calculateTimeScore(): float
    {
        $hour = (int) (new \DateTimeImmutable())->format('H');
        
        // Heures nocturnes plus risquées
        return match(true) {
            $hour >= 2 && $hour <= 6 => 0.7,
            $hour >= 22 || $hour <= 1 => 0.5,
            default => 0.2
        };
    }

    private function calculateCustomerScore(string $customerId): float
    {
        // Score basé sur l'historique du client
        $customerHistory = $this->transactionRepository->getCustomerTransactionHistory($customerId);
        
        if (empty($customerHistory)) {
            return 0.6; // Nouveau client = risque modéré
        }

        $successfulTransactions = array_filter($customerHistory, fn($tx) => $tx['status'] === 'completed');
        $successRate = count($successfulTransactions) / count($customerHistory);

        return match(true) {
            $successRate >= 0.95 => 0.1, // Client fiable
            $successRate >= 0.8 => 0.3,
            $successRate >= 0.6 => 0.5,
            default => 0.8 // Client à risque
        };
    }
} 