<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function sms_login_registration_config()
{
    return [
        "name" => "SMS Login & Registration",
        "description" => "OTP login and registration with mobile number using configurable SMS providers.",
        "version" => "1.1.3",
        "author" => "Nimafire",
        "language" => "english",
        "fields" => []
    ];
}

function smslr_install_schema()
{
    $schema = Capsule::schema();
    if (!$schema->hasTable("mod_smslr_settings")) {
        $schema->create("mod_smslr_settings", function ($table) {
            $table->increments("id");
            $table->string("setting_key", 100)->unique();
            $table->text("setting_value")->nullable();
        });
    }
    if (!$schema->hasTable("mod_smslr_otps")) {
        $schema->create("mod_smslr_otps", function ($table) {
            $table->bigIncrements("id");
            $table->string("phone", 32)->index();
            $table->string("purpose", 20);
            $table->string("code_hash", 255);
            $table->dateTime("expires_at");
            $table->dateTime("last_sent_at")->nullable();
            $table->unsignedInteger("attempts")->default(0);
            $table->unsignedInteger("resends")->default(0);
            $table->string("ip_address", 45)->nullable();
            $table->text("payload")->nullable();
            $table->string("status", 20)->default("pending");
            $table->dateTime("created_at");
        });
    }
    if (!$schema->hasTable("mod_smslr_logs")) {
        $schema->create("mod_smslr_logs", function ($table) {
            $table->bigIncrements("id");
            $table->string("phone", 32)->nullable();
            $table->string("event", 40);
            $table->string("provider", 40)->nullable();
            $table->string("status", 30);
            $table->string("ip_address", 45)->nullable();
            $table->text("message")->nullable();
            $table->text("response")->nullable();
            $table->dateTime("created_at");
        });
    }
}

function smslr_defaults()
{
    return [
        "enabled" => "0",
        "mode" => "both",
        "provider" => "elanak",
        "wsdl_url" => "https://payammatni.com/webservice/url/send.php",
        "username" => "",
        "password" => "",
        "sender" => "",
        "sms_type" => "0",
        "mobile_field_id" => "0",
        "to_format" => "09",
        "otp_length" => "6",
        "otp_expiry" => "5",
        "resend_interval" => "60",
        "max_attempts" => "5",
        "max_resends" => "3",
        "daily_limit" => "10",
        "ip_hourly_limit" => "20",
        "otp_message" => "Your verification code is: {code}",
        "registration_password_length" => "32"
    ];
}

function sms_login_registration_activate()
{
    try {
        smslr_install_schema();
        foreach (smslr_defaults() as $key => $value) {
            if (!Capsule::table("mod_smslr_settings")->where("setting_key", $key)->exists()) {
                Capsule::table("mod_smslr_settings")->insert(["setting_key" => $key, "setting_value" => $value]);
            }
        }
        return ["status" => "success", "description" => "SMS Login & Registration activated."];
    } catch (\Throwable $e) {
        return ["status" => "error", "description" => $e->getMessage()];
    }
}

function sms_login_registration_deactivate()
{
    return ["status" => "success", "description" => "The module is disabled. Data and logs have been preserved."];
}

function sms_login_registration_upgrade($vars)
{
    try {
        smslr_install_schema();
    } catch (\Throwable $e) {
    }
}

function smslr_settings()
{
    $settings = smslr_defaults();
    try {
        foreach (Capsule::table("mod_smslr_settings")->get() as $row) {
            $settings[$row->setting_key] = (string) $row->setting_value;
        }
    } catch (\Throwable $e) {
    }
    return $settings;
}

function smslr_cc_hash()
{
    if (!empty($GLOBALS["cc_encryption_hash"])) {
        return (string) $GLOBALS["cc_encryption_hash"];
    }
    if (defined("ROOTDIR") && is_readable(ROOTDIR . "/configuration.php")) {
        $source = (string) file_get_contents(ROOTDIR . "/configuration.php");
        if (preg_match('/\$cc_encryption_hash\s*=\s*([\'"])(.*?)\1\s*;/s', $source, $match)) {
            return $match[2];
        }
    }
    return "";
}

function smslr_keys()
{
    $hash = smslr_cc_hash();
    if ($hash === "") {
        return [];
    }
    return [hash("sha256", "smslr|" . $hash, true), hash("sha256", $hash, true)];
}

