<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeviceBindingService {

    public function createDevice($request) {
        $dvc = [
            'user_agent' => $request->header('User-Agent'),
            'ip_address' => $request->ip(),
            'device_id' => $request->header('Device-Id'),
            'device_type' => $request->header('Device-Type'),
            'device_model' => $request->header('Device-Model'),
            'device_os' => $request->header('Device-OS'),
            'device_os_version' => $request->header('Device-OS-Version'),
        ];
        return hash('sha256', serialize($dvc));
    }

    public function registerDevide($matricula, $dF, $context = [])  {
        $dvcId = 'dev_'.bin2hex(random_bytes(16));

        $deviceRecord = [
            'device_id' => $dvcId,
            'user_matricula' => $matricula,
            'device_fingerprint' => $dF,
            'first_seen' => now(),
            'last_seen' => now(),
            'trust_level' => 1.0,
            'registration_context' => Crypt::encrypt($context),
            'status' => 'active',
        ];

        DB::table('acl_usuario_device')->insert($deviceRecord);

        return $dvcId;
    }


    /**
     * Verifica se dispositivo é confiável para o usuário
     */
    public function verifyDeviceTrust($matricula, $deviceFingerprint)
    {
        $device = DB::table('acl_usuario_device')
            ->where('user_matricula', $matricula)
            ->where('device_fingerprint', $deviceFingerprint)
            ->where('status', 'active')
            ->first();

        if (!$device) {
            return [
                'trusted' => false,
                'reason' => 'device_not_registered',
                'action' => 'register_new_device'
            ];
        }

        // Atualizar último acesso
        DB::table('acl_usuario_device')
            ->where('id', $device->id)
            ->update(['last_seen' => now()]);

        // Verificar nível de confiança
        if ($device->trust_level < 0.5) {
            return [
                'trusted' => false,
                'reason' => 'low_trust_level',
                'trust_level' => $device->trust_level,
                'action' => 'require_additional_verification'
            ];
        }

        return [
            'trusted' => true,
            'device_id' => $device->device_id,
            'trust_level' => $device->trust_level,
            'last_seen' => $device->last_seen
        ];
    }


    /**
     * Detecta atividade suspeita baseada em dispositivos
     */
    public function detectSuspiciousDeviceActivity($matricula, $deviceFingerprint)
    {
        $suspiciousIndicators = [];

        // 1. Múltiplos dispositivos em pouco tempo
        $recentDevices = DB::table('acl_usuario_device')
            ->where('user_matricula', $matricula)
            ->where('first_seen', '>', now()->subHours(1))
            ->count();

        if ($recentDevices > 2) {
            $suspiciousIndicators[] = 'multiple_devices_short_time';
        }

        // 2. Dispositivo de localização muito diferente
        $userDevices = DB::table('acl_usuario_device')
            ->where('user_matricula', $matricula)
            ->where('status', 'active')
            ->get();

        $currentIP = request()->ip();
        $suspiciousLocation = $this->detectSuspiciousLocation($currentIP, $userDevices);
        if ($suspiciousLocation) {
            $suspiciousIndicators[] = 'suspicious_location';
        }

        // 3. Padrão de User-Agent suspeito
        $userAgent = request()->header('User-Agent');
        if ($this->isSuspiciousUserAgent($userAgent)) {
            $suspiciousIndicators[] = 'suspicious_user_agent';
        }

        return $suspiciousIndicators;
    }

    /**
     * Integra com DocumentoBindingService existente
     */
    public function enhanceDocumentBinding($documentPath, $userInfo, $deviceFingerprint)
    {
        $deviceTrust = $this->verifyDeviceTrust($userInfo['matricula'], $deviceFingerprint);

        if (!$deviceTrust['trusted']) {
            throw new \Exception('DOCUMENTO REJEITADO: Dispositivo não confiável detectado. Razão: ' . $deviceTrust['reason']);
        }

        $suspiciousActivity = $this->detectSuspiciousDeviceActivity($userInfo['matricula'], $deviceFingerprint);

        if (!empty($suspiciousActivity)) {
            Log::warning('Atividade suspeita de dispositivo detectada', [
                'matricula' => $userInfo['matricula'],
                'indicators' => $suspiciousActivity,
                'device_fingerprint' => substr($deviceFingerprint, 0, 8) . '...'
            ]);

            // Se for muito suspeito, rejeitar
            if (in_array('multiple_devices_short_time', $suspiciousActivity) &&
                in_array('suspicious_location', $suspiciousActivity)) {
                throw new \Exception('DOCUMENTO REJEITADO: Padrão de uso suspeito detectado - possível fraude cross-device');
            }
        }

        return [
            'device_id' => $deviceTrust['device_id'],
            'trust_level' => $deviceTrust['trust_level'],
            'suspicious_indicators' => $suspiciousActivity
        ];
    }

    private function detectSuspiciousLocation($currentIP, $userDevices)
    {
        // Implementação simplificada - em produção usar GeoIP
        foreach ($userDevices as $device) {
            $context = Crypt::decrypt($device->registration_context);
            if (isset($context['ip_address']) && $context['ip_address'] !== $currentIP) {
                // IPs muito diferentes podem ser suspeitos
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica se o User-Agent é suspeito
     */
    private function isSuspiciousUserAgent($userAgent)
    {
        if (empty($userAgent)) {
            return true;
        }

        $suspiciousPatterns = [
            // User agents muito genéricos ou suspeitos
            '/^Mozilla\/5\.0$/i',
            '/curl/i',
            '/wget/i',
            '/python/i',
            '/bot/i',
            '/crawl/i',
            '/spider/i',
            '/scraper/i',
            // User agents muito antigos
            '/MSIE [1-6]\./i',
            // User agents com caracteres suspeitos
            '/[<>{}]/i',
            // User agents muito longos (possível payload)
            '/.{500,}/',
            // User agents comuns de ferramentas automatizadas
            '/PostmanRuntime/i',
            '/Insomnia/i',
            '/HTTPie/i',
            '/Apache-HttpClient/i',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }

        return false;
    }

}