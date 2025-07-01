<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DeviceBindingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestDeviceBinding extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:device-binding';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa a funcionalidade do DeviceBindingService';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('=== Teste do DeviceBindingService ===');
        $this->newLine();

        $deviceService = new DeviceBindingService();

        // 1. Criar fingerprint do dispositivo
        $this->info('1. Criando fingerprint do dispositivo...');
        $request = $this->createMockRequest();
        $deviceFingerprint = $deviceService->createDevice($request);
        $this->line("Device Fingerprint: " . substr($deviceFingerprint, 0, 16) . "...");
        $this->newLine();

        // 2. Registrar dispositivo para um usuário
        $this->info('2. Registrando dispositivo para usuário \'user123\'...');
        $context = [
            'ip_address' => $request->ip(),
            'timestamp' => now(),
            'registration_method' => 'manual'
        ];

        try {
            $deviceId = $deviceService->registerDevide('user123', $deviceFingerprint, $context);
            $this->line("Device ID criado: $deviceId");
            $this->newLine();
        } catch (\Exception $e) {
            $this->error("Erro ao registrar dispositivo: " . $e->getMessage());
            $this->newLine();
        }

        // 3. Verificar confiança do dispositivo
        $this->info('3. Verificando confiança do dispositivo...');
        try {
            $trustResult = $deviceService->verifyDeviceTrust('user123', $deviceFingerprint);
            $this->line("Resultado da verificação:");
            $this->line(json_encode($trustResult, JSON_PRETTY_PRINT));
            $this->newLine();
        } catch (\Exception $e) {
            $this->error("Erro ao verificar confiança: " . $e->getMessage());
            $this->newLine();
        }

        // 4. Detectar atividade suspeita
        $this->info('4. Detectando atividade suspeita...');
        try {
            $suspicious = $deviceService->detectSuspiciousDeviceActivity('user123', $deviceFingerprint);
            $this->line("Indicadores suspeitos: " . json_encode($suspicious));
            $this->newLine();
        } catch (\Exception $e) {
            $this->error("Erro ao detectar atividade suspeita: " . $e->getMessage());
            $this->newLine();
        }

        // 5. Teste com User-Agent suspeito
        $this->info('5. Testando com User-Agent suspeito...');
        $suspiciousRequest = $this->createSuspiciousRequest();
        $suspiciousFingerprint = $deviceService->createDevice($suspiciousRequest);

        try {
            $suspicious = $deviceService->detectSuspiciousDeviceActivity('user123', $suspiciousFingerprint);
            $this->line("Indicadores suspeitos com curl: " . json_encode($suspicious));
            $this->newLine();
        } catch (\Exception $e) {
            $this->error("Erro: " . $e->getMessage());
            $this->newLine();
        }

        // 6. Teste de integração com documento
        $this->info('6. Testando integração com documento...');
        try {
            $userInfo = ['matricula' => 'user123'];
            $result = $deviceService->enhanceDocumentBinding('/path/to/doc.pdf', $userInfo, $deviceFingerprint);
            $this->line("Resultado da integração: " . json_encode($result, JSON_PRETTY_PRINT));
            $this->newLine();
        } catch (\Exception $e) {
            $this->error("Erro na integração: " . $e->getMessage());
            $this->newLine();
        }

        // 7. Verificar dados na tabela
        $this->info('7. Verificando dados na tabela...');
        $devices = DB::table('acl_usuario_device')->where('user_matricula', 'user123')->get();
        $this->line("Total de dispositivos registrados para user123: " . $devices->count());

        foreach ($devices as $device) {
            $this->line("- Device ID: {$device->device_id}, Status: {$device->status}, Trust Level: {$device->trust_level}");
        }
        $this->newLine();

        $this->info('=== Teste concluído ===');

        // Limpar dados de teste
        if ($this->confirm('Deseja limpar os dados de teste criados?', true)) {
            DB::table('acl_usuario_device')->where('user_matricula', 'user123')->delete();
            $this->info('Dados de teste removidos.');
        }

        return 0;
    }

    private function createMockRequest()
    {
        return Request::create('/test', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'HTTP_DEVICE_ID' => 'device-123',
            'HTTP_DEVICE_TYPE' => 'desktop',
            'HTTP_DEVICE_MODEL' => 'Windows PC',
            'HTTP_DEVICE_OS' => 'Windows',
            'HTTP_DEVICE_OS_VERSION' => '10.0',
            'REMOTE_ADDR' => '192.168.1.100'
        ]);
    }

    private function createSuspiciousRequest()
    {
        return Request::create('/test', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'curl/7.68.0',
            'HTTP_DEVICE_ID' => 'suspicious-device',
            'HTTP_DEVICE_TYPE' => 'unknown',
            'HTTP_DEVICE_MODEL' => 'Unknown',
            'HTTP_DEVICE_OS' => 'Linux',
            'HTTP_DEVICE_OS_VERSION' => 'Unknown',
            'REMOTE_ADDR' => '10.0.0.1'
        ]);
    }
}