function smslr_encrypt($value)
{
    if ($value === "") {
        return "";
    }
    $keys = smslr_keys();
    if (!$keys) {
        throw new RuntimeException("Encryption key is not available.");
    }
    $iv = random_bytes(12);
    $tag = "";
    $cipher = openssl_encrypt($value, "aes-256-gcm", $keys[0], OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException("Encryption failed.");
    }
    return base64_encode($iv . $tag . $cipher);
}

function smslr_decrypt($value)
{
    if (!$value) {
        return "";
    }
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) < 29) {
        return "";
    }
    foreach (smslr_keys() as $key) {
        $plain = openssl_decrypt(substr($raw, 28), "aes-256-gcm", $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain !== false) {
            return (string) $plain;
        }
    }
    return "";
}

function smslr_save_settings($input)
{
    $ranges = [
        "otp_length" => [4, 8],
        "otp_expiry" => [1, 60],
        "resend_interval" => [10, 3600],
        "max_attempts" => [1, 20],
        "max_resends" => [0, 20],
        "daily_limit" => [1, 200],
        "ip_hourly_limit" => [1, 500]
    ];
    $plain = ["enabled", "mode", "provider", "wsdl_url", "username", "sender", "sms_type", "to_format", "mobile_field_id", "otp_message"];
    $keys = array_merge($plain, array_keys($ranges), ["password"]);
    foreach ($keys as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = trim((string) $input[$key]);
        if ($key === "password") {
            if ($value === "") {
                continue;
            }
            $value = smslr_encrypt($value);
        } elseif (isset($ranges[$key])) {
            $value = (string) max($ranges[$key][0], min($ranges[$key][1], (int) $value));
        } elseif ($key === "enabled") {
            $value = $value === "1" ? "1" : "0";
        } elseif ($key === "mode") {
            $value = in_array($value, ["login", "register", "both"], true) ? $value : "both";
        } elseif ($key === "sms_type") {
            $value = $value === "1" ? "1" : "0";
        } elseif ($key === "to_format") {
            $value = in_array($value, ["09", "+98", "98"], true) ? $value : "09";
        } elseif ($key === "provider") {
            $value = function_exists("smslr_provider_" . $value . "_send") ? $value : "elanak";
        } elseif ($key === "mobile_field_id") {
            $value = (string) max(0, (int) $value);
        } elseif ($key === "wsdl_url") {
            if (!filter_var($value, FILTER_VALIDATE_URL) || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== "https") {
                continue;
            }
        } elseif ($key === "otp_message") {
            if ($value === "") {
                continue;
            }
            if (strpos($value, "{code}") === false) {
                $value .= " {code}";
            }
        }
        Capsule::table("mod_smslr_settings")->updateOrInsert(["setting_key" => $key], ["setting_value" => $value]);
    }
}

function smslr_ip()
{
    try {
        if (class_exists("\\App") && method_exists("\\App", "getRemoteIp")) {
            $ip = (string) \App::getRemoteIp();
            if ($ip !== "") {
                return $ip;
            }
        }
    } catch (\Throwable $e) {
    }
    return (string) ($_SERVER["REMOTE_ADDR"] ?? "");
}

function smslr_log($phone, $event, $status, $message = "", $response = "")
{
    try {
        Capsule::table("mod_smslr_logs")->insert([
            "phone" => $phone,
            "event" => $event,
            "provider" => "elanak",
            "status" => $status,
            "ip_address" => smslr_ip(),
            "message" => mb_substr((string) $message, 0, 4000),
            "response" => mb_substr((string) $response, 0, 8000),
            "created_at" => date("Y-m-d H:i:s")
        ]);
    } catch (\Throwable $e) {
    }
}

function smslr_prune()
{
    try {
        Capsule::table("mod_smslr_logs")->where("created_at", "<", date("Y-m-d H:i:s", time() - 90 * 86400))->delete();
        Capsule::table("mod_smslr_otps")->where("created_at", "<", date("Y-m-d H:i:s", time() - 2 * 86400))->delete();
    } catch (\Throwable $e) {
    }
}

function smslr_digits($value)
{
    $value = strtr((string) $value, [
        "۰" => "0", "۱" => "1", "۲" => "2", "۳" => "3", "۴" => "4", "۵" => "5", "۶" => "6", "۷" => "7", "۸" => "8", "۹" => "9",
        "٠" => "0", "١" => "1", "٢" => "2", "٣" => "3", "٤" => "4", "٥" => "5", "٦" => "6", "٧" => "7", "٨" => "8", "٩" => "9"
    ]);
    return preg_replace('/\D+/', '', $value);
}

