<?php
declare(strict_types=1); // Activar el tipado estricto

require 'libraries/Numletras.php';
require 'libraries/Variables_diversas_model.php';
require_once('libraries/fpdf/fpdf.php');
require_once('libraries/qr/phpqrcode/qrlib.php');

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

try {
    // Verificar que el campo 'datosJSON' esté presente
    if (empty($_POST['datosJSON'])) {
        throw new Exception("No se ha recibido el campo 'datosJSON'.");
    }

    // Decodificar el JSON recibido
    $array = $_POST['datosJSON'];
    $array_llegada = json_decode($array);

    if (!$array_llegada) {
        throw new Exception("El JSON recibido es inválido.");
    }

    // Extraer los datos del JSON
    $empresa = get_object_vars($array_llegada->empresa);
    $cliente = get_object_vars($array_llegada->cliente);
    $venta = get_object_vars($array_llegada->venta);

    // Procesar los detalles
    $detalle = [];
    foreach ($array_llegada->items as $value) {
        $detalle[] = get_object_vars($value);
    }

    // Cálculos para la venta
    $empresa['modo'] = 0;
    $venta['total_a_pagar'] = number_format($detalle[0]['cantidad'] * $detalle[0]['precio'], 2);
    $venta['total_gravada'] = number_format(($venta['total_a_pagar'] / 1.18), 2);
    $venta['total_igv'] = number_format(($venta['total_a_pagar'] - $venta['total_gravada']), 2);
    $venta['total_exonerada'] = null;
    $venta['total_inafecta'] = null;

    $venta['fecha_emision'] = date("Y-m-d");
    $venta['hora_emision'] = date("H:i:s");
    $venta['fecha_vencimiento'] = null;

    $detalle[0]['tipo_igv_codigo'] = 10;
    $detalle[0]['precio_base'] = ($detalle[0]['precio'] / 1.18);
    $detalle[0]['codigo_producto'] = 'c_1020';
    $detalle[0]['codigo_sunat'] = '-';

    // Nombre del archivo PDF
    $nombre_archivo = $empresa['ruc'] . '-' . $venta['tipo_documento_codigo'] . '-' . $venta['serie'] . '-' . $venta['numero'];

    // Llamar a la función para crear el PDF
    crear_pdf($empresa, $cliente, $venta, $detalle, $nombre_archivo);

    // Devolver la respuesta
    echo json_encode([
        'status' => 'success',
        'message' => 'PDF generado exitosamente',
        'pdf_url' => 'files/facturacion_electronica/PDF/' . $nombre_archivo . '.pdf'
    ]);

} catch (Exception $e) {
    // Enviar mensaje de error en formato JSON
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al generar el PDF: ' . $e->getMessage()
    ]);
}

/**
 * Función para crear el PDF del comprobante
 */
