<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee') ?? $this->route('id');

        return [
            'employee_id' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('employees', 'employee_id')->ignore($employeeId),
            ],
            'nik' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('employees', 'nik')->ignore($employeeId),
            ],
            'name' => 'sometimes|required|string|max:255',
            'card_no' => 'nullable|string|max:50',
            'department' => 'sometimes|required|string|max:100',
            'role' => 'nullable|string|max:100',
            'role_jabatan' => 'nullable|string|max:100',
            'fingerprint_enrolled' => 'nullable|boolean',
            'card_enrolled' => 'nullable|boolean',
        ];
    }
}