function smslr_normalize_phone($phone)
{
    $phone = smslr_digits($phone);
    if (strpos($phone, "0098") === 0) {
        $phone = substr($phone, 4);
    } elseif (strpos($phone, "98") === 0 && strlen($phone) === 12) {
        $phone = substr($phone, 2);
    }
    if (strlen($phone) === 10 && $phone[0] === "9") {
        $phone = "0" . $phone;
    }
    return $phone;
}

function smslr_parse_response($body)
{
    $raw = trim((string) $body);
    $decoded = json_decode($raw, true);
    return ["raw" => $raw, "data" => is_array($decoded) ? $decoded : null];
}

function smslr_response_failed($result)
{
    $raw = $result["raw"];
    if ($raw === "") {
        return true;
    }
    $data = $result["data"];
    if (is_array($data)) {
        foreach (["error", "errors", "error_code"] as $key) {
            if (!empty($data[$key])) {
                return true;
            }
        }
        foreach (["status", "result", "success", "ok"] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                if ($value === false) {
                    return true;
                }
                if (is_string($value) && in_array(strtolower($value), ["error", "fail", "failed", "false"], true)) {
                    return true;
                }
            }
        }
        if (isset($data["code"]) && is_numeric($data["code"]) && (int) $data["code"] < 0) {
            return true;
        }
        return false;
    }
    if (preg_match('/^-\d+$/', $raw)) {
        return true;
    }
    return (bool) preg_match('/\b(error|fail|failed|invalid|denied|unauthorized)\b/i', $raw);
}

function smslr_provider_request($method, $params, $settings)
{
    if (!function_exists("curl_init")) {
        throw new RuntimeException("PHP cURL extension is not enabled.");
    }
    $endpoint = trim((string) $settings["wsdl_url"]);
    if ($endpoint === "" || (string) $settings["username"] === "" || (string) $settings["password"] === "") {
        throw new RuntimeException("SMS provider settings are incomplete.");
    }
    if (!filter_var($endpoint, FILTER_VALIDATE_URL) || strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== "https") {
        throw new RuntimeException("The SMS service URL must be a valid HTTPS URL.");
    }
    $password = smslr_decrypt($settings["password"]);
    if ($password === "") {
        throw new RuntimeException("The stored SMS password could not be read. Save the password again.");
    }
    $query = array_merge([
        "method" => strtolower($method),
        "format" => "json",
        "username" => $settings["username"],
        "password" => $password
    ], $params);
    $url = $endpoint . (strpos($endpoint, "?") === false ? "?" : "&") . http_build_query($query, "", "&", PHP_QUERY_RFC3986);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ["Accept: application/json, text/plain, */*"]
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $curlError !== "") {
        throw new RuntimeException("SMS service connection failed: " . $curlError);
    }
    $result = smslr_parse_response($body);
    $result["http_code"] = $httpCode;
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("SMS service returned HTTP " . $httpCode . ": " . mb_substr($result["raw"], 0, 500));
    }
    return $result;
}

function smslr_flatten($value, &$out)
{
    if (is_array($value)) {
        foreach ($value as $item) {
            smslr_flatten($item, $out);
        }
    } elseif (is_scalar($value) || $value === null) {
        $text = trim((string) $value);
        if ($text !== "") {
            $out[] = $text;
        }
    }
}

function smslr_elanak_errors()
{
    return [
        "0" => "Invalid username or password",
        "1" => "Insufficient credit",
        "2" => "Invalid sender number",
        "4" => "Sending is not enabled for this account",
        "5" => "Account panel is inactive",
        "6" => "Account panel has expired",
        "7" => "Message text is empty",
        "9" => "No recipient was given",
        "10" => "Time restriction on shared lines",
        "11" => "Unknown error, contact the SMS provider",
        "16" => "Too many recipients"
    ];
}

function smslr_elanak_error($result)
{
    $values = [];
    $isList = false;
    if ($result["data"] !== null) {
        smslr_flatten($result["data"], $values);
        $isList = array_keys($result["data"]) === range(0, count($result["data"]) - 1);
    } elseif ($result["raw"] !== "") {
        $values = [trim($result["raw"], "\"' \t\r\n")];
        $isList = true;
    }
    if (!$values) {
        return "Empty response from the SMS service.";
    }
    foreach ($values as $value) {
        if (preg_match('/^\d{9,}$/', $value)) {
            return "";
        }
    }
    $errors = smslr_elanak_errors();
    if ($isList && isset($errors[$values[0]])) {
        return "Provider error " . $values[0] . ": " . $errors[$values[0]] . ".";
    }
    return null;
}

function smslr_format_recipient($phone, $settings)
{
    $tail = substr($phone, 1);
    if (($settings["to_format"] ?? "09") === "+98") {
        return "+98" . $tail;
    }
    if (($settings["to_format"] ?? "09") === "98") {
        return "98" . $tail;
    }
    return $phone;
}

function smslr_provider_elanak_send($phone, $content, $settings)
{
    if ((string) $settings["sender"] === "") {
        throw new RuntimeException("Sender number is required.");
    }
    $result = smslr_provider_request("sendsms", [
        "from" => (string) $settings["sender"],
        "to" => smslr_format_recipient($phone, $settings),
        "text" => (string) $content,
        "type" => (string) $settings["sms_type"]
    ], $settings);
    $error = smslr_elanak_error($result);
    if ($error === null) {
        $error = smslr_response_failed($result) ? "Unrecognized error response." : "";
    }
    if ($error !== "") {
        throw new RuntimeException($error . " Response: " . mb_substr($result["raw"], 0, 300));
    }
    return $result;
}

function smslr_provider_elanak_credit($settings)
{
    $result = smslr_provider_request("getcredit", [], $settings);
    $plain = trim($result["raw"], "\"' \t\r\n");
    $errors = smslr_elanak_errors();
    if (in_array($plain, ["0", "5", "6"], true)) {
        throw new RuntimeException("Provider error " . $plain . ": " . $errors[$plain] . ".");
    }
    if (preg_match('/^[\d,.\s]+$/', $plain)) {
        return $result;
    }
    if (smslr_response_failed($result)) {
        throw new RuntimeException("SMS service returned an error: " . mb_substr($result["raw"], 0, 300));
    }
    return $result;
}

function smslr_provider_send($phone, $content, $settings)
{
    $function = "smslr_provider_" . $settings["provider"] . "_send";
    if (!function_exists($function)) {
        $function = "smslr_provider_elanak_send";
    }
    return $function($phone, $content, $settings);
}

function smslr_provider_credit($settings)
{
    $function = "smslr_provider_" . $settings["provider"] . "_credit";
    if (!function_exists($function)) {
        $function = "smslr_provider_elanak_credit";
    }
    return $function($settings);
}

function smslr_custom_fields()
{
    return Capsule::table("tblcustomfields")->where("type", "client")->orderBy("fieldname")->get(["id", "fieldname", "fieldtype"]);
}

function smslr_client_ids_by_phone($phone, $fieldId)
{
    $last4 = substr($phone, -4);
    $persian = strtr($last4, ["0" => "۰", "1" => "۱", "2" => "۲", "3" => "۳", "4" => "۴", "5" => "۵", "6" => "۶", "7" => "۷", "8" => "۸", "9" => "۹"]);
    $arabic = strtr($last4, ["0" => "٠", "1" => "١", "2" => "٢", "3" => "٣", "4" => "٤", "5" => "٥", "6" => "٦", "7" => "٧", "8" => "٨", "9" => "٩"]);
    $rows = Capsule::table("tblcustomfieldsvalues")
        ->where("fieldid", (int) $fieldId)
        ->where(function ($query) use ($last4, $persian, $arabic) {
            $query->where("value", "like", "%" . $last4 . "%")
                ->orWhere("value", "like", "%" . $persian . "%")
                ->orWhere("value", "like", "%" . $arabic . "%");
        })
        ->get(["relid", "value"]);
    $ids = [];
    foreach ($rows as $row) {
        if (smslr_normalize_phone($row->value) === $phone) {
            $ids[(int) $row->relid] = true;
        }
    }
    return array_keys($ids);
}

function smslr_find_client_by_phone($phone, $fieldId)
{
    if (!$fieldId) {
        return null;
    }
    $ids = smslr_client_ids_by_phone($phone, $fieldId);
    if (count($ids) !== 1) {
        return null;
    }
    return Capsule::table("tblclients")->where("id", $ids[0])->whereIn("status", ["Active", "Inactive"])->first();
}

function smslr_phone_taken($phone, $fieldId)
{
    return count(smslr_client_ids_by_phone($phone, $fieldId)) > 0;
}

function smslr_email_taken($email)
{
    if (Capsule::table("tblclients")->where("email", $email)->exists()) {
        return true;
    }
    try {
        return Capsule::table("tblusers")->where("email", $email)->exists();
    } catch (\Throwable $e) {
        return false;
    }
}


function smslr_create_sso($clientId)
{
    if (!function_exists("localAPI")) {
        throw new RuntimeException("WHMCS Local API is unavailable.");
    }

    $result = localAPI("CreateSsoToken", [
        "client_id" => (int) $clientId
    ]);

    $url = is_array($result) ? (string) ($result["redirect_url"] ?? "") : "";

    if (!is_array($result) || ($result["result"] ?? "") !== "success" || !preg_match('#^https?://#i', $url)) {
        throw new RuntimeException("WHMCS could not create a single sign-on token: " . json_encode($result));
    }

    return $url;
}

function smslr_json($data)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(200);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function smslr_fail($message, $extra = [])
{
    return array_merge(["ok" => false, "message" => $message], $extra);
}

function smslr_same_origin()
{
    $origin = (string) ($_SERVER["HTTP_ORIGIN"] ?? "");
    if ($origin === "") {
        return true;
    }
    $host = parse_url($origin, PHP_URL_HOST);
    $current = preg_replace('/:\d+$/', '', (string) ($_SERVER["HTTP_HOST"] ?? ""));
    return $host !== null && $host !== false && strcasecmp((string) $host, $current) === 0;
}

function smslr_handle_send($settings, $purpose)
{
    $mode = (string) $settings["mode"];
    if (($purpose === "login" && !in_array($mode, ["login", "both"], true)) || ($purpose === "register" && !in_array($mode, ["register", "both"], true))) {
        return smslr_fail("این روش در حال حاضر فعال نیست.");
    }
    $phone = smslr_normalize_phone($_POST["phone"] ?? "");
    if (!preg_match('/^09\d{9}$/', $phone)) {
        return smslr_fail("شماره همراه معتبر نیست.");
    }
    $fieldId = (int) $settings["mobile_field_id"];
    if ($fieldId <= 0) {
        return smslr_fail("ورود پیامکی هنوز پیکربندی نشده است.");
    }
    $payload = [];
    $deliver = true;
    if ($purpose === "login") {
        $deliver = smslr_find_client_by_phone($phone, $fieldId) !== null;
    } else {
        $payload = [
            "firstname" => mb_substr(trim(strip_tags((string) ($_POST["firstname"] ?? ""))), 0, 100),
            "lastname" => mb_substr(trim(strip_tags((string) ($_POST["lastname"] ?? ""))), 0, 100),
            "email" => mb_substr(trim((string) ($_POST["email"] ?? "")), 0, 190)
        ];
        if ($payload["firstname"] === "" || $payload["lastname"] === "" || !filter_var($payload["email"], FILTER_VALIDATE_EMAIL)) {
            return smslr_fail("نام، نام خانوادگی و یک ایمیل معتبر وارد کنید.");
        }
        if (smslr_email_taken($payload["email"]) || smslr_phone_taken($phone, $fieldId)) {
            return smslr_fail("این ایمیل یا شماره همراه قبلاً ثبت شده است.");
        }
    }
    $now = time();
    $interval = (int) $settings["resend_interval"];
    $last = Capsule::table("mod_smslr_otps")->where("phone", $phone)->where("purpose", $purpose)->whereIn("status", ["pending", "ghost"])->orderBy("id", "desc")->first();
    $resends = 0;
    if ($last) {
        $sentAt = (int) strtotime((string) $last->last_sent_at);
        if ($sentAt > $now - $interval) {
            $wait = max(1, $interval - ($now - $sentAt));
            return smslr_fail("لطفاً " . $wait . " ثانیه دیگر دوباره تلاش کنید.", ["wait" => $wait]);
        }
        if ((int) strtotime((string) $last->expires_at) > $now) {
            if ((int) $last->resends >= (int) $settings["max_resends"]) {
                return smslr_fail("تعداد دفعات ارسال مجدد کد به سقف مجاز رسیده است.");
            }
            $resends = (int) $last->resends + 1;
        }
    }
    $ip = smslr_ip();
    $daily = Capsule::table("mod_smslr_otps")->where("phone", $phone)->where("created_at", ">=", date("Y-m-d 00:00:00"))->count();
    if ($daily >= (int) $settings["daily_limit"]) {
        return smslr_fail("سقف درخواست روزانه برای این شماره تکمیل شده است.");
    }
    $hourly = Capsule::table("mod_smslr_otps")->where("ip_address", $ip)->where("created_at", ">=", date("Y-m-d H:i:s", $now - 3600))->count();
    if ($hourly >= (int) $settings["ip_hourly_limit"]) {
        return smslr_fail("تعداد درخواست‌ها بیش از حد مجاز است. بعداً تلاش کنید.");
    }
    if (random_int(1, 20) === 1) {
        smslr_prune();
    }
    $length = max(4, min(8, (int) $settings["otp_length"]));
    $code = (string) random_int(10 ** ($length - 1), (10 ** $length) - 1);
    $row = [
        "phone" => $phone,
        "purpose" => $purpose,
        "code_hash" => "x",
        "expires_at" => date("Y-m-d H:i:s", $now + (int) $settings["otp_expiry"] * 60),
        "last_sent_at" => date("Y-m-d H:i:s", $now),
        "attempts" => 0,
        "resends" => $resends,
        "ip_address" => $ip,
        "payload" => json_encode($payload, JSON_UNESCAPED_UNICODE),
        "status" => "ghost",
        "created_at" => date("Y-m-d H:i:s", $now)
    ];
    if ($deliver) {
        try {
            $response = smslr_provider_send($phone, str_replace("{code}", $code, (string) $settings["otp_message"]), $settings);
            $row["status"] = "pending";
            $row["code_hash"] = password_hash($code, PASSWORD_DEFAULT);
            smslr_log($phone, "otp_send_" . $purpose, "sent", "OTP sent", $response["raw"]);
        } catch (\Throwable $e) {
            $row["status"] = "failed";
            Capsule::table("mod_smslr_otps")->insert($row);
            smslr_log($phone, "otp_send_" . $purpose, "failed", $e->getMessage());
            return smslr_fail("ارسال پیامک انجام نشد. لطفاً بعداً دوباره تلاش کنید.");
        }
    } else {
        $matched = smslr_client_ids_by_phone($phone, $fieldId);
        $detail = count($matched) === 0 ? "no custom field value matched" : (count($matched) > 1 ? "more than one client has this number" : "the matched client is not Active or Inactive");
        smslr_log($phone, "otp_send_login", "skipped", "No SMS was sent: " . $detail . " (field ID " . $fieldId . ").");
    }
    Capsule::table("mod_smslr_otps")->where("phone", $phone)->where("purpose", $purpose)->whereIn("status", ["pending", "ghost"])->update(["status" => "replaced"]);
    Capsule::table("mod_smslr_otps")->insert($row);
    $_SESSION["smslr_phone"] = $phone;
    $_SESSION["smslr_purpose"] = $purpose;
    return ["ok" => true, "message" => "کد تأیید ارسال شد.", "length" => $length, "resend" => $interval, "phone" => $phone];
}

