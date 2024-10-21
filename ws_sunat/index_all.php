<?php
// Configurar error_reporting para solo mostrar errores críticos
error_reporting(E_ERROR | E_PARSE);

// NOMBRE DE ARCHIVO A PROCESAR
$serie = $_GET['cod_7'] ?? '';
$NomArch = $_GET['numero_documento'] ?? '';

if (empty($NomArch)) {
    die(json_encode(['success' => false, 'message' => 'Número de documento no proporcionado.']));
}

// Definir la ruta y el método según el código de acción (cod_6)
switch ($_GET['cod_6']) {
    case 1:
        $ruta = '../files/facturacion_electronica/FIRMA/';
        $metodo = 'sendBill';   // Facturas o boletas
        break;
    case 2:
        $ruta = '../files/facturacion_electronica/BAJA/FIRMA/';
        $metodo = 'sendSummary';    // Enviar anulación
        break;
    case 3:
        $ruta = '../files/facturacion_electronica/BAJA/RPTA/';
        $metodo = 'getStatus';  // Método para recibir ticket de anulación
        break;
    case 4:
        $ruta = '../files/facturacion_electronica/FIRMA/';
        $metodo = 'getStatusCdr';  // Preguntar estado CDR
        break;
    default:
        die(json_encode(['success' => false, 'message' => 'Código de acción inválido.']));
}

// Si no es para enviar ticket, crear archivo ZIP usando ZipArchive
if (in_array($_GET['cod_6'], [1, 2])) {
    $zip = new ZipArchive();
    $zipFilePath = $ruta . $NomArch . ".zip";
    $xmlFilePath = $ruta . $NomArch . ".xml";

    if (file_exists($zipFilePath)) {
        $r = 1; // El archivo ZIP ya existe
    } else {
        if ($zip->open($zipFilePath, ZipArchive::CREATE) === TRUE) {
            $zip->addFile($xmlFilePath, basename($xmlFilePath)); // Añadir el archivo XML al ZIP
            $zip->close();
        } else {
            die(json_encode(['success' => false, 'message' => 'Error al crear el archivo ZIP.']));
        }
    }
    chmod($zipFilePath, 0777); // Cambiar permisos del archivo ZIP
}

// Generar el contenido en base al tipo de acción (cod_6)
switch ($_GET['cod_6']) {
    case 1:        
    case 2:        
        $content = '<fileName>' . $NomArch . '.zip</fileName><contentFile>' . base64_encode(file_get_contents($zipFilePath)) . '</contentFile>';
        break;
    case 3:
        $content = '<ticket>' . $_GET['cod_8'] . '</ticket>';
        break;
    case 4:
        $documento = explode("-", $_GET['numero_documento']);        
        $content = '<rucComprobante>' . $documento[0] . '</rucComprobante>
        <tipoComprobante>' . $documento[1] . '</tipoComprobante>
        <serieComprobante>' . $documento[2] . '</serieComprobante>
        <numeroComprobante>' . $documento[3] . '</numeroComprobante>';
        break;
}

// Definir clase para SOAP
class feedSoap extends SoapClient {

    public $XMLStr = "";

    public function setXMLStr($value) {
        $this->XMLStr = $value;
    }

    public function getXMLStr() {
        return $this->XMLStr;
    }

    public function __doRequest($request, $location, $action, $version, $one_way = 0) {
        $request = $this->XMLStr;
        $dom = new DOMDocument('1.0', 'UTF-8'); // Definir codificación UTF-8

        if (!$dom->loadXML($request)) {
            throw new Exception('Error al cargar el XML.');
        }

        $request = $dom->saveXML();
        return parent::__doRequest($request, $location, $action, $version, $one_way);
    }

    public function SoapClientCall($SOAPXML) {
        $this->setXMLStr($SOAPXML);
    }
}

// Función para llamada SOAP
function soapCall($wsdlURL, $callFunction = "", $XMLString) {
    $client = new feedSoap($wsdlURL, array('trace' => true));
    $client->SoapClientCall($XMLString);
    $client->__call("$callFunction", array());
    return $client->__getLastResponse();
}

