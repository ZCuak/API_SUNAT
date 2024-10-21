<?php
declare(strict_types=1); // Activar el tipado estricto

header('Content-Type: text/html; charset=UTF-8');

// URL para enviar las solicitudes a SUNAT
$wsdlURL = 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';

// NOMBRE DE ARCHIVO A PROCESAR
$NomArch = '20604051984-01-F001-100';

// Crear el archivo .ZIP usando la clase nativa ZipArchive
$zip = new ZipArchive();
$zipFileName = $NomArch . ".zip";

if ($zip->open($zipFileName, ZipArchive::CREATE) === TRUE) {
    $zip->addFile($NomArch . ".xml", basename($NomArch . ".xml"));
    $zip->close();
    chmod($zipFileName, 0777);
} else {
    die("Error al crear el archivo ZIP");
}

// Clase personalizada para la llamada SOAP
class feedSoap extends SoapClient {
    public string $XMLStr = "";

    public function setXMLStr(string $value): void {
        $this->XMLStr = $value;
    }

    public function getXMLStr(): string {
        return $this->XMLStr;
    }

    // Ajuste en el tipo booleano del parámetro $one_way
    public function __doRequest(string $request, string $location, string $action, int $version, bool $one_way = false): string {
        $request = $this->XMLStr;
        $dom = new DOMDocument('1.0');
        try {
            $dom->loadXML($request);
        } catch (DOMException $e) {
            die($e->code);
        }
        $request = $dom->saveXML();
        return parent::__doRequest($request, $location, $action, $version, $one_way);
    }

    public function SoapClientCall(string $SOAPXML): void {
        $this->setXMLStr($SOAPXML);
    }
}

function soapCall(string $wsdlURL, string $callFunction, string $XMLString): string {
    $client = new feedSoap($wsdlURL, array('trace' => true));
    $client->SoapClientCall($XMLString);

    // Aquí en lugar de __call usamos directamente la llamada como un método
    $response = $client->$callFunction();

    return $client->__getLastResponse();
}

// Estructura del XML para la conexión
$XMLString = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
 <soapenv:Header>
     <wsse:Security>
         <wsse:UsernameToken Id="ABC-123">
             <wsse:Username>20604051984MODDATOS</wsse:Username>
             <wsse:Password>moddatos</wsse:Password>
         </wsse:UsernameToken>
     </wsse:Security>
 </soapenv:Header>
 <soapenv:Body>
     <ser:sendBill>
        <fileName>' . $NomArch . '.zip</fileName>
        <contentFile>' . base64_encode(file_get_contents($NomArch . '.zip')) . '</contentFile>
     </ser:sendBill>
 </soapenv:Body>
</soapenv:Envelope>';

// Realizamos la llamada a nuestra función
$result = soapCall($wsdlURL, 'sendBill', $XMLString);

descargarRespone($NomArch, $result);
$response = leerXmlResponse($NomArch);
descargarCDR_ZIP($NomArch, $response);
obtenerCDR_XML($NomArch);
$respuesta = leerCDR_XML($NomArch);
var_dump($respuesta);
eliminarArchivos($NomArch);

// Funciones auxiliares

function descargarRespone(string $NomArch, string $result): void {
    $archivo = fopen('C' . $NomArch . '.xml', 'w+');
    fputs($archivo, $result);
    fclose($archivo);
}

function leerXmlResponse(string $NomArch): string {
    $xml = simplexml_load_file('C' . $NomArch . '.xml');
    foreach ($xml->xpath('//applicationResponse') as $response) {}
    return (string) $response;
}

function descargarCDR_ZIP(string $NomArch, string $response): void {
    $cdr = base64_decode($response);
    $archivo = fopen('R-' . $NomArch . '.zip', 'w+');
    fputs($archivo, $cdr);
    fclose($archivo);
    chmod('R-' . $NomArch . '.zip', 0777);
}

function obtenerCDR_XML(string $NomArch): void {
    $zip = new ZipArchive();
    $zipFileName = 'R-' . $NomArch . '.zip';
    
    if ($zip->open($zipFileName) === TRUE) {
        $zip->extractTo('.');
        $zip->close();
        chmod('R-' . $NomArch . '.xml', 0777);
    } else {
        die("Error al extraer el archivo ZIP");
    }
}

function leerCDR_XML(string $NomArch): array {
    $resultado = [];
    if (file_exists('R-' . $NomArch . '.xml')) {
        // Cambié el 'null' por '0' en el tercer parámetro, ya que debe ser un entero
        $library = new SimpleXMLElement('R-' . $NomArch . '.xml', 0, true);
        $ns = $library->getDocNamespaces();
        $ext1 = $library->children($ns['cac']);
        $ext2 = $ext1->DocumentResponse;
        $ext3 = $ext2->children($ns['cac']);
        $ext4 = $ext3->children($ns['cbc']);
        $resultado = [
            'respuesta_sunat_codigo' => trim((string) $ext4->ResponseCode),
            'respuesta_sunat_descripcion' => trim((string) $ext4->Description)
        ];
    }
    return $resultado;
}

function eliminarArchivos(string $NomArch): void {
    unlink('C' . $NomArch . '.xml');
}
