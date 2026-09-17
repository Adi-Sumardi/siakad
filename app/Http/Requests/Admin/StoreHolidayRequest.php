<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreHolidayRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date', 'unique:holidays,date'],
            'label' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.unique' => 'Tanggal tersebut sudah terdaftar sebagai hari libur.',
        ];
    }
}
