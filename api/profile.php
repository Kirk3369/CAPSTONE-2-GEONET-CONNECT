<?php

require_once dirname(__DIR__) . "/includes/auth.php";
require_once dirname(__DIR__) . "/includes/geonet.php";

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["logged_in"], $_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "Sign in to update your profile."]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (
    !isset($_SESSION["profile_csrf_token"], $input["csrfToken"]) ||
    !is_string($input["csrfToken"]) ||
    !hash_equals($_SESSION["profile_csrf_token"], $input["csrfToken"])
) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Refresh the profile page and try again."]);
    exit();
}

try {
    if (($input["action"] ?? "") === "request_plan_change") {
        if (($_SESSION["role"] ?? "") !== "subscriber") {
            http_response_code(403);
            echo json_encode(["ok" => false, "error" => "Subscriber access required."]);
            exit();
        }
        $request = geonet_request_own_plan_change(
            $_SESSION["user_id"],
            $input["plan"] ?? "",
            $input["connectionId"] ?? null
        );
        echo json_encode([
            "ok" => true,
            "message" => "Your plan change request was sent for admin approval.",
            "request" => $request,
        ]);
        exit();
    }
    if (($input["action"] ?? "") === "request_additional_connection") {
        if (($_SESSION["role"] ?? "") !== "subscriber") {
            http_response_code(403);
            echo json_encode(["ok" => false, "error" => "Subscriber access required."]);
            exit();
        }
        geonet_create_additional_connection_request($_SESSION["user_id"], $input);
        echo json_encode([
            "ok" => true,
            "message" => "Your additional connection request was sent for admin and technician review.",
        ]);
        exit();
    }
    if (($input["action"] ?? "") !== "update_profile") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Unknown action."]);
        exit();
    }
    $profile = geonet_update_own_profile($_SESSION["user_id"], $input);
    $_SESSION["display_name"] = $profile["name"];
    $_SESSION["username"] = $profile["email"];
    echo json_encode([
        "ok" => true,
        "message" => "Profile updated.",
        "profile" => $profile,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Could not update your profile."]);
}
