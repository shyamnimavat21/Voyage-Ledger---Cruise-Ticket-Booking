<?php
// Prevent PHP warnings/notices from distorting JSON output on free hosts
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set strict API response headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Content-Type: application/json; charset=UTF-8");

// Handle pre-flight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require __DIR__ . '/config.php'; // $host, $user, $pass, $dbname, RAZORPAY_KEY_ID/SECRET, $allowedAdmins

$conn = @new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$PRICES = [
    "Goa" => 9200, "Lakshadweep" => 27000, "Kochi" => 15400, "Malta" => 58900,
    "Istanbul" => 52300, "Marseille" => 64750, "Maldives" => 41000, "Whitsundays" => 92400
];

function generateCustomTicketID($conn) {
    $prefix = "VL-26-";
    $chars = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $attempts = 0;
    do {
        $randomCode = "";
        for ($i = 0; $i < 4; $i++) {
            $randomCode .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $candidateId = $prefix . $randomCode;
        $stmt = $conn->prepare("SELECT id FROM bookings WHERE id = ?");
        $stmt->bind_param("s", $candidateId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        $attempts++;
    } while ($exists && $attempts < 100);
    return $candidateId;
}

function razorpayRequest($method, $path, $payload = null) {
    $ch = curl_init("https://api.razorpay.com/v1/" . $path);
    curl_setopt($ch, CURLOPT_USERPWD, RAZORPAY_KEY_ID . ":" . RAZORPAY_KEY_SECRET);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return [null, "cURL error: $err"];
    }
    $decoded = json_decode($response, true);
    if ($httpCode >= 400) {
        return [null, $decoded['error']['description'] ?? "Razorpay API error ($httpCode)"];
    }
    return [$decoded, null];
}

if ($action === 'login') {

    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $role = $input['role'] ?? '';

    if ($email === '' || $password === '' || !in_array($role, ['client', 'admin'], true)) {
        http_response_code(400);
        echo json_encode(["error" => "Missing email, password, or role."]);
        exit();
    }

    $isAdminEmail = in_array(strtolower($email), array_map('strtolower', $allowedAdmins));

    if ($role === 'admin' && !$isAdminEmail) {
        http_response_code(403);
        echo json_encode(["error" => "Access Denied: You are not authorized to log into the Admin Terminal."]);
        exit();
    }

    if ($isAdminEmail) {
        $role = 'admin';
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        if (password_verify($password, $user_data['password'])) {
            if ($isAdminEmail && $user_data['role'] !== 'admin') {
                $updateRole = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
                $updateRole->bind_param("i", $user_data['id']);
                $updateRole->execute();
                $updateRole->close();
            }
            echo json_encode(["success" => true, "user" => ["username" => $email, "role" => $role]]);
        } else {
            http_response_code(401);
            echo json_encode(["error" => "Incorrect password for this profile."]);
        }
    } else {
        $targetRole = $isAdminEmail ? 'admin' : 'client';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $conn->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
        $insert->bind_param("sss", $email, $hash, $targetRole);
        if ($insert->execute()) {
            echo json_encode([
                "success" => true,
                "message" => "Account created automatically",
                "user" => ["username" => $email, "role" => $targetRole]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Failed to create account: " . $conn->error]);
        }
    }
}

elseif ($action === 'get_bookings') {

    $username = $_GET['username'] ?? '';
    $role = $_GET['role'] ?? '';

    if ($username === '' || $role === '') {
        http_response_code(400);
        echo json_encode(["error" => "Missing session parameters."]);
        exit();
    }

    $isAdminEmail = in_array(strtolower($username), array_map('strtolower', $allowedAdmins));

    if ($role === 'admin' && $isAdminEmail) {
        $result = $conn->query("SELECT * FROM bookings ORDER BY created_at DESC");
    } else {
        $stmt = $conn->prepare("SELECT * FROM bookings WHERE booked_by = ? ORDER BY created_at DESC");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
    }

    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    echo json_encode($bookings);
}

elseif ($action === 'create_order') {

    $passenger  = trim($input['passenger'] ?? '');
    $booked_by  = trim($input['bookedBy'] ?? '');
    $cabinClass = $input['cabinClass'] ?? '';
    $from       = $input['from'] ?? '';
    $to         = $input['to'] ?? '';
    $date       = $input['date'] ?? '';
    $tripType   = $input['tripType'] ?? '';

    if (!$passenger || !$booked_by || !$from || !$to || !$date) {
        http_response_code(400);
        echo json_encode(["error" => "Missing booking details."]);
        exit();
    }

    $priceAmount = $PRICES[$to] ?? 15000;
    $amountPaise = $priceAmount * 100;
    $fareString = "₹" . number_format($priceAmount);
    $cabinNum = random_int(1000, 8999) . '-' . chr(65 + random_int(0, 5));
    $codeFrom = strtoupper(substr($from, 0, 3));
    $codeTo = strtoupper(substr($to, 0, 3));

    [$order, $err] = razorpayRequest('POST', 'orders', [
        "amount" => $amountPaise,
        "currency" => "INR",
        "receipt" => "vl_" . time() . "_" . random_int(1000, 9999),
        "payment_capture" => 1
    ]);

    if ($err) {
        http_response_code(502);
        echo json_encode(["error" => "Could not create payment order: $err"]);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO pending_orders
        (razorpay_order_id, passenger, booked_by, cabin, cabin_class, from_port, code_from, to_port, code_to, departure_date, fare, amount_paise, trip_type)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param(
        "sssssssssssis",
        $order['id'], $passenger, $booked_by, $cabinNum, $cabinClass,
        $from, $codeFrom, $to, $codeTo, $date, $fareString, $amountPaise, $tripType
    );
    $stmt->execute();
    $stmt->close();

    $pay = $conn->prepare("INSERT INTO payments (razorpay_order_id, amount_paise, status) VALUES (?, ?, 'created')");
    $pay->bind_param("si", $order['id'], $amountPaise);
    $pay->execute();
    $pay->close();

    echo json_encode([
        "success" => true,
        "orderId" => $order['id'],
        "amount" => $amountPaise,
        "currency" => "INR",
        "keyId" => RAZORPAY_KEY_ID,
        "fare" => $fareString
    ]);
}

elseif ($action === 'verify_payment') {

    $orderId = $input['razorpay_order_id'] ?? '';
    $paymentId = $input['razorpay_payment_id'] ?? '';
    $signature = $input['razorpay_signature'] ?? '';

    if (!$orderId || !$paymentId || !$signature) {
        http_response_code(400);
        echo json_encode(["error" => "Missing payment verification fields."]);
        exit();
    }

    $expectedSignature = hash_hmac('sha256', $orderId . "|" . $paymentId, RAZORPAY_KEY_SECRET);
    if (!hash_equals($expectedSignature, $signature)) {
        $fail = $conn->prepare("UPDATE payments SET status = 'failed', razorpay_payment_id = ? WHERE razorpay_order_id = ?");
        $fail->bind_param("ss", $paymentId, $orderId);
        $fail->execute();

        http_response_code(400);
        echo json_encode(["error" => "Payment signature verification failed."]);
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM pending_orders WHERE razorpay_order_id = ?");
    $stmt->bind_param("s", $orderId);
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_assoc();

    if (!$pending) {
        http_response_code(404);
        echo json_encode(["error" => "No matching pending order found."]);
        exit();
    }

    $ticketId = generateCustomTicketID($conn);

    $insert = $conn->prepare("INSERT INTO bookings
        (id, passenger, booked_by, cabin, cabin_class, from_port, code_from, to_port, code_to, departure_date, fare, amount_paise, trip_type, razorpay_order_id, razorpay_payment_id, payment_status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'paid')");
    $insert->bind_param(
        "sssssssssssisss",
        $ticketId, $pending['passenger'], $pending['booked_by'], $pending['cabin'], $pending['cabin_class'],
        $pending['from_port'], $pending['code_from'], $pending['to_port'], $pending['code_to'],
        $pending['departure_date'], $pending['fare'], $pending['amount_paise'], $pending['trip_type'],
        $orderId, $paymentId
    );

    if ($insert->execute()) {
        $del = $conn->prepare("DELETE FROM pending_orders WHERE razorpay_order_id = ?");
        $del->bind_param("s", $orderId);
        $del->execute();

        $pay = $conn->prepare("UPDATE payments SET status = 'paid', razorpay_payment_id = ?, booking_id = ? WHERE razorpay_order_id = ?");
        $pay->bind_param("sss", $paymentId, $ticketId, $orderId);
        $pay->execute();

        echo json_encode(["success" => true, "ticketId" => $ticketId]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Booking creation failed: " . $conn->error]);
    }
}

elseif ($action === 'cancel_booking') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        http_response_code(400);
        echo json_encode(["error" => "Missing booking id."]);
        exit();
    }
    $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
    $stmt->bind_param("s", $id);
    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Cancellation failed: " . $conn->error]);
    }
}

else {
    http_response_code(400);
    echo json_encode(["error" => "Unknown or missing action."]);
}

$conn->close();