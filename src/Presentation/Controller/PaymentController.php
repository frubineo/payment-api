<?php

declare(strict_types=1);

namespace PaymentApi\Presentation\Controller;

use PaymentApi\Application\UseCase\ProcessPayment;
use PaymentApi\Application\DTO\PaymentRequest;
use PaymentApi\Application\DTO\PaymentResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Contrôleur API pour les paiements
 * 
 * Expose les endpoints REST pour le traitement des paiements
 * Respecte les principes REST et les bonnes pratiques de sécurité
 */
#[Route('/api/v1/payments', name: 'payment_api_')]
final class PaymentController
{
    public function __construct(
        private readonly ProcessPayment $processPaymentUseCase,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactory $paymentLimiter
    ) {
    }

    /**
     * Traite un nouveau paiement
     * 
     * @Route("", methods=["POST"], name="process")
     */
    #[Route('', methods: ['POST'], name: 'process')]
    public function processPayment(Request $request): JsonResponse
    {
        $requestId = $this->generateRequestId();
        $clientIp = $request->getClientIp();
        
        $this->logger->info('Nouvelle requête de paiement reçue', [
            'request_id' => $requestId,
            'client_ip' => $clientIp,
            'user_agent' => $request->headers->get('User-Agent'),
            'content_length' => $request->headers->get('Content-Length')
        ]);

        try {
            // 1. Vérification du rate limiting
            $limiter = $this->paymentLimiter->create($clientIp);
            if (!$limiter->consume(1)->isAccepted()) {
                return $this->createErrorResponse(
                    'Rate limit exceeded. Too many payment attempts.',
                    429,
                    $requestId
                );
            }

            // 2. Validation du contenu JSON
            $paymentData = $this->parseJsonRequest($request);

            // 3. Validation des données de paiement
            $validationErrors = $this->validatePaymentData($paymentData);
            if (!empty($validationErrors)) {
                return $this->createValidationErrorResponse($validationErrors, $requestId);
            }

            // 4. Création du DTO de requête
            $paymentRequest = $this->createPaymentRequest($paymentData, $request);

            // 5. Exécution du Use Case
            $paymentResponse = $this->processPaymentUseCase->execute($paymentRequest);

            // 6. Logging du résultat
            $this->logPaymentResult($paymentResponse, $requestId);

            // 7. Création de la réponse HTTP
            return $this->createSuccessResponse($paymentResponse, $requestId);

        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Données de paiement invalides', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'client_ip' => $clientIp
            ]);

