<?php

namespace Zammpay\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zammpay\PaymentStatus;
use Zammpay\ZammpayClient;
use Zammpay\ZammpayException;

final class ZammpayClientTest extends TestCase
{
    private const KEY = 'test-api-key';

    /** @var FakeTransport */
    private $transport;

    /** @var ZammpayClient */
    private $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new ZammpayClient(self::KEY, 'https://pay.example.com/api/', $this->transport);
    }

    public function testCreatePaymentPostsTheDataWithTheApiKey(): void
    {
        $this->transport->json(200, [
            'success' => true,
            'trx_id' => 'ABCD1234',
            'payment_url' => 'https://pay.example.com/api/payment/ABCD1234',
        ]);

        $response = $this->client->createPayment(self::paymentData());

        $this->assertTrue($response->isSuccess());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ABCD1234', $response->getTransactionId());
        $this->assertSame('https://pay.example.com/api/payment/ABCD1234', $response->getPaymentUrl());
        $this->assertSame([
            'transaction_id' => 'ABCD1234',
            'payment_url' => 'https://pay.example.com/api/payment/ABCD1234',
        ], $response->getData());

        $request = $this->transport->requests[0];
        $this->assertSame('https://pay.example.com/api/payment/create', $request['url']);
        $this->assertSame(self::KEY, $request['fields']['api_key']);
        $this->assertSame(100, $request['fields']['amount']);
        $this->assertSame('https://shop.example.com/callback', $request['fields']['callback_url']);
    }

    public function testCreatePaymentUsesThePaymentUrlZammpaySends(): void
    {
        $this->transport->json(200, [
            'success' => true,
            'trx_id' => 'ABCD1234',
            'payment_url' => 'https://checkout.example.com/ABCD1234',
        ]);

        $response = $this->client->createPayment(self::paymentData());

        $this->assertSame('https://checkout.example.com/ABCD1234', $response->getPaymentUrl());
    }

    public function testCreatePaymentBuildsThePaymentUrlWhenZammpaySendsNone(): void
    {
        $this->transport->json(200, ['success' => true, 'trx_id' => 'ABCD1234']);

        $response = $this->client->createPayment(self::paymentData());

        $this->assertSame('https://pay.example.com/api/payment/ABCD1234', $response->getPaymentUrl());
    }

    public function testTheCallersApiKeyCannotReplaceTheClientsOwn(): void
    {
        $this->transport->json(200, ['success' => true, 'trx_id' => 'ABCD1234']);

        $this->client->createPayment(['api_key' => 'someone-else'] + self::paymentData());

        $this->assertSame(self::KEY, $this->transport->requests[0]['fields']['api_key']);
    }

    public function testCreatePaymentReturnsTheApiError(): void
    {
        $this->transport->json(401, ['success' => false, 'message' => 'Invalid API key']);

        $response = $this->client->createPayment(self::paymentData());

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Invalid API key', $response->getMessage());
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $response->getData());
        $this->assertNull($response->getTransactionId());
    }

    public function testCreatePaymentReturnsTheValidationErrors(): void
    {
        $this->transport->json(422, [
            'message' => 'The amount field is required.',
            'errors' => ['amount' => ['The amount field is required.']],
        ]);

        $response = $this->client->createPayment(['success_url' => 'https://shop.example.com/success']);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('The amount field is required.', $response->getMessage());
        $this->assertSame(['amount' => ['The amount field is required.']], $response->getErrors());
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testASuccessFlagOnAnErrorStatusIsNotASuccess(): void
    {
        $this->transport->json(500, ['success' => true, 'trx_id' => 'ABCD1234']);

        $response = $this->client->createPayment(self::paymentData());

        $this->assertFalse($response->isSuccess());
        $this->assertSame('The payment could not be created.', $response->getMessage());
    }

    public function testAnswerThatIsNotJsonThrows(): void
    {
        $this->transport->raw(502, '<html>Bad Gateway</html>');

        try {
            $this->client->createPayment(self::paymentData());
            $this->fail('Expected a ZammpayException.');
        } catch (ZammpayException $e) {
            $this->assertSame(502, $e->getHttpStatus());
            $this->assertStringContainsString('not JSON', $e->getMessage());
        }
    }

    public function testEmptyAnswerThrows(): void
    {
        $this->transport->raw(200, '');

        $this->expectException(ZammpayException::class);

        $this->client->verifyPayment('ABCD1234');
    }

    public function testConnectionErrorsReachTheCaller(): void
    {
        $this->transport->fail(new ZammpayException('Could not connect to ZAMM PAY: timeout.'));

        $this->expectException(ZammpayException::class);
        $this->expectExceptionMessage('Could not connect to ZAMM PAY: timeout.');

        $this->client->createPayment(self::paymentData());
    }

    public function testVerifyPaymentReturnsTheStatus(): void
    {
        $this->transport->json(200, self::verifiedPayment(PaymentStatus::COMPLETED));

        $response = $this->client->verifyPayment(' ABCD1234 ');

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->isCompleted());
        $this->assertFalse($response->isPending());
        $this->assertSame('Completed', $response->getStatus());
        $this->assertSame('100.00', $response->getAmount());
        $this->assertSame('ABCD1234', $response->getTransactionId());
        $this->assertSame('bkash-personal', $response->get('method'));
        $this->assertSame('fallback', $response->get('missing', 'fallback'));
        $this->assertSame('Completed', $response->getData()['status']);

        $request = $this->transport->requests[0];
        $this->assertSame('https://pay.example.com/api/payment/verify', $request['url']);
        $this->assertSame(['trx_id' => 'ABCD1234', 'api_key' => self::KEY], $request['fields']);
    }

    public function testAPendingPaymentIsNotCompleted(): void
    {
        $this->transport->json(200, self::verifiedPayment(PaymentStatus::PENDING));

        $response = $this->client->verifyPayment('ABCD1234');

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isCompleted());
    }

    public function testVerifyPaymentReturnsNotFound(): void
    {
        $this->transport->json(404, ['success' => false, 'message' => 'Payment not found']);

        $response = $this->client->verifyPayment('NOPE');

        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->isCompleted());
        $this->assertSame('Payment not found', $response->getMessage());
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testVerifyPaymentRefusesAnEmptyTransactionId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client->verifyPayment('  ');
    }

    public function testVerifyCallbackFetchesTheRealStatusFromZammpay(): void
    {
        $this->transport->json(200, self::verifiedPayment(PaymentStatus::PENDING));

        // A forged callback claims the payment is completed; ZAMM PAY says it is not.
        $response = $this->client->verifyCallback([
            'trx_id' => 'ABCD1234',
            'api_key' => self::KEY,
            'status' => PaymentStatus::COMPLETED,
        ]);

        $this->assertFalse($response->isCompleted());
        $this->assertSame('https://pay.example.com/api/payment/verify', $this->transport->requests[0]['url']);
        $this->assertSame('ABCD1234', $this->transport->requests[0]['fields']['trx_id']);
    }

    public function testVerifyCallbackRefusesAnotherApiKeyWithoutARequest(): void
    {
        $response = $this->client->verifyCallback(['trx_id' => 'ABCD1234', 'api_key' => 'someone-else']);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('The callback was not sent for this API key.', $response->getMessage());
        $this->assertSame([], $this->transport->requests);
    }

    public function testVerifyCallbackRefusesAPayloadWithoutATransactionId(): void
    {
        $response = $this->client->verifyCallback(['trx_id' => ['ABCD1234']]);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('The callback has no transaction ID.', $response->getMessage());
        $this->assertSame([], $this->transport->requests);
    }

    public function testTheApiKeyMustNotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ZammpayClient(' ', ZammpayClient::DEFAULT_BASE_URL, $this->transport);
    }

    public function testTheBaseUrlMustBeAnHttpUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ZammpayClient(self::KEY, 'pay.zammbd.com/api', $this->transport);
    }

    public function testDebugOutputHidesTheApiKey(): void
    {
        $this->assertStringNotContainsString(self::KEY, print_r($this->client, true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentData(): array
    {
        return [
            'amount' => 100,
            'cus_name' => 'Demo User',
            'cus_email' => 'demo@example.com',
            'success_url' => 'https://shop.example.com/success',
            'cancel_url' => 'https://shop.example.com/cancel',
            'callback_url' => 'https://shop.example.com/callback',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function verifiedPayment(string $status): array
    {
        return [
            'success' => true,
            'status' => $status,
            'amount' => '100.00',
            'paid_amount' => $status === PaymentStatus::COMPLETED ? '100.00' : null,
            'extra_amount' => $status === PaymentStatus::COMPLETED ? '0.00' : null,
            'trx_id' => 'ABCD1234',
            'method_trx' => 'BK123XYZ',
            'method' => 'bkash-personal',
            'domain' => 'shop.example.com',
            'cus_name' => 'Demo User',
            'cus_email' => 'demo@example.com',
            'cus_number' => null,
            'cus_address' => null,
            'extra' => null,
        ];
    }
}