// Definir la URL del WSDL basado en el tipo de documento
$wsdlURL = '';
if ($_GET['cod_1'] == 1) {
    // FACTURAS
    $wsdlURL = ($_GET['cod_2'] == 1) ? 'billService.wsdl' : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
} elseif ($_GET['cod_1'] == 9) {
    // GUIAS
    $wsdlURL = ($_GET['cod_2'] == 1) ? 'https://e-guiaremision.sunat.gob.pe/ol-ti-itemision-guia-gem/billService?wsdl' : 'https://e-beta.sunat.gob.pe/ol-ti-itemision-guia-gem-beta/billService?wsdl';
} else {
    die(json_encode(['success' => false, 'message' => 'Código de documento inválido.']));
}

// Estructura del XML para la conexión
$XMLString = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
    <soapenv:Header>
        <wsse:Security>
            <wsse:UsernameToken>
                <wsse:Username>' . htmlspecialchars($_GET['cod_3'] . $_GET['cod_4']) . '</wsse:Username>
                <wsse:Password>' . htmlspecialchars($_GET['cod_5']) . '</wsse:Password>
            </wsse:UsernameToken>
        </wsse:Security>
    </soapenv:Header>
    <soapenv:Body>
        <ser:' . $metodo . '>' . $content . '</ser:' . $metodo . '>
    </soapenv:Body>
</soapenv:Envelope>';

// Función para extraer ticket
function buscoTicket($result) {
    $library = new SimpleXMLElement($result);
    $ns = $library->getDocNamespaces();
    $ext1 = $library->children($ns['soapenv']);
    $ext2 = $ext1->Body;
    $ext3 = $ext2->children($ns['ser']);
    $ext4 = $ext3->sendSummaryResponse;
    $ext5 = $ext4->children();
    $ticket = $ext5->ticket;

    return ($ticket[0]);
}

// Realizamos la llamada a la función SOAP
$error_mensaje = '';
$error_existe = 0;
$result = '';

try {
    $result = soapCall($wsdlURL, "sendBill", $XMLString);
    
    switch ($_GET['cod_6']) {
        case 1:
            $archivo = fopen('C' . $NomArch . '.xml', 'w+');
            fwrite($archivo, $result);
            fclose($archivo);

            $xml = simplexml_load_file('C' . $NomArch . '.xml');
            foreach ($xml->xpath('//applicationResponse') as $response) {
                // Procesar respuesta
            }

            $cdr = base64_decode($response);
            $archivo = fopen($ruta . 'R-' . $NomArch . '.zip', 'w+');
            fwrite($archivo, $cdr);
            fclose($archivo);
            chmod($ruta . 'R-' . $NomArch . '.zip', 0777);

            $archive = new ZipArchive();
            if ($archive->open($ruta . 'R-' . $NomArch . '.zip') === TRUE) {
                $archive->extractTo($ruta);
                $archive->close();
                chmod($ruta . 'R-' . $NomArch . '.xml', 0777);
            } else {
                throw new Exception("Error: No se pudo extraer el archivo ZIP.");
            }
            unlink('C' . $NomArch . '.xml');
            break;
        case 2:
            $result = buscoTicket($result);
            break;
        case 3:
            $archivo = fopen('C' . $NomArch . '.xml', 'w+');
            fwrite($archivo, $result);
            fclose($archivo);

            $xml = simplexml_load_file('C' . $NomArch . '.xml');
            foreach ($xml->xpath('//content') as $response) {
                // Procesar contenido
            }

            $cdr = base64_decode($response);
            $archivo = fopen($ruta . 'R-' . $NomArch . '.zip', 'w+');
            fwrite($archivo, $cdr);
            fclose($archivo);

            $archive = new ZipArchive();
            if ($archive->open($ruta . 'R-' . $NomArch . '.zip') === TRUE) {
                $archive->extractTo($ruta);
                $archive->close();
            } else {
                throw new Exception("Error: No se pudo extraer el archivo ZIP.");
            }
            break;
    }
} catch (Exception $e) {
    $error_existe = 1;
    $error_mensaje = $e->getMessage();
}

// Formatear respuesta JSON
$jsondata = [
    'param_ver'     => $result,
    'success'       => ($error_existe === 0),
    'error_mensaje' => $error_mensaje,
    'error_existe'  => $error_existe
];

if ($_GET['cod_6'] == 2) {
    $jsondata['ticket'] = $result;
}

echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);
