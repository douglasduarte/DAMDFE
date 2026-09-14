# DAMDFE para PHP

Biblioteca independente para gerar o Documento Auxiliar do MDF-e (DAMDFE) a
partir do XML autorizado do MDF-e.

O projeto usa FPDF para o desenho vetorial do documento e `milon/barcode` para
o código de barras. A integração com Laravel é opcional: a classe retorna o
conteúdo binário do PDF e a aplicação decide como salvar, transmitir ou
baixar o arquivo.

## Instalação

```bash
composer require douglasduarte/damdfe
```

## Uso

```php
use DAMDFE\Damdfe;

$xml = file_get_contents(__DIR__ . '/mdfe.xml');
$pdf = (new Damdfe($xml))->render();

file_put_contents(__DIR__ . '/damdfe.pdf', $pdf);
```

Em uma aplicação Laravel:

```php
return response((new \DAMDFE\Damdfe($xml))->render(), 200, [
    'Content-Type' => 'application/pdf',
    'Content-Disposition' => 'inline; filename="damdfe.pdf"',
]);
```

## Layout disponível

O layout A4 segue a disposição do DACTE do
[NFePHP/sped-da](https://github.com/nfephp-org/sped-da), adaptada aos dados do
MDF-e e às caixas arredondadas do DAMDFE do
[BrazilFiscalReport](https://github.com/Engenere/BrazilFiscalReport). O bloco
principal usa canhoto no topo e três colunas: emitente, identificação fiscal
com código de barras e chave de acesso, e modal com QR Code. Abaixo ficam o
início e o término do percurso, o resumo ANTT e a composição da carga.

O documento inclui:

- emitente, identificação, chave, protocolo, QR Code e código de barras;
- resumo ANTT, veículos, reboques e condutores;
- vale-pedágio, percurso e composição da carga em duas colunas;
- seguros, CIOT e informações adicionais;
- marcas d'água de homologação, ausência de protocolo e contingência.

Quando o XML não contém `qrCodMDFe`, a biblioteca monta a URL pública de
consulta a partir da chave de acesso e do ambiente.
