<?php

namespace App\Http\Controllers;

use App\Services\DocumentoBindingService;
use App\Services\DeviceBindingService;
use App\Services\DocumentoSecurityService;
use App\Services\DocumentSecurityService;
use App\Services\PrivacyService;
use App\Services\SignatureServices;
use App\Services\ZeroKnowlegdeService;
use Illuminate\Http\Request;
use setasign\Fpdi\Fpdi;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Exception;


class PDF_Rotate extends Fpdi
{
    protected $angle = 0;

    public function Rotate($angle, $x = -1, $y = -1)
    {
        if ($x == -1) {
            $x = $this->x;
        }
        if ($y == -1) {
            $y = $this->y;
        }
        if ($this->angle != 0) {
            $this->_out('Q');
        }
        $this->angle = $angle;
        if ($angle != 0) {
            $angle *= M_PI / 180;
            $c = cos($angle);
            $s = sin($angle);
            $cx = $x * $this->k;
            $cy = ($this->h - $y) * $this->k;
            $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $c, $s, -$s, $c, $cx, $cy, -$cx, -$cy));
        }
    }

    public function _endpage()
    {
        if ($this->angle != 0) {
            $this->angle = 0;
            $this->_out('Q');
        }
        parent::_endpage();
    }

    public function RotatedText($x, $y, $txt, $angle)
    {
        //Text rotated around its origin
        $this->Rotate($angle, $x, $y);
        $this->Text($x, $y, $txt);
        $this->Rotate(0);
    }
}

class SignatureController extends Controller
{

    public function __construct()
    {
        $this->middleware('throttle:10,1');
    }

