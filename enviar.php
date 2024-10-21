<?php
require 'libraries/efactura.php';

header('Access-Control-Allow-Origin: *');
$name_file = $_REQUEST['name_file'];

ws_sunat($empresa, $nombre_archivo);

function ws_sunat($empresa, $nombre_archivo){
    //enviar a Sunat
    //cod_1: Select web Service: 1 factura, boletas --- 9 es para guias
    //cod_2: Entorno:  0 Beta, 1 Produccion
    //cod_3: ruc
    //cod_4: usuario secundario USU(segun seha beta o producci贸n)
    //cod_5: usuario secundario PASSWORD(segun seha beta o producci贸n)
    //cod_6: Accion:   1 enviar documento a Sunat --  2 enviar a anular  --  3 enviar ticket
    //cod_7: serie de documento
    //cod_8: numero ticket
    //$ruta_dominio = "https://grupofact.com/API_SUNAT/ws";
    $ruta_dominio = "https://apisunat.llamadevs.com/";
    
    $url = $ruta_dominio."/ws_sunat/index.php?numero_documento=".$nombre_archivo."&cod_1=1&cod_2=0&cod_3=".$empresa['ruc']."&cod_4=MODDATOS&cod_5=moddatos&cod_6=1";
    //echo $url;exit;
    $data = file_get_contents($url);
    $info = json_decode($data, TRUE);

    $respuesta_codigo = '';
    $respuesta_mensaje = '';

    $jsondata = array(
        'success'       =>  true,
        'codigo'        =>  $respuesta_codigo,
        'error_existe'  =>  $info['error_existe'],
        'message'       =>  $respuesta_mensaje.$info['error_mensaje']
    );
    echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);
}