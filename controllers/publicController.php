<?php

class PublicController {
    private $supabase;

    public function __construct($supabase) {
        $this->supabase = $supabase;
    }

    public function getDoctor($doctorId) {
        $result = $this->supabase->from('doctors')
            ->select('id, name, specialty, phone, clinic_address')
            ->eq('id', $doctorId)
            ->single();

        if ($result->error || !$result->data) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Doctor not found']);
            return;
        }

        echo json_encode(['success' => true, 'doctor' => $result->data]);
    }

    public function bookVisit($data) {
        $doctorId = $data['doctor_id'] ?? null;
        $patientName = $data['patient_name'] ?? null;
        $visitDate = $data['visit_date'] ?? null;
        $visitTime = $data['visit_time'] ?? null;

        if (!$doctorId || !$patientName || !$visitDate || !$visitTime) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required fields']);
            return;
        }

        $result = $this->supabase->from('patient_visits')->insert([
            'doctor_id' => $doctorId,
            'patient_name' => $patientName,
            'visit_date' => $visitDate,
            'visit_time' => $visitTime,
            'status' => 'pending'
        ]);

        if ($result->error) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $result->error]);
            return;
        }

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Visit booked successfully!',
            'visit' => $result->data
        ]);
    }
}
