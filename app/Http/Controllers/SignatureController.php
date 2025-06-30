<?php

namespace App\Http\Controllers;

use App\Services\DocumentoBindingService;
use App\Services\PrivacyService;
use App\Services\SignatureServices;
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
            $signatureServices = new SignatureServices();
            $privacyService = new PrivacyService();

            // esse codigo vai verificar atividades suspeitas
            $documentoBindingService = new DocumentoBindingService();


            // Caso vem acontencer de um dia ter mais informações, aqui é onde deve ser adicionado
            $nome = $request->nome;
            $cpf = $request->cpf;
            $cargo = $request->cargo;
            $secretaria = $request->secretaria;
            $data_assinatura = $request->date ?? date('d/m/Y H:i:s');
            $matricula = $request->matricula;


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
                    'entidade' => $entidade,
                    'chave_publica' => $chave_publica,
                    'matricula' => $matricula,
                    'codigo_transacao' => $codigoTransacao,
                    'dta_ass' => $data_assinatura,
                )
            );


            // Gerar o qrcode
            $qrImage = QrCode::format('png')->size(500)->generate('http://192.168.18.243:8001/api/verifySignature?token=' . $token);
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
                ->header('Access-Control-Expose-Headers', 'verification-code, id-documento, document-binding-id, validation-code') // Expor todos os headers para o cliente
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        } catch (Exception $e) {
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }

            // Se for rejeição por suspeição, retornar erro 400 (Bad Request)
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

            // Para outros erros, retornar erro 500
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

        $data = DB::table('acl_secure_token')->where('token', $token)->first();

        if (!$data) {
            throw new Exception('Não existe nenhum documento assinado digitalmente com esse token.');
        }

        $data = Crypt::decrypt($data->encrypted_data);

        $publicKey = base64_decode($data['chave_publica']);

        $assinatura = file_get_contents($data['matricula'] . "/assinatura.bin");
        $documento = file_get_contents($data['matricula'] . "/documento.pdf");

        $resultado = openssl_verify($documento, $assinatura, $publicKey, OPENSSL_ALGO_SHA256);


        if ($resultado === 1) {
            return view('pagamento-aprovado', [
                'codigo_transacao' => $data['codigo_transacao'],
                'dta_ass' => $data['dta_ass'],
                'nome' => $data['nome'] ?? 'VITOR EMANUEL PIRES DE OLIVEIRA',
            ]);
        } else {
            return response()->json([
                'error' => 'Documento não autenticado',
                'type' => 'document_rejected',
                'reason' => 'suspicious_content_detected'
            ], 400)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
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
            $fpdi = new Fpdi();
            $fpdi->AddPage();
            $fpdi->SetFont('helvetica', 'B', 16);
            $fpdi->Cell(0, 20, 'DOCUMENTO ASSINADO DIGITALMENTE', 0, 1, 'C');

            $fpdi->SetFont('helvetica', '', 12);
            $fpdi->Cell(0, 10, 'ID do Documento: ' . $id_documento, 0, 1, 'L');
            $fpdi->Cell(0, 10, 'Data de Assinatura: ' . date('d/m/Y H:i:s'), 0, 1, 'L');
            $fpdi->Cell(0, 10, 'Signatario: VITOR EMANUEL PIRES DE OLIVEIRA', 0, 1, 'L');
            $fpdi->Cell(0, 10, 'CPF: 123.456.789-01', 0, 1, 'L');
            $fpdi->Cell(0, 10, 'Cargo: Analista de Sistemas', 0, 1, 'L');
            $fpdi->Cell(0, 10, 'Secretaria: Secretaria de Estado de Planejamento e Orcamento', 0, 1, 'L');
            $fpdi->Cell(0, 10, 'Matricula: 123456', 0, 1, 'L');

            $fpdi->Ln(10);
            $fpdi->SetFont('helvetica', 'B', 12);
            $fpdi->Cell(0, 10, 'Este documento foi assinado digitalmente e e valido para todos os fins legais.', 0, 1, 'C');

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

        // Verificar se contém principalmente imagens (típico de print)
        $hasImages = strpos($content, '/Image') !== false;
        $hasText = strpos($content, '/Font') !== false || strpos($content, '/Text') !== false;

        if ($hasImages && !$hasText) {
            Log::error('PDF contém apenas imagens - ALTAMENTE SUSPEITO de print/screenshot', [
                'filename' => $fileName
            ]);

            // Se é só imagem, muito provavelmente é print
            throw new Exception('DOCUMENTO REJEITADO: PDF contém apenas imagens sem texto. Isso indica um print/screenshot. Envie o documento PDF original com texto selecionável.');
        }

        // Verificar tamanho do arquivo vs conteúdo (prints tendem a ser grandes)
        $fileSize = filesize($pdfPath);
        if ($fileSize > 5 * 1024 * 1024 && !$hasText) { // Maior que 5MB sem texto
            Log::error('PDF grande sem conteúdo textual - REJEITADO como imagem convertida', [
                'size_mb' => round($fileSize / (1024 * 1024), 2),
                'filename' => $fileName
            ]);

            throw new Exception('DOCUMENTO REJEITADO: PDF muito grande (' . round($fileSize / (1024 * 1024), 1) . 'MB) sem texto. Isso indica conversão de imagem/print para PDF. Envie o documento original.');
        }

        // Verificar se arquivo foi modificado muito recentemente (indica conversão recente)
        $fileAge = time() - filemtime($pdfPath);
        if ($fileAge < 60) { // Menos de 1 minuto
            Log::warning('PDF criado há poucos segundos - possível conversão de print', [
                'age_seconds' => $fileAge,
                'filename' => $fileName
            ]);
        }
    }
}
