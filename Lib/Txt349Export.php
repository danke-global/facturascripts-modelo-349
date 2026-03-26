<?php

namespace FacturaScripts\Plugins\Modelo349\Lib;

use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\DataSrc\Empresas;

class Txt349Export
{
    /**
     * Generate BOE-format .349 file content
     */
    public static function export(
        string $codejercicio,
        string $period,
        array  $purchasesData,
        array  $salesData,
        string $companyNif,
        string $companyName,
        string $phone,
        string $contactName
    ): string
    {
        $lines = [];

        // Combine all operators
        $allOperators = [];
        foreach ($purchasesData as $row) {
            $allOperators[] = $row;
        }
        foreach ($salesData as $row) {
            $allOperators[] = $row;
        }

        $totalBase = 0.0;
        foreach ($allOperators as $op) {
            $totalBase += $op['base'];
        }

        // TIPO 1: Declarante
        $lines[] = self::buildType1(
            $codejercicio,
            $period,
            $companyNif,
            $companyName,
            $phone,
            $contactName,
            count($allOperators),
            $totalBase
        );

        // TIPO 2: Operadores
        foreach ($allOperators as $op) {
            $lines[] = self::buildType2(
                $codejercicio,
                $companyNif,
                $op['cifnif'],
                $op['nombre'],
                $op['clave'],
                $op['base']
            );
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    protected static function buildType1(
        string $year,
        string $period,
        string $nif,
        string $name,
        string $phone,
        string $contactName,
        int    $numOperators,
        float  $totalBase
    ): string
    {
        $record = '1';                                          // 1: tipo registro
        $record .= '349';                                       // 2-4: modelo
        $record .= str_pad($year, 4, '0', STR_PAD_LEFT);       // 5-8: ejercicio
        $record .= self::formatNif($nif);                       // 9-17: NIF declarante
        $record .= self::alphanumeric($name, 40);               // 18-57: razón social
        $record .= ' ';                                         // 58: blanco
        // 59-107: persona de contacto
        $record .= str_pad(substr(preg_replace('/[^0-9]/', '', $phone), 0, 9), 9, ' '); // 59-67: teléfono
        $record .= self::alphanumeric($contactName, 40);        // 68-107: nombre contacto
        // 108-120: número identificativo declaración
        $record .= '349' . $year . str_pad('1', 6, '0', STR_PAD_LEFT); // 108-120
        $record .= ' ';                                         // 121: complementaria
        $record .= ' ';                                         // 122: sustitutiva
        $record .= str_pad('0', 13, '0');                       // 123-135: num declaración anterior
        // 136-137: período
        $record .= self::formatPeriod($period);
        // 138-146: num total operadores
        $record .= str_pad($numOperators, 9, '0', STR_PAD_LEFT);
        // 147-161: importe total (13 enteros + 2 decimales)
        $record .= self::formatAmount($totalBase, 15);
        // 162-170: num operadores rectificaciones
        $record .= str_pad('0', 9, '0');
        // 171-185: importe rectificaciones
        $record .= str_pad('0', 15, '0');
        // 186: cambio periodicidad
        $record .= ' ';
        // 187-390: blancos
        $record .= str_repeat(' ', 204);
        // 391-399: NIF representante legal
        $record .= str_repeat(' ', 9);
        // 400-500: blancos
        $record .= str_repeat(' ', 101);

        return $record;
    }

    protected static function buildType2(
        string $year,
        string $declarantNif,
        string $operatorNif,
        string $operatorName,
        string $clave,
        float  $base
    ): string
    {
        // Extract country code and number from NIF
        list($countryCode, $vatNumber) = self::parseEuNif($operatorNif);

        $record = '2';                                          // 1: tipo registro
        $record .= '349';                                       // 2-4: modelo
        $record .= str_pad($year, 4, '0', STR_PAD_LEFT);       // 5-8: ejercicio
        $record .= self::formatNif($declarantNif);              // 9-17: NIF declarante
        $record .= str_repeat(' ', 58);                         // 18-75: blancos
        // 76-92: NIF operador comunitario
        $record .= self::alphanumeric($countryCode, 2);         // 76-77: código país
        $record .= self::alphanumeric($vatNumber, 15);          // 78-92: número
        $record .= self::alphanumeric($operatorName, 40);       // 93-132: nombre
        $record .= $clave;                                      // 133: clave operación
        // 134-146: base imponible (11 enteros + 2 decimales)
        $record .= self::formatAmount($base, 13);
        // 147-178: blancos
        $record .= str_repeat(' ', 32);
        // 179-195: NIF sustituto
        $record .= str_repeat(' ', 17);
        // 196-235: nombre sustituto
        $record .= str_repeat(' ', 40);
        // 236-500: blancos
        $record .= str_repeat(' ', 265);

        return $record;
    }

    protected static function formatNif(string $nif): string
    {
        // Spanish NIF: right-aligned, last char = control, zero-padded left
        $nif = strtoupper(trim($nif));
        return str_pad($nif, 9, ' ', STR_PAD_LEFT);
    }

    protected static function parseEuNif(string $nif): array
    {
        $nif = strtoupper(trim($nif));
        // Try to extract 2-letter country code
        if (preg_match('/^([A-Z]{2})(.+)$/', $nif, $matches)) {
            return [$matches[1], $matches[2]];
        }
        // Fallback
        return ['', $nif];
    }

    protected static function alphanumeric(string $value, int $length): string
    {
        $value = strtoupper(trim($value));
        // Remove accents
        $value = strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ü' => 'U', 'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        ]);
        return str_pad(substr($value, 0, $length), $length, ' ');
    }

    protected static function formatAmount(float $amount, int $totalLength): string
    {
        $intPart = (int)floor(abs($amount));
        $decPart = round((abs($amount) - $intPart) * 100);
        $intLength = $totalLength - 2;
        return str_pad($intPart, $intLength, '0', STR_PAD_LEFT) . str_pad((int)$decPart, 2, '0', STR_PAD_LEFT);
    }

    protected static function formatPeriod(string $period): string
    {
        switch ($period) {
            case 'T1': return '1T';
            case 'T2': return '2T';
            case 'T3': return '3T';
            case 'T4': return '4T';
            default: return '0A';
        }
    }
}
