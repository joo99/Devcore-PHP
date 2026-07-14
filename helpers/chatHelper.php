<?php

class ChatHelper {
    private $db;
    private $DEFAULT_DOCTOR_ID = '5b075375-0b21-4e67-91c8-1ab2c709fa85';

    public function __construct($db) {
        $this->db = $db;
    }

    public function createChatRoom($data) {
        $doctorId = $data['doctor_id'] ?? $this->DEFAULT_DOCTOR_ID;
        $patientName = $data['patient_name'] ?? '';
        $patientPhone = $data['patient_phone'] ?? '';

        if (empty($patientName) || empty($patientPhone)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'missing patient_name/patient_phone']);
            return;
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("INSERT INTO chat_rooms (doctor_id, patient_name, patient_phone, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$doctorId, $patientName, $patientPhone]);
            $roomId = $this->db->lastInsertId();

            $autoMessage = "👋 أهلاً بك يا {$patientName}! \nنرحب بك في عيادتنا، سنتواصل معك قريباً للمتابعة.";
            $stmtMsg = $this->db->prepare("INSERT INTO chat_messages (room_id, sender_type, message, is_read) VALUES (?, 'system', ?, 0)");
            $stmtMsg->execute([$roomId, $autoMessage]);

            $this->db->commit();

            // Get the newly created room to match original response style
            $stmt = $this->db->prepare("SELECT * FROM chat_rooms WHERE id = ?");
            $stmt->execute([$roomId]);
            $newRoom = $stmt->fetch();
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Chat room created successfully',
                'room' => $newRoom,
                'autoMessage' => $autoMessage
            ]);
        } catch (PDOException $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function getChatRooms($params) {
        $doctorId = $params['doctor_id'] ?? $this->DEFAULT_DOCTOR_ID;

        try {
            $stmt = $this->db->prepare("SELECT * FROM chat_rooms WHERE doctor_id = ? ORDER BY updated_at DESC");
            $stmt->execute([$doctorId]);
            $rooms = $stmt->fetchAll();

            $roomsWithMessages = [];
            foreach ($rooms as $room) {
                $roomId = $room['id'];

                // Last message
                $stmtLast = $this->db->prepare("SELECT * FROM chat_messages WHERE room_id = ? ORDER BY created_at DESC LIMIT 1");
                $stmtLast->execute([$roomId]);
                $lastMessage = $stmtLast->fetch();
                if ($lastMessage) {
                    $lastMessage['alignment'] = ($lastMessage['sender_type'] === 'patient') ? 'left' : 'right';
                }

                // Unread count
                $stmtUnread = $this->db->prepare("SELECT COUNT(*) as count FROM chat_messages WHERE room_id = ? AND sender_type = 'patient' AND is_read = 0");
                $stmtUnread->execute([$roomId]);
                $unreadCount = $stmtUnread->fetch()['count'];

                $room['unread_count'] = $unreadCount;
                $room['last_message'] = $lastMessage;
                $roomsWithMessages[] = $room;
            }

            echo json_encode([
                'success' => true,
                'rooms' => $roomsWithMessages,
                'count' => count($roomsWithMessages)
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function getChatRoom($roomId) {
        try {
            $stmtRoom = $this->db->prepare("SELECT * FROM chat_rooms WHERE id = ?");
            $stmtRoom->execute([$roomId]);
            $room = $stmtRoom->fetch();

            if (!$room) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'room not found']);
                return;
            }

            // Mark as read
            $stmtRead = $this->db->prepare("UPDATE chat_messages SET is_read = 1 WHERE room_id = ? AND sender_type = 'patient' AND is_read = 0");
            $stmtRead->execute([$roomId]);

            // Get messages
            $stmtMsgs = $this->db->prepare("SELECT * FROM chat_messages WHERE room_id = ? ORDER BY created_at ASC");
            $stmtMsgs->execute([$roomId]);
            $messages = $stmtMsgs->fetchAll();

            $room['messages'] = array_map(function($msg) {
                $msg['alignment'] = ($msg['sender_type'] === 'patient') ? 'left' : 'right';
                return $msg;
            }, $messages);

            echo json_encode(['success' => true, 'room' => $room]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function sendMessage($data) {
        $roomId = $data['roomId'] ?? null;
        $senderType = $data['senderType'] ?? 'patient';
        $message = $data['message'] ?? '';

        if (!$roomId || !$message) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'missing roomId/message']);
            return;
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO chat_messages (room_id, sender_type, message, is_read) VALUES (?, ?, ?, 0)");
            $stmt->execute([$roomId, $senderType, $message]);
            $msgId = $this->db->lastInsertId();

            // Update room updated_at
            $stmtRoom = $this->db->prepare("UPDATE chat_rooms SET updated_at = NOW() WHERE id = ?");
            $stmtRoom->execute([$roomId]);

            // Get the newly created message to match original response style
            $stmt = $this->db->prepare("SELECT * FROM chat_messages WHERE id = ?");
            $stmt->execute([$msgId]);
            $newMessage = $stmt->fetch();
            if ($newMessage) {
                $newMessage['alignment'] = $newMessage['sender_type'] === 'patient' ? 'left' : 'right';
            }
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Message sent successfully',
                'data' => $newMessage
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function updateChatRoomStatus($roomId, $data) {
        $status = $data['status'] ?? '';
        if (!in_array($status, ['active', 'closed', 'archived'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid status']);
            return;
        }

        try {
            $stmt = $this->db->prepare("UPDATE chat_rooms SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $roomId]);

            // Get the updated room
            $stmt = $this->db->prepare("SELECT * FROM chat_rooms WHERE id = ?");
            $stmt->execute([$roomId]);
            $updatedRoom = $stmt->fetch();
            
            echo json_encode([
                'success' => true, 
                'message' => "Chat room {$status} successfully",
                'room' => $updatedRoom
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function deleteChatRoom($roomId, $params) {
        $doctorId = $params['doctor_id'] ?? $this->DEFAULT_DOCTOR_ID;

        try {
            $stmtCheck = $this->db->prepare("SELECT doctor_id FROM chat_rooms WHERE id = ?");
            $stmtCheck->execute([$roomId]);
            $room = $stmtCheck->fetch();

            if (!$room) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'room not found']);
                return;
            }

            if ($room['doctor_id'] !== $doctorId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'not authorized (doctor_id mismatch)']);
                return;
            }

            $stmtDel = $this->db->prepare("DELETE FROM chat_rooms WHERE id = ?");
            $stmtDel->execute([$roomId]);

            echo json_encode(['success' => true, 'message' => 'Chat room deleted successfully']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
?>
