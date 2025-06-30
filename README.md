<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400"></a></p>

<p align="center">
<a href="https://travis-ci.org/laravel/framework"><img src="https://travis-ci.org/laravel/framework.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains over 1500 video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the Laravel [Patreon page](https://patreon.com/taylorotwell).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Cubet Techno Labs](https://cubettech.com)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[Many](https://www.many.co.uk)**
- **[Webdock, Fast VPS Hosting](https://www.webdock.io/en)**
- **[DevSquad](https://devsquad.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[OP.GG](https://op.gg)**
- **[WebReinvent](https://webreinvent.com/?utm_source=laravel&utm_medium=github&utm_campaign=patreon-sponsors)**
- **[Lendio](https://lendio.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

# Sistema de Assinatura Digital - iSignature

## Proteção de Pastas com Senha

O sistema agora suporta a proteção das pastas de usuário com senha. Quando uma senha é fornecida na requisição, todos os arquivos de assinatura (assinatura.bin, chave_publica.pem, documento.pdf) são criptografados usando AES-256-CBC.

### Como Usar

#### 1. Criar Assinatura com Proteção por Senha

Para proteger a pasta do usuário com senha, inclua o campo `senha` na requisição:

```bash
curl -X POST http://localhost:8000/api/ \
  -H "Content-Type: multipart/form-data" \
  -F "pdf=@documento.pdf" \
  -F "entidade=EXEMPLO" \
  -F "cnpj=12.345.678/0001-90" \
  -F "nome=João Silva" \
  -F "cpf=123.456.789-01" \
  -F "cargo=Analista" \
  -F "secretaria=Secretaria de Exemplo" \
  -F "matricula=123456" \
  -F "senha=minhasenhasegura123"
```

#### 2. Verificar Assinatura Protegida

A senha é automaticamente incluída no QR code gerado. Quando o QR code for escaneado, a verificação será feita usando a senha armazenada.

### Segurança

- **Criptografia**: AES-256-CBC
- **Chave**: Derivada do SHA-256 da senha
- **IV**: Aleatório para cada arquivo
- **Validação**: Hash SHA-256 da senha é armazenado em `.protected`

### Estrutura da Pasta

#### Sem Proteção:
```
123456/
├── assinatura.bin
├── chave_publica.pem
└── documento.pdf
```

#### Com Proteção:
```
123456/
├── .protected          (hash da senha)
├── assinatura.bin      (criptografado)
├── chave_publica.pem   (criptografado)
└── documento.pdf       (criptografado)
```

### Tratamento de Erros

- Se a pasta estiver protegida e a senha não for fornecida: "Senha é obrigatória para acessar arquivos protegidos"
- Se a senha estiver incorreta: "Senha incorreta"
- Se houver erro na descriptografia: "Erro ao descriptografar o arquivo"

### Compatibilidade

- Pastas criadas sem senha continuam funcionando normalmente
- Não há impacto em assinaturas existentes não protegidas
- O sistema detecta automaticamente se uma pasta está protegida

### Exemplo de Response

#### Sucesso (com proteção):
```http
HTTP/1.1 200 OK
Content-Type: application/pdf
Verification-Code: 12A-34B-56C
Id-Documento: a5fc0a29-bab4-46f5-a60e-6e93ed5314e1
```

#### Erro (senha incorreta):
```json
{
  "codigo_transacao": "a5fc0a29-bab4-46f5-a60e-6e93ed5314e1",
  "erro": "Senha incorreta"
}
```

### Métodos Disponíveis na SignatureServices

```php
// Verificar se uma pasta está protegida
$isProtected = $signatureServices->isProtected($matricula);

// Validar senha
$isValid = $signatureServices->validatePassword($matricula, $senha);

// Ler arquivo protegido
$content = $signatureServices->readProtectedFile($matricula, 'assinatura.bin', $senha);
```
