<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);

require('lib/pclzip.lib.php'); // Librería que comprime archivos en .ZIP
//NOMBRE DE ARCHIVO A PROCESAR.
//$NomArch = '20604051984-01-F001-131';
//$ruta = '../files/facturacion_electronica/FIRMA/';


$serie = $_GET['cod_7'];
$NomArch = $_GET['numero_documento'];
//$ruta = ($_GET['cod_6'] == '0') ? '../files/facturacion_electronica/FIRMA/' : '../files/facturacion_electronica/BAJA/FIRMA/' ;
switch ($_GET['cod_6']) {
    case 1:
        $ruta = '../files/facturacion_electronica/FIRMA/';
        $metodo = 'sendBill';   //hacer facturas o boletas
        break;
    case 2:
        $ruta = '../files/facturacion_electronica/BAJA/FIRMA/';
        $metodo = 'sendSummary';    //enviar anulación
        break;
    case 3:
        $ruta = '../files/facturacion_electronica/BAJA/RPTA/';
        $metodo = 'getStatus';  //metodo recibie ticket de anulacion
        break;
    case 4:
        $ruta = '../files/facturacion_electronica/FIRMA/';
        $metodo = 'getStatusCdr';  //pregunta estado CDR        
        break;
}

//enviar a Sunat       
//cod_1: Select web Service: 1 factura, boletas --- 9 es para guias
//cod_2: Entorno:  0 Beta, 1 Produccion
//cod_3: ruc
//cod_4: usuario secundario USU(segun seha beta o producción)
//cod_5: usuario secundario PASSWORD(segun seha beta o producción)
//cod_6: Accion:   1 enviar documento a Sunat --  2 enviar a anular  --  3 enviar ticket  -- 4 getStatusCDR
//cod_7: tipo_documento-serie-numero
//cod_8: numero ticket

## =============================================================================

//creamos zip siempre queno recibo tickets
//echo "aa";
//echo $ruta.$NomArch . ".zip";exit;
if(($_GET['cod_6'] == 1) || ($_GET['cod_6'] == 2) ){
    ## Creación del archivo .ZIP
    $zip = new PclZip($ruta.$NomArch . ".zip");
    //var_dump($zip);exit;

    if(file_exists($ruta.$NomArch . ".zip")){
        $r = 1;
    }else{    
        $zip->add($ruta.$NomArch.".xml", PCLZIP_OPT_REMOVE_PATH, $ruta, PCLZIP_OPT_ADD_PATH, '');
    }

    //$zip->create($NomArch . ".xml");
    chmod($ruta.$NomArch . ".zip", 0777);
    //echo $ruta.$NomArch . ".zip";
    # ==============================================================================
    # Procedimiento para enviar comprobante a la SUNAT
}


switch ($_GET['cod_6']) {
    case 1:        
        $content = '<fileName>' . $NomArch . '.zip</fileName><contentFile>' . base64_encode(file_get_contents($ruta.$NomArch . '.zip')) . '</contentFile>';
        break;
    case 2:        
        $content = '<fileName>' . $NomArch . '.zip</fileName><contentFile>' . base64_encode(file_get_contents($ruta.$NomArch . '.zip')) . '</contentFile>';
        break;
    case 3:        
        $content = '<ticket>'.$_GET['cod_8'].'</ticket>';
        break;
    case 4:        
        $documento = explode("-", $_GET['numero_documento']);        
        $content= '<rucComprobante>'.$documento[0].'</rucComprobante>
        <tipoComprobante>'.$documento[1].'</tipoComprobante>
        <serieComprobante>'.$documento[2].'</serieComprobante>
        <numeroComprobante>'.$documento[3].'</numeroComprobante>';
        break;
}


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
        $dom = new DOMDocument('1.0');
        try {
            $dom->loadXML($request);
        } catch (DOMException $e) {
            die($e->code);
        }
        $request = $dom->saveXML();
        //Solicitud
        return parent::__doRequest($request, $location, $action, $version, $one_way = 0);
    }

    public function SoapClientCall($SOAPXML) {
        return $this->setXMLStr($SOAPXML);
    }

}

function soapCall($wsdlURL, $callFunction = "", $XMLString) {
    $client = new feedSoap($wsdlURL, array('trace' => true));
    $reply = $client->SoapClientCall($XMLString);
    //echo "REQUEST:\n" . $client->__getFunctions() . "\n";
    $client->__call("$callFunction", array(), array());
    //$request = prettyXml($client->__getLastRequest());
    //echo highlight_string($request, true) . "<br/>\n";
    return $client->__getLastResponse();
}

switch ($_GET['cod_2']) {
    case 0:
        $wsdlURL = 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
        break;
    case 1:
        $wsdlURL = 'billService.wsdl';
        break;    
}

//Estructura del XML para la conexión
$XMLString = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
 <soapenv:Header>
     <wsse:Security>
         <wsse:UsernameToken>
             <wsse:Username>'.$_GET['cod_3'].$_GET['cod_4'].'</wsse:Username>
             <wsse:Password>'.$_GET['cod_5'].'</wsse:Password>
         </wsse:UsernameToken>
     </wsse:Security>
 </soapenv:Header>
 <soapenv:Body>
     <ser:'.$metodo.'>'.$content.'</ser:'. $metodo .'>
 </soapenv:Body>
