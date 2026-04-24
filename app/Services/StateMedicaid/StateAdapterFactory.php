<?php

namespace App\Services\StateMedicaid;

class StateAdapterFactory
{
    public static function for(string $stateCode): ?StateAdapter
    {
        return match (strtoupper($stateCode)) {
            'CA' => new CaMedicaidAdapter(),
            'NY' => new NyMedicaidAdapter(),
            'FL' => new FlMedicaidAdapter(),
            default => null,
        };
    }
}
