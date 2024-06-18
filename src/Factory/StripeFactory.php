<?php

namespace App\Factory;


use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Event\StripeEvent;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webmozart\Assert\Assert;

class StripeFactory
{
    public function __construct(
        private string $stripeSecretKey,
        private string $webhookSecret,
        private EventDispatcherInterface $eventDispatcher,
    ) {
        Stripe::setApiKey($stripeSecretKey);
        Stripe::setApiVersion('2024-04-10');
    }

    /**
     * Créer une session checkout Stripe avec les informations de la commande
     * pour ensuite rediriger l'utilisateur vers la page de paiement
     *
     * @return Session
     */
    public function createSession(Order $order, string $successUrl, string $cancelUrl): Session
    {
        Assert::notEmpty($order->getPayments(), "You must have at least one payment method to create a Stripe session.");

        return Session::create([
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $order->getUser()->getEmail(),
            'line_items' => array_map(function (OrderItem $orderItem): array {

                return [
                    'quantity' => $orderItem->getQuantity(),
                    'price_data' => [
                        'currency' => 'EUR',
                        'product_data' => [
                            'name' => $orderItem->getQuantity() . ' x ' . $orderItem->getProductVariant()->getProduct()->getName(),
                        ],
                        'unit_amount' => bcmul($orderItem->getPriceTTC() / $orderItem->getQuantity(), 100),
                    ],
                ];
            }, $order->getOrderItems()->toArray()),
            'shipping_options' => [
                [
                    'shipping_rate_data' => [
                        'type' => 'fixed_amount',
                        'fixed_amount' => [
                            'currency' => 'EUR',
                            'amount' => $order->getShippings()->last()->getDelivery()->getPrice() * 100,
                        ],
                        'display_name' => $order->getShippings()->last()->getDelivery()->getName(),
                    ],
                ],
            ],
            'metadata' => [
                'order_id' => $order->getId(),
                'payment_id' => $order->getPayments()->last()->getId(),
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'order_id' => $order->getId(),
                    'payment_id' => $order->getPayments()->last()->getId(),
                ],
            ],
        ]);
    }

    /**
     * Permet d'analyser la requete stripe et de retourner l'element correspondant
     *
     * @param string $signature // Signature stripe de la requete
     * @param mixed $body // Contenu de la requete
     * @return Event|JsonResponse
     */
    public function handleStripeRequest(string $signature, mixed $body): Event|JsonResponse
    {
        if (!$body) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'missing body content',
            ], 404);
        }

        $event = $this->getEvent($signature, $body);

        if ($event instanceof JsonResponse) {
            return $event;
        }

        $event = new StripeEvent($event);

        $this->eventDispatcher->dispatch($event, $event->getName());

        return new JsonResponse([
            'status' => 'success',
            'message' => 'Event received and processed successfully',
        ]);
    }

    /**
     * Permet de decoder la requete stripe et de retourner un evenement 
     *
     * @param string $signature La signature stripe de la requete
     * @param mixed $body Le contenu de la requete
     * @return Event|JsonResponse Evenement stripe ou reponse json en cas d'erreur
     */
    private function getEvent(string $signature, mixed $body): Event|JsonResponse {
        try {
            $event = Webhook::constructEvent($body, $signature, $this->webhookSecret);
        } catch(\UnexpectedValueException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $e->getCode());
        } catch (SignatureVerificationException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $e->getCode()); 
        }

        return $event;
    }
}
