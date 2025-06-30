<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento Aprovado</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<style>
    html {
        background: #f5f6fa;
        position: relative;
        font-family: "DM Sans", sans-serif !important;
        margin: 0;
        padding: 0;
        height: 100vh;
    }

    body {
        margin: 0;
        padding: 20px;
        height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .payment-container {
        background: #fff;
        max-width: 500px;
        width: 90%;
        margin: 0 auto;
        padding: 40px 30px;
        border-radius: 12px;
        border: 1px solid #dbdfea;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        text-align: center;
    }

    .success-icon {
        width: 80px;
        height: 80px;
        background: #10b981;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 30px;
        box-shadow: 0 0 0 10px rgba(16, 185, 129, 0.1);
    }

    .success-icon i {
        color: white;
        font-size: 32px;
    }

    .payment-title {
        /* font-size: 24px; */
        font-weight: 600;
        color: #374151;
        margin-bottom: 30px;
        line-height: 1.2;
    }

    .payment-amount {
        font-size: 32px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 30px;
    }

    .payment-details {
        background: #f9fafb;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
        text-align: left;
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid #e5e7eb;
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-label {
        font-size: 14px;
        color: #6b7280;
        font-weight: 500;
        text-align: left;
    }

    .detail-value {
        font-size: 14px;
        color: #374151;
        font-weight: 600;
    }

    .transaction-code {
        border-radius: 8px;
        margin-bottom: 30px;
        font-family: 'Courier New', monospace;
        font-size: 12px;
        font-weight: 600;
        color: #495057;
        word-break: break-all;
        text-align: center;
        letter-spacing: 1px;
        text-align: left;
    }

    .description {
        font-family: 'Courier New', monospace;
        font-size: 13px;
        font-weight: 600;
        color: #495057;
    }

    .download-btn {
        background: #10b981;
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-block;
        min-width: 180px;
    }

    .download-btn:hover {
        background: #059669;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
    }

    .footer {
        margin-top: 40px;
        padding-top: 20px;
        border-top: 1px solid #e5e7eb;
        font-size: 12px;
        color: #9ca3af;
    }

    @media (max-width: 600px) {
        .payment-container {
            padding: 30px 20px;
        }

        .payment-title {
            font-size: 20px;
        }

        .payment-amount {
            font-size: 28px;
        }
    }

    .separator {
        border-top: 1px solid #e5e7eb;
        height: 1px;
        width: 100%;
        margin: 10px 0;
    }
</style>

<body>
    <div class="payment-container">
        <div class="success-icon">
            <i class="fas fa-check"></i>
        </div>

        <h6 class="payment-title">Seu documento foi assinado com sucesso</h6>

        <br />
        <div style="display: flex; flex-direction: row; justify-content: space-between; align-items: center;">
            <div class="detail-label">Nome:</div>
            <div class="description">
                {{$nome}}
            </div>
        </div>
        <div class="separator">
        </div>
        <div style="display: flex; flex-direction: row; justify-content: space-between; align-items: center;">
            <div class="detail-label">Data da assinatura:</div>
            <div class="description">
                {{$dta_ass}}
            </div>
        </div>
        <div class="separator">
        </div>
        <div>
            <div class="detail-label" style="margin-bottom: 8px;">Código do documento:</div>
            <div class="transaction-code">
                {{$codigo_transacao}}
            </div>
        </div>

        <a href="{{ $url_comprovante ?? '#' }}" class="download-btn">
            <i class="fas fa-download" style="margin-right: 8px;"></i>
            Baixar documento
        </a>

        <div class="footer">
            <p>Copyright © {{ date('Y') }} Itarget Tecnologia LTDA. Todos direitos reservados.</p>
        </div>
    </div>
</body>
</html>
