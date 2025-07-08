<?php
$rootPath = filter_input(INPUT_SERVER, 'DOCUMENT_ROOT', FILTER_SANITIZE_STRING);
$PHP_SELF = filter_input(INPUT_SERVER, 'PHP_SELF', FILTER_SANITIZE_STRING);
ini_set('error_log', 'error_log');
$Pathfile = dirname(dirname($PHP_SELF, 2));
$Pathfiles = $rootPath.$Pathfile;
require_once $Pathfiles.'/config.php';
require_once $Pathfiles.'/functions.php';
require_once $Pathfiles.'/text.php';

$amount = htmlspecialchars($_GET['price'], ENT_QUOTES, 'UTF-8');
$invoice_id = htmlspecialchars($_GET['order_id'], ENT_QUOTES, 'UTF-8'); // Will be used for description or metadata

// Fetch Zarinpal Merchant ID from PaySetting table (assuming it will be stored there)
// IMPORTANT: Replace "merchant_id_zarinpal" with the actual NamePay value once decided
$merchant_id = select("PaySetting", "ValuePay", "NamePay", "merchant_id_zarinpal", "select")['ValuePay'];
if (empty($merchant_id)) {
    echo "Error: Zarinpal Merchant ID not configured.";
    // Log this error for the admin
    error_log("Zarinpal Merchant ID not found in PaySetting table for NamePay='merchant_id_zarinpal'");
    return;
}

$checkprice = select("Payment_report", "price", "id_order", $invoice_id, "select")['price'];
$user_details = select("Payment_report", "id_user", "id_order", $invoice_id, "select"); // Get user_id to fetch email/mobile
$user_info = [];
if ($user_details && isset($user_details['id_user'])) {
    $user_contact_info = select("user", "phone, email", "id", $user_details['id_user'], "select");
    if ($user_contact_info) {
        $user_info['mobile'] = $user_contact_info['phone'] ?? '';
        $user_info['email'] = $user_contact_info['email'] ?? '';
    }
}


if ($checkprice != $amount) {
    echo $textbotlang['users']['moeny']['invalidprice'];
    return;
}

// Zarinpal API uses Toman, ensure amount is correct. If it's already in Toman, no change.
// If it's in Rial, divide by 10. For now, assuming $amount is in Toman as per Zarinpal docs.
// $amount_toman = $amount; // Or $amount / 10 if $amount is in Rial

$callback_url = $domainhosts . "/payment/zarinpal/back.php?order_id=" . $invoice_id; // Pass order_id for context in callback
$description = "Payment for Order ID: " . $invoice_id;

$data = [
    'merchant_id' => $merchant_id,
    'amount'      => $amount, // Amount in Toman
    'callback_url'=> $callback_url,
    'description' => $description,
    'metadata'    => [
        // Add mobile and email if available, otherwise Zarinpal might make them mandatory on their page
         'mobile' => $user_info['mobile'] ?? '', // Optional: user's mobile number
         'email'  => $user_info['email'] ?? '',  // Optional: user's email
    ],
    // 'currency' => 'IRT', // Optional: Zarinpal defaults to IRT if not specified
];

$data_json = json_encode($data);

$ch = curl_init('https://payment.zarinpal.com/pg/v4/payment/request.json');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Content-Length: ' . strlen($data_json)
]);

$result_json = curl_exec($ch);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    echo "cURL Error: " . $curl_error;
    error_log("Zarinpal request cURL Error for order_id $invoice_id: $curl_error");
    return;
}

$result = json_decode($result_json);

if (isset($result->data) && !empty($result->data) && $result->data->code == 100) {
    // Save authority to database associated with order_id for verification step
    update("Payment_report", "authority", $result->data->authority, "id_order", $invoice_id);
    header('Location: https://payment.zarinpal.com/pg/StartPay/' . $result->data->authority);
    exit;
} else {
    $error_message = "Payment request failed.";
    if (!empty($result->errors)) {
        // Zarinpal errors can be an array or object. Let's try to get a message.
        $error_details = "";
        if (is_array($result->errors) && !empty($result->errors)) {
            $first_error = reset($result->errors);
            $error_details = "Code: " . ($first_error->code ?? 'N/A') . ", Message: " . ($first_error->message ?? 'Unknown error');
        } elseif (is_object($result->errors) && isset($result->errors->code) && isset($result->errors->message)) {
             $error_details = "Code: " . $result->errors->code . ", Message: " . $result->errors->message;
        } elseif (is_string($result->errors)) {
            $error_details = $result->errors;
        } else if (isset($result->data) && isset($result->data->message)) {
            // Sometimes error is in data->message when data->code is not 100
            $error_details = "Code: " . ($result->data->code ?? 'N/A') . ", Message: " . $result->data->message;
        }
        $error_message .= " Details: " . $error_details;
        error_log("Zarinpal request error for order_id $invoice_id: " . $error_details . " | Full Response: " . $result_json);
    } else {
        error_log("Zarinpal request failed for order_id $invoice_id with no specific error details. Response: " . $result_json);
    }
    echo $error_message;
    // Consider a more user-friendly error page or message
}
?>
