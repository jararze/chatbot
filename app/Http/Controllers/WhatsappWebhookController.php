<?php

namespace App\Http\Controllers;

use App\Models\Truck;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\WhatsappAPIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookController extends Controller
{

    protected $whatsappService;

    public function __construct(WhatsappAPIService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    public function verify(Request $request)
    {

        Log::info('Verificación webhook', $request->all());

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        Log::info('Parámetros', ['mode' => $mode, 'token' => $token, 'challenge' => $challenge]);
        if ($mode === 'subscribe' && $token === config('services.whatsapp.webhook_verify_token')) {
            return response($challenge, 200);
        }

        return response('Verification failed', 403);
    }

    public function handle(Request $request)
    {
        try {
            Log::info('WhatsApp webhook payload', $request->all());

            $payload = $request->all();

            if (!isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
                return response('No message found', 200);
            }

            $message = $payload['entry'][0]['changes'][0]['value']['messages'][0];
            $from = $message['from'];
            $messageId = $message['id'];
            $timestamp = $message['timestamp'];
            $type = $message['type'];

            // Obtener o crear una conversación
            $conversation = WhatsappConversation::firstOrCreate(
                ['phone_number' => $from],
                [
                    'current_step' => 'welcome',
                    'is_active' => true,
                    'last_interaction' => now(),
                ]
            );

            $conversation->last_interaction = now();
            $conversation->save();

            // Registrar mensaje entrante
            $messageText = '';
            $messageData = [];

            if ($type === 'text') {
                $messageText = $message['text']['body'];
            } elseif ($type === 'interactive') {
                $interactiveData = $message['interactive'];
                $interactiveType = $interactiveData['type'];

                if ($interactiveType === 'button_reply') {
                    $messageText = $interactiveData['button_reply']['title'];
                    $messageData['button_id'] = $interactiveData['button_reply']['id'];
                }
            }

            WhatsappMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'incoming',
                'message' => $messageText,
                'metadata' => [
                    'message_id' => $messageId,
                    'timestamp' => $timestamp,
                    'type' => $type,
                    'data' => $messageData,
                ],
                'message_id' => $messageId,
            ]);

            // Procesar la conversación según el paso actual
            $this->processConversation($conversation, $messageText);

            return response('Message processed', 200);
        } catch (\Exception $e) {
            Log::error('Error processing webhook: ' . $e->getMessage());
            return response('Error processing webhook', 500);
        }
    }

    protected function processConversation(WhatsappConversation $conversation, $message)
    {
        switch ($conversation->current_step) {
            case 'welcome':
                $this->handleWelcomeStep($conversation);
                break;

            case 'ask_license_plate':
                $this->handleLicensePlateStep($conversation, $message);
                break;

            case 'show_menu':
                $this->handleMenuStep($conversation, $message);
                break;

            case 'show_truck_details':
                $this->handleTruckDetailsStep($conversation, $message);
                break;

            case 'show_maintenance':
                $this->handleMaintenanceStep($conversation, $message);
                break;

            default:
                // Si no reconocemos el paso, volvamos al inicio
                $this->resetConversation($conversation);
                break;
        }
    }

    protected function handleWelcomeStep(WhatsappConversation $conversation)
    {
        $welcomeMessage = "👋 ¡Bienvenido al sistema de información de camiones! Para comenzar, necesito que me proporciones la placa del camión.";

        $this->sendAndLogMessage($conversation, $welcomeMessage);

        $conversation->current_step = 'ask_license_plate';
        $conversation->save();
    }

    protected function handleLicensePlateStep(WhatsappConversation $conversation, $message)
    {
        // Limpiamos la placa de espacios y la convertimos a mayúsculas
        $licensePlate = strtoupper(trim($message));

        // Buscamos si existe un camión con esa placa
        $truck = Truck::where('license_plate', $licensePlate)->first();

        if ($truck) {
            // Guardamos la placa en la conversación
            $conversation->license_plate = $licensePlate;
            $conversation->context_data = [
                'truck_id' => $truck->id,
                'driver_name' => $truck->driver_name,
                'model' => $truck->model,
                'year' => $truck->year,
            ];
            $conversation->current_step = 'show_menu';
            $conversation->save();

            $this->showMainMenu($conversation, $truck);
        } else {
            $this->sendAndLogMessage($conversation, "❌ No encontré ningún camión con la placa $licensePlate. Por favor, verifica e intenta nuevamente.");
        }
    }

    protected function handleMenuStep(WhatsappConversation $conversation, $message)
    {
        if (!$conversation->license_plate || !isset($conversation->context_data['truck_id'])) {
            $this->resetConversation($conversation);
            return;
        }

        $truck = Truck::find($conversation->context_data['truck_id']);

        if (!$truck) {
            $this->resetConversation($conversation);
            return;
        }

        switch (strtolower($message)) {
            case '1':
            case 'detalles del camión':
                $conversation->current_step = 'show_truck_details';
                $conversation->save();
                $this->showTruckDetails($conversation, $truck);
                break;

            case '2':
            case 'información de mantenimiento':
                $conversation->current_step = 'show_maintenance';
                $conversation->save();
                $this->showMaintenanceInfo($conversation, $truck);
                break;

            case '3':
            case 'consultar otra placa':
                $this->sendAndLogMessage($conversation, "Por favor, ingresa la nueva placa del camión que deseas consultar:");
                $conversation->current_step = 'ask_license_plate';
                $conversation->license_plate = null;
                $conversation->context_data = null;
                $conversation->save();
                break;

            case '4':
            case 'finalizar':
                $this->sendAndLogMessage($conversation, "Gracias por utilizar nuestro servicio. ¡Hasta pronto! 👋");
                $this->resetConversation($conversation);
                break;

            default:
                $this->sendAndLogMessage($conversation, "No entendí tu selección. Por favor, elige una opción válida.");
                $this->showMainMenu($conversation, $truck);
                break;
        }
    }

    protected function handleTruckDetailsStep(WhatsappConversation $conversation, $message)
    {
        if (strtolower($message) === 'volver al menú' || strtolower($message) === 'volver') {
            $conversation->current_step = 'show_menu';
            $conversation->save();

            $truck = Truck::find($conversation->context_data['truck_id']);
            $this->showMainMenu($conversation, $truck);
        } else {
            $this->sendAndLogMessage($conversation, "Para volver al menú principal, escribe 'volver'.");
        }
    }

    protected function handleMaintenanceStep(WhatsappConversation $conversation, $message)
    {
        if (strtolower($message) === 'volver al menú' || strtolower($message) === 'volver') {
            $conversation->current_step = 'show_menu';
            $conversation->save();

            $truck = Truck::find($conversation->context_data['truck_id']);
            $this->showMainMenu($conversation, $truck);
        } else {
            $this->sendAndLogMessage($conversation, "Para volver al menú principal, escribe 'volver'.");
        }
    }

    protected function showMainMenu(WhatsappConversation $conversation, Truck $truck)
    {
        $headerText = "Información del Camión";
        $bodyText = "Se encontró el camión con placa {$truck->license_plate}.\nConductor: {$truck->driver_name}\n\nSelecciona una opción:";

        $options = [
            ['id' => 'details', 'title' => '1. Detalles del Camión'],
            ['id' => 'maintenance', 'title' => '2. Info de Mantenimiento'],
            ['id' => 'new_plate', 'title' => '3. Consultar otra placa'],
            ['id' => 'end', 'title' => '4. Finalizar'],
        ];

        try {
            $response = $this->whatsappService->sendInteractiveMessage(
                $conversation->phone_number,
                $headerText,
                $bodyText,
                $options
            );

            WhatsappMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outgoing',
                'message' => json_encode([
                    'header' => $headerText,
                    'body' => $bodyText,
                    'options' => $options
                ]),
                'metadata' => $response,
                'message_id' => $response['messages'][0]['id'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending menu: ' . $e->getMessage());

            // Fallback a mensaje de texto simple
            $menuText = "Menú Principal:\n1. Detalles del Camión\n2. Información de Mantenimiento\n3. Consultar otra placa\n4. Finalizar";
            $this->sendAndLogMessage($conversation, $menuText);
        }
    }

    protected function showTruckDetails(WhatsappConversation $conversation, Truck $truck)
    {
        $detailsMessage = "📋 *DETALLES DEL CAMIÓN*\n\n" .
            "*Placa:* {$truck->license_plate}\n" .
            "*Conductor:* {$truck->driver_name}\n" .
            "*Modelo:* {$truck->model}\n" .
            "*Año:* {$truck->year}\n" .
            "*Estado actual:* {$truck->status}\n\n" .
            "Para volver al menú principal, escribe 'volver'.";

        $this->sendAndLogMessage($conversation, $detailsMessage);
    }

    protected function showMaintenanceInfo(WhatsappConversation $conversation, Truck $truck)
    {
        $lastMaintenance = $truck->last_maintenance->format('d/m/Y');
        $daysSinceLastMaintenance = $truck->last_maintenance->diffInDays(now());

        $maintenanceMessage = "🔧 *INFORMACIÓN DE MANTENIMIENTO*\n\n" .
            "*Placa:* {$truck->license_plate}\n" .
            "*Último mantenimiento:* {$lastMaintenance}\n" .
            "*Días desde el último mantenimiento:* {$daysSinceLastMaintenance} días\n";

        if ($daysSinceLastMaintenance > 90) {
            $maintenanceMessage .= "\n⚠️ *ALERTA:* El vehículo necesita mantenimiento urgente.\n";
        } elseif ($daysSinceLastMaintenance > 75) {
            $maintenanceMessage .= "\n⚠️ *AVISO:* El vehículo necesitará mantenimiento pronto.\n";
        } else {
            $maintenanceMessage .= "\n✅ Mantenimiento al día.\n";
        }

        $maintenanceMessage .= "\nPara volver al menú principal, escribe 'volver'.";

        $this->sendAndLogMessage($conversation, $maintenanceMessage);
    }

    protected function resetConversation(WhatsappConversation $conversation)
    {
        $conversation->current_step = 'welcome';
        $conversation->license_plate = null;
        $conversation->context_data = null;
        $conversation->save();
    }

    protected function sendAndLogMessage(WhatsappConversation $conversation, $message)
    {
        try {
            $response = $this->whatsappService->sendTextMessage($conversation->phone_number, $message);

            WhatsappMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outgoing',
                'message' => $message,
                'metadata' => $response,
                'message_id' => $response['messages'][0]['id'] ?? null,
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('Error sending message: ' . $e->getMessage());
            throw $e;
        }
    }

}
