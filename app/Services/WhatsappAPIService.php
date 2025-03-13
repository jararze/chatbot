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

            Log::info('Intentando enviar mensaje', [
                'to' => $to,
                'text' => $text,
                'token' => substr($this->accessToken, 0, 10) . '...' // muestra solo los primeros 10 caracteres por seguridad
            ]);

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

    public function sendInteractiveMessage($recipient, $header, $body, $options = [], $buttons = [])
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => [
                    'type' => 'text',
                    'text' => $header
                ],
                'body' => [
                    'text' => $body
                ],
                'action' => [
                    'button' => 'Ver opciones',
                    'sections' => [
                        [
                            'title' => 'Opciones disponibles',
                            'rows' => array_map(function($option) {
                                return [
                                    'id' => $option['id'],
                                    'title' => $option['title']
                                ];
                            }, $options)
                        ]
                    ]
                ]
            ]
        ];

        // Si tenemos botones, agregar botones adicionales
        if (!empty($buttons)) {
            // Para botones, necesitamos usar un tipo de mensaje interactivo diferente
            // Nota: WhatsApp solo permite un máximo de 3 botones
            $buttonPayload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipient,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => [
                        'text' => $body
                    ],
                    'action' => [
                        'buttons' => array_slice(array_map(function($button) {
                            return [
                                'type' => 'reply',
                                'reply' => [
                                    'id' => $button['id'],
                                    'title' => $button['title']
                                ]
                            ];
                        }, $buttons), 0, 3) // Limitamos a 3 botones máximo
                    ]
                ]
            ];

            // Enviar el mensaje con botones después del mensaje principal
            $this->sendRequest($buttonPayload);
        }

        return $this->sendRequest($payload);
    }

    /**
     * Método para enviar cualquier tipo de solicitud a la API de WhatsApp
     */
    protected function sendRequest($payload)
    {
        try {
            Log::info('Enviando solicitud a WhatsApp API', [
                'to' => $payload['to'],
                'type' => $payload['type'],
                'token' => substr($this->accessToken, 0, 10) . '...'
            ]);

            $response = $this->client->post("{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = json_decode($response->getBody(), true);
            Log::info('WhatsApp API response', $body);

            return $body;
        } catch (\Exception $e) {
            Log::error('Error en WhatsApp API: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Envía un mensaje con botones de acción rápida
     */
    public function sendButtonMessage($recipient, $bodyText, $buttons)
    {
        // Limitar a máximo 3 botones (limitación de WhatsApp)
        $buttons = array_slice($buttons, 0, 3);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => [
                    'text' => $bodyText
                ],
                'action' => [
                    'buttons' => array_map(function($button) {
                        return [
                            'type' => 'reply',
                            'reply' => [
                                'id' => $button['id'],
                                'title' => $button['title']
                            ]
                        ];
                    }, $buttons)
                ]
            ]
        ];

        return $this->sendRequest($payload);
    }


}
