<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/db.php';

function mpesaSetting(string $key, string $default = ''): string
{
    global $conn;
    $stmt = $conn->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function mpesaEnvironment(): string
{
    return mpesaSetting('mpesa_environment', 'sandbox') === 'live' ? 'live' : 'sandbox';
}

function mpesaBaseUrl(): string
{
    return mpesaEnvironment() === 'live'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

function mpesaEnvironmentCredential(string $name): string
{
    $environment = mpesaEnvironment();
    $key = 'mpesa_' . $environment . '_' . $name;
    $value = mpesaSetting($key);

    // Backward compatibility for the existing sandbox credentials already saved
    // before the separate developer settings page was introduced.
    if ($value === '' && $environment === 'sandbox') {
        $legacyNames = [
            'consumer_key' => 'mpesa_consumer_key',
            'consumer_secret' => 'mpesa_consumer_secret',
            'passkey' => 'mpesa_passkey',
        ];
        $legacyKey = $legacyNames[$name] ?? '';
        if ($legacyKey !== '') {
            $value = mpesaSetting($legacyKey);
        }
    }

    return $value;
}

function mpesaAccessToken(): string
{
    $key = mpesaEnvironmentCredential('consumer_key');
    $secret = mpesaEnvironmentCredential('consumer_secret');

    if ($key === '' || $secret === '') {
        throw new RuntimeException('M-PESA ' . ucfirst(mpesaEnvironment()) . ' Consumer Key and Consumer Secret are not configured in Developer Settings.');
    }

    $ch = curl_init(mpesaBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($key . ':' . $secret)],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        throw new RuntimeException('M-PESA OAuth failed: ' . ($error ?: (string)$body));
    }

    $data = json_decode((string)$body, true);
    if (empty($data['access_token'])) {
        throw new RuntimeException('M-PESA did not return an access token.');
    }

    return (string)$data['access_token'];
}

function mpesaNormalizePhone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($phone, '0')) {
        $phone = '254' . substr($phone, 1);
    }
    if (str_starts_with($phone, '7')) {
        $phone = '254' . $phone;
    }
    return $phone;
}


function mpesaStkQuery(string $checkoutRequestId): array
{
    $checkoutRequestId=trim($checkoutRequestId);
    if($checkoutRequestId==='')throw new RuntimeException('A CheckoutRequestID is required to query an STK payment.');
    $businessType=mpesaSetting('mpesa_business_type','paybill');
    $shortcode=mpesaEnvironment()==='sandbox'?mpesaSetting('mpesa_sandbox_shortcode','174379'):mpesaSetting('mpesa_shortcode');
    $passkey=mpesaEnvironmentCredential('passkey');
    if($shortcode===''||$passkey==='')throw new RuntimeException('Configure the M-PESA shortcode and passkey before querying STK status.');
    $timestamp=date('YmdHis');
    $password=base64_encode($shortcode.$passkey.$timestamp);
    $payload=['BusinessShortCode'=>$shortcode,'Password'=>$password,'Timestamp'=>$timestamp,'CheckoutRequestID'=>$checkoutRequestId];
    $ch=curl_init(mpesaBaseUrl().'/mpesa/stkpushquery/v1/query');
    curl_setopt_array($ch,[
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.mpesaAccessToken(),'Content-Type: application/json'],
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,
    ]);
    $body=curl_exec($ch);$httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$httpCode>=400)throw new RuntimeException('M-PESA STK status query failed: '.($error?: (string)$body));
    $data=json_decode((string)$body,true);
    if(!is_array($data))throw new RuntimeException('M-PESA returned an invalid STK status response.');
    return $data;
}

function mpesaStkPush(
    float $amount,
    string $phone,
    string $accountReference,
    string $description = 'Dibonke Bookshop Payment',
    ?string $callbackOverride = null
): array {
    $environment = mpesaEnvironment();
    $businessType = mpesaSetting('mpesa_business_type', 'paybill');
    $shortcode = $environment === 'sandbox'
        ? mpesaSetting('mpesa_sandbox_shortcode', '174379')
        : mpesaSetting('mpesa_shortcode');
    $passkey = mpesaEnvironmentCredential('passkey');
    $callback = $callbackOverride ?? (
        $environment === 'sandbox'
            ? mpesaSetting('mpesa_sandbox_callback_url', mpesaSetting('mpesa_callback_url'))
            : mpesaSetting('mpesa_live_callback_url')
    );

    if ($shortcode === '' || $passkey === '' || $callback === '') {
        throw new RuntimeException('Configure the ' . ucfirst($environment) . ' shortcode, passkey and HTTPS callback URL in Developer Settings.');
    }
    if (!filter_var($callback, FILTER_VALIDATE_URL) || parse_url($callback, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('The M-PESA STK callback URL must be a valid public HTTPS URL.');
    }
    if (!in_array($businessType, ['paybill', 'till'], true)) {
        $businessType = 'paybill';
    }

    $phone = mpesaNormalizePhone($phone);
    if (!preg_match('/^2547\d{8}$/', $phone)) {
        throw new RuntimeException('Enter a valid Kenyan M-PESA phone number.');
    }
    if ($amount <= 0) {
        throw new RuntimeException('The STK Push amount must be greater than zero.');
    }

    $timestamp = date('YmdHis');
    $password = base64_encode($shortcode . $passkey . $timestamp);
    $transactionType = $businessType === 'till' ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline';

    $payload = [
        'BusinessShortCode' => $shortcode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => $transactionType,
        'Amount' => (int)round($amount),
        'PartyA' => $phone,
        'PartyB' => $shortcode,
        'PhoneNumber' => $phone,
        'CallBackURL' => $callback,
        'AccountReference' => substr($accountReference, 0, 12),
        'TransactionDesc' => substr($description, 0, 20),
    ];

    $ch = curl_init(mpesaBaseUrl() . '/mpesa/stkpush/v1/processrequest');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . mpesaAccessToken(),
            'Content-Type: application/json',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        throw new RuntimeException('M-PESA STK Push failed: ' . ($error ?: (string)$body));
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid M-PESA response.');
    }

    return $data;
}
