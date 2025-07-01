<?php

namespace App\Services;

class SignatureServices
{


    public function generatePosition($position, $pageWidth, $pageHeight, $qrWidth, $qrHeight, $textWidth)
    {
        switch ($position) {
            case 'footer':
                $qrX = 10;
                $qrY = $pageHeight - $qrHeight - 400;
                $textX = $qrX + $qrWidth + 5;
                $textY = $qrY;
                break;
            case 'header':
                $qrX = 10;
                $qrY = 20;
                $textX = $qrX + $qrWidth + 5;
                $textY = $qrY + 2;
                break;
            case 'left':
                $qrX = 10;
                $qrY = ($pageHeight - $qrHeight) / 2;
                $textX = $qrX + $qrWidth + 5;
                $textY = $qrY;
                break;
            case 'right':
                $qrX = $pageWidth - $qrWidth - $textWidth - 15;
                $qrY = ($pageHeight - $qrHeight) / 2;
                $textX = $qrX + $qrWidth + 5;
                $textY = $qrY;
                break;
            default:
                $qrX = 10;
                $qrY = $pageHeight - $qrHeight - 20;
                $textX = $qrX + $qrWidth + 5;
                $textY = $qrY + 2;
        }
        return compact('qrX', 'qrY', 'textX', 'textY');
    }

    public function randomCode()
    {
        return   str_pad(rand(10, 99), 2, '0', STR_PAD_LEFT) .
            chr(rand(65, 90)) . '-' .
            str_pad(rand(10, 99), 2, '0', STR_PAD_LEFT) .
            chr(rand(65, 90)) . '-' .
            str_pad(rand(10, 99), 2, '0', STR_PAD_LEFT) .
            chr(rand(65, 90));
    }

    /**
     * Criptografa dados usando uma senha
     */
    private function encryptData($data, $password)
    {
        $key = hash('sha256', $password, true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Descriptografa dados usando uma senha
     */
    private function decryptData($encryptedData, $password)
    {
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $key = hash('sha256', $password, true);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }

    public function openSSL($data, $matricula, $documento = null, $senha = null)
    {
        $privateKeyResource = openssl_pkey_new([
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($privateKeyResource, $privateKey);
        $publicKey = openssl_pkey_get_details($privateKeyResource)["key"];

        $conteudo = file_get_contents($documento);
        openssl_sign($conteudo, $assinatura, $privateKey, OPENSSL_ALGO_SHA256);

        $securityService = new DocumentoSecurityService();

        $userInfo = [
            'matricula' => $matricula,
            'cpf' => $data['cpf']
        ];

        $documentId = $securityService->secureDocument(
            $conteudo,
            $userInfo,
            $assinatura,
            $publicKey
        );

        return [
            'public_key' => base64_encode($publicKey),
            'document_id' => $documentId
        ];
    }
    /**
     * Verifica se uma pasta está protegida por senha
     */
    public function isProtected($matricula)
    {
        return file_exists("$matricula/.protected");
    }

    /**
     * Valida a senha de uma pasta protegida
     */
    public function validatePassword($matricula, $senha)
    {
        if (!$this->isProtected($matricula)) {
            return true; // Pasta não protegida, sempre válida
        }

        $storedHash = file_get_contents("$matricula/.protected");
        return hash('sha256', $senha) === $storedHash;
    }

    /**
     * Lê um arquivo protegido da pasta do usuário
     */
    public function readProtectedFile($matricula, $fileName, $senha = null)
    {
        $filePath = "$matricula/$fileName";

        if (!file_exists($filePath)) {
            throw new \Exception("Arquivo não encontrado: $fileName");
        }

        $content = file_get_contents($filePath);

        if ($this->isProtected($matricula)) {
            if (!$senha) {
                throw new \Exception("Senha é obrigatória para acessar arquivos protegidos");
            }

            if (!$this->validatePassword($matricula, $senha)) {
                throw new \Exception("Senha incorreta");
            }

            $content = $this->decryptData($content, $senha);
            if ($content === false) {
                throw new \Exception("Erro ao descriptografar o arquivo");
            }
        }

        return $content;
    }

    public function verifySignature($hash, $matricula)
    {
        $assinatura = file_get_contents("$hash/assinatura.bin");
        $publicKey = file_get_contents("$hash/chave_publica.pem");
        $hashDocument = file_get_contents("$hash/hash.bin");

        openssl_public_decrypt($assinatura, $hashDescriptografado, $publicKey);

        if ($hashDocument === $hashDescriptografado) {
            echo "Assinatura válida e documento íntegro.";
        } else {
            echo "Assinatura inválida ou documento foi alterado." . $hash . " " . $hashDescriptografado;
        }
    }

    public static function formataCnpjCpf($value)
    {
        $cnpj_cpf = preg_replace("/\D/", '', $value);
        if (strlen($cnpj_cpf) === 11) {
            return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cnpj_cpf);
        }
        return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj_cpf);
    }

    public static function formataTelefone($value)
    {
        $telefone = preg_replace("/\D/", '', $value);
        if (strlen($telefone) === 11) {
            return preg_replace("/(\d{2})(\d{5})(\d{4})/", "(\$1) \$2.\$3", $telefone);
        }
        return preg_replace("/(\d{2})(\d{4})(\d{4})/", "(\$1) \$2.\$3", $telefone);
    }

    public static function removeCaracteresEspeciais($string, $espaco = null)
    {

        if (!isset($espaco)) {
            $espaco = '_';
        }

        $aSaida = array('á', 'à', 'ã', 'â', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ü', 'ç', 'Á', 'À', 'Ã', 'Â', 'É', 'Ê', 'Í', 'Ó', 'Ô', 'Õ', 'Ú', 'Ü', 'Ç', ' ', '(', ')', '.');

        $entrada = "aaaaeeiooouucAAAAEEIOOOUUC" . $espaco;

        $tamEntar = strlen($entrada);

        for ($i = 0; $i < $tamEntar; $i++) {

            $charEntrar = substr($entrada, $i, 1);
            $charSair = $aSaida[$i];
            if (substr_count($string, $charSair) > 0) {
                $string = str_replace($charSair, $charEntrar, $string);
            }
        }

        $nome = strtolower($string);
        $nome = preg_replace('/[áàãâä]/ui', 'a', $nome);
        $nome = preg_replace('/[éèêë]/ui', 'e', $nome);
        $nome = preg_replace('/[íìîï]/ui', 'i', $nome);
        $nome = preg_replace('/[óòõôö]/ui', 'o', $nome);
        $nome = preg_replace('/[úùûü]/ui', 'u', $nome);
        $nome = preg_replace('/[ç]/ui', 'c', $nome);
        $nome = strtoupper($nome);
        $nome = trim($nome);

        return strtoupper($nome);
    }
}
