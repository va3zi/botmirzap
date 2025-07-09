<?php
// --- Database Configuration ---
//
// IMPORTANT:
// 1. Fill in your actual Zarinpal Merchant ID below.
// 2. Run this script ONCE from your web browser.
// 3. DELETE THIS SCRIPT IMMEDIATELY AFTER RUNNING IT FOR SECURITY.
//
$db_host = 'localhost'; // Usually 'localhost'
$db_username = 'pDGsWpzw';
$db_password = 'hsGDX4Zz';
$db_name = 'mirzabot';

$zarinpal_merchant_id_to_set = 'YOUR_ZARINPAL_MERCHANT_ID_HERE'; // ***** EDIT THIS LINE *****

// --- End of Configuration ---

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Zarinpal DB Setup</title>";
echo "<style>body { font-family: sans-serif; line-height: 1.6; padding: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; }</style>";
echo "</head><body><h1>Zarinpal Database Setup Script</h1>";

// Connect to MySQL
$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

// Check connection
if ($conn->connect_error) {
    die("<p class='error'>Connection failed: " . htmlspecialchars($conn->connect_error) . "</p></body></html>");
}
echo "<p class='success'>Successfully connected to the database.</p>";
$conn->set_charset("utf8mb4");

// --- Step 1: Update Payment_report table ---
echo "<h2>Step 1: Updating 'Payment_report' table...</h2>";

// Check if 'authority' column exists
$result = $conn->query("SHOW COLUMNS FROM `Payment_report` LIKE 'authority'");
if ($result->num_rows == 0) {
    $sql_add_authority = "ALTER TABLE `Payment_report` ADD `authority` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Zarinpal Authority Token' AFTER `payment_Status`;";
    if ($conn->query($sql_add_authority) === TRUE) {
        echo "<p class='success'>Column 'authority' added successfully to 'Payment_report' table.</p>";
    } else {
        echo "<p class='error'>Error adding 'authority' column: " . htmlspecialchars($conn->error) . "</p>";
    }
} else {
    echo "<p class='info'>Column 'authority' already exists in 'Payment_report' table.</p>";
}

// Check if 'ref_id' column exists
$result = $conn->query("SHOW COLUMNS FROM `Payment_report` LIKE 'ref_id'");
if ($result->num_rows == 0) {
    $sql_add_ref_id = "ALTER TABLE `Payment_report` ADD `ref_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Zarinpal Reference ID' AFTER `authority`;";
    if ($conn->query($sql_add_ref_id) === TRUE) {
        echo "<p class='success'>Column 'ref_id' added successfully to 'Payment_report' table.</p>";
    } else {
        echo "<p class='error'>Error adding 'ref_id' column: " . htmlspecialchars($conn->error) . "</p>";
    }
} else {
    echo "<p class='info'>Column 'ref_id' already exists in 'Payment_report' table.</p>";
}

// --- Step 2: Update PaySetting table for Zarinpal ---
echo "<h2>Step 2: Updating 'PaySetting' table for Zarinpal...</h2>";

// Zarinpal Merchant ID
$name_pay_merchant = 'merchant_id_zarinpal';
$stmt_check = $conn->prepare("SELECT ValuePay FROM PaySetting WHERE NamePay = ?");
$stmt_check->bind_param("s", $name_pay_merchant);
$stmt_check->execute();
$result_check = $stmt_check->get_result();

if ($result_check->num_rows > 0) {
    // Row exists, update it
    $stmt_update = $conn->prepare("UPDATE PaySetting SET ValuePay = ? WHERE NamePay = ?");
    $stmt_update->bind_param("ss", $zarinpal_merchant_id_to_set, $name_pay_merchant);
    if ($stmt_update->execute()) {
        echo "<p class='success'>Row for '$name_pay_merchant' updated successfully. Set to: '" . htmlspecialchars($zarinpal_merchant_id_to_set) . "'</p>";
    } else {
        echo "<p class='error'>Error updating row for '$name_pay_merchant': " . htmlspecialchars($stmt_update->error) . "</p>";
    }
    $stmt_update->close();
} else {
    // Row does not exist, insert it
    $stmt_insert = $conn->prepare("INSERT INTO PaySetting (NamePay, ValuePay) VALUES (?, ?)");
    $stmt_insert->bind_param("ss", $name_pay_merchant, $zarinpal_merchant_id_to_set);
    if ($stmt_insert->execute()) {
        echo "<p class='success'>Row for '$name_pay_merchant' inserted successfully. Set to: '" . htmlspecialchars($zarinpal_merchant_id_to_set) . "'</p>";
    } else {
        echo "<p class='error'>Error inserting row for '$name_pay_merchant': " . htmlspecialchars($stmt_insert->error) . "</p>";
    }
    $stmt_insert->close();
}
$stmt_check->close();

if ($zarinpal_merchant_id_to_set === 'YOUR_ZARINPAL_MERCHANT_ID_HERE') {
    echo "<p class='error' style='font-weight:bold; border: 2px solid red; padding: 10px;'>IMPORTANT: You have not set your actual Zarinpal Merchant ID in this script. Please edit the script, replace 'YOUR_ZARINPAL_MERCHANT_ID_HERE' with your real Merchant ID, and run the script again.</p>";
}


// Zarinpal Status
$name_pay_status = 'statuszarinpal';
$value_pay_status_default = 'onzarinpal'; // Default to 'on'

$stmt_check_status = $conn->prepare("SELECT ValuePay FROM PaySetting WHERE NamePay = ?");
$stmt_check_status->bind_param("s", $name_pay_status);
$stmt_check_status->execute();
$result_check_status = $stmt_check_status->get_result();

if ($result_check_status->num_rows > 0) {
    echo "<p class='info'>Row for '$name_pay_status' already exists in 'PaySetting' table.</p>";
    // You could choose to update it here if you want to force a specific status, e.g., to 'onzarinpal'
    // $stmt_update_status = $conn->prepare("UPDATE PaySetting SET ValuePay = ? WHERE NamePay = ?");
    // $stmt_update_status->bind_param("ss", $value_pay_status_default, $name_pay_status);
    // if ($stmt_update_status->execute()) {
    //     echo "<p class='success'>Row for '$name_pay_status' ensured to be '$value_pay_status_default'.</p>";
    // }
    // $stmt_update_status->close();
} else {
    $stmt_insert_status = $conn->prepare("INSERT INTO PaySetting (NamePay, ValuePay) VALUES (?, ?)");
    $stmt_insert_status->bind_param("ss", $name_pay_status, $value_pay_status_default);
    if ($stmt_insert_status->execute()) {
        echo "<p class='success'>Row for '$name_pay_status' inserted successfully with value '$value_pay_status_default'.</p>";
    } else {
        echo "<p class='error'>Error inserting row for '$name_pay_status': " . htmlspecialchars($stmt_insert_status->error) . "</p>";
    }
    $stmt_insert_status->close();
}
$stmt_check_status->close();


echo "<h2>Setup Complete!</h2>";
echo "<p style='font-weight:bold; color:red;'>VERY IMPORTANT: DELETE THIS SCRIPT FROM YOUR SERVER NOW!</p>";
echo "</body></html>";

$conn->close();
?>
