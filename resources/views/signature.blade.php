<head>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<style>
    html {
        background: #f5f6fa;
        position: relative;
        font-family: "DM Sans", sans-serif !important;


    }

    main {
        margin: 0 auto;
    }

    .content {
        background: #fff;
        width: 80%;
        height: 10%;
        margin: 0 auto;
        /* top: 50%;
        left: 50%;
        transform: translate(-50%, -50%); */
        padding: 20px;
        padding: 1.25rem;
        border: 1px solid #dbdfea;
        border-radius: 4px;
    }

    .content section h6 {
        font-size: 11px;
        line-height: 1.2;
        letter-spacing: 0.2em;
        color: #8094ae;
        text-transform: uppercase;
        font-weight: 700;
        font-family: "DM Sans", sans-serif;
    }

    .link {
        color: #526484;
        display: block;
        line-height: 1.3;
        padding: 0.5rem 0;
        text-decoration: none;
        font-size: 12px;
    }

    .link:hover {
        transition: all 0.3s ease;
        text-decoration: underline;
    }

    .description {
        font-size: 12px;
        color: #ccc;
        line-height: 1.3;
        margin-top: -25px;
    }

    table {
        display: table;
        width: 100%;
        margin-bottom: 1rem;
        color: #526484;
        vertical-align: top;
        /* border-color: #dbdfea; */
        border-collapse: collapse;
    }

    thead,
    tbody,
    tfoot,
    tr,
    td,
    th {
        /* border-color: #dbdfea;
        border-style: solid;
        border-width: 1px; */
        padding: 8px;
    }

    table thead th {
        color: #2263b3;
        font-size: 12px;
        text-transform: uppercase;
        border-bottom: 1px solid #dbdfea;
        /* background-color: #f5f6fa; */
        text-align: left;
    }

    table tbody td {
        font-size: 10px;
        line-height: 1.3;
        padding: 12px 8px;
        color: #526484;
        vertical-align: middle;
    }

    table tbody tr:hover {
        background-color: #f5f6fa;
    }

    center {
        margin-top: 45px;
    }

    .card {
        background: #fff;
        padding: 1rem;
        width: 80%;
        margin: 0 auto;
        border-radius: 12px;
        border: 1px solid #dbdfea;
        border-left: 8px solid #2563eb;
        -webkit-transition: all 0.2s ease;
        transition: all 0.2s ease;
        height: auto;
        display: -webkit-box;
        display: -webkit-flex;
        display: -ms-flexbox;
        display: flex;
        -webkit-align-items: flex-start;
        -webkit-box-align: flex-start;
        -ms-flex-align: flex-start;
        align-items: flex-start;
        -webkit-flex-direction: column;
        -ms-flex-direction: column;
        flex-direction: column;
        margin-bottom: 10px;
    }
</style>

<main>
    <div class="content">
        <section>
            <header>
                <h6 style="margin-left: 3px;">Isignature</h6>
                <p class="description" style="font-size: 15px !important;">Sistema de Assinatura Digital</p>
                <div style="margin-top: -13px;">
                    <span class="description" style="font-size: 10px !important;">
                        <strong style="color: #2263b3;">Código:</strong> {{ $codigoVerificao }}
                    </span>
                </div>
            </header>

            <br />

            <div style="display: flex; align-items: center;gap:10px">
                <div style="width: 10px; text-align: center;">
                    <i class="fa-solid fa-location" style="font-size: 10px; color:#2263b3"></i>
                </div>

                <a href="#" class="link">
                    ID do documento: {{ $id_documento }}
                </a>
            </div>

            <div style="display: flex; align-items: center;gap:10px">
                <div style="width: 10px; text-align: center;">
                    <i class="fa-solid fa-file" style="font-size: 10px; color:#2263b3;"></i>
                </div>

                <a href="/api/document/{{ $id_documento }}" target="_blank" class="link">Visualizar documento</a>
            </div>


        </section>
    </div>

    <br />
    <div class="card">
     <strong style="font-size: 14px !important;">{{ $nome }}</strong>
     <br />
     <div>
        <span style="font-size: 10px !important;">
            <strong>CPF:</strong> {{ $cpf }}
        </span>
        <br />
        <span style="font-size: 10px !important;">
            <strong>Matrícula:</strong> {{ $matricula }}
        </span>
        <br />
        <span style="font-size: 10px !important;">
            <strong>Cargo:</strong> {{ $cargo }}
        </span>
        <br />
        <span style="font-size: 10px !important;">
            <strong>Órgão:</strong> {{ $secretaria }}
        </span>
    </div>
    </div>
    <!-- <table>
        <thead>
            <tr>
                <th class="w-40">Signatário</th>
                <th class="w-60px">CPF</th>
                <th>Cargo</th>
                <th>Secretaria</th>
                <th>Matrícula</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $nome }}</td>
                <td>{{ $cpf }}</td>
                <td>{{ $cargo }}</td>
                <td>{{ $secretaria }}</td>
                <td>{{ $matricula }}</td>
            </tr>
        </tbody>
    </table> -->


</main>


<center>
    <p class="description">
        Copyright © 2025 Itarget Tecnologia LTDA. Todos direitos reservados.
    </p>
</center>