function crear_pdf(array $empresa, array $cliente, array $venta, array $detalle, string $nombre): void
{
    try {
        $num = new Numletras();
        $totalVenta = explode(".", $venta['total_a_pagar']);
        $totalLetras = $num->num2letras($totalVenta[0]);
        $totalLetras = 'Son: ' . $totalLetras . ' con ' . $totalVenta[1] . '/100 soles';

        $fijo = 233 + 10;
        $ancho = 8.4;
        $numero_filas = count($detalle);
        $total_y = $fijo + $ancho * $numero_filas;

        // Crear el PDF
        $pdf = new FPDF('P', 'mm', [80, $total_y]);
        $pdf->SetMargins(2, 2, 2);
        $pdf->AddPage();

        $tamano_x = 60;
        $tamano_y = 40;
        $ruta_foto = 'logo.PNG';

        // Verificar si la imagen existe antes de cargarla
        if (file_exists($ruta_foto)) {
            $pdf->Image($ruta_foto, 10, 0, $tamano_x, $tamano_y);
        }
        $pdf->Ln($tamano_y);

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(74, 6, $empresa["nombre_comercial"], 'B', 1, 'C');

        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(74, 6, $empresa["razon_social"], 0, 1, 'C');
        $pdf->Cell(74, 6, "RUC: " . $empresa["ruc"], 0, 1, 'C');
        $pdf->MultiCell(74, 5, mb_convert_encoding($empresa["domicilio_fiscal"], 'ISO-8859-1', 'UTF-8'));
        $pdf->Cell(74, 1, "-----------------------------------------------------------------------------", 0, 0, 'C');
        $pdf->Ln(4);

        // Tipo de documento
        $tipo_documento = match ($venta['tipo_documento_codigo']) {
            '01' => 'FACTURA',
            '03' => 'BOLETA',
            default => 'DOCUMENTO',
        };

        $pdf->Cell(74, 7, mb_convert_encoding($tipo_documento . " DE VENTA ELECTRÓNICA", 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
        $pdf->Ln(5);
        $pdf->Cell(74, 7, $venta["serie"] . "-" . $venta["numero"], 0, 0, 'L');
        $pdf->Ln(5);
        $pdf->Cell(74, 7, "Fecha/hora emisión: " . $venta["fecha_emision"], 0, 0, 'L');
        $pdf->Ln(5);
        $pdf->Cell(74, 7, "Vendedor: Juan Perez", 0, 0, 'L');
        $pdf->Ln(5);
        $pdf->Cell(74, 1, "-----------------------------------------------------------------------------", 0, 0, 'C');
        $pdf->Ln(4);

        // Información del cliente
        $tipo_documento_cliente = match ($cliente['codigo_tipo_entidad']) {
            '1' => 'DNI',
            '6' => 'RUC',
            default => 'DOC',
        };

        $pdf->MultiCell(74, 5, mb_convert_encoding("Cliente: " . $cliente["razon_social_nombres"], 'ISO-8859-1', 'UTF-8'));
        $pdf->Cell(74, 7, mb_convert_encoding($tipo_documento_cliente . ": " . $cliente['numero_documento'], 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
        $pdf->Cell(74, 1, "-----------------------------------------------------------------------------", 0, 1, 'C');

        // Detalle de productos
        $pdf->Cell(16, 7, "Productos", 'B', 0, 'L');
        $pdf->Cell(45, 7, "", 0, 0, 'L');
        $pdf->Cell(9, 7, "Total", 'B', 0, 'R');
        $pdf->Ln(4);

        $impuesto = 1.18;
        $pdf->Cell(60, 10, mb_convert_encoding($detalle[0]['producto'], 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
        $pdf->Cell(10, 10, number_format(($detalle[0]['cantidad'] * ($detalle[0]['precio_base'] * $impuesto)), 2), 0, 0, 'R');
        $pdf->Ln(4);
        $pdf->Cell(12, 10, number_format($detalle[0]['precio_base'] * $impuesto, 2) . " x " . $detalle[0]['cantidad'], 0, 0, 'L');
        $pdf->Ln(4);

        $pdf->Ln(4);
        $pdf->Cell(74, 1, "-----------------------------------------------------------------------------", 0, 1, 'C');

        // Totales
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(30, 7, "", 0, 0, 'R');
        $pdf->Cell(20, 7, "Gravada: ", 0, 0, 'L');
        $pdf->Cell(20, 7, "S/. " . $venta['total_gravada'], 0, 0, 'R');
        $pdf->Ln(6);

        $pdf->Cell(30, 7, "", 0, 0, 'R');
        $pdf->Cell(20, 7, "IGV: 18% ", 0, 0, 'L');
        $pdf->Cell(20, 7, "S/. " . $venta['total_igv'], 0, 0, 'R');
        $pdf->Ln(7);

        $pdf->Cell(30, 7, "", 0, 0, 'R');
        $pdf->Cell(20, 7, "Total:", 0, 0, 'L');
        $pdf->Cell(20, 7, "S/. " . $venta['total_a_pagar'], 0, 1, 'R');
        $pdf->Ln(4);

        $pdf->MultiCell(0, 5, mb_convert_encoding($totalLetras, 'ISO-8859-1', 'UTF-8'));

        // Generar el código QR y añadirlo al PDF
        $rutaqr = GetImgQr($venta, $empresa, $tipo_documento, $cliente);

        // Añadir el QR al PDF
        $pdf->Image($rutaqr, 21, 190, 40, 40);
        
        // Guardar el archivo PDF
        $pdf->Output('files/facturacion_electronica/PDF/' . $nombre . '.pdf', 'F');

    } catch (Exception $e) {
        throw new Exception('Error al generar el PDF: ' . $e->getMessage());
    }
}

/**
 * Función para generar el código QR del comprobante
 */
function GetImgQr(array $venta, array $empresa, string $tipo_documento, array $cliente): string
{
    $textoQR = '';
    $textoQR .= $empresa['ruc'] . "|"; // RUC EMPRESA
    $textoQR .= $tipo_documento . "|"; // TIPO DE DOCUMENTO 
    $textoQR .= $venta['serie'] . "|"; // SERIE
    $textoQR .= $venta['numero'] . "|"; // NUMERO
    $textoQR .= $venta['total_igv'] . "|"; // MTO TOTAL IGV
    $textoQR .= $venta['total_a_pagar'] . "|"; // MTO TOTAL DEL COMPROBANTE
    $textoQR .= $venta['fecha_emision'] . "|"; // FECHA DE EMISION
    $textoQR .= $cliente['codigo_tipo_entidad'] . "|"; // TIPO DE DOCUMENTO ADQUIRENTE
    $textoQR .= $cliente['numero_documento'] . "|"; // NUMERO DE DOCUMENTO ADQUIRENTE

    $nombreQR = $venta['tipo_documento_codigo'] . '-' . $venta['serie'] . '-' . $venta['numero'];
    QRcode::png($textoQR, "files/facturacion_electronica/qr/" . $nombreQR . ".png", QR_ECLEVEL_L, 10, 2);

    return "files/facturacion_electronica/qr/{$nombreQR}.png";
}
