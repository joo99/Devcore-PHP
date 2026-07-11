<?php

class PatientController {
    private $supabase;
    private $DEFAULT_DOCTOR_ID = '11111111-1111-1111-1111-111111111111';

    public function __construct($supabase) {
        $this->supabase = $supabase;
    }

    public function getPatients($params) {
        $doctorId = $params['doctor_id'] ?? $this->DEFAULT_DOCTOR_ID;
        $status = $params['status'] ?? null;

        $query = $this->supabase->from('patients')
            ->select('*')
            ->eq('doctor_id', $doctorId);

        if ($status) {
            $query->eq('status', $status);
        }

        $result = $query->order('created_at', ['ascending' => false])->execute();

        if ($result->error) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $result->error]);
            return;
        }

        $patients = $result->data ?? [];
        $stats = [
            'total' => count($patients),
            'pending' => count(array_filter($patients, fn($p) => $p['status'] === 'pending')),
            'complete' => count(array_filter($patients, fn($p) => $p['status'] === 'complete'))
        ];

        echo json_encode([
            'success' => true,
            'patients' => $patients,
            'stats' => $stats
        ]);
    }

    public function addPatient($data) {
        if (empty($data['name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Patient name is required']);
            return;
        }

        $patientData = array_merge([
            'doctor_id' => $this->DEFAULT_DOCTOR_ID,
            'status' => 'pending'
        ], $data);

        $result = $this->supabase->from('patients')->insert($patientData);

        if ($result->error) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $result->error]);
            return;
        }

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Patient added',
            'patient' => $result->data
        ]);
    }

    public function updatePatient($id, $data) {
        $result = $this->supabase->from('patients')
            ->update($data)
            ->eq('id', $id)
            ->execute();

        if ($result->error) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $result->error]);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Patient updated',
            'patient' => $result->data
        ]);
    }

    public function updatePatientStatus($id, $data) {
        $status = $data['status'] ?? '';
        if (!in_array($status, ['pending', 'complete'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid status. Must be pending or complete']);
            return;
        }

        $result = $this->supabase->from('patients')
            ->update(['status' => $status])
            ->eq('id', $id)
            ->execute();

        if ($result->error) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $result->error]);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => "Patient status updated to {$status}",
            'patient' => $result->data
        ]);
    }

    public function deletePatient($id) {
        $result = $this->supabase->from('patients')
            ->delete()
            ->eq('id', $id)
            ->execute();

        if ($result->error) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $result->error]);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Patient deleted'
        ]);
    }
}
