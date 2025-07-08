<?php
$rootPath = filter_input(INPUT_SERVER, 'DOCUMENT_ROOT', FILTER_SANITIZE_STRING);
$PHP_SELF = filter_input(INPUT_SERVER, 'PHP_SELF', FILTER_SANITIZE_STRING);
$Pathfile = dirname(dirname($PHP_SELF, 2));
$Pathfiles = $rootPath . $Pathfile;

require_once $Pathfiles . '/config.php';
require_once $Pathfiles . '/jdf.php'; // For date formatting
require_once $Pathfiles . '/botapi.php'; // For Telegram notifications
require_once $Pathfiles . '/functions.php';
require_once $Pathfiles . '/panels.php'; // May not be needed here, review usage
require_once $Pathfiles . '/text.php';

// Zarinpal sends Authority and Status via GET parameters to the callback_url
$authority = htmlspecialchars($_GET['Authority'] ?? '', ENT_QUOTES, 'UTF-8');
$status = htmlspecialchars($_GET['Status'] ?? '', ENT_QUOTES, 'UTF-8');
$invoice_id = htmlspecialchars($_GET['order_id'] ?? '', ENT_QUOTES, 'UTF-8'); // Retrieved from callback_url query param

$payment_status_message = ""; // User-facing status message
$detailed_status_message = ""; // Additional details for the user
$ref_id_display = ""; // To display Zarinpal's ref_id

// Fetch Zarinpal Merchant ID
$merchant_id = select("PaySetting", "ValuePay", "NamePay", "merchant_id_zarinpal", "select")['ValuePay'];
if (empty($merchant_id)) {
    $payment_status_message = "خطا: مرچنت کد زرین پال تنظیم نشده است.";
    error_log("Zarinpal Merchant ID not found in PaySetting table for NamePay='merchant_id_zarinpal' during callback for order_id: " . $invoice_id);
    // Display error and exit or redirect to an error page
} else if (empty($authority) || empty($invoice_id)) {
    $payment_status_message = $textbotlang['users']['moeny']['payment_failed']; // Or a more specific error
    error_log("Zarinpal callback: Missing Authority or order_id. Authority: $authority, Order ID: $invoice_id");
} else if ($status == 'OK') { // Payment was successful from user's perspective, now verify
    $Payment_report = select("Payment_report", "*", "id_order", $invoice_id, "select");
    $price = $Payment_report['price'] ?? 0;
    $db_authority = $Payment_report['authority'] ?? '';

    // Basic check: does the authority from callback match the one we stored?
    // This is a weak check, primary validation is the API call.
    if ($db_authority != $authority) {
        $payment_status_message = $textbotlang['users']['moeny']['payment_failed'];
        $detailed_status_message = "عدم تطابق اطلاعات پرداخت."; // "Payment information mismatch."
        error_log("Zarinpal callback: Authority mismatch for order_id $invoice_id. DB: $db_authority, GET: $authority");
    } else if ($price > 0) {
        $data = [
            'merchant_id' => $merchant_id,
            'amount'      => $price, // Amount in Toman
            'authority'   => $authority,
        ];
        $data_json = json_encode($data);

        $ch = curl_init('https://payment.zarinpal.com/pg/v4/payment/verify.json');
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
            $payment_status_message = $textbotlang['users']['moeny']['payment_error_occurred'];
            $detailed_status_message = "خطا در ارتباط با زرین پال برای تایید پرداخت.";
            error_log("Zarinpal verification cURL Error for order_id $invoice_id, authority $authority: $curl_error");
        } else {
            $result = json_decode($result_json);
            if (isset($result->data) && !empty($result->data) && ($result->data->code == 100 || $result->data->code == 101)) {
                // Code 100: Verified
                // Code 101: Previously Verified (treat as success if order not already processed)
                $payment_status_message = $textbotlang['users']['moeny']['payment_success'];
                $detailed_status_message = $textbotlang['users']['moeny']['payment_success_dec'];
                $ref_id_display = $result->data->ref_id;
                update("Payment_report", "ref_id", $ref_id_display, "id_order", $invoice_id);


                if ($Payment_report['payment_Status'] != "paid") {
                    DirectPayment($Payment_report['id_order']); // Process the order
                    update("user", "Processing_value", "0", "id", $Payment_report['id_user']);
                    update("user", "Processing_value_one", "0", "id", $Payment_report['id_user']);
                    update("user", "Processing_value_tow", "0", "id", $Payment_report['id_user']);
                    update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);

                    $setting = select("setting", "*"); // Fetch general settings
                    if (isset($setting['Channel_Report']) && strlen($setting['Channel_Report']) > 0) {
                        // Assuming a similar report message format, or create a new one for Zarinpal
                        sendmessage($setting['Channel_Report'], sprintf($textbotlang['Admin']['Report']['zarinpal_success'] ?? "پرداخت موفق زرین پال برای کاربر %s مبلغ %s تومان، شماره پیگیری: %s", $Payment_report['id_user'], $price, $ref_id_display), null, 'HTML');
                    }
                } else {
                     // Already paid, but verification is successful (code 101 or reprocessing)
                    $detailed_status_message = $textbotlang['users']['moeny']['payment_already_verified'] ?? "این پرداخت قبلا تایید شده است.";
                }
            } else {
                // Verification failed
                $payment_status_message = $textbotlang['users']['moeny']['payment_failed'];
                $error_code = $result->errors->code ?? ($result->data->code ?? 'Unknown');
                $error_msg_zarinpal = $result->errors->message ?? ($result->data->message ?? 'خطای نامشخص از زرین پال');
                $detailed_status_message = "علت: " . $error_msg_zarinpal . " (کد خطا: " . $error_code . ")";
                error_log("Zarinpal verification failed for order_id $invoice_id, authority $authority. Code: $error_code, Message: $error_msg_zarinpal. Full Response: " . $result_json);
                update("Payment_report", "payment_Status", "failed", "id_order", $invoice_id); // Mark as failed
            }
        }
    } else {
        $payment_status_message = $textbotlang['users']['moeny']['payment_failed'];
        $detailed_status_message = "اطلاعات سفارش یافت نشد یا مبلغ صفر است.";
        error_log("Zarinpal callback: Order details not found or price is zero for order_id $invoice_id.");
    }
} else { // Status is 'NOK' or something else
    $payment_status_message = $textbotlang['users']['moeny']['payment_cancelled_by_user'];
    $detailed_status_message = "پرداخت توسط کاربر لغو شده یا در انجام آن با خطا مواجه شده است.";
    error_log("Zarinpal callback: Payment status was '$status' for order_id $invoice_id, authority $authority.");
    update("Payment_report", "payment_Status", "cancelled", "id_order", $invoice_id); // Mark as cancelled
}