</soapenv:Envelope>';
//echo $XMLString;exit;

//Realizamos la llamada a nuestra función
$ruta_cdr = '../files/facturacion_electronica/CDR/';
$result = soapCall($wsdlURL, $callFunction = "sendBill", $XMLString);
descargarRespone($NomArch, $result, $ruta_cdr);
$response = leerXmlResponse($NomArch, $ruta_cdr);
descargarCDR_ZIP($NomArch, $response, $ruta_cdr);
obtenerCDR_XML($NomArch, $ruta_cdr);
$respuesta = leerCDR_XML($NomArch, $ruta, $ruta_cdr);
elminarArchivos($NomArch, $ruta, $ruta_cdr);

echo json_encode($respuesta);EXIT;

//Descargamos el Archivo Response
function descargarRespone($NomArch, $result, $ruta_cdr){
    $archivo = fopen($ruta_cdr.'C' . $NomArch . '.xml', 'w+');
    fputs($archivo, $result);
    fclose($archivo);
}

/* LEEMOS EL ARCHIVO XML */
function leerXmlResponse($NomArch, $ruta_cdr){
    $xml = simplexml_load_file($ruta_cdr.'C' . $NomArch . '.xml');
    foreach ($xml->xpath('//applicationResponse') as $response) {

    }
    return $response;
}

/* AQUI DESCARGAMOS EL ARCHIVO CDR(CONSTANCIA DE RECEPCIÓN) */
function descargarCDR_ZIP($NomArch, $response, $ruta_cdr){
    $cdr = base64_decode($response);
    $archivo = fopen($ruta_cdr.'R-' . $NomArch . '.zip', 'w+');
    fputs($archivo, $cdr);
    fclose($archivo);
    chmod($ruta_cdr.'R-' . $NomArch . '.zip', 0777);
}

function obtenerCDR_XML($NomArch, $ruta_cdr){
    $archive = new PclZip($ruta_cdr.'R-' . $NomArch . '.zip');
    if ($archive->extract(PCLZIP_OPT_PATH, $ruta_cdr) == 0) {
        die("Error : " . $archive->errorInfo(true));
    } else {
        //chmod($ruta.'R-' . $NomArch . '.xml', 0777);
    }
}

function leerCDR_XML($NomArch, $ruta, $ruta_cdr){
    $resultado = array();
    //$ruta_general = "https://" . $_SERVER["SERVER_NAME"]. "/API_SUNAT/files/facturacion_electronica/";
    $ruta_general = carpeta_actual()."/files/facturacion_electronica/";
    //echo "abc";exit;        
    if(file_exists($ruta_cdr.'R-' . $NomArch . '.xml')){            
        $library = new SimpleXMLElement($ruta_cdr.'R-' . $NomArch . '.xml', null, true);

        $ns = $library->getDocNamespaces();
        $ext1 = $library->children($ns['cac']);
        $ext2 = $ext1->DocumentResponse;
        $ext3 = $ext2->children($ns['cac']);            
        $ext4 = $ext3->children($ns['cbc']);
        
        $codigo_hash = getFirma($NomArch, $ruta);
        $xml_base_64 = base64_encode(file_get_contents($ruta.$NomArch . '.zip'));
        $cdr_base_64 = base64_encode(file_get_contents($ruta_cdr.'R-' . $NomArch . '.zip'));

        $resultado = array(
            'respuesta_sunat_codigo'        => trim($ext4->ResponseCode),
            'respuesta_sunat_descripcion'   => trim($ext4->Description),
            
            'ruta_xml'  => $ruta_general.'FIRMA/'.$NomArch.'.xml',
            'ruta_cdr'  => $ruta_general.'CDR/R-' .$NomArch.'.xml',
            'ruta_pdf'  => $ruta_general.'PDF/'.$NomArch.'.pdf',
            
            'codigo_hash'   =>  $codigo_hash,
            'xml_base_64'   =>  $xml_base_64,
            'cdr_base_64'   =>  $cdr_base_64
        );
    }
    return $resultado;
}

function getFirma($NomArch, $ruta){
    $xml    = simplexml_load_file($ruta. $NomArch . '.xml');
    foreach ($xml->xpath('//ds:DigestValue') as $response) {

    }
    return $response;
}

function elminarArchivos($NomArch, $ruta, $ruta_cdr){
    /* Eliminamos el Archivo Response */
    unlink($ruta_cdr.'C' . $NomArch . '.xml');
    unlink($ruta_cdr.'R-' . $NomArch . '.zip');
    unlink($ruta . $NomArch . '.zip');
}

function carpeta_actual(){
    $archivo_actual = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
    $dir = explode('/', $archivo_actual);
    
    $cadena = '';
    for($i=0;  $i<(count($dir) - 2); $i++){
        $cadena .= $dir[$i]."/";
    }
    return substr($cadena, 0, -1);
}