            return $this->createErrorResponse(
                'Invalid payment data: ' . $e->getMessage(),
                400,
                $requestId
            );

        } catch (\Exception $e) {
            $this->logger->error('Erreur inattendue lors du traitement du paiement', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'client_ip' => $clientIp
            ]);

            return $this->createErrorResponse(
                'Internal server error. Please try again later.',
                500,
                $requestId
            );
        }
    }

    /**
     * Récupère le statut d'une transaction
     * 
     * @Route("/{transactionId}", methods=["GET"], name="get_status")
     */
    #[Route('/{transactionId}', methods: ['GET'], name: 'get_status')]
    public function getTransactionStatus(string $transactionId, Request $request): JsonResponse
    {
        $requestId = $this->generateRequestId();

        $this->logger->info('Requête de statut de transaction', [
            'request_id' => $requestId,
            'transaction_id' => $transactionId,
            'client_ip' => $request->getClientIp()
        ]);

        try {
            // Validation du format de l'ID de transaction
            if (!$this->isValidTransactionId($transactionId)) {
                return $this->createErrorResponse(
                    'Invalid transaction ID format',
                    400,
                    $requestId
                );
            }

            // TODO: Implémenter la récupération du statut
            // $transaction = $this->getTransactionUseCase->execute($transactionId);

            return $this->createSuccessResponse([
                'transaction_id' => $transactionId,
                'status' => 'pending', // TODO: Statut réel
                'message' => 'Transaction status retrieved successfully'
            ], $requestId);

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la récupération du statut', [
                'request_id' => $requestId,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return $this->createErrorResponse(
                'Unable to retrieve transaction status',
                500,
                $requestId
            );
        }
    }

    /**
     * Endpoint de santé pour le monitoring
     * 
     * @Route("/health", methods=["GET"], name="health")
     */
    #[Route('/health', methods: ['GET'], name: 'health')]
    public function healthCheck(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'healthy',
            'service' => 'payment-api',
            'version' => '1.0.0',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checks' => [
                'database' => 'ok', // TODO: Vérification réelle
                'payment_gateway' => 'ok', // TODO: Vérification réelle
                'fraud_detection' => 'ok' // TODO: Vérification réelle
            ]
        ]);
    }

    /**
     * Parse le contenu JSON de la requête
     */
    private function parseJsonRequest(Request $request): array
    {
        $content = $request->getContent();
        
        if (empty($content)) {
            throw new \InvalidArgumentException('Request body cannot be empty');
        }

        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON format: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Valide les données de paiement
     */
    private function validatePaymentData(array $data): array
    {
        $errors = [];

        // Champs requis
        $requiredFields = ['amount', 'currency', 'customer_id', 'payment_method'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[] = "Field '{$field}' is required";
            }
        }

        // Validation du montant
        if (isset($data['amount'])) {
            if (!is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
                $errors[] = 'Amount must be a positive number';
            }
            
            if ((float) $data['amount'] > 10000) {
                $errors[] = 'Amount exceeds maximum allowed limit';
            }
        }

        // Validation de la devise
        if (isset($data['currency'])) {
            $allowedCurrencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];
            if (!in_array(strtoupper($data['currency']), $allowedCurrencies, true)) {
                $errors[] = 'Currency not supported';
            }
        }

        // Validation de l'ID client
        if (isset($data['customer_id'])) {
            if (!is_string($data['customer_id']) || strlen($data['customer_id']) < 3) {
                $errors[] = 'Customer ID must be at least 3 characters long';
            }
        }

        // Validation de la méthode de paiement
        if (isset($data['payment_method']) && is_array($data['payment_method'])) {
            if (!isset($data['payment_method']['type'])) {
                $errors[] = 'Payment method type is required';
            }
        }

        return $errors;
    }

    /**
     * Crée une requête de paiement depuis les données et la requête HTTP
     */
    private function createPaymentRequest(array $data, Request $request): PaymentRequest
    {
        // Enrichissement avec les données de la requête HTTP
        $enrichedData = array_merge($data, [
            'ip_address' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'request_id' => $this->generateRequestId()
        ]);

        return PaymentRequest::fromArray($enrichedData);
    }

    /**
     * Log le résultat du traitement de paiement
     */
    private function logPaymentResult(PaymentResponse $response, string $requestId): void
    {
        $logLevel = $response->isSuccessful() ? 'info' : 'warning';
        
        $this->logger->log($logLevel, 'Résultat du traitement de paiement', [
            'request_id' => $requestId,
            'transaction_id' => $response->getTransactionId(),
            'status' => $response->getStatus(),
            'success' => $response->isSuccessful(),
            'fraud_detected' => $response->isFraudDetected(),
            'requires_action' => $response->requiresUserAction()
        ]);
    }

    /**
     * Crée une réponse de succès
     */
    private function createSuccessResponse(mixed $data, string $requestId): JsonResponse
    {
        $responseData = [
            'success' => true,
            'request_id' => $requestId,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        ];

        if ($data instanceof PaymentResponse) {
            $responseData = array_merge($responseData, $data->toArray());
            $httpStatus = $data->getHttpStatusCode();
        } else {
            $responseData['data'] = $data;
            $httpStatus = 200;
        }

        return new JsonResponse($responseData, $httpStatus);
    }

    /**
     * Crée une réponse d'erreur
     */
    private function createErrorResponse(string $message, int $statusCode, string $requestId): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $statusCode
            ],
            'request_id' => $requestId,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        ], $statusCode);
    }

    /**
     * Crée une réponse d'erreur de validation
     */
    private function createValidationErrorResponse(array $errors, string $requestId): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'message' => 'Validation failed',
                'code' => 422,
                'details' => $errors
            ],
            'request_id' => $requestId,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        ], 422);
    }

    /**
     * Génère un ID de requête unique
     */
    private function generateRequestId(): string
    {
        return 'req_' . uniqid() . '_' . time();
    }

    /**
     * Vérifie si l'ID de transaction est valide (format UUID)
     */
    private function isValidTransactionId(string $transactionId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $transactionId) === 1;
    }
} 