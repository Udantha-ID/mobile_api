<?php
class FcmHelper {
    private static $serviceAccountPath = __DIR__ . "/../secure/firebase-service-account.json";
    private static $projectId = "YOUR_FIREBASE_PROJECT_ID"; // from Firebase console

    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function getAccessToken() {
        $sa = json_decode(file_get_contents(self::$serviceAccountPath), true);

        $now = time();
        $header  = ["alg" => "RS256", "typ" => "JWT"];
        $claims  = [
            "iss"   => $sa["client_email"],
            "scope" => "https://www.googleapis.com/auth/firebase.messaging",
            "aud"   => "https://oauth2.googleapis.com/token",
            "exp"   => $now + 3600,
            "iat"   => $now,
        ];

        $segments = self::base64UrlEncode(json_encode($header)) . "." .
                    self::base64UrlEncode(json_encode($claims));

        openssl_sign($segments, $signature, $sa["private_key"], "SHA256");
        $jwt = $segments . "." . self::base64UrlEncode($signature);

        $ch = curl_init("https://oauth2.googleapis.com/token");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
                "assertion"  => $jwt,
            ]),
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return $resp["access_token"] ?? null;
    }

    // $data values must all be strings (FCM v1 requirement)
    public static function send($fcmToken, $title, $body, array $data = []) {
        $accessToken = self::getAccessToken();
        if (!$accessToken) {
            return ["success" => false, "message" => "Failed to get access token"];
        }

        $stringData = [];
        foreach ($data as $k => $v) { $stringData[$k] = (string)$v; }

        $payload = [
            "message" => [
                "token" => $fcmToken,
                "notification" => ["title" => $title, "body" => $body],
                "data" => $stringData,
            ],
        ];

        $ch = curl_init("https://fcm.googleapis.com/v1/projects/" . self::$projectId . "/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $accessToken",
                "Content-Type: application/json",
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ["success" => $code === 200, "response" => json_decode($response, true)];
    }
}