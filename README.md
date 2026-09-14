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

## Estado atual

A primeira versão concentra a leitura dos campos principais do MDF-e, o
layout A4, a chave de acesso, protocolo, modal, documentos transportados,
totais, marca d'água de homologação/sem autorização e contingência. O layout
será refinado por regressão visual contra os PDFs de referência do
BrazilFiscalReport.
