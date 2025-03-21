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

            Log::info('Conversación', [
                'id' => $conversation->id,
                'nueva' => $conversation->wasRecentlyCreated,
                'paso' => $conversation->current_step
            ]);

            // NUEVO: Asegurarnos de que se envía el mensaje de bienvenida si es una conversación nueva
            if ($conversation->wasRecentlyCreated || $conversation->current_step === 'welcome') {
                $this->handleWelcomeStep($conversation);
                $conversation->last_interaction = now();
                $conversation->save();

                // Si solo estamos mandando bienvenida, terminamos aquí
                if ($type !== 'text' || empty($message['text']['body'])) {
                    return response('Welcome message sent', 200);
                }
            }

            $conversation->last_interaction = now();
            $conversation->save();

            // Registrar mensaje entrante
            $messageText = '';
            $messageData = [];

            if ($type === 'text') {
                $messageText = $message['text']['body'];
                Log::info('Mensaje de texto recibido', ['texto' => $messageText]);
            } elseif ($type === 'interactive') {
                $interactiveData = $message['interactive'];
                $interactiveType = $interactiveData['type'];

                if ($interactiveType === 'button_reply') {
                    // Manejar respuesta de botón
                    $messageText = $interactiveData['button_reply']['title'];
                    $messageData['button_id'] = $interactiveData['button_reply']['id'];

                    // Procesamiento especial basado en el ID del botón
                    if ($interactiveData['button_reply']['id'] === 'exit') {
                        Log::info('Botón de salida presionado');
                        $messageText = 'Finalizar'; // Forzar comportamiento de salida
                    } else if ($interactiveData['button_reply']['id'] === 'back_to_menu') {
                        Log::info('Botón de volver al menú presionado');
                        $messageText = 'menu'; // Forzar comportamiento de menú
                    }

                } elseif ($interactiveType === 'list_reply') {
                    // Manejar respuesta de lista
                    $messageText = $interactiveData['list_reply']['title'];
                    $messageData['list_id'] = $interactiveData['list_reply']['id'];

                    // IMPORTANTE: Debemos usar tanto el título como el ID para procesar correctamente
                    Log::info('Opción de lista seleccionada', [
                        'title' => $messageText,
                        'id' => $interactiveData['list_reply']['id']
                    ]);

                    // Almacenar el ID en una variable especial que usaremos en el procesamiento
                    $conversation->context_data = array_merge($conversation->context_data ?? [], [
                        'last_selected_option_id' => $interactiveData['list_reply']['id']
                    ]);
                    $conversation->save();
                }

                Log::info('Mensaje interactivo recibido', [
                    'interactive_type' => $interactiveType,
                    'message_text' => $messageText,
                    'message_data' => $messageData
                ]);
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

    // Modificaciones para el método processConversation
    protected function processConversation(WhatsappConversation $conversation, $message)
    {
        Log::info('Procesando conversación', [
            'phone' => $conversation->phone_number,
            'message' => $message,
            'step' => $conversation->current_step
        ]);

        // Verificar comando de salida global
        if (strtolower(trim($message)) === 'salir' || strtolower(trim($message)) === 'finalizar' ||
            $message === 'Finalizar Consulta' || $message === 'Finalizar' ||
            $message === 'exit' || $message === 'end') {

            $this->sendAndLogMessage($conversation, "Gracias por utilizar nuestro servicio. ¡Hasta pronto! 👋");
            $this->resetConversation($conversation);
            return;
        }

        // Si el último mensaje es de más de 5 minutos, reiniciamos la conversación
        if ($conversation->last_interaction->diffInMinutes(now()) > 60) {
            $this->sendAndLogMessage($conversation, "La conversación estuvo inactiva por más de 60 minutos. Comenzando nuevamente.");
            $this->resetConversation($conversation);
            $this->handleWelcomeStep($conversation);
            return;
        }

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

            case 'show_security':
                $this->handleSecurityStep($conversation, $message);
                break;

            case 'show_quality':
                $this->handleQualityStep($conversation, $message);
                break;

            case 'show_transport':
                $this->handleTransportStep($conversation, $message);
                break;

            default:
                // Si no reconocemos el paso, volvamos al inicio
                $this->resetConversation($conversation);
                break;
        }
    }

    protected function handleWelcomeStep(WhatsappConversation $conversation)
    {
        $welcomeMessage = "👋 ¡Bienvenido al sistema de información de camiones! Para comenzar, necesito que me proporciones la placa del camión.\n\nPuedes escribir 'salir' en cualquier momento para finalizar la conversación.";

        $this->sendAndLogMessage($conversation, $welcomeMessage);

        $conversation->current_step = 'ask_license_plate';
        $conversation->save();
    }

    // Add inactivity check method to be used in command scheduling
    public function checkInactiveConversations()
    {
        // Find conversations that have been inactive for more than 30 minutes
        $inactiveConversations = WhatsappConversation::where('is_active', true)
            ->where('last_interaction', '<', now()->subMinutes(30))
            ->get();

        foreach ($inactiveConversations as $conversation) {
            $this->sendAndLogMessage($conversation, "Esta conversación ha estado inactiva por 30 minutos y se cerrará automáticamente. ¡Hasta pronto! 👋");
            $conversation->is_active = false;
            $conversation->save();
        }

        return count($inactiveConversations);
    }

    protected function handleLicensePlateStep(WhatsappConversation $conversation, $message)
    {

        // Prevenir mensajes repetidos - verificar si pasó al menos 10 segundos desde el último mensaje
        $lastMessage = WhatsappMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'outgoing')
            ->orderBy('created_at', 'desc')
            ->first();

        // Si el último mensaje fue enviado hace menos de 10 segundos, no enviamos otro
        if ($lastMessage && $lastMessage->created_at->diffInSeconds(now()) < 10) {
            return;
        }

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

    // Modificaciones para el método showMainMenu
    protected function showMainMenu(WhatsappConversation $conversation, Truck $truck)
    {
        $headerText = "Información del Camión";
        $bodyText = "Se encontró el camión con placa {$truck->license_plate}.\nConductor: {$truck->driver_name}\n\nSelecciona una opción:";

        $options = [
            ['id' => 'details', 'title' => '1. Detalles del Camión'],
            ['id' => 'security', 'title' => '2. Seguridad'],
            ['id' => 'quality', 'title' => '3. Calidad'],
            ['id' => 'transport', 'title' => '4. Transportes'],
            ['id' => 'new_plate', 'title' => '5. Consultar otra placa'],
            ['id' => 'end', 'title' => '6. Finalizar'],
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
                    'options' => $options,
                ]),
                'metadata' => $response,
                'message_id' => $response['messages'][0]['id'] ?? null,
            ]);

            sleep(1);

            $buttons = [
                ['id' => 'back_to_menu', 'title' => 'Menú Principal'],
                ['id' => 'exit', 'title' => 'Finalizar Consulta']
            ];

            $buttonResponse = $this->whatsappService->sendButtonMessage(
                $conversation->phone_number,
                "Para finalizar o regresar al menu principal en cualquier momento, puedes presionar este botón:",
                $buttons
            );

            WhatsappMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outgoing',
                'message' => "Botón para finalizar",
                'metadata' => [
                    'buttons' => $buttons,
                    'response' => $buttonResponse
                ],
                'message_id' => $buttonResponse['messages'][0]['id'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending menu: ' . $e->getMessage());

            // Fallback a mensaje de texto simple
            $menuText = "Menú Principal:\n1. Detalles del Camión\n2. Seguridad\n3. Calidad\n4. Transportes\n5. Consultar otra placa\n6. Finalizar";
            $this->sendAndLogMessage($conversation, $menuText);
        }
    }

    // Modificaciones para el método handleMenuStep
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

        $normalizedMessage = strtolower(trim($message));

        switch ($normalizedMessage) {
            case '1':
            case 'detalles del camión':
            case 'detalles':
            case 'details': // ID del botón
                $conversation->current_step = 'show_truck_details';
                $conversation->save();
                $this->showTruckDetails($conversation, $truck);
                break;

            case '2':
            case 'seguridad':
            case 'security': // ID del botón
                $conversation->current_step = 'show_security';
                $conversation->save();
                $this->showSecurityQuestions($conversation);
                break;

            case '3':
            case 'calidad':
            case 'quality': // ID del botón
                $conversation->current_step = 'show_quality';
                $conversation->save();
                $this->showQualityQuestions($conversation);
                break;

            case '4':
            case 'transportes':
            case 'transport': // ID del botón
                $conversation->current_step = 'show_transport';
                $conversation->save();
                $this->showTransportQuestions($conversation);
                break;

            case '5':
            case 'consultar otra placa':
            case 'otra placa':
            case 'new_plate': // ID del botón
                $this->sendAndLogMessage($conversation, "Por favor, ingresa la nueva placa del camión que deseas consultar:");
                $conversation->current_step = 'ask_license_plate';
                $conversation->license_plate = null;
                $conversation->context_data = null;
                $conversation->save();
                break;

            case '6':
            case 'finalizar':
            case 'terminar':
            case 'end': // ID del botón
            case 'finalizar consulta':
            case 'exit': // ID del botón
                $this->sendAndLogMessage($conversation, "Gracias por utilizar nuestro servicio. ¡Hasta pronto! 👋");
                $this->resetConversation($conversation);
                break;

            default:
                $this->sendAndLogMessage($conversation, "No entendí tu selección. Por favor, elige una opción válida o escribe 'salir' para finalizar.");
                $this->showMainMenu($conversation, $truck);
                break;
        }
    }

    protected function handleTruckDetailsStep(WhatsappConversation $conversation, $message)
    {
        // Normalizar el mensaje para facilitar la comparación
        $normalizedMessage = strtolower(trim($message));

        // Manejar respuestas de botones
        if ($normalizedMessage === 'volver al menú' || $normalizedMessage === 'volver' ||
            $normalizedMessage === 'menu' || $message === 'back_to_menu' ||
            $message === 'Volver al Menú') {

            $conversation->current_step = 'show_menu';
            $conversation->save();

            $truck = Truck::find($conversation->context_data['truck_id']);
            $this->showMainMenu($conversation, $truck);
            return;
        }

        // Manejar respuesta de botón de salida
        if ($normalizedMessage === 'finalizar' || $normalizedMessage === 'salir' ||
            $message === 'exit' || $message === 'Finalizar') {

            $this->sendAndLogMessage($conversation, "Gracias por utilizar nuestro servicio. ¡Hasta pronto! 👋");
            $this->resetConversation($conversation);
            return;
        }

        // Si no es ninguna de las opciones anteriores
        $this->sendAndLogMessage($conversation, "No entendí tu mensaje. Para volver al menú principal, escribe 'volver' o presiona el botón 'Volver al Menú'.\nPara finalizar, escribe 'salir' o presiona el botón 'Finalizar'.");

    }

    protected function handleMaintenanceStep(WhatsappConversation $conversation, $message)
    {
        // Normalizar el mensaje para facilitar la comparación
        $normalizedMessage = strtolower(trim($message));

        // Manejar respuestas de botones
        if ($normalizedMessage === 'volver al menú' || $normalizedMessage === 'volver' ||
            $normalizedMessage === 'menu' || $message === 'back_to_menu' ||
            $message === 'Volver al Menú') {

            $conversation->current_step = 'show_menu';
            $conversation->save();

            $truck = Truck::find($conversation->context_data['truck_id']);
            $this->showMainMenu($conversation, $truck);
            return;
        }

        // Manejar respuesta de botón de salida
        if ($normalizedMessage === 'finalizar' || $normalizedMessage === 'salir' ||
            $message === 'exit' || $message === 'Finalizar') {

            $this->sendAndLogMessage($conversation, "Gracias por utilizar nuestro servicio. ¡Hasta pronto! 👋");
            $this->resetConversation($conversation);
            return;
        }

        // Si no es ninguna de las opciones anteriores
        $this->sendAndLogMessage($conversation, "No entendí tu mensaje. Para volver al menú principal, escribe 'volver' o presiona el botón 'Volver al Menú'.\nPara finalizar, escribe 'salir' o presiona el botón 'Finalizar'.");
    }

    // Nuevos métodos para manejar las opciones de Seguridad, Calidad y Transportes
    protected function showSecurityQuestions(WhatsappConversation $conversation)
    {
        $securityQuestions = [
            "¿Cuáles son los EPPS con lo que debo contar?",
            "¿Qué es un comportamiento inseguro?",
            "¿Qué es una condición insegura?",
            "¿Cuál es la velocidad máxima que debo recorrer?",
            "¿Cuánto tiempo debería descansar en el trayecto?",
            "¿A quién debo reportar una falla mecánica?",
            "¿A quién debo comunicar si tuve un problema en ruta?",
            "¿Cuáles son los eventos en ruta que no están aceptados?"
        ];

        $headerText = "Preguntas sobre Seguridad";
        $bodyText = "Selecciona la pregunta que deseas consultar:";

        $options = [];
        foreach ($securityQuestions as $index => $question) {
            $options[] = [
                'id' => 'security_' . ($index + 1),
                'title' => ($index + 1) . '. ' . substr($question, 0, 24) . (strlen($question) > 24 ? '...' : '')
            ];
        }

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
                    'options' => $options,
                ]),
                'metadata' => $response,
                'message_id' => $response['messages'][0]['id'] ?? null,
            ]);

            sleep(1);

            $buttons = [
                ['id' => 'back_to_menu', 'title' => 'Volver al Menú'],
                ['id' => 'exit', 'title' => 'Finalizar']
            ];

            $buttonResponse = $this->whatsappService->sendButtonMessage(
                $conversation->phone_number,
                "¿Qué deseas hacer ahora?",
                $buttons
            );

            WhatsappMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outgoing',
                'message' => "Botones de navegación",
                'metadata' => [
                    'buttons' => $buttons,
                    'response' => $buttonResponse
                ],
                'message_id' => $buttonResponse['messages'][0]['id'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending security questions: ' . $e->getMessage());
            $this->sendAndLogMessage($conversation, "No se pudieron cargar las preguntas. Para volver al menú principal, escribe 'volver' o 'menu'.");
        }
    }

    protected function showQualityQuestions(WhatsappConversation $conversation)
    {
        $qualityQuestions = [
            "Recomendaciones para una carga segura",
            "¿En qué caso se cobra al chofer un producto en mal estado?",
            "¿Con cuántos precintos debe contar mi camión?",
            "¿Qué pasa si mi lona/techo están en mal estado?"
        ];

        $headerText = "Preguntas sobre Calidad";
        $bodyText = "Selecciona la pregunta que deseas consultar:";

        $options = [];
        foreach ($qualityQuestions as $index => $question) {
            $options[] = [
                'id' => 'quality_' . ($index + 1),
                'title' => ($index + 1) . '. ' . substr($question, 0, 24) . (strlen($question) > 24 ? '...' : '')
            ];
        }

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
                    'options' => $options,
                ]),
                'metadata' => $response,
                'message_id' => $response['messages'][0]['id'] ?? null,
            ]);

            sleep(1);

            $buttons = [
                ['id' => 'back_to_menu', 'title' => 'Volver al Menú'],
                ['id' => 'exit', 'title' => 'Finalizar']
            ];

            $buttonResponse = $this->whatsappService->sendButtonMessage(
                $conversation->phone_number,
                "¿Qué deseas hacer ahora?",
                $buttons
            );

            WhatsappMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outgoing',
                'message' => "Botones de navegación",
                'metadata' => [
                    'buttons' => $buttons,
                    'response' => $buttonResponse
                ],
                'message_id' => $buttonResponse['messages'][0]['id'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending quality questions: ' . $e->getMessage());
            $this->sendAndLogMessage($conversation, "No se pudieron cargar las preguntas. Para volver al menú principal, escribe 'volver' o 'menu'.");
        }
    }

    protected function showTransportQuestions(WhatsappConversation $conversation)
    {
        $transportQuestions = [
            "¿Cuál es la ubicación de X centro de distribución o Planta?"
        ];

        $headerText = "Preguntas sobre Transportes";
        $bodyText = "Selecciona la pregunta que deseas consultar:";

        $options = [];
        foreach ($transportQuestions as $index => $question) {
            $options[] = [
                'id' => 'transport_' . ($index + 1),
                'title' => ($index + 1) . '. ' . substr($question, 0, 24) . (strlen($question) > 24 ? '...' : '')
            ];
        }

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
                    'options' => $options,
                ]),
                'metadata' => $response,
                'message_id' => $response['messages'][0]['id'] ?? null,
            ]);

            sleep(1);

            $buttons = [
                ['id' => 'back_to_menu', 'title' => 'Volver al Menú'],
                ['id' => 'exit', 'title' => 'Finalizar']
            ];

            $buttonResponse = $this->whatsappService->sendButtonMessage(
                $conversation->phone_number,
                "¿Qué deseas hacer ahora?",
                $buttons
            );

            WhatsappMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outgoing',
                'message' => "Botones de navegación",
                'metadata' => [
                    'buttons' => $buttons,
                    'response' => $buttonResponse
                ],
                'message_id' => $buttonResponse['messages'][0]['id'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending transport questions: ' . $e->getMessage());
            $this->sendAndLogMessage($conversation, "No se pudieron cargar las preguntas. Para volver al menú principal, escribe 'volver' o 'menu'.");
        }
    }


    protected function showTruckDetails(WhatsappConversation $conversation, Truck $truck)
    {
        $detailsMessage = "📋 *DETALLES DEL CAMIÓN*\n\n" .
            "*Placa:* {$truck->license_plate}\n" .
            "*Conductor:* {$truck->driver_name}\n" .
            "*Modelo:* {$truck->model}\n" .
            "*Año:* {$truck->year}\n" .
            "*Estado actual:* {$truck->status}\n";

        $this->sendAndLogMessage($conversation, $detailsMessage);

        sleep(1);

        $buttons = [
            ['id' => 'back_to_menu', 'title' => 'Volver al Menú'],
            ['id' => 'exit', 'title' => 'Finalizar']
        ];

        try {
            $buttonResponse = $this->whatsappService->sendButtonMessage(
                $conversation->phone_number,
                "¿Qué deseas hacer ahora?",
                $buttons
            );

            WhatsappMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outgoing',
                'message' => "Botones de navegación",
                'metadata' => [
                    'buttons' => $buttons,
                    'response' => $buttonResponse
                ],
                'message_id' => $buttonResponse['messages'][0]['id'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending navigation buttons: ' . $e->getMessage());
            $this->sendAndLogMessage($conversation, "Para volver al menú principal, escribe 'volver' o 'menu'.\nPara finalizar, escribe 'salir'.");
        }
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

        $this->sendAndLogMessage($conversation, $maintenanceMessage);

        // Agregar botones de navegación
        sleep(1); // Pequeña pausa para asegurar que los mensajes lleguen en orden

        $buttons = [
            ['id' => 'back_to_menu', 'title' => 'Volver al Menú'],
            ['id' => 'exit', 'title' => 'Finalizar']
        ];

        try {
            $buttonResponse = $this->whatsappService->sendButtonMessage(
                $conversation->phone_number,
                "¿Qué deseas hacer ahora?",
                $buttons
            );

            WhatsappMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outgoing',
                'message' => "Botones de navegación",
                'metadata' => [
                    'buttons' => $buttons,
                    'response' => $buttonResponse
                ],
                'message_id' => $buttonResponse['messages'][0]['id'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending navigation buttons: ' . $e->getMessage());
            $this->sendAndLogMessage($conversation, "Para volver al menú principal, escribe 'volver' o 'menu'.\nPara finalizar, escribe 'salir'.");
        }

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


            Log::info('Enviando mensaje', [
                'phone' => $conversation->phone_number,
                'message' => $message,
            ]);

            // Prevenir mensajes repetidos - verificar si el mismo mensaje se envió en los últimos 10 segundos
            $lastMessage = WhatsappMessage::where('conversation_id', $conversation->id)
                ->where('direction', 'outgoing')
                ->where('message', $message)
                ->where('created_at', '>', now()->subSeconds(10))
                ->first();

            if ($lastMessage) {
                Log::info('Mensaje repetido, no se envía nuevamente', [
                    'mensaje' => $message
                ]);
                return null;
            }

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

    // Métodos para manejar las respuestas a las preguntas
    protected function handleSecurityStep(WhatsappConversation $conversation, $message)
    {
        // Manejar botones de navegación primero
        $normalizedMessage = strtolower(trim($message));

        if ($normalizedMessage === 'volver al menú' || $normalizedMessage === 'volver' ||
            $normalizedMessage === 'menu' || $message === 'back_to_menu' ||
            $message === 'Volver al Menú') {

            $conversation->current_step = 'show_menu';
            $conversation->save();

            $truck = Truck::find($conversation->context_data['truck_id']);
            if ($truck) {
                $this->showMainMenu($conversation, $truck);
            } else {
                $this->resetConversation($conversation);
            }
            return;
        }

        // Manejar botón de salida
        if ($normalizedMessage === 'finalizar' || $normalizedMessage === 'salir' ||
            $message === 'exit' || $message === 'Finalizar') {

            $this->sendAndLogMessage($conversation, "Gracias por utilizar nuestro servicio. ¡Hasta pronto! 👋");
            $this->resetConversation($conversation);
            return;
        }

        // Obtener ID de la opción seleccionada
        $optionId = null;
        if (isset($conversation->context_data['last_selected_option_id'])) {
            $optionId = $conversation->context_data['last_selected_option_id'];
        }

        // Responder la pregunta seleccionada
        $securityAnswers = [
            'security_1' => "📋 *EPPS NECESARIOS*\n\nDebes contar con los siguientes Equipos de Protección Personal (EPP):\n- Casco de seguridad\n- Chaleco reflectante\n- Zapatos de seguridad\n- Guantes de protección\n- Gafas de seguridad\n- Protección auditiva cuando sea necesario",

            'security_2' => "🚫 *COMPORTAMIENTO INSEGURO*\n\nUn comportamiento inseguro es cualquier acción del trabajador que puede llevar a un accidente, como:\n- No usar EPP requerido\n- Exceder límites de velocidad\n- Usar el celular mientras se conduce\n- Conducir bajo efectos de alcohol o drogas\n- No respetar señales de tránsito\n- Realizar maniobras peligrosas",

            'security_3' => "⚠️ *CONDICIÓN INSEGURA*\n\nUna condición insegura es cualquier situación en el entorno que puede causar un accidente, como:\n- Frenos en mal estado\n- Luces defectuosas\n- Llantas desgastadas\n- Espejos rotos o mal ajustados\n- Carga mal asegurada\n- Señalización deficiente",

            'security_4' => "🚦 *VELOCIDAD MÁXIMA*\n\n- En carreteras principales: 80 km/h\n- En zonas urbanas: 40 km/h\n- En zonas escolares: 30 km/h\n- Dentro de instalaciones: 10 km/h\n\nRecuerda siempre respetar los límites de velocidad establecidos en las señales de tránsito.",

            'security_5' => "⏱️ *DESCANSO EN TRAYECTO*\n\nDebes descansar:\n- 15 minutos cada 2 horas de conducción continua\n- Mínimo 30 minutos después de 4 horas de conducción\n- Al menos 8 horas de descanso por cada jornada de 24 horas\n\nSiempre que sientas fatiga, detente en un lugar seguro.",

            'security_6' => "🔧 *REPORTE DE FALLAS MECÁNICAS*\n\nDebes reportar cualquier falla mecánica a:\n1. Tu supervisor directo\n2. Al departamento de mantenimiento\n3. Al número de emergencia: [NÚMERO DE CONTACTO]\n\nUtiliza el formato de reporte de fallas y toma fotografías si es posible.",

            'security_7' => "🚨 *PROBLEMAS EN RUTA*\n\nEn caso de problemas en ruta, debes comunicarte con:\n1. Centro de control operativo: [NÚMERO DE CONTACTO]\n2. Tu supervisor directo\n3. En caso de emergencia, al número de emergencia nacional\n\nMantén siempre tu teléfono cargado y con saldo.",

            'security_8' => "❌ *EVENTOS NO ACEPTADOS EN RUTA*\n\nLos siguientes eventos no están permitidos:\n- Desvíos no autorizados de la ruta\n- Paradas no programadas\n- Transportar pasajeros no autorizados\n- Exceder tiempos de entrega sin justificación\n- Consumir alcohol o drogas\n- Conducir a exceso de velocidad\n- Manipular los precintos de seguridad"
        ];

        // Si tenemos un ID válido y existe una respuesta
        if ($optionId && isset($securityAnswers[$optionId])) {
            $this->sendAndLogMessage($conversation, $securityAnswers[$optionId]);

            sleep(1);

            // Mostrar nuevamente las opciones después de responder
            $this->showSecurityQuestions($conversation);
        }
        // Si no encontramos un ID válido o no hay respuesta
        else {
            $this->sendAndLogMessage($conversation, "No entendí tu selección. Por favor, elige una de las opciones disponibles o escribe 'volver' para regresar al menú principal.");

            sleep(1);

            // Mostrar nuevamente las opciones
            $this->showSecurityQuestions($conversation);
        }
    }

    protected function handleQualityStep(WhatsappConversation $conversation, $message)
    {
        // Manejar botones de navegación primero
        $normalizedMessage = strtolower(trim($message));

        if ($normalizedMessage === 'volver al menú' || $normalizedMessage === 'volver' ||
            $normalizedMessage === 'menu' || $message === 'back_to_menu' ||
            $message === 'Volver al Menú') {

            $conversation->current_step = 'show_menu';
            $conversation->save();

            $truck = Truck::find($conversation->context_data['truck_id']);
            if ($truck) {
                $this->showMainMenu($conversation, $truck);
            } else {
                $this->resetConversation($conversation);
            }
            return;
        }

        // Manejar botón de salida
        if ($normalizedMessage === 'finalizar' || $normalizedMessage === 'salir' ||
            $message === 'exit' || $message === 'Finalizar') {

            $this->sendAndLogMessage($conversation, "Gracias por utilizar nuestro servicio. ¡Hasta pronto! 👋");
            $this->resetConversation($conversation);
            return;
        }

        // Obtener ID de la opción seleccionada
        $optionId = null;
        if (isset($conversation->context_data['last_selected_option_id'])) {
            $optionId = $conversation->context_data['last_selected_option_id'];
        }

        // Responder la pregunta seleccionada
        $qualityAnswers = [
            'quality_1' => "📦 *RECOMENDACIONES PARA UNA CARGA SEGURA*\n\n- Distribuye el peso uniformemente\n- Asegura la carga con correas, cadenas o redes apropiadas\n- No excedas la capacidad máxima del vehículo\n- Verifica que la carga esté bien fijada antes de partir\n- Usa calzas para evitar desplazamientos\n- Protege la carga de la lluvia con lonas en buen estado\n- Identifica y señaliza cargas que sobresalgan",

            'quality_2' => "💰 *COBROS AL CHOFER POR PRODUCTOS EN MAL ESTADO*\n\nSe cobrará al chofer cuando:\n- Se compruebe negligencia en el manejo de la carga\n- No se hayan usado las protecciones adecuadas estando disponibles\n- Se incumplan los procedimientos de carga/descarga\n- Se transporte productos de manera inadecuada\n- No se reporten daños previos a la carga\n- Se dañe el producto por exceso de velocidad o maniobras bruscas",

            'quality_3' => "🔒 *PRECINTOS REQUERIDOS*\n\nTu camión debe contar con los siguientes precintos:\n- Precinto de puerta trasera (obligatorio)\n- Precinto lateral (si aplica)\n- Precinto de tanque de combustible\n- Precinto de cabina (según el tipo de carga)\n\nRecuerda: Todos los precintos deben estar correctamente numerados y registrados en la guía de despacho.",

            'quality_4' => "🛑 *CONSECUENCIAS DE LONA/TECHO EN MAL ESTADO*\n\n- La mercancía podría dañarse por exposición al agua o sol\n- Se te puede negar el ingreso a plantas o centros de distribución\n- Podrías recibir una multa por incumplimiento de normativas\n- Se puede rechazar la carga en el punto de entrega\n- La empresa podría suspender temporalmente tu asignación\n- Podrías ser responsable económicamente por daños a la mercadería"
        ];

        // Si tenemos un ID válido y existe una respuesta
        if ($optionId && isset($qualityAnswers[$optionId])) {
            $this->sendAndLogMessage($conversation, $qualityAnswers[$optionId]);

            sleep(1);

            // Mostrar nuevamente las opciones después de responder
            $this->showQualityQuestions($conversation);
        }
        // Si no encontramos un ID válido o no hay respuesta
        else {
            $this->sendAndLogMessage($conversation, "No entendí tu selección. Por favor, elige una de las opciones disponibles o escribe 'volver' para regresar al menú principal.");

            sleep(1);

            // Mostrar nuevamente las opciones
            $this->showQualityQuestions($conversation);
        }
    }

    protected function handleTransportStep(WhatsappConversation $conversation, $message)
    {
        // Manejar botones de navegación primero
        $normalizedMessage = strtolower(trim($message));

        if ($normalizedMessage === 'volver al menú' || $normalizedMessage === 'volver' ||
            $normalizedMessage === 'menu' || $message === 'back_to_menu' ||
            $message === 'Volver al Menú') {

            $conversation->current_step = 'show_menu';
            $conversation->save();

            $truck = Truck::find($conversation->context_data['truck_id']);
            if ($truck) {
                $this->showMainMenu($conversation, $truck);
            } else {
                $this->resetConversation($conversation);
            }
            return;
        }

        // Manejar botón de salida
        if ($normalizedMessage === 'finalizar' || $normalizedMessage === 'salir' ||
            $message === 'exit' || $message === 'Finalizar') {

            $this->sendAndLogMessage($conversation, "Gracias por utilizar nuestro servicio. ¡Hasta pronto! 👋");
            $this->resetConversation($conversation);
            return;
        }

        // Obtener ID de la opción seleccionada
        $optionId = null;
        if (isset($conversation->context_data['last_selected_option_id'])) {
            $optionId = $conversation->context_data['last_selected_option_id'];
        }

        // Responder la pregunta seleccionada
        $transportAnswers = [
            'transport_1' => "📍 *UBICACIONES DE CENTROS DE DISTRIBUCIÓN Y PLANTAS*\n\n- Centro de Distribución Norte: [DIRECCIÓN COMPLETA]\nHorario: Lunes a Sábado 6:00 - 18:00\nContacto: [NÚMERO]\n\n- Centro de Distribución Sur: [DIRECCIÓN COMPLETA]\nHorario: Lunes a Viernes 7:00 - 19:00\nContacto: [NÚMERO]\n\n- Planta Principal: [DIRECCIÓN COMPLETA]\nHorario: 24/7\nContacto: [NÚMERO]\n\n- Centro de Operaciones: [DIRECCIÓN COMPLETA]\nHorario: Lunes a Domingo 5:00 - 22:00\nContacto: [NÚMERO]"
        ];

        // Si tenemos un ID válido y existe una respuesta
        if ($optionId && isset($transportAnswers[$optionId])) {
            $this->sendAndLogMessage($conversation, $transportAnswers[$optionId]);

            sleep(1);

            // Mostrar nuevamente las opciones después de responder
            $this->showTransportQuestions($conversation);
        }
        // Si no encontramos un ID válido o no hay respuesta
        else {
            $this->sendAndLogMessage($conversation, "No entendí tu selección. Por favor, elige una de las opciones disponibles o escribe 'volver' para regresar al menú principal.");

            sleep(1);

            // Mostrar nuevamente las opciones
            $this->showTransportQuestions($conversation);
        }
    }

}
