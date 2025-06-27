// TENTAR COM API ONLINE
            // $qrContent = 'https://api.rh247.com.br/' .  $request->entidade .'/services/signature/confirmation-signature?codigoVerificacao=' . $codigoVerificao .'&id-documento=' . $uuid->toString() . '&data=' . json_encode(array(
            //     'entidade' => $entidade,
            //     'cnpj' => $cnpj,
            //     'nome' => $nome,
            //     'cpf' => $cpf,
            //     'cargo' => $cargo,
            //     'secretaria' => $secretaria,
            //     'matricula' => $matricula,
            // ));
            // TENTAR COM API LOCAL
            // $qrContent = 'http://192.168.18.243:8000/' .  $request->entidade . '/services/signature/confirmation-signature?codigoVerificacao=' . $codigoVerificao . '&id-documento=' . $uuid->toString() . '&data=' . json_encode(array(
            //     'entidade' => $entidade,
            //     'cnpj' => $cnpj,
            //     'nome' => $nome,
            //     'cpf' => $cpf,
            //     'cargo' => $cargo,
            //     'secretaria' => $secretaria,
            //     'matricula' => $matricula,
            //     'dta_ass' => $data_assinatura,
            // ));


             // Aqui configuramos o url do qr code que será gerado
            // $qrContent = 'http://192.168.18.243:8001/api/signature/' . $codigoVerificao . '?data=' . json_encode(array(
            //     'entidade' => $entidade,
            //     'cnpj' => $cnpj,
            //     'nome' => $nome,
            //     'cpf' => $cpf,
            //     'cargo' => $cargo,
            //     'secretaria' => $secretaria,
            //     'matricula' => $matricula,
            // ));