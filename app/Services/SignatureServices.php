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

    public function randomCode() {
    return   str_pad(rand(10, 99), 2, '0', STR_PAD_LEFT) .
       chr(rand(65, 90)) .'-' .
       str_pad(rand(10, 99), 2, '0', STR_PAD_LEFT) .
       chr(rand(65, 90)) . '-' .
       str_pad(rand(10, 99), 2, '0', STR_PAD_LEFT) .
       chr(rand(65, 90));
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
