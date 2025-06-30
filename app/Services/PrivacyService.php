<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PrivacyService
{


    public function secureData(array $data): string {
        $token = 'tk_'. bin2hex(random_bytes(16));

        DB::table('acl_secure_token')->insert([
            'token' => $token,
            'encrypted_data' => Crypt::encrypt($data),
            'created_at' => now(),
            'expires_at' => now()->addYears(100),
        ]);

        return $token;
    }
}
