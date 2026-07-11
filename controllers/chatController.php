<?php

class ChatController {
    private $supabase;
    private $DEFAULT_DOCTOR_ID = '5b075375-0b21-4e67-91c8-1ab2c709fa85';

    public function __construct($supabase) {
        $this->supabase = $supabase;
    }

    public function createChatRoom($data) {
        $doctorId = $data['doctor_id'] ?? $this->DEFAULT_DOCTOR_ID;
        $patientName = $data['patient_name'] ?? '';
        $patientPhone = $data['patient_phone'] ?? '';

        if (empty($patientName) || empty($patientPhone)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Patient name and phone are required'
            ]);
            return;
        }

        $result = $this->supabase->from('chat_rooms')->insert([
            'doctor_id' => $doctorId,
            'patient_name' => $patientName,
            'patient_phone' => $patientPhone,
            'status' => 'active'
        ]);

        if ($result->error) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $result->error]);
            return;
        }

        $room = $result->data;
        $autoMessage = "👋 أهلاً بك يا {$patientName}! \nنرحب بك في عيادتنا، سنتواصل معك قريباً للمتابعة.";

        $this->supabase->from('chat_messages')->insert([
            'room_id' => $room['id'],
            'sender_type' => 'system',
            'message' => $autoMessage,
            'is_read' => false
        ]);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Chat room created successfully',
            'room' => $room,
            'autoMessage' => $autoMessage
        ]);
    }

    public function getChatRooms($params) {
        $doctorId = $params['doctor_id'] ?? $this->DEFAULT_DOCTOR_ID;

        $result = $this->supabase->from('chat_rooms')
            ->select('*')
            ->eq('doctor_id', $doctorId)
            ->order('updated_at', ['ascending' => false])
            ->execute();

        if ($result->error) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $result->error]);
            return;
        }

        $rooms = $result->data ?? [];
        $roomsWithMessages = [];

        foreach ($rooms as $room) {
            $lastMsgResult = $this->supabase->from('chat_messages')
                ->select('*')
                ->eq('room_id', $room['id'])
                ->order('created_at', ['ascending' => false])
                ->execute();
            
            $lastMessage = $lastMsgResult->data[0] ?? null;

            $unreadResult = $this->supabase->from('chat_messages')
                ->select('*')
                ->eq('room_id', $room['id'])
                ->eq('sender_type', 'patient')
                ->eq('is_read', false)
                ->execute();
            
            $unreadCount = count($unreadResult->data ?? []);

            if ($lastMessage) {
                $lastMessage['alignment'] = $lastMessage['sender_type'] === 'patient' ? 'left' : 'right';
            }

            $room['unread_count'] = $unreadCount;
            $room['last_message'] = $lastMessage;
            $roomsWithMessages[] = $room;
        }

        echo json_encode([
            'success' => true,
            'rooms' => $roomsWithMessages,
            'count' => count($roomsWithMessages)
        ]);
    }

    public function getChatRoom($roomId) {
        $roomResult = $this->supabase->from('chat_rooms')
            ->select('*')
            ->eq('id', $roomId)
            ->single();

        if ($roomResult->error || !$roomResult->data) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Chat room not found']);
            return;
        }

        $messagesResult = $this->supabase->from('chat_messages')
            ->select('*')
            ->eq('room_id', $roomId)
            ->order('created_at', ['ascending' => true])
            ->execute();

        $this->supabase->from('chat_messages')
            ->update(['is_read' => true])
            ->eq('room_id', $roomId)
            ->eq('sender_type', 'patient')
            ->eq('is_read', false)
            ->execute();

        $messages = $messagesResult->data ?? [];
        $messagesWithAlignment = array_map(function($msg) {
            $msg['alignment'] = $msg['sender_type'] === 'patient' ? 'left' : 'right';
            return $msg;
        }, $messages);

        $room = $roomResult->data;
        $room['messages'] = $messagesWithAlignment;

        echo json_encode([
            'success' => true,
            'room' => $room
        ]);
    }

    public function sendMessage($data) {
        $roomId = $data['roomId'] ?? null;
        $senderType = $data['senderType'] ?? 'patient';
        $message = $data['message'] ?? '';

        if (!$roomId || !$message) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Room ID and message are required']);
            return;
        }

        $result = $this->supabase->from('chat_messages')->insert([
            'room_id' => $roomId,
            'sender_type' => $senderType,
            'message' => $message,
            'is_read' => false
        ]);

        if ($result->error) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to save message: ' . $result->error]);
            return;
        }

        $this->supabase->from('chat_rooms')
            ->update(['updated_at' => date('c')])
            ->eq('id', $roomId)
            ->execute();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Message sent successfully',
            'data' => $result->data
        ]);
    }

    public function updateChatRoomStatus($roomId, $data) {
        $status = $data['status'] ?? '';
        if (!in_array($status, ['active', 'closed', 'archived'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid status']);
            return;
        }

        $result = $this->supabase->from('chat_rooms')
            ->update(['status' => $status, 'updated_at' => date('c')])
            ->eq('id', $roomId)
            ->execute();

        if ($result->error || !$result->data) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Chat room not found']);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => "Chat room {$status} successfully",
            'room' => $result->data
        ]);
    }

    public function deleteChatRoom($roomId, $params) {
        $doctorId = $params['doctor_id'] ?? $this->DEFAULT_DOCTOR_ID;

        $roomResult = $this->supabase->from('chat_rooms')
            ->select('doctor_id')
            ->eq('id', $roomId)
            ->single();

        if ($roomResult->error || !$roomResult->data) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Chat room not found']);
            return;
        }

        if ($roomResult->data['doctor_id'] !== $doctorId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You are not authorized to delete this chat room']);
            return;
        }

        $result = $this->supabase->from('chat_rooms')
            ->delete()
            ->eq('id', $roomId)
            ->execute();

        if ($result->error) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $result->error]);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Chat room deleted successfully'
        ]);
    }
}
