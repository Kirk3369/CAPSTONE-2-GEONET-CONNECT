<?php

require_once dirname(__DIR__) . "/includes/auth.php";
require_once dirname(__DIR__) . "/includes/geonet.php";

header("Content-Type: application/json; charset=utf-8");

function sms_respond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

function sms_normalize_phone($phone)
{
    $digits = preg_replace("/\D+/", "", (string) $phone);
    if (preg_match("/^09\d{9}$/", $digits)) {
        $digits = "63" . substr($digits, 1);
    } elseif (preg_match("/^9\d{9}$/", $digits)) {
        $digits = "63" . $digits;
    }
    return preg_match("/^639\d{9}$/", $digits) ? $digits : null;
}

function sms_provider_error($response)
{
    $decoded = json_decode((string) $response, true);
    if (is_array($decoded)) {
        foreach (["error", "message", "details"] as $key) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                return substr((string) $decoded[$key], 0, 500);
            }
        }
    }
    $text = substr(trim((string) $response), 0, 500);
    if ($text !== "" && preg_match("//u", $text) !== 1) {
        return "The SMS provider returned an unreadable error response.";
    }
    return $text ?: "The SMS provider rejected the request.";
}

if (!isset($_SESSION["logged_in"])) {
    sms_respond(401, ["ok" => false, "error" => "Unauthorized"]);
}
if (($_SESSION["role"] ?? "") !== "admin") {
    sms_respond(403, ["ok" => false, "error" => "Admin access required."]);
}

try {
    $config = geonet_sms_config();
} catch (Throwable $e) {
    sms_respond(500, ["ok" => false, "error" => "Database is not ready. Open install.php first."]);
}

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
if ($method === "GET") {
    $safe = $config;
    unset($safe["password"], $safe["signing_key"]);
    $safe["has_password"] = $config["password"] !== "";
    $safe["has_signing_key"] = $config["signing_key"] !== "";
    try {
        sms_respond(200, [
            "ok" => true,
            "config" => $safe,
            "recent_logs" => geonet_sms_logs(20),
        ]);
    } catch (Throwable $e) {
        sms_respond(500, ["ok" => false, "error" => "Could not load SMS settings and delivery history."]);
    }
}
if ($method !== "POST") {
    header("Allow: GET, POST");
    sms_respond(405, ["ok" => false, "error" => "Method not allowed."]);
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    $input = $_POST;
}
$sessionToken = $_SESSION["profile_csrf_token"] ?? "";
$requestToken = $input["csrfToken"] ?? "";
if ($sessionToken === "" || !is_string($requestToken) || !hash_equals($sessionToken, $requestToken)) {
    sms_respond(403, ["ok" => false, "error" => "Your session token is invalid. Refresh the page and try again."]);
}

$action = $input["action"] ?? "";
if ($action === "save_config") {
    try {
        geonet_save_sms_config($input);
        sms_respond(200, ["ok" => true, "message" => "SMS settings saved."]);
    } catch (InvalidArgumentException $e) {
        sms_respond(422, ["ok" => false, "error" => $e->getMessage()]);
    } catch (Throwable $e) {
        sms_respond(500, ["ok" => false, "error" => "Could not save SMS settings."]);
    }
}
if ($action === "clear_logs") {
    try {
        $deleted = geonet_clear_sms_logs();
        sms_respond(200, [
            "ok" => true,
            "deleted" => $deleted,
            "message" => "SMS delivery history cleared.",
        ]);
    } catch (Throwable $e) {
        sms_respond(500, ["ok" => false, "error" => "Could not clear SMS delivery history."]);
    }
}
if ($action !== "send") {
    sms_respond(400, ["ok" => false, "error" => "Unknown SMS action."]);
}
if (empty($config["enabled"])) {
    sms_respond(400, ["ok" => false, "error" => "SMS notifications are disabled in Settings."]);
}
if (empty($config["mock_mode"]) && (empty($config["username"]) || empty($config["password"]))) {
    sms_respond(400, ["ok" => false, "error" => "Add SMSGate credentials or enable mock mode before sending."]);
}
if (isset($input["expectedMock"]) && $input["expectedMock"] !== !empty($config["mock_mode"])) {
    sms_respond(409, ["ok" => false, "error" => "SMS mode changed since you opened the composer. Close it and review the current settings before sending."]);
}

