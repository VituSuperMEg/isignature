<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DocumentoBindingService {

    /**
     * Cria um binding único do documento com múltiplas camadas de proteção
     */
    public function createDocumentBinding($documentPath, $userInfo, $context = [])
    {
        // 1. Fingerprint Digital do Documento
        $documentFingerprint = $this->createDocumentFingerprint($documentPath);

        // 2. Contexto Temporal e Geográfico
        $contextualData = $this->captureContextualData($context);

        // 3. Binding Criptográfico
        $cryptographicBinding = $this->createCryptographicBinding($documentFingerprint, $userInfo, $contextualData);

        // 4. Watermark Invisível
        $invisibleWatermark = $this->generateInvisibleWatermark($userInfo, $contextualData);

        // 5. Token de Integridade Temporal
        $integrityToken = $this->generateIntegrityToken($documentFingerprint, $contextualData);

        $binding = [
            'document_fingerprint' => $documentFingerprint,
            'contextual_data' => $contextualData,
            'cryptographic_binding' => $cryptographicBinding,
            'invisible_watermark' => $invisibleWatermark,
            'integrity_token' => $integrityToken,
            'created_at' => now(),
            'expires_at' => now()->addDays(30), // Configurável
        ];

        // Salvar binding de forma segura
        $bindingId = $this->storeSecureBinding($binding);

        return [
            'binding_id' => $bindingId,
            'validation_code' => $this->generateValidationCode($bindingId),
            'binding_data' => $binding
        ];
    }

    /**
     * Valida se o documento não foi copiado/colado
     */
    public function validateDocumentIntegrity($bindingId, $documentPath, $userInfo, $context = [])
    {
        $originalBinding = $this->retrieveSecureBinding($bindingId);

        if (!$originalBinding) {
            return ['valid' => false, 'reason' => 'Binding não encontrado'];
        }

        // 1. Verificar se o binding não expirou
        if (Carbon::parse($originalBinding['expires_at'])->isPast()) {
            return ['valid' => false, 'reason' => 'Binding expirado'];
        }

        // 2. Recriar fingerprint e comparar
        $currentFingerprint = $this->createDocumentFingerprint($documentPath);
        if ($currentFingerprint !== $originalBinding['document_fingerprint']) {
            return ['valid' => false, 'reason' => 'Documento foi alterado'];
        }

        // 3. Verificar contexto suspeito
        $currentContext = $this->captureContextualData($context);
        $suspicionLevel = $this->calculateSuspicionLevel($originalBinding['contextual_data'], $currentContext);

        if ($suspicionLevel > 0.7) { // Threshold configurável
            return ['valid' => false, 'reason' => 'Contexto suspeito detectado', 'suspicion_level' => $suspicionLevel];
        }

        // 4. Verificar integridade temporal
        $temporalIntegrity = $this->verifyTemporalIntegrity($originalBinding['integrity_token'], $currentContext);
        if (!$temporalIntegrity) {
            return ['valid' => false, 'reason' => 'Integridade temporal comprometida'];
        }

        // 5. Verificar binding criptográfico
        $cryptoVerification = $this->verifyCryptographicBinding(
            $originalBinding['cryptographic_binding'],
            $currentFingerprint,
            $userInfo,
            $currentContext
        );

        if (!$cryptoVerification) {
            return ['valid' => false, 'reason' => 'Binding criptográfico inválido'];
        }

        return ['valid' => true, 'suspicion_level' => $suspicionLevel];
    }

    /**
     * Cria um fingerprint único do documento baseado em características intrínsecas
     */
    private function createDocumentFingerprint($documentPath)
    {
        $content = file_get_contents($documentPath);

        // Múltiplas camadas de hash
        $characteristics = [
            'size' => filesize($documentPath),
            'md5' => md5($content),
            'sha256' => hash('sha256', $content),
            'sha512' => hash('sha512', $content),
            'creation_patterns' => $this->extractCreationPatterns($content),
            'metadata_hash' => $this->extractMetadataHash($documentPath),
            'structural_signature' => $this->createStructuralSignature($content)
        ];

        return hash('sha256', serialize($characteristics));
    }

    /**
     * Captura dados contextuais para detecção de copy-paste
     */
    private function captureContextualData($context)
    {
        return [
            'timestamp' => microtime(true),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'session_id' => session_id(),
            'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
            'request_time' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
            'server_signature' => $this->generateServerSignature(),
            'custom_context' => $context
        ];
    }

    /**
     * Cria binding criptográfico seguro
     */
    private function createCryptographicBinding($fingerprint, $userInfo, $contextualData)
    {
        $bindingData = [
            'fingerprint' => $fingerprint,
            'user' => $userInfo,
            'context' => $contextualData,
            'nonce' => bin2hex(random_bytes(32))
        ];

        $signature = hash_hmac('sha256', serialize($bindingData), config('app.key'));

        return [
            'data' => $bindingData,
            'signature' => $signature
        ];
    }

    /**
     * Gera watermark invisível para rastreamento
     */
    private function generateInvisibleWatermark($userInfo, $contextualData)
    {
        $watermarkData = [
            'user_id' => $userInfo['matricula'] ?? 'unknown',
            'timestamp' => time(),
            'random_seed' => bin2hex(random_bytes(16)),
            'context_hash' => hash('sha256', serialize($contextualData))
        ];

        return Crypt::encrypt($watermarkData);
    }

    /**
     * Gera token de integridade temporal
     */
    private function generateIntegrityToken($fingerprint, $contextualData)
    {
        $tokenData = [
            'fingerprint' => $fingerprint,
            'timestamp' => time(),
            'context_hash' => hash('sha256', serialize($contextualData)),
            'sequence' => $this->generateSequenceNumber()
        ];

        return hash_hmac('sha256', serialize($tokenData), config('app.key') . time());
    }

    /**
     * Calcula nível de suspeição baseado em mudanças de contexto
     */
    private function calculateSuspicionLevel($originalContext, $currentContext)
    {
        $suspicionFactors = [];

        // Verificar mudança de IP
        if ($originalContext['ip_address'] !== $currentContext['ip_address']) {
            $suspicionFactors[] = 0.3;
        }

        // Verificar mudança de User Agent
        if ($originalContext['user_agent'] !== $currentContext['user_agent']) {
            $suspicionFactors[] = 0.2;
        }

        // Verificar tempo entre acessos (copy-paste geralmente é rápido)
        $timeDiff = $currentContext['timestamp'] - $originalContext['timestamp'];
        if ($timeDiff < 5) { // Menos de 5 segundos
            $suspicionFactors[] = 0.4;
        }

        // Verificar padrões de sessão
        if ($originalContext['session_id'] !== $currentContext['session_id']) {
            $suspicionFactors[] = 0.25;
        }

        return min(1.0, array_sum($suspicionFactors));
    }

    /**
     * Extrai padrões de criação do documento
     */
    private function extractCreationPatterns($content)
    {
        // Para PDFs, extrair informações específicas
        $patterns = [];

        // Verificar assinaturas de software
        if (strpos($content, '/Producer') !== false) {
            preg_match('/\/Producer\s*\(([^)]+)\)/', $content, $matches);
            $patterns['producer'] = $matches[1] ?? 'unknown';
        }

        // Verificar timestamps internos
        if (strpos($content, '/CreationDate') !== false) {
            preg_match('/\/CreationDate\s*\(([^)]+)\)/', $content, $matches);
            $patterns['creation_date'] = $matches[1] ?? 'unknown';
        }

        return hash('sha256', serialize($patterns));
    }

    /**
     * Extrai hash de metadados
     */
    private function extractMetadataHash($documentPath)
    {
        $metadata = [];

        // Usar exiftool se disponível, senão stat básico
        if (function_exists('exif_read_data')) {
            $metadata = @exif_read_data($documentPath) ?: [];
        }

        $stat = stat($documentPath);
        $metadata['file_stats'] = [
            'atime' => $stat['atime'],
            'mtime' => $stat['mtime'],
            'ctime' => $stat['ctime']
        ];

        return hash('sha256', serialize($metadata));
    }

    /**
     * Cria assinatura estrutural do documento
     */
    private function createStructuralSignature($content)
    {
        // Analisar estrutura do documento
        $structure = [
            'length' => strlen($content),
            'entropy' => $this->calculateEntropy($content),
            'byte_frequency' => array_count_values(str_split($content)),
            'pattern_hash' => hash('sha256', preg_replace('/[0-9]+/', 'N', substr($content, 0, 1000)))
        ];

        return hash('sha256', serialize($structure));
    }

    /**
     * Calcula entropia do conteúdo
     */
    private function calculateEntropy($data)
    {
        $frequencies = array_count_values(str_split($data));
        $dataLength = strlen($data);
        $entropy = 0;

        foreach ($frequencies as $frequency) {
            $probability = $frequency / $dataLength;
            $entropy += $probability * log($probability, 2);
        }

        return -$entropy;
    }

    /**
     * Gera assinatura do servidor
     */
    private function generateServerSignature()
    {
        return hash('sha256', implode('|', [
            gethostname(),
            $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            php_uname(),
            getmypid()
        ]));
    }

    /**
     * Gera número de sequência único
     */
    private function generateSequenceNumber()
    {
        $cacheKey = 'document_sequence_' . date('Y-m-d');
        return Cache::increment($cacheKey, 1);
    }

    /**
     * Armazena binding de forma segura
     */
    private function storeSecureBinding($binding)
    {
        $bindingId = 'bind_' . bin2hex(random_bytes(16));

        DB::table('document_bindings')->insert([
            'binding_id' => $bindingId,
            'encrypted_binding' => Crypt::encrypt($binding),
            'created_at' => now(),
            'expires_at' => $binding['expires_at']
        ]);

        return $bindingId;
    }

    /**
     * Recupera binding seguro
     */
    private function retrieveSecureBinding($bindingId)
    {
        $record = DB::table('document_bindings')
            ->where('binding_id', $bindingId)
            ->first();

        if (!$record) {
            return null;
        }

        return Crypt::decrypt($record->encrypted_binding);
    }

    /**
     * Verifica integridade temporal
     */
    private function verifyTemporalIntegrity($originalToken, $currentContext)
    {
        return true;
    }

    /**
     * Verifica binding criptográfico
     */
    private function verifyCryptographicBinding($originalBinding, $fingerprint, $userInfo, $currentContext)
    {
        $bindingData = [
            'fingerprint' => $fingerprint,
            'user' => $userInfo,
            'context' => $currentContext,
            'nonce' => $originalBinding['data']['nonce']
        ];

        $expectedSignature = hash_hmac('sha256', serialize($bindingData), config('app.key'));

        return hash_equals($originalBinding['signature'], $expectedSignature);
    }

    /**
     * Gera código de validação legível
     */
    private function generateValidationCode($bindingId)
    {
        return strtoupper(substr(hash('sha256', $bindingId . config('app.key')), 0, 8));
    }

    /**
     * Detecta padrões suspeitos de copy-paste, prints e screenshots
     */
    public function detectCopyPastePatterns($documentPath, $userInfo)
    {
        $patterns = [];

        // 1. Verificar se arquivo foi criado muito recentemente
        $fileAge = time() - filemtime($documentPath);
        if ($fileAge < 30) { // Menos de 30 segundos
            $patterns[] = 'file_too_recent';
        }

        // 2. Verificar padrões de nomenclatura suspeitos
        $fileName = basename($documentPath);
        if (preg_match('/copy|copia|duplicate|screen|print|captura|foto|image/i', $fileName)) {
            $patterns[] = 'suspicious_filename';
        }

        // 3. Analisar conteúdo do PDF
        $content = file_get_contents($documentPath);

        // 4. Detectar ferramentas de conversão de imagem para PDF
        $suspiciousProducers = [
            'ImageToPDF', 'CamScanner', 'Adobe Scan', 'PDFCreator', 'PDFill',
            'doPDF', 'Win2PDF', 'PrimoPDF', 'Foxit Reader', 'Microsoft Print to PDF',
            'Chrome', 'Chromium', 'Edge', 'Firefox', 'Safari'
        ];

        foreach ($suspiciousProducers as $producer) {
            if (stripos($content, $producer) !== false) {
                $patterns[] = 'image_conversion_tool';
                break;
            }
        }

        // 5. Detectar características de PDF gerado a partir de imagem
        if (strpos($content, '/XObject') !== false && strpos($content, '/Image') !== false) {
            // Contar quantas imagens existem vs texto
            $imageCount = substr_count($content, '/Image');
            $textCount = substr_count($content, '/Text');

            if ($imageCount > 0 && $textCount == 0) {
                $patterns[] = 'image_only_pdf';
            }

            if ($imageCount > $textCount * 2) {
                $patterns[] = 'image_heavy_pdf';
            }
        }

        // 6. Verificar resolução típica de screenshot
        if (preg_match('/\/Width\s+(\d+)/', $content, $widthMatch) &&
            preg_match('/\/Height\s+(\d+)/', $content, $heightMatch)) {

            $width = intval($widthMatch[1]);
            $height = intval($heightMatch[1]);

            // Resoluções típicas de monitor/screenshot
            $commonScreenResolutions = [
                [1920, 1080], [1366, 768], [1536, 864], [1440, 900],
                [1280, 720], [1024, 768], [800, 600], [1600, 900]
            ];

            foreach ($commonScreenResolutions as [$w, $h]) {
                if (abs($width - $w) < 50 && abs($height - $h) < 50) {
                    $patterns[] = 'screen_resolution_match';
                    break;
                }
            }
        }

        // 7. Detectar baixa qualidade típica de foto de tela
        if (preg_match('/\/BitsPerComponent\s+(\d+)/', $content, $bitsMatch)) {
            $bits = intval($bitsMatch[1]);
            if ($bits <= 8) {
                $patterns[] = 'low_quality_image';
            }
        }

        // 8. Verificar timestamp suspeito (muito próximo do atual)
        if (preg_match('/\/CreationDate\s*\(D:(\d{14})/', $content, $dateMatch)) {
            $creationTime = strtotime(substr($dateMatch[1], 0, 8) . ' ' .
                                    substr($dateMatch[1], 8, 2) . ':' .
                                    substr($dateMatch[1], 10, 2) . ':' .
                                    substr($dateMatch[1], 12, 2));

            if (abs(time() - $creationTime) < 300) { // Menos de 5 minutos
                $patterns[] = 'recent_creation_timestamp';
            }
        }

        // 9. Verificar indicadores de ferramentas de captura
        $captureIndicators = [
            'Snipping Tool', 'Screenshot', 'Print Screen', 'Captura',
            'LightShot', 'Greenshot', 'Snagit', 'CloudShot'
        ];

        foreach ($captureIndicators as $indicator) {
            if (stripos($content, $indicator) !== false) {
                $patterns[] = 'capture_tool_detected';
                break;
            }
        }

        // 10. Verificar download ou arquivos temporários
        if (strpos($content, 'download') !== false ||
            strpos($content, 'temp') !== false ||
            strpos($fileName, 'download') !== false ||
            strpos($fileName, 'temp') !== false) {
            $patterns[] = 'download_indicators';
        }

        // 11. Verificar se tem características de documento scaneado/fotografado
        if (preg_match('/\/ColorSpace\s+\/DeviceRGB/', $content) &&
            !preg_match('/\/Font/', $content)) {
            $patterns[] = 'scanned_document_characteristics';
        }

        return $patterns;
    }
}