function smslr_handle_verify($settings)
{
    $phone = (string) ($_SESSION["smslr_phone"] ?? "");
    $purpose = (string) ($_SESSION["smslr_purpose"] ?? "");
    if ($phone === "" || !in_array($purpose, ["login", "register"], true)) {
        return smslr_fail("درخواست نامعتبر است. دوباره کد دریافت کنید.", ["restart" => true]);
    }
    $code = smslr_digits($_POST["code"] ?? "");
    $generic = "کد وارد شده صحیح نیست یا منقضی شده است.";
    $otp = Capsule::table("mod_smslr_otps")->where("phone", $phone)->where("purpose", $purpose)->where("status", "pending")->orderBy("id", "desc")->first();
    if (!$otp || (int) strtotime((string) $otp->expires_at) < time()) {
        return smslr_fail($generic);
    }
    if ((int) $otp->attempts >= (int) $settings["max_attempts"]) {
        Capsule::table("mod_smslr_otps")->where("id", $otp->id)->update(["status" => "locked"]);
        return smslr_fail("تعداد تلاش‌های ناموفق بیش از حد مجاز است. کد جدید دریافت کنید.", ["restart" => true]);
    }
    Capsule::table("mod_smslr_otps")->where("id", $otp->id)->increment("attempts");
    if ($code === "" || !password_verify($code, $otp->code_hash)) {
        smslr_log($phone, "otp_verify_" . $purpose, "invalid", "Invalid verification code");
        return smslr_fail($generic);
    }
    $claimed = Capsule::table("mod_smslr_otps")->where("id", $otp->id)->where("status", "pending")->update(["status" => "verified"]);
    if (!$claimed) {
        return smslr_fail($generic);
    }
    smslr_log($phone, "otp_verify_" . $purpose, "success", "OTP verified");
    $fieldId = (int) $settings["mobile_field_id"];
    if ($purpose === "login") {
        $client = smslr_find_client_by_phone($phone, $fieldId);
        if (!$client) {
            return smslr_fail($generic);
        }
        try {
            $url = smslr_create_sso($client->id);
        } catch (\Throwable $e) {
            smslr_log($phone, "sso_login", "failed", $e->getMessage());
            return smslr_fail("کد تأیید شد ولی ورود انجام نشد. از ورود معمولی استفاده کنید.");
        }
    } else {
        $payload = json_decode((string) ($otp->payload ?: "{}"), true);
        $payload = is_array($payload) ? $payload : [];
        $email = (string) ($payload["email"] ?? "");
        if ($email === "" || smslr_email_taken($email) || smslr_phone_taken($phone, $fieldId)) {
            return smslr_fail("این ایمیل یا شماره همراه قبلاً ثبت شده است.");
        }
        $passwordLength = max(12, min(64, (int) $settings["registration_password_length"]));
        $password = substr(bin2hex(random_bytes((int) ceil($passwordLength / 2))), 0, $passwordLength);
        $result = localAPI("AddClient", [
            "firstname" => (string) ($payload["firstname"] ?? ""),
            "lastname" => (string) ($payload["lastname"] ?? ""),
            "email" => $email,
            "password2" => $password,
            "address1" => "-",
            "city" => "-",
            "state" => "-",
            "postcode" => "0",
            "country" => "IR",
            "phonenumber" => "+98." . substr($phone, 1),
            "customfields" => base64_encode(serialize([$fieldId => $phone])),
            "skipvalidation" => true
        ]);
        if (!is_array($result) || ($result["result"] ?? "") !== "success" || empty($result["clientid"])) {
            smslr_log($phone, "register", "failed", "WHMCS AddClient failed", json_encode($result, JSON_UNESCAPED_UNICODE));
            return smslr_fail("ایجاد حساب انجام نشد. با پشتیبانی تماس بگیرید.");
        }
        smslr_log($phone, "register", "success", "Client created: " . (int) $result["clientid"]);
        try {
            $url = smslr_create_sso((int) $result["clientid"]);
        } catch (\Throwable $e) {
            smslr_log($phone, "sso_register", "failed", $e->getMessage());
            return smslr_fail("حساب ساخته شد ولی ورود خودکار انجام نشد. از ورود معمولی (بازیابی رمز) استفاده کنید.");
        }
    }
    unset($_SESSION["smslr_phone"], $_SESSION["smslr_purpose"]);
    return ["ok" => true, "message" => "تأیید شد. در حال انتقال...", "redirect" => $url];
}

function smslr_ajax($settings)
{
    try {
        if (($settings["enabled"] ?? "0") !== "1") {
            smslr_json(smslr_fail("ورود پیامکی در حال حاضر فعال نیست."));
        }
        if (!smslr_same_origin()) {
            smslr_json(smslr_fail("درخواست نامعتبر است."));
        }
        $action = (string) ($_POST["smslr_action"] ?? "");
        if ($action === "send_login" || $action === "send_register") {
            smslr_json(smslr_handle_send($settings, substr($action, 5)));
        }
        if ($action === "verify") {
            smslr_json(smslr_handle_verify($settings));
        }
        smslr_json(smslr_fail("درخواست نامعتبر است."));
    } catch (\Throwable $e) {
        smslr_log(null, "ajax_error", "failed", $e->getMessage());
        smslr_json(smslr_fail("خطای داخلی. لطفاً بعداً دوباره تلاش کنید."));
    }
}

function sms_login_registration_clientarea($vars)
{
    if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST" && isset($_POST["smslr_action"])) {
        smslr_ajax(smslr_settings());
    }
    return [
        "pagetitle" => "SMS Login",
        "breadcrumb" => ["index.php?m=sms_login_registration" => "SMS Login"],
        "templatefile" => "unavailable",
        "requirelogin" => false,
        "vars" => ["message" => "ورود با شماره همراه از طریق صفحه ورود انجام می‌شود."]
    ];
}

