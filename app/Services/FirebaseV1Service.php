<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class FirebaseV1Service
{
    /**
     * Send a high-priority push notification via FCM v1
     */
    public static function send($tokens, $title, $body, $data = [])
    {
        $projectId = env('FIREBASE_PROJECT_ID');
        $credentialsPath = storage_path('app/' . env('FIREBASE_CREDENTIALS_PATH'));

        if (!file_exists($credentialsPath)) {
            Log::error('FCM v1: Service Account JSON not found at ' . $credentialsPath);
            return false;
        }

        $accessToken = self::getAccessToken($credentialsPath);
        if (!$accessToken) return false;

        $client = new Client();
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $successCount = 0;
        $failureCount = 0;

        // FCM v1 sends messages to one token at a time in the individual endpoint
        // (For true multicasting, you can use Topics or many parallel requests)
        foreach ($tokens as $token) {
            try {
                $response = $client->post($url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'message' => [
                            'token' => $token,
                            'notification' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                            'data' => array_merge($data, [
                                'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
                            ]),
                            'android' => [
                                'priority' => 'high',
                                'notification' => [
                                    'channel_id' => 'high-priority',
                                    'color' => '#4f46e5',
                                    'sound' => 'default'
                                ]
                            ]
                        ]
                    ]
                ]);

                if ($response->getStatusCode() === 200) {
                    $successCount++;
                }
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $failureCount++;
                $response = $e->getResponse();
                $body = json_decode($response->getBody()->getContents(), true);
                
                // If token is invalid or not found, remove it from our DB
                $errorCode = $body['error']['status'] ?? '';
                if ($errorCode === 'NOT_FOUND' || $errorCode === 'INVALID_ARGUMENT') {
                    \App\Models\StudentDeviceToken::where('token', $token)->delete();
                    Log::info("FCM v1: Deleted invalid token: {$token}");
                } else {
                    Log::error('FCM v1 Delivery Failed: ' . $e->getMessage());
                }
            } catch (\Exception $e) {
                $failureCount++;
                Log::error('FCM v1 General Error: ' . $e->getMessage());
            }
        }

        Log::info("FCM v1 Batch Sent: Success: {$successCount}, Failure: {$failureCount}");
        return $successCount > 0;
    }

    /**
     * Generate OAuth2 Access Token for FCM v1
     */
    private static function getAccessToken($jsonPath)
    {
        try {
            $config = json_decode(file_get_contents($jsonPath), true);
            $now = time();
            
            // Build JWT Header & Payload
            $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $payload = base64_encode(json_encode([
                'iss' => $config['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now
            ]));

            // Sign the JWT
            $signature = '';
            openssl_sign($header . '.' . $payload, $signature, $config['private_key'], 'SHA256');
            $signedJwt = $header . '.' . $payload . '.' . base64_encode($signature);

            // Request access token
            $client = new Client();
            $response = $client->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $signedJwt
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data['access_token'] ?? null;
        } catch (\Exception $e) {
            Log::error('FCM v1 Auth Error: ' . $e->getMessage());
            return null;
        }
    }
}
