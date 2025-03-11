<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
class WhatsappAPIService
{
    protected $client;
    protected $apiVersion;
    protected $phoneNumberId;
    protected $accessToken;
    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://graph.facebook.com/',
        ]);

        $this->apiVersion = config('services.whatsapp.api_version');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
        $this->accessToken = config('services.whatsapp.access_token');
    }
    public function sendTextMessage($to, $text)
    {
        try {
            $response = $this->client->post("{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'text',
                    'text' => [
                        'body' => $text,
                    ],
                ],
            ]);

            $body = json_decode($response->getBody(), true);
            Log::info('WhatsApp message sent', $body);

            return $body;
        } catch (\Exception $e) {
            Log::error('Error sending WhatsApp message: ' . $e->getMessage());
            throw $e;
        }
    }

    public function sendInteractiveMessage($to, $headerText, $bodyText, $options)
    {
        try {
            $buttons = [];

            foreach ($options as $index => $option) {
                $buttons[] = [
                    'type' => 'reply',
                    'reply' => [
                        'id' => $option['id'],
                        'title' => $option['title']
                    ]
                ];
            }

            $response = $this->client->post("{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'interactive',
                    'interactive' => [
                        'type' => 'button',
                        'header' => [
                            'type' => 'text',
                            'text' => $headerText
                        ],
                        'body' => [
                            'text' => $bodyText
                        ],
                        'action' => [
                            'buttons' => $buttons
                        ]
                    ]
                ],
            ]);

            $body = json_decode($response->getBody(), true);
            Log::info('WhatsApp interactive message sent', $body);

            return $body;
        } catch (\Exception $e) {
            Log::error('Error sending WhatsApp interactive message: ' . $e->getMessage());
            throw $e;
        }
    }


}
