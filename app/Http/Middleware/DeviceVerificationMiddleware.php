<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\DeviceBindingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DeviceVerificationMiddleware
{
    protected $deviceService;

    public function __construct(DeviceBindingService $deviceService)
    {
        $this->deviceService = $deviceService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Só verifica se o usuário estiver autenticado
        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();
        $deviceFingerprint = $this->deviceService->createDevice($request);

        try {
            // Verificar se o dispositivo é confiável
            $trustResult = $this->deviceService->verifyDeviceTrust($user->matricula, $deviceFingerprint);

            if (!$trustResult['trusted']) {
                Log::warning('Dispositivo não confiável detectado', [
                    'user_id' => $user->id,
                    'matricula' => $user->matricula,
                    'reason' => $trustResult['reason'],
                    'device_fingerprint' => substr($deviceFingerprint, 0, 8) . '...'
                ]);

                // Se dispositivo não está registrado, registrá-lo automaticamente
                if ($trustResult['reason'] === 'device_not_registered') {
                    $context = [
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->header('User-Agent'),
                        'timestamp' => now(),
                        'registration_method' => 'auto'
                    ];

                    $deviceId = $this->deviceService->registerDevide($user->matricula, $deviceFingerprint, $context);

                    Log::info('Novo dispositivo registrado automaticamente', [
                        'user_id' => $user->id,
                        'device_id' => $deviceId
                    ]);
                }
            }

            // Detectar atividade suspeita
            $suspiciousActivity = $this->deviceService->detectSuspiciousDeviceActivity($user->matricula, $deviceFingerprint);

            if (!empty($suspiciousActivity)) {
                Log::warning('Atividade suspeita detectada', [
                    'user_id' => $user->id,
                    'matricula' => $user->matricula,
                    'indicators' => $suspiciousActivity,
                    'device_fingerprint' => substr($deviceFingerprint, 0, 8) . '...'
                ]);

                // Se houver múltiplos indicadores suspeitos, requerer verificação adicional
                if (count($suspiciousActivity) >= 2) {
                    return response()->json([
                        'error' => 'Atividade suspeita detectada',
                        'message' => 'Sua conta foi temporariamente restringida devido a atividade suspeita. Entre em contato com o suporte.',
                        'code' => 'SUSPICIOUS_DEVICE_ACTIVITY'
                    ], 403);
                }
            }

            // Adicionar informações do dispositivo à requisição para uso posterior
            $request->merge([
                'device_fingerprint' => $deviceFingerprint,
                'device_trust_level' => $trustResult['trust_level'] ?? 0,
                'device_id' => $trustResult['device_id'] ?? null,
                'suspicious_indicators' => $suspiciousActivity
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na verificação de dispositivo', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Em caso de erro, permitir acesso mas registrar o problema
            // Em produção, você pode decidir ser mais restritivo
        }

        return $next($request);
    }
}
