<?php

namespace App\Http\Controllers;

use App\Services\SignatureServices;
use Illuminate\Http\Request;
use setasign\Fpdi\Fpdi;
use Exception;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use setasign\Fpdi\PdfReader;

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

    public function index(Request $request)
    {
        try {
            $entidade = $request->entidade;
            $cnpj = $request->cnpj;
            $signatureServices = new SignatureServices();



            if (!$request->hasFile('pdf')) {
                return response()->json(['error' => 'No PDF file provided'], 400)
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            }


            $pdfPath = $request->file('pdf')->getPathname();

            $tempPath = tempnam(sys_get_temp_dir(), 'pdf_');

            // remove qualquer senha ou criptografia do pdf
            exec("pdftk {$pdfPath} output {$tempPath} allow AllFeatures", $output, $returnVar);

            Log::info($returnVar);
            if ($returnVar !== 0) {
                throw new Exception("Failed to process PDF with pdftk" . $returnVar);
            }

            // Caso vem acontencer de um dia ter mais informações, aqui é onde deve ser adicionado
            $nome = $request->nome;
            $cpf = $request->cpf;
            $cargo = $request->cargo;
            $secretaria = $request->secretaria;
            $matricula = $request->matricula;


            $fpdi = new PDF_Rotate();
            $pageCount = $fpdi->setSourceFile($tempPath);
            $uuid = Uuid::uuid4();

            $codigoVerificao = $signatureServices->randomCode();

            // Aqui configuramos o url do qr code que será gerado
            $qrContent = 'http://192.168.18.243:8001/api/signature/' . $codigoVerificao . '?data=' . json_encode(array(
                'entidade' => $entidade,
                'cnpj' => $cnpj,
                'nome' => $nome,
                'cpf' => $cpf,
                'cargo' => $cargo,
                'secretaria' => $secretaria,
                'matricula' => $matricula,
            ));

            // Gerar o qrcode
            $qrImage = QrCode::format('png')->size(100)->generate($qrContent);
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
                        "DOCUMENTO ASSINADO DIGITALMENTE. CODIGO {$codigoVerificao}",
                        strtoupper($nome) . " CPF: " . $signatureServices->formataCnpjCpf($cpf) . " MATRICULA: {$matricula}",
                        "CARGO: " . strtoupper($cargo) . " ORGAO:" . $signatureServices->removeCaracteresEspeciais($secretaria, ' '),
                        "APONTE SUA CAMARA PARA O QRCODE PARA VERIFICAR AUTENTICIDADE.",
                    ];
                    $totalTextBlockWidth = (count($textLines) - 1) * $lineHeight;

                    // --- Bloco da Direita ---
                    $qrX_right = $pageWidth - $qrWidth - $margin;
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

            // return response()->json([
            //     'pdf' => base64_encode($output),
            //     'codigoVerificao' => $codigoVerificao
            // ])
            //     ->header('Access-Control-Allow-Origin', '*')
            //     ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            //     ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

            return response($output, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Verification-Code', $codigoVerificao)
                ->header('Id-Documento', $uuid->toString())
                ->header('Access-Control-Expose-Headers', 'verification-code, id-documento') // essa propriedade é para que o cliente possa acessar o header Verification-Code
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        } catch (Exception $e) {
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }
            return response()->json([
                'error' => 'Error processing PDF: ' . $e->getMessage()
            ], 500)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }

    public function signature($codigoVerificao, Request $request)
    {
        $data = $request->input('data');
        $data = json_decode($data, true);

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
}