    public function index(Request $request)
    {
        try {
            $entidade = $request->entidade;
            $cnpj = $request->cnpj;

            $zk =  new ZeroKnowlegdeService();
            $signatureServices = new SignatureServices();
            $privacyService = new PrivacyService();



            // esse codigo vai verificar atividades suspeitas
            $documentoBindingService = new DocumentoBindingService();

            // sistema de vinculação de dispositivos para segurança adicional
            $deviceBindingService = new DeviceBindingService();


            if ($request->has(['dados', 'iv'])) {
                $dadosArrayInput = $request->input('dados');
                $ivArrayInput = $request->input('iv');

                $dadosArray = is_string($dadosArrayInput) ? json_decode($dadosArrayInput, true) : $dadosArrayInput;
                $ivArray = is_string($ivArrayInput) ? json_decode($ivArrayInput, true) : $ivArrayInput;

                if (!is_array($dadosArray) || !is_array($ivArray)) {
                    throw new Exception('Dados criptografados inválidos - formato incorreto');
                }

                $senha = '';
                foreach ($ivArray as $byte) {
                    $senha .= chr($byte);
                }

                $dadosDescriptografados = $this->descriptografarDados($dadosArray, $ivArray, $senha);

                $nome = $dadosDescriptografados['nome'] ?? null;
                $cpf = $dadosDescriptografados['cpf'] ?? null;
                $cargo = $dadosDescriptografados['cargo'] ?? null;
                $secretaria = $dadosDescriptografados['secretaria'] ?? null;
                $matricula = $dadosDescriptografados['matricula'] ?? null;
                $entidade = $dadosDescriptografados['entidade'] ?? null;
                Log::info('Dados descriptografados com sucesso', [
                    'matricula' => $matricula,
                    'cpf_partial' => $cpf ? substr($cpf, 0, 3) . '***' : null
                ]);
            } else {
                $nome = $request->nome;
                $cpf = $request->cpf;
                $cargo = $request->cargo;
                $secretaria = $request->secretaria;
                $matricula = $request->matricula;
            }

            $data_assinatura = $request->date ?? date('d/m/Y H:i:s');


            $zkAuth = $zk->createUserProof(
                $matricula,
                $cpf,
                $request->senha ?? null
            );

            // === DEVICE BINDING - Verificação e registro de dispositivo ===
            $deviceInfo = null;
            try {
                Log::info('Iniciando verificação de dispositivo', ['matricula' => $matricula]);

                // Criar fingerprint do dispositivo
                $deviceFingerprint = $deviceBindingService->createDevice($request);
                Log::info('Device fingerprint criado', [
                    'matricula' => $matricula,
                    'fingerprint_preview' => substr($deviceFingerprint, 0, 8) . '...'
                ]);

                // Verificar se o dispositivo é confiável
                $trustResult = $deviceBindingService->verifyDeviceTrust($matricula, $deviceFingerprint);

                if (!$trustResult['trusted']) {
                    Log::warning('Dispositivo não confiável detectado', [
                        'matricula' => $matricula,
                        'reason' => $trustResult['reason'],
                        'device_fingerprint' => substr($deviceFingerprint, 0, 8) . '...'
                    ]);

                    // Se dispositivo não está registrado, registrá-lo automaticamente
                    if ($trustResult['reason'] === 'device_not_registered') {
                        $context = [
                            'ip_address' => $request->ip(),
                            'user_agent' => $request->header('User-Agent'),
                            'timestamp' => now(),
                            'registration_method' => 'auto_signature',
                            'document_type' => 'pdf_signature',
                            'entidade' => $entidade
                        ];

                        $deviceId = $deviceBindingService->registerDevide($matricula, $deviceFingerprint, $context);

                        Log::info('Novo dispositivo registrado automaticamente', [
                            'matricula' => $matricula,
                            'device_id' => $deviceId
                        ]);

                        $deviceInfo = [
                            'device_id' => $deviceId,
                            'trust_level' => 1.0,
                            'newly_registered' => true
                        ];
                    }
                } else {
                    Log::info('Dispositivo confiável verificado', [
                        'matricula' => $matricula,
                        'device_id' => $trustResult['device_id'],
                        'trust_level' => $trustResult['trust_level']
                    ]);

                    $deviceInfo = [
                        'device_id' => $trustResult['device_id'],
                        'trust_level' => $trustResult['trust_level'],
                        'newly_registered' => false
                    ];
                }

                // Detectar atividade suspeita
                $suspiciousActivity = $deviceBindingService->detectSuspiciousDeviceActivity($matricula, $deviceFingerprint);

                if (!empty($suspiciousActivity)) {
                    Log::warning('Atividade suspeita de dispositivo detectada', [
                        'matricula' => $matricula,
                        'indicators' => $suspiciousActivity,
                        'device_fingerprint' => substr($deviceFingerprint, 0, 8) . '...'
                    ]);

                    // Se houver múltiplos indicadores suspeitos, rejeitar
                    if (count($suspiciousActivity) >= 2) {
                        Log::error('Documento rejeitado por atividade suspeita de dispositivo', [
                            'matricula' => $matricula,
                            'indicators' => $suspiciousActivity
                        ]);

                        throw new Exception('DOCUMENTO REJEITADO: Atividade suspeita detectada no dispositivo. Múltiplos indicadores de segurança foram acionados. Entre em contato com o suporte se você acredita que isso é um erro.');
                    }

                    if ($deviceInfo) {
                        $deviceInfo['suspicious_indicators'] = $suspiciousActivity;
                    }
                }

            } catch (Exception $deviceException) {
                Log::error('Erro na verificação de dispositivo', [
                    'matricula' => $matricula,
                    'error' => $deviceException->getMessage(),
                    'trace' => $deviceException->getTraceAsString()
                ]);

                // Se foi erro de rejeição por atividade suspeita, propagar
                if (strpos($deviceException->getMessage(), 'DOCUMENTO REJEITADO') !== false) {
                    throw $deviceException;
                }

                // Para outros erros, continuar mas registrar que houve problema
                $deviceInfo = [
                    'device_id' => 'ERROR_' . time(),
                    'trust_level' => 0,
                    'error' => true,
                    'error_message' => $deviceException->getMessage()
                ];
            }

            if (!$request->hasFile('pdf')) {
                return response()->json(['error' => 'No PDF file provided'], 400)
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            }

            $pdfPath = $request->file('pdf')->getPathname();

            $this->validatePdfIntegrity($pdfPath);

            $tempPath = tempnam(sys_get_temp_dir(), 'pdf_');

            // remove qualquer senha ou criptografia do pdf
            exec("pdftk {$pdfPath} output {$tempPath} allow AllFeatures", $output, $returnVar);

            Log::info($returnVar);
            if ($returnVar !== 0) {
                throw new Exception("Failed to process PDF with pdftk" . $returnVar);
            }

            $fpdi = new PDF_Rotate();
            $pageCount = $fpdi->setSourceFile($tempPath);
            $uuid = Uuid::uuid4();


            $codigoTransacao = $uuid->toString();

            $chave_publica = $signatureServices->openSSL(array(
                'cpf' => $cpf,
                'matricula' => $matricula,
            ), $matricula, $pdfPath);


            try {
                Log::info('Iniciando detecção de padrões suspeitos', ['matricula' => $matricula]);
                $suspiciousPatterns = $documentoBindingService->detectCopyPastePatterns($pdfPath, [
                    'cpf' => $cpf,
                    'matricula' => $matricula,
                ]);

                if (!empty($suspiciousPatterns)) {
                    Log::warning('Padrões suspeitos detectados', ['patterns' => $suspiciousPatterns, 'matricula' => $matricula]);

                    $suspicionScore = $this->calculateSuspicionScore($suspiciousPatterns);

                    if ($suspicionScore >= 2) {
                        Log::error('Documento rejeitado por suspeição detectada', [
                            'patterns' => $suspiciousPatterns,
                            'suspicion_score' => $suspicionScore,
                            'matricula' => $matricula
                        ]);

                        throw new Exception('DOCUMENTO REJEITADO: Detectadas características de print/screenshot ou documento não original. Este sistema não aceita documentos capturados de tela. Padrões detectados: ' . implode(', ', $suspiciousPatterns) . '. Envie o documento PDF original.');
                    }

                    if ($suspicionScore >= 1) {
                        Log::warning('Documento com suspeição baixa aceito com monitoramento', [
                            'patterns' => $suspiciousPatterns,
                            'suspicion_score' => $suspicionScore,
                            'matricula' => $matricula
                        ]);
                    }
                }

                Log::info('Criando document binding', ['matricula' => $matricula]);
                $binding = $documentoBindingService->createDocumentBinding($pdfPath, [
                    'cpf' => $cpf,
                    'matricula' => $matricula,
                    'nome' => $nome,
                ], [
                    'entidade' => $entidade,
                    'cargo' => $cargo,
                ]);

                // Integrar device binding com document binding se disponível
                if ($deviceInfo && !empty($deviceInfo['device_id']) && !isset($deviceInfo['error'])) {
                    try {
                        Log::info('Integrando device binding com document binding', [
                            'matricula' => $matricula,
                            'device_id' => $deviceInfo['device_id']
                        ]);

                        $deviceEnhancement = $deviceBindingService->enhanceDocumentBinding(
                            $pdfPath,
                            ['matricula' => $matricula],
                            $deviceFingerprint ?? null
                        );

                        $binding['device_info'] = $deviceEnhancement;

                        Log::info('Device binding integrado com sucesso ao documento', [
                            'matricula' => $matricula,
                            'device_id' => $deviceEnhancement['device_id'],
                            'trust_level' => $deviceEnhancement['trust_level']
                        ]);

                    } catch (Exception $deviceIntegrationException) {
                        Log::error('Erro ao integrar device binding com document binding', [
                            'matricula' => $matricula,
                            'error' => $deviceIntegrationException->getMessage()
                        ]);

                        // Se foi rejeição por device suspeito, propagar erro
                        if (strpos($deviceIntegrationException->getMessage(), 'DOCUMENTO REJEITADO') !== false) {
                            throw $deviceIntegrationException;
                        }
                    }
                }

                Log::info('Document binding criado com sucesso', [
                    'binding_id' => $binding['binding_id'] ?? 'não definido',
                    'validation_code' => $binding['validation_code'] ?? 'não definido',
                    'matricula' => $matricula
                ]);
            } catch (Exception $bindingException) {
                Log::error('Erro ao criar document binding', [
                    'error' => $bindingException->getMessage(),
                    'matricula' => $matricula,
                    'trace' => $bindingException->getTraceAsString()
                ]);

                if (strpos($bindingException->getMessage(), 'DOCUMENTO REJEITADO') !== false) {
                    throw $bindingException;
                }

                $binding = [
                    'binding_id' => 'ERROR_' . time(),
                    'validation_code' => 'ERROR'
                ];
            }


            // $md5Hash = md5($pdfPath);
            $codigoVerificao = $signatureServices->randomCode();

            $token = $privacyService->secureData(
                array(
                    'nome' => $nome,
                    'entidade' => $entidade,
                    'chave_publica' => $chave_publica['public_key'],
                    'document_id' => $chave_publica['document_id'],
                    'codigo_transacao' => $codigoTransacao,
                    'dta_ass' => $data_assinatura,
                )
            );

            // Gerar o qrcode
            // $qrImage = QrCode::format('png')->size(500)->generate('http://192.168.18.243:8001/api/verifySignature?token=' . $token . '&zk=' . $zkAuth['zk_token']);
            $qrImage = QrCode::format('png')->size(500)->generate('http://192.168.18.243:8000/'.$entidade.'/services/signature/confirmation-signature?token=' . $token . '&zk=' . $zkAuth['zk_token']);
            $qrData = 'data://text/plain;base64,' . base64_encode($qrImage);

            $repeat = $request->input('repeat', false);

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $template = $fpdi->importPage($pageNo);
                $fpdi->addPage();
                $fpdi->useTemplate($template);

                if ($repeat || $pageNo === $pageCount) {
                    $pageHeight = $fpdi->GetPageHeight();
                    $pageWidth = $fpdi->GetPageWidth();
                    $margin = 10;
                    $lineHeight = 4;
                    $qrWidth = 20;
                    $qrHeight = 20;

                    $fpdi->setFont('helvetica', '', 6);
                    $textLines = [
                        "DOCUMENTO ASSINADO DIGITALMENTE. CODIGO {$codigoVerificao} - DATA DA ASSINATURA: {$data_assinatura}",
                        strtoupper($nome) . " CPF: " . $signatureServices->formataCnpjCpf($cpf) . " MATRICULA: {$matricula}",
                        "CARGO: " . strtoupper($cargo) . " ORGAO:" . $signatureServices->removeCaracteresEspeciais($secretaria, ' '),
                        "APONTE SUA CAMARA PARA O QRCODE PARA VERIFICAR AUTENTICIDADE.",
                    ];
                    $totalTextBlockWidth = (count($textLines) - 1) * $lineHeight;

                    // --- Bloco da Direita ---
                    // $qrX_right = $pageWidth - $qrWidth - $margin;
                    $qrY_common = $pageHeight - $qrHeight - $margin;
                    // $fpdi->Image($qrData, $qrX_right, $qrY_common, $qrWidth, $qrHeight, 'png');

                    // $textBlockY_right = $qrY_common - 2;
                    // $textBlockStartX_right = $qrX_right + ($qrWidth / 2) - ($totalTextBlockWidth / 2);

                    // $currentX_right = $textBlockStartX_right;
                    // foreach ($textLines as $line) {
                    //     $fpdi->RotatedText($currentX_right, $textBlockY_right, $line, 90);
                    //     $currentX_right += $lineHeight;
                    // }

                    // --- Bloco da Esquerda ---
                    $qrX_left = $margin;
                    $fpdi->Image($qrData, $qrX_left, $qrY_common, $qrWidth, $qrHeight, 'png');

                    $textBlockY_left = $qrY_common - 2;
                    $textBlockStartX_left = $qrX_left + ($qrWidth / 2) - ($totalTextBlockWidth / 2) - 1;

                    $currentX_left = $textBlockStartX_left;
                    foreach ($textLines as $line) {
                        $fpdi->RotatedText($currentX_left, $textBlockY_left, $line, 90);
                        $currentX_left += $lineHeight;
                    }
                }
            }

            $output = $fpdi->Output('S');

            unlink($tempPath);

            return response($output, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Verification-Code', $codigoVerificao)
                ->header('Id-Documento', $codigoTransacao)
                ->header('Document-Binding-Id', $binding['binding_id'])
                ->header('Validation-Code', $binding['validation_code'])
                ->header('Device-Id', $deviceInfo['device_id'] ?? 'not-available')
                ->header('Device-Trust-Level', $deviceInfo['trust_level'] ?? '0')
                ->header('Device-Newly-Registered', $deviceInfo['newly_registered'] ?? 'false')
                ->header('Access-Control-Expose-Headers', 'verification-code, id-documento, document-binding-id, validation-code, device-id, device-trust-level, device-newly-registered') // Expor todos os headers para o cliente
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        } catch (Exception $e) {
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }

            if (strpos($e->getMessage(), 'DOCUMENTO REJEITADO') !== false) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'type' => 'document_rejected',
                    'reason' => 'suspicious_content_detected'
                ], 400)
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            }

            return response()->json([
                'error' => 'Error processing PDF: ' . $e->getMessage()
            ], 500)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }

    public function verifySignature(Request $request)
    {
        $token = $request->input('token');
        $zkToken = $request->input('zk');


        $zkRecord = DB::table('acl_zero_knowledge')->where('token', $zkToken)->first();
        if (!$zkRecord || now() > $zkRecord->expires_at) {
            throw new Exception('Token de autenticação inválido ou expirado');
        }

        $tokenData = DB::table('acl_secure_token')->where('token', $token)->first();
        if (!$tokenData) {
            throw new Exception('Documento não encontrado');
        }

        $data = Crypt::decrypt($tokenData->encrypted_data);

        if ($request->has(['verificar_cpf', 'verificar_matricula'])) {
            $userInfo = [
                'matricula' => $request->verificar_matricula,
                'cpf' => $request->verificar_cpf
            ];

            $securityService = new DocumentoSecurityService();
            $documentData = $securityService->retrieveDocument($data['document_id'], $userInfo);

            if (!$documentData) {
                throw new Exception('Acesso negado - dados incorretos');
            }

            $publicKey = $documentData['public_key'];
            $resultado = openssl_verify(
                $documentData['document'],
                $documentData['signature'],
                $publicKey,
                OPENSSL_ALGO_SHA256
            );

            if ($resultado === 1) {
                // return response()->json([
                //     'success' => true,
                //     'message' => 'Documento valido',
                //     'data' => array(
                //         'nome' => $data['nome'],
                //         'codigo_transacao' => $data['codigo_transacao'],
                //         'dta_ass' => $data['dta_ass'],
                //         'entidade' => $data['entidade'],
                //     )
                // ], 200);
                return view('pagamento-aprovado', [
                    'nome' => $data['nome'],
                    'codigo_transacao' => $data['codigo_transacao'],
                    'dta_ass' => $data['dta_ass'],
                    'verificacao_completa' => true
                ]);
            }
        }

        $securityService = new DocumentoSecurityService();
        if ($securityService->verifyDocumentIntegrity($data['document_id'])) {
            // return response()->json([
            //     'success' => true,
            //     'message' => 'Documento valido',
            //     'data' => array(
            //         'nome' => $data['nome'],
            //         'codigo_transacao' => $data['codigo_transacao'],
            //         'dta_ass' => $data['dta_ass'],
            //         'entidade' => $data['entidade'],
            //     )
            // ], 200);
            return view('pagamento-aprovado', [
                'nome' => $data['nome'],
                'codigo_transacao' => $data['codigo_transacao'],
                'dta_ass' => $data['dta_ass'],
                'documento_valido' => true
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Documento inválido'
        ], 400);
        // throw new Exception('Documento inválido');
    }

    public function signature($codigoVerificao, Request $request)
    {
        $data = $request->input('data');

        // Verificar se já é um array ou se precisa decodificar JSON
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        $nome = $data['nome'];
        $cpf = $data['cpf'];
        $cargo = $data['cargo'];
        $secretaria = $data['secretaria'];
        $matricula = $data['matricula'];

        // if ($codigoVerificao == '378712') {
        return view('signature', array(
            'nome' => strtoupper($nome),
            'cpf' => $cpf,
            'cargo' => strtoupper($cargo),
            'secretaria' => strtoupper($secretaria),
            'matricula' => $matricula,
            'id_documento' => 'a5fc0a29-bab4-46f5-a60e-6e93ed5314e1',
            'codigoVerificao' => $codigoVerificao,
        ));
        // } else {
        return view('singnature-invalido', array(
            'nome' => strtoupper($nome),
            'cpf' => $cpf,
            'cargo' => strtoupper($cargo),
            'secretaria' => strtoupper($secretaria),
            'matricula' => '123456',
            'id_documento' => 'a5fc0a29-bab4-46f5-a60e-6e93ed5314e1',
            'codigoVerificao' => $codigoVerificao,
        ));
        // }
    }



    public function viewDocument($id_documento)
    {
        try {
            // Verificar se o documento existe nas tabelas de tokens seguros
            $tokenData = DB::table('acl_secure_token')
                ->where('encrypted_data', 'LIKE', '%"codigo_transacao":"' . $id_documento . '"%')
                ->first();

            if ($tokenData) {
                // Documento encontrado nos tokens seguros - mostrar informações básicas
                $data = Crypt::decrypt($tokenData->encrypted_data);

                $fpdi = new Fpdi();
                $fpdi->AddPage();
                $fpdi->SetFont('helvetica', 'B', 16);
                $fpdi->Cell(0, 20, 'DOCUMENTO ASSINADO DIGITALMENTE', 0, 1, 'C');

                $fpdi->SetFont('helvetica', '', 12);
                $fpdi->Cell(0, 10, 'ID do Documento: ' . $id_documento, 0, 1, 'L');
                $fpdi->Cell(0, 10, 'Data de Assinatura: ' . ($data['dta_ass'] ?? date('d/m/Y H:i:s')), 0, 1, 'L');
                $fpdi->Cell(0, 10, 'Signatario: ' . strtoupper($data['nome'] ?? 'NAO INFORMADO'), 0, 1, 'L');
                $fpdi->Cell(0, 10, 'Entidade: ' . strtoupper($data['entidade'] ?? 'NAO INFORMADO'), 0, 1, 'L');

                $fpdi->Ln(10);
                $fpdi->SetFont('helvetica', 'B', 12);
                $fpdi->Cell(0, 10, 'Este documento foi assinado digitalmente e e valido para todos os fins legais.', 0, 1, 'C');

                $fpdi->Ln(5);
                $fpdi->SetFont('helvetica', '', 10);
                $fpdi->Cell(0, 10, 'Para verificar a integridade completa, use o QR Code presente no documento original.', 0, 1, 'C');

            } else {
                // Documento não encontrado - mostrar página de erro
                $fpdi = new Fpdi();
                $fpdi->AddPage();
                $fpdi->SetFont('helvetica', 'B', 16);
                $fpdi->SetTextColor(255, 0, 0);
                $fpdi->Cell(0, 20, 'DOCUMENTO NAO ENCONTRADO', 0, 1, 'C');

                $fpdi->SetFont('helvetica', '', 12);
                $fpdi->SetTextColor(0, 0, 0);
                $fpdi->Cell(0, 10, 'ID do Documento: ' . $id_documento, 0, 1, 'L');
                $fpdi->Cell(0, 10, 'Status: Documento nao localizado no sistema', 0, 1, 'L');

                $fpdi->Ln(10);
                $fpdi->SetFont('helvetica', 'B', 12);
                $fpdi->Cell(0, 10, 'Verifique se o ID do documento esta correto.', 0, 1, 'C');
            }

            $output = $fpdi->Output('S');

            return response($output, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="documento_' . $id_documento . '.pdf"')
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar documento: ' . $e->getMessage()
            ], 500)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }

    /**
     * Exibe página para visualização segura do documento
     */
    public function showDocumentViewer($id_documento)
    {
        return view('view-document', [
            'id_documento' => $id_documento
        ]);
    }

    /**
     * Visualizar o documento PDF assinado original
     */
    public function viewSignedDocument($codigo_transacao, Request $request)
    {
        try {

            var_dump($codigo_transacao);
            die();
        } catch (Exception $e) {

        }
    }

    /**
     * Calcula pontuação de suspeição baseada nos padrões detectados
     */
    private function calculateSuspicionScore($patterns)
    {
        $score = 0;

        // Pontuações por tipo de padrão suspeito
        $patternScores = [
            'file_too_recent' => 1,
            'suspicious_filename' => 2,
            'image_conversion_tool' => 2, // Suficiente para rejeitar
            'image_only_pdf' => 2, // Suficiente para rejeitar
            'image_heavy_pdf' => 2, // Suficiente para rejeitar
            'screen_resolution_match' => 2, // Suficiente para rejeitar
            'low_quality_image' => 1,
            'recent_creation_timestamp' => 1,
            'capture_tool_detected' => 3, // Rejeição imediata
            'download_indicators' => 1,
            'scanned_document_characteristics' => 2
        ];

        foreach ($patterns as $pattern) {
            $score += $patternScores[$pattern] ?? 1;
        }

        return $score;
    }

    /**
     * Validação inicial de integridade do PDF
     */
    private function validatePdfIntegrity($pdfPath)
    {
        $content = file_get_contents($pdfPath);
        $fileName = basename($pdfPath);

        // Verificar se realmente é um PDF
        if (!str_starts_with($content, '%PDF-')) {
            throw new Exception('Arquivo não é um PDF válido');
        }

        // Verificar se foi gerado por ferramenta de conversão de imagem
        $imageConversionTools = [
            'Microsoft Print to PDF',
            'Chrome',
            'Chromium',
            'Edge',
            'ImageToPDF',
            'CamScanner',
            'Adobe Scan',
            'PDFCreator'
        ];

        foreach ($imageConversionTools as $tool) {
            if (stripos($content, $tool) !== false) {
                Log::warning('PDF gerado por ferramenta de conversão detectada', [
                    'tool' => $tool,
                    'filename' => $fileName
                ]);
                break;
            }
        }

        $hasImages = strpos($content, '/Image') !== false;
        $hasText = strpos($content, '/Font') !== false || strpos($content, '/Text') !== false;

        if ($hasImages && !$hasText) {
            Log::error('PDF contém apenas imagens - ALTAMENTE SUSPEITO de print/screenshot', [
                'filename' => $fileName
            ]);

            throw new Exception('DOCUMENTO REJEITADO: PDF contém apenas imagens sem texto. Isso indica um print/screenshot. Envie o documento PDF original com texto selecionável.');
        }

        $fileSize = filesize($pdfPath);
        if ($fileSize > 5 * 1024 * 1024 && !$hasText) {
            Log::error('PDF grande sem conteúdo textual - REJEITADO como imagem convertida', [
                'size_mb' => round($fileSize / (1024 * 1024), 2),
                'filename' => $fileName
            ]);

            throw new Exception('DOCUMENTO REJEITADO: PDF muito grande (' . round($fileSize / (1024 * 1024), 1) . 'MB) sem texto. Isso indica conversão de imagem/print para PDF. Envie o documento original.');
        }

        $fileAge = time() - filemtime($pdfPath);
        if ($fileAge < 60) {
            Log::warning('PDF criado há poucos segundos - possível conversão de print', [
                'age_seconds' => $fileAge,
                'filename' => $fileName
            ]);
        }
    }

    /**
     * Descriptografa dados AES-GCM enviados do frontend
     */
        private function descriptografarDados($dadosArray, $ivArray, $senha)
    {
        try {
            $dadosCriptografados = '';
            foreach ($dadosArray as $byte) {
                $dadosCriptografados .= chr($byte);
            }

            // Se a senha vem no IV, então o IV real precisa ser extraído dos dados ou gerado
            // Vamos extrair o IV dos primeiros 12 bytes dos dados criptografados
            $ivLength = 12; // AES-GCM usa IV de 12 bytes
            $iv = substr($dadosCriptografados, 0, $ivLength);
            $dadosCriptografadosReal = substr($dadosCriptografados, $ivLength);

            $salt = "f891350c35fb47cc1557f441b5fdfa04";
            $iterations = 100000;
            $keyLength = 32; // 256 bits

            $chave = hash_pbkdf2('sha256', $senha, $salt, $iterations, $keyLength, true);

            $tagLength = 16;
            $ciphertext = substr($dadosCriptografadosReal, 0, -$tagLength);
            $tag = substr($dadosCriptografadosReal, -$tagLength);

            $dadosDescriptografados = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $chave,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($dadosDescriptografados === false) {
                throw new Exception('Falha na descriptografia - dados ou senha inválidos');
            }

            $dados = json_decode($dadosDescriptografados, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Erro ao decodificar JSON: ' . json_last_error_msg());
            }

            return $dados;

        } catch (Exception $e) {
            Log::error('Erro na descriptografia AES-GCM', [
                'error' => $e->getMessage(),
                'dados_length' => count($dadosArray),
                'iv_length' => count($ivArray),
                'senha_length' => strlen($senha)
            ]);
            throw new Exception('Erro na descriptografia: ' . $e->getMessage());
        }
    }
}