function sms_login_registration_output($vars)
{
    $message = "";
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_smslr"])) {
            smslr_save_settings($_POST);
            $message = "Settings saved.";
        }
        if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["test_smslr"])) {
            try {
                $credit = smslr_provider_credit(smslr_settings());
                $message = "Provider connection successful. Credit response: " . htmlspecialchars($credit["raw"], ENT_QUOTES, "UTF-8");
                smslr_log(null, "provider_test", "success", "Credit check completed", $credit["raw"]);
            } catch (\Throwable $e) {
                $message = "Provider test failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8");
                smslr_log(null, "provider_test", "failed", $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        $message = "Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8");
    }
    $settings = smslr_settings();
    $fields = smslr_custom_fields();
    $logs = Capsule::table("mod_smslr_logs")->orderBy("id", "desc")->limit(50)->get();
    $passwordSet = $settings["password"] !== "";
    $csrf = function_exists("generate_token") ? generate_token("form") : "";
    echo '<div class="wrap"><h2>SMS Login & Registration</h2>';
    if ($message) {
        echo '<div class="alert alert-info">' . $message . '</div>';
    }
    echo '<form method="post">' . $csrf . '<input type="hidden" name="save_smslr" value="1"><table class="form" width="100%" cellspacing="2" cellpadding="3">';
    $select = function ($name, $label, $options, $value) {
        echo '<tr><td class="fieldlabel" width="25%">' . htmlspecialchars($label) . '</td><td class="fieldarea"><select name="' . $name . '" class="form-control">';
        foreach ($options as $key => $title) {
            echo '<option value="' . htmlspecialchars((string) $key) . '"' . ((string) $value === (string) $key ? ' selected' : '') . '>' . htmlspecialchars($title) . '</option>';
        }
        echo '</select></td></tr>';
    };
    $input = function ($name, $label, $value, $type = "text", $hint = "") {
        echo '<tr><td class="fieldlabel" width="25%">' . htmlspecialchars($label) . '</td><td class="fieldarea"><input class="form-control" type="' . $type . '" name="' . $name . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8") . '" autocomplete="off">' . ($hint !== "" ? '<small>' . htmlspecialchars($hint) . '</small>' : '') . '</td></tr>';
    };
    $select("enabled", "Module Status", ["0" => "Disabled", "1" => "Enabled"], $settings["enabled"]);
    $select("mode", "Available Features", ["login" => "Login only", "register" => "Registration only", "both" => "Login and Registration"], $settings["mode"]);
    $select("provider", "SMS Provider", ["elanak" => "Payam Matni / elanak"], $settings["provider"]);
    $input("wsdl_url", "Service URL", $settings["wsdl_url"]);
    $input("username", "SMS Username", $settings["username"]);
    $input("password", "SMS Password", "", "password", $passwordSet ? "A password is saved. Leave blank to keep it." : "No password saved.");
    $input("sender", "Sender Number", $settings["sender"]);
    $select("sms_type", "SMS Type", ["0" => "Normal", "1" => "Flash"], $settings["sms_type"]);
    $select("to_format", "Recipient Number Format", ["09" => "09123456789", "+98" => "+989123456789", "98" => "989123456789"], $settings["to_format"]);
    $fieldOptions = ["0" => "Select a custom client field"];
    foreach ($fields as $field) {
        $fieldOptions[$field->id] = $field->fieldname . " (ID: " . $field->id . ")";
    }
    $select("mobile_field_id", "Mobile Custom Field", $fieldOptions, $settings["mobile_field_id"]);
    $input("otp_length", "OTP Length (4-8)", $settings["otp_length"], "number");
    $input("otp_expiry", "OTP Expiry in Minutes", $settings["otp_expiry"], "number");
    $input("resend_interval", "Resend Interval in Seconds", $settings["resend_interval"], "number");
    $input("max_attempts", "Maximum Verification Attempts", $settings["max_attempts"], "number");
    $input("max_resends", "Maximum Resends", $settings["max_resends"], "number");
    $input("daily_limit", "Daily OTP Limit per Mobile", $settings["daily_limit"], "number");
    $input("ip_hourly_limit", "Hourly OTP Limit per IP", $settings["ip_hourly_limit"], "number");
    $input("otp_message", "OTP Message ({code})", $settings["otp_message"]);
    echo '</table><p><button type="submit" class="btn btn-primary">Save Settings</button></p></form>';
    echo '<form method="post">' . $csrf . '<button type="submit" name="test_smslr" value="1" class="btn btn-default">Test Provider / Get Credit</button></form>';
    echo '<hr><h3>Recent Logs</h3><table class="table table-striped"><thead><tr><th>Date</th><th>Mobile</th><th>Event</th><th>Status</th><th>Message</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        echo '<tr><td>' . htmlspecialchars($log->created_at) . '</td><td>' . htmlspecialchars((string) $log->phone) . '</td><td>' . htmlspecialchars($log->event) . '</td><td>' . htmlspecialchars($log->status) . '</td><td>' . htmlspecialchars((string) $log->message) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