// HTML for displaying the result to the user
// This part is similar to aqayepardakht's back.php
$display_price = select("Payment_report", "price", "id_order", $invoice_id, "select")['price'] ?? $price ?? 0;
?>
<html>
<head>
    <title><?php echo $textbotlang['users']['moeny']['invoice_title']; ?></title>
    <style>
        @font-face {
            font-family: 'vazir';
            src: url('../../../../fonts/Vazir.eot'); /* Adjusted path */
            src: local('☺'), url('../../../../fonts/Vazir.woff') format('woff'), url('../../../../fonts/Vazir.ttf') format('truetype'); /* Adjusted path */
        }
        body {
            font-family: vazir, sans-serif;
            background-color: #f2f2f2;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            direction: rtl;
            text-align: center;
        }
        .confirmation-box {
            background-color: #ffffff;
            border-radius: 8px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }
        h1 {
            color: #333333;
            margin-bottom: 20px;
            font-size: 1.5em;
        }
        p {
            color: #666666;
            margin-bottom: 10px;
            font-size: 0.9em;
        }
        .btn {
            display: inline-block; /* Changed to inline-block for better centering if needed */
            margin-top: 20px;
            padding: 12px 25px;
            background-color: #4CAF50; /* Green */
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 1em;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background-color: #45a049;
        }
        .ref-id {
            font-weight: bold;
            color: #333;
        }
    </style>
</head>
<body>
<div class="confirmation-box">
    <h1><?php echo $payment_status_message; ?></h1>
    <p><?php echo $textbotlang['users']['moeny']['transaction_number']; ?><span><?php echo htmlspecialchars($invoice_id, ENT_QUOTES, 'UTF-8'); ?></span></p>
    <?php if (!empty($ref_id_display)): ?>
        <p><?php echo $textbotlang['users']['moeny']['tracking_code_gateway'] ?? "شماره پیگیری زرین پال:"; ?> <span class="ref-id"><?php echo htmlspecialchars($ref_id_display, ENT_QUOTES, 'UTF-8'); ?></span></p>
    <?php endif; ?>
    <p><?php echo $textbotlang['users']['moeny']['payment_amount']; ?> <span><?php echo htmlspecialchars($display_price, ENT_QUOTES, 'UTF-8'); ?></span> <?php echo $textbotlang['users']['moeny']['currency']; ?></p>
    <p><?php echo $textbotlang['users']['moeny']['date_label']; ?> <span><?php echo jdate('Y/m/d H:i:s'); ?></span></p>
    <?php if (!empty($detailed_status_message)): ?>
        <p><?php echo $detailed_status_message; ?></p>
    <?php endif; ?>
    <a class="btn" href="https://t.me/<?php echo htmlspecialchars($usernamebot ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo $textbotlang['users']['moeny']['back_to_bot']; ?></a>
</div>
</body>
</html>
