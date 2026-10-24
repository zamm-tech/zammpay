# ZAMM PAY PHP Library

[![Tests](https://github.com/zamm-tech/zammpay/actions/workflows/tests.yml/badge.svg)](https://github.com/zamm-tech/zammpay/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/zamm-tech/zammpay.svg)](https://packagist.org/packages/zamm-tech/zammpay)
[![PHP Version](https://img.shields.io/packagist/php-v/zamm-tech/zammpay.svg)](https://packagist.org/packages/zamm-tech/zammpay)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

The official PHP library for the [ZAMM PAY](https://pay.zammbd.com) payment gateway. Use it to create payments, send customers to checkout, and confirm that they paid.

- Create a payment and get its checkout URL
- Verify a payment's status, amount and customer details
- Check callbacks safely, without trusting the callback body
- No dependencies besides the cURL and JSON extensions
- PHP 7.2 to 8.4

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [How a payment works](#how-a-payment-works)
- [Create a payment](#create-a-payment)
- [Handle the callback](#handle-the-callback)
- [Verify when the customer returns](#verify-when-the-customer-returns)
- [Responses](#responses)
- [Errors](#errors)
- [Configuration](#configuration)
- [Security checklist](#security-checklist)
- [Development](#development)

## Requirements

- PHP 7.2 or newer
- The `curl` and `json` extensions
- An API key, which you can find for each domain in the ZAMM PAY dashboard

## Installation

```bash
composer require zamm-tech/zammpay
```

## How a payment works

1. Your server calls `createPayment()`. ZAMM PAY returns a transaction ID and a checkout URL.
2. You save the transaction ID with your order and redirect the customer to the checkout URL.
3. The customer pays. ZAMM PAY posts the payment to your `callback_url`, then sends the customer to your `success_url`.
4. On the callback, and again when the customer returns, you call ZAMM PAY to confirm that the payment is `Completed` and the amount is correct. Only then do you fulfil the order.

If the customer cancels, ZAMM PAY sends them to your `cancel_url`.

## Create a payment

```php
use Zammpay\ZammpayClient;
use Zammpay\ZammpayException;

$zammpay = new ZammpayClient(getenv('ZAMMPAY_API_KEY'));

try {
    $response = $zammpay->createPayment([
        'amount'       => 500,
        'cus_name'     => 'Demo User',
        'cus_email'    => 'demo@example.com',
        'cus_number'   => '01700000000',
        'success_url'  => 'https://your-site.com/payment/success?order=1001',
        'cancel_url'   => 'https://your-site.com/payment/cancel?order=1001',
        'callback_url' => 'https://your-site.com/payment/callback',
        'extra'        => ['order_id' => 1001],
    ]);
} catch (ZammpayException $e) {
    // ZAMM PAY could not be reached. Log it and ask the customer to try again.
    exit('Payment is unavailable right now. Please try again.');
}

if (! $response->isSuccess()) {
    // For example "Invalid API key" or "The amount must be at least 0.01."
    exit('Could not start the payment: ' . $response->getMessage());
}

// Save the transaction ID with your order. You need it to verify the payment later.
$order->zammpay_trx_id = $response->getTransactionId();
$order->save();

header('Location: ' . $response->getPaymentUrl());
exit;
```

ZAMM PAY opens `success_url` and `cancel_url` exactly as you give them and does not add the transaction ID. Put your own order ID in these URLs, as above, so you know which order the customer is returning for.

### Payment fields

| Field          | Required | Description                                                                 |
| -------------- | -------- | --------------------------------------------------------------------------- |
| `amount`       | Yes      | The amount to charge. The minimum is 0.01.                                  |
| `success_url`  | Yes      | Where the customer goes after paying.                                       |
| `cancel_url`   | Yes      | Where the customer goes if they cancel.                                     |
| `callback_url` | Yes      | A public URL on your server. ZAMM PAY posts to it when the payment completes. |
| `cus_name`     | No       | The customer's name (up to 255 characters).                                 |
| `cus_email`    | No       | The customer's email address.                                               |
| `cus_number`   | No       | The customer's phone number (up to 15 characters).                          |
| `cus_address`  | No       | The customer's address (up to 255 characters).                              |
| `currency`     | No       | Defaults to `BDT`.                                                          |
| `extra`        | No       | Your own data, such as an order ID. It comes back in the callback and in `verifyPayment()`. |

You don't need to pass `api_key`. The client adds it to every request.

## Handle the callback

When a payment completes, ZAMM PAY sends a form POST to your `callback_url`.

**Never trust the callback body.** Anyone can post to your callback URL. `verifyCallback()` reads only the transaction ID from the body and asks ZAMM PAY for the payment's real status:

```php
// https://your-site.com/payment/callback
use Zammpay\ZammpayClient;
use Zammpay\ZammpayException;

$zammpay = new ZammpayClient(getenv('ZAMMPAY_API_KEY'));

try {
    $payment = $zammpay->verifyCallback($_POST);
} catch (ZammpayException $e) {
    http_response_code(503); // An answer of 5xx makes ZAMM PAY try once more.
    exit;
}

if (! $payment->isCompleted()) {
    http_response_code(400);
    exit;
}

$order = Order::findByZammpayTrxId($payment->getTransactionId());

if ($order === null || ! amountsMatch($payment->getAmount(), $order->total)) {
    http_response_code(400);
    exit;
}

// Callbacks can arrive more than once, so fulfil each order only once.
if (! $order->isPaid()) {
    $order->markAsPaid($payment->get('method'), $payment->get('method_trx'));
}

http_response_code(200);
echo 'OK';
```

```php
function amountsMatch(string $paid, $expected): bool
{
    return abs((float) $paid - (float) $expected) < 0.01;
}
```

Your callback URL must answer with a 2xx status within 10 seconds. It must not redirect, because ZAMM PAY does not follow redirects. In the ZAMM PAY dashboard you can see each callback attempt and send it again.

## Verify when the customer returns

A callback can be missed. Your server might be down, for example. So verify the payment again when the customer reaches your `success_url`. Remember that anyone can open that URL, so reaching it is not proof of payment.

```php
// https://your-site.com/payment/success?order=1001
$order = Order::find($_GET['order'] ?? null);

$payment = $zammpay->verifyPayment($order->zammpay_trx_id);

if ($payment->isCompleted() && amountsMatch($payment->getAmount(), $order->total)) {
    if (! $order->isPaid()) {
        $order->markAsPaid($payment->get('method'), $payment->get('method_trx'));
    }

    echo 'Thank you! Your payment was received.';
} elseif ($payment->isPending()) {
    echo 'Your payment is still being confirmed.';
} else {
    echo 'Your payment was not completed.';
}
```

## Responses

Every API call returns a `Zammpay\ZammpayResponse`.

| Method               | Returns                                                                              |
| -------------------- | ------------------------------------------------------------------------------------ |
| `isSuccess()`        | Whether the API call succeeded. For a verification, this does not mean the payment is paid. |
| `isCompleted()`      | Whether the payment's status is `Completed`.                                         |
| `isPending()`        | Whether the payment's status is `Pending`.                                           |
| `isCanceled()`       | Whether the payment's status is `Canceled`.                                          |
| `getTransactionId()` | The ZAMM PAY transaction ID.                                                         |
| `getPaymentUrl()`    | The checkout URL (from `createPayment()`).                                           |
| `getStatus()`        | `Pending`, `Completed` or `Canceled`. Compare it with the `Zammpay\PaymentStatus` constants. |
| `getAmount()`        | The payment amount as a decimal string, such as `"500.00"`.                          |
| `get($key, $default)`| One field of the response data.                                                      |
| `getData()`          | All the response data as an array.                                                   |
| `getMessage()`       | The error message of an unsuccessful call.                                           |
| `getErrors()`        | Field errors of a failed validation, such as `['amount' => ['...']]`.                |
| `getStatusCode()`    | The HTTP status ZAMM PAY answered with.                                              |

### Verification data

`verifyPayment()` and `verifyCallback()` return these fields through `get()` and `getData()`:

| Field          | Description                                                                 |
| -------------- | --------------------------------------------------------------------------- |
| `status`       | `Pending`, `Completed` or `Canceled`                                        |
| `amount`       | The amount the payment was created for                                      |
| `paid_amount`  | The amount the customer paid (`null` until the payment completes)           |
| `extra_amount` | Any amount paid above `amount` (`null` until the payment completes)         |
| `trx_id`       | The ZAMM PAY transaction ID                                                 |
| `method`       | The wallet the customer paid with, such as `bkash-personal`                 |
| `method_trx`   | The transaction ID from that wallet                                         |
| `domain`       | Your domain the payment belongs to                                          |
| `cus_name`, `cus_email`, `cus_number`, `cus_address` | The customer details you sent         |
| `extra`        | The `extra` data you sent                                                   |

## Errors

The library reports two kinds of failure:

- **API errors**, such as an invalid API key, a failed validation, a payment that doesn't exist or too many requests. These come back as a response where `isSuccess()` is `false`. Read the reason from `getMessage()`, `getErrors()` and `getStatusCode()`.
- **Connection errors**, such as a timeout, a DNS failure or an answer that isn't JSON. These throw `Zammpay\ZammpayException`. Its `getHttpStatus()` returns the HTTP status when ZAMM PAY did answer.

Programming mistakes throw `InvalidArgumentException`. Examples are an empty API key and an empty transaction ID.

```php
try {
    $response = $zammpay->createPayment($data);
} catch (ZammpayException $e) {
    $logger->error('ZAMM PAY unreachable', ['error' => $e->getMessage(), 'status' => $e->getHttpStatus()]);
    throw $e;
}

if (! $response->isSuccess()) {
    $logger->warning('ZAMM PAY refused the payment', [
        'message' => $response->getMessage(),
        'errors'  => $response->getErrors(),
        'status'  => $response->getStatusCode(),
    ]);
}
```

## Configuration

By default, a request may take 30 seconds in total and 10 seconds to connect. To change these limits, pass your own `CurlTransport`:

```php
use Zammpay\Http\CurlTransport;
use Zammpay\ZammpayClient;

$zammpay = new ZammpayClient(
    getenv('ZAMMPAY_API_KEY'),
    ZammpayClient::DEFAULT_BASE_URL,
    new CurlTransport(15, 5) // total timeout, connect timeout (seconds)
);
```

To send requests through your own HTTP client, or to fake them in your tests, implement `Zammpay\Http\Transport`. It has one method, `post(string $url, array $fields): HttpResponse`.

## Security checklist

- Keep your API key on your server, in an environment variable. Never put it in front-end code or commit it to Git.
- Fulfil an order only after `isCompleted()` is `true` **and** the amount matches your order.
- Use `verifyCallback()` for callbacks. Never read the status from `$_POST`.
- Make fulfilment idempotent, because callbacks and returning customers can confirm the same payment twice.
- Use HTTPS for your `success_url`, `cancel_url` and `callback_url`.

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Support

- Website: [pay.zammbd.com](https://pay.zammbd.com)
- Email: [zammtechbd@gmail.com](mailto:zammtechbd@gmail.com)
- Issues: [github.com/zamm-tech/zammpay/issues](https://github.com/zamm-tech/zammpay/issues)

## License

The MIT License. See [LICENSE](LICENSE).
