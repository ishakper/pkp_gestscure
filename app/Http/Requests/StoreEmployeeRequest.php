<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'nullable|string|max:50|unique:employees,employee_id',
            'nik' => 'required|string|max:50|unique:employees,nik',
            'name' => 'required|string|max:255',
            'card_no' => 'nullable|string|max:50',
            'department' => 'required|string|max:100',
            'role' => 'nullable|string|max:100',
            'role_jabatan' => 'nullable|string|max:100',
            'fingerprint_enrolled' => 'nullable|boolean',
            'card_enrolled' => 'nullable|boolean',
            'door_ids' => 'nullable|array',
            'door_ids.*' => 'exists:doors,door_id',
        ];
    }
}