$message = $input["message"] ?? null;
$direct = ($input["context"] ?? "") === "subscribers";
$outageId = $direct ? "" : ($input["outageId"] ?? null);
$audience = $direct ? "subscribers" : ($input["audience"] ?? "");
if (!is_string($message) || !is_string($outageId) || !is_string($audience)) {
    sms_respond(422, ["ok" => false, "error" => "Message, outage, and recipient group must be valid text values."]);
}
$message = trim($message);
$outageId = trim($outageId);
if (!in_array($audience, ["both", "technicians", "subscribers"], true)) {
    sms_respond(422, ["ok" => false, "error" => "Choose a valid recipient group."]);
}
if (preg_match("//u", $message) !== 1) {
    sms_respond(422, ["ok" => false, "error" => "Message contains invalid text encoding."]);
}
$messageLength = preg_match_all("/./us", $message, $messageCharacters);
if ($message === "" || $messageLength === false || $messageLength > 1000) {
    sms_respond(422, ["ok" => false, "error" => "Message is required and must be 1,000 characters or fewer."]);
}

if ($direct) {
    if ($config["mock_mode"] || !isset($input["expectedMock"]) || $input["expectedMock"] !== false) {
        sms_respond(409, ["ok" => false, "error" => "Live subscriber sending requires Mock mode off. Refresh the inbox after changing settings."]);
    }
    $ids = $input["subscriberIds"] ?? null;
    if (!is_array($ids) || !$ids || count($ids) > 1000) {
        sms_respond(422, ["ok" => false, "error" => "Select between 1 and 1,000 subscribers."]);
    }
    foreach ($ids as $id) {
        if (!is_string($id) || !preg_match('/^SUB-[0-9]{1,16}$/D', $id)) {
            sms_respond(422, ["ok" => false, "error" => "Invalid subscriber selection."]);
        }
    }
    $ids = array_values(array_unique($ids));
    try {
        $q = geonet_pdo()->prepare("SELECT public_id, full_name AS name, phone, 'subscriber' AS role FROM subscribers WHERE public_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $q->execute($ids);
        $people = $q->fetchAll();
        if (count($people) !== count($ids)) sms_respond(422, ["ok" => false, "error" => "A selected subscriber no longer exists. Refresh and try again."]);
    } catch (Throwable $e) {
        sms_respond(500, ["ok" => false, "error" => "Could not load selected subscribers."]);
    }
} else {
try {
    $payload = geonet_payload();
} catch (Throwable $e) {
    sms_respond(500, ["ok" => false, "error" => "Could not load current outage recipients."]);
}
$outage = null;
foreach ($payload["outages"] as $candidate) {
    if ((string) $candidate["id"] === $outageId && $candidate["status"] === "active") {
        $outage = $candidate;
        break;
    }
}
if ($outage === null) {
    sms_respond(422, ["ok" => false, "error" => "Choose an active outage before sending an SMS report."]);
}

$people = [];
if ($audience === "both" || $audience === "technicians") {
    $assignedIds = [];
    foreach ($payload["jobs"] as $job) {
        if ((string) $job["outageId"] === $outageId && $job["status"] !== "resolved") {
            $assignedIds[] = (string) $job["technicianId"];
        }
    }
    $technicians = array_values(array_filter($payload["technicians"], function ($technician) use ($assignedIds) {
        return $assignedIds
            ? in_array((string) $technician["id"], $assignedIds, true)
            : $technician["availability"] !== "off_duty";
    }));
    foreach ($technicians as $technician) {
        $people[] = [
            "name" => $technician["name"],
            "phone" => $technician["phone"],
            "role" => "technician",
        ];
    }
}
if ($audience === "both" || $audience === "subscribers") {
    foreach ($outage["subscribers"] as $subscriberId) {
        foreach ($payload["subscribers"] as $subscriber) {
            if ((string) $subscriber["id"] === (string) $subscriberId) {
                $people[] = [
                    "name" => $subscriber["name"],
                    "phone" => $subscriber["phone"],
                    "role" => "subscriber",
                ];
                break;
            }
        }
    }
}

}

$recipients = [];
$seenNumbers = [];
$skipped = 0;
foreach ($people as $person) {
    $number = sms_normalize_phone($person["phone"] ?? "");
    if ($number === null) {
        $skipped++;
        continue;
    }
    if (isset($seenNumbers[$number])) {
        continue;
    }
    $seenNumbers[$number] = true;
    $recipients[] = [
        "name" => (string) $person["name"],
        "role" => $person["role"],
        "phone" => $number,
    ];
}
if (count($recipients) === 0) {
    sms_respond(422, [
        "ok" => false,
        "error" => $skipped
            ? "No selected recipients have a valid Philippine mobile number."
            : "No recipients are available for this outage and recipient group.",
        "skipped" => $skipped,
    ]);
}
if (count($recipients) > 1000) {
    sms_respond(422, [
        "ok" => false,
        "error" => "This audience exceeds the 1,000-recipient safety limit. Narrow the recipient group before sending.",
    ]);
}

$useMock = !empty($config["mock_mode"]);
$providerResults = [];
$requestError = null;
$ambiguous = false;
if ($useMock) {
    foreach ($recipients as $recipient) {
        $providerResults[$recipient["phone"]] = ["status" => "logged", "note" => "Mock mode — no carrier send."];
    }
} else {
    if (!function_exists("curl_init")) {
        sms_respond(500, ["ok" => false, "error" => "The PHP cURL extension is required for live SMS sending."]);
    }
    $request = [
        "textMessage" => ["text" => $message],
        "phoneNumbers" => array_map(fn($r) => "+" . $r["phone"], $recipients),
    ];
    if ($config["device_id"] !== "") $request["deviceId"] = $config["device_id"];
    $ch = curl_init("https://api.sms-gate.app/3rdparty/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $config["username"] . ":" . $config["password"],
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
        CURLOPT_POSTFIELDS => json_encode($request),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        $requestError = "No response from SMSGate. Check the dashboard before retrying to avoid duplicates.";
        $ambiguous = true;
    } elseif ($httpCode < 200 || $httpCode >= 300) {
        $requestError = "SMSGate returned HTTP {$httpCode}. Check credentials, device status and dashboard before retrying.";
        $ambiguous = $httpCode >= 500 || $httpCode === 408;
    } else {
        $providerMessage = json_decode($response, true);
        if (!is_array($providerMessage) || !is_string($providerMessage["id"] ?? null) || $providerMessage["id"] === "") {
            $requestError = "SMSGate returned no message ID. Check its dashboard before retrying.";
            $ambiguous = true;
        } else {
            foreach ($recipients as $recipient) {
                $providerResults[$recipient["phone"]] = [
                    "status" => "queued",
                    "note" => "SMSGate message ID: " . substr($providerMessage["id"], 0, 100) . ". Queued; check the SMSGate dashboard for delivery.",
                ];
            }
        }
    }
}

$results = [];
foreach ($recipients as $recipient) {
    $providerResult = $providerResults[$recipient["phone"]] ?? [
        "status" => $ambiguous ? "unknown" : "failed",
        "note" => $requestError ?: "The provider did not return a result for this recipient.",
    ];
    $entry = [
        "id" => bin2hex(random_bytes(8)),
        "at" => date("c"),
        "name" => $recipient["name"],
        "role" => $recipient["role"],
        "phone" => $recipient["phone"],
        "context" => $direct ? "subscribers" : "outage",
        "outageId" => $direct ? null : $outageId,
        "message" => $message,
        "status" => $providerResult["status"],
        "provider" => $useMock ? "mock" : $config["provider"],
        "note" => $providerResult["note"] ?? null,
    ];
    try {
        geonet_log_sms($entry);
    } catch (Throwable $e) {
        sms_respond(500, [
            "ok" => false,
            "error" => "An SMS may have been submitted, but its result could not be saved to the log. Check SMSGate before retrying.",
        ]);
    }
    $results[] = [
        "name" => $entry["name"],
        "role" => $entry["role"],
        "phone" => "••••" . substr($entry["phone"], -4),
        "status" => $entry["status"],
        "note" => $entry["note"],
    ];
}

$counts = array_count_values(array_column($results, "status"));
$accepted = ($counts["queued"] ?? 0) + ($counts["pending"] ?? 0) + ($counts["sent"] ?? 0);
$failed = ($counts["failed"] ?? 0);
$unknown = ($counts["unknown"] ?? 0);
$messageText = $useMock
    ? count($results) . " SMS message(s) logged in mock mode; none were sent to a carrier."
    : "{$accepted} message(s) accepted by SMSGate, {$failed} failed, {$unknown} with unknown status. Delivery may still be pending.";
if ($skipped > 0) {
    $messageText .= " {$skipped} recipient(s) with invalid phone numbers were skipped.";
}
sms_respond(200, [
    "ok" => true,
    "mock" => $useMock,
    "sent" => $accepted,
    "failed" => $failed,
    "unknown" => $unknown,
    "skipped" => $skipped,
    "results" => $results,
    "message" => $messageText,
]);
