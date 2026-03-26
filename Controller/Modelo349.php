<?php

namespace FacturaScripts\Plugins\Modelo349\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Plugins\Modelo349\Lib\Txt349Export;

class Modelo349 extends Controller
{
    /** @var string */
    public $activetab = 'purchases';

    /** @var string */
    public $codejercicio;

    /** @var string */
    public $period = 'T1';

    /** @var array */
    public $purchasesData = [];

    /** @var array */
    public $purchasesTotals = [];

    /** @var array */
    public $salesData = [];

    /** @var array */
    public $salesTotals = [];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'Modelo 349';
        $data['icon'] = 'fa-solid fa-globe-europe';
        return $data;
    }

    /**
     * @param int|null $idempresa
     * @return Ejercicio[]
     */
    public function allExercises(?int $idempresa): array
    {
        if (empty($idempresa)) {
            return Ejercicios::all();
        }

        $list = [];
        foreach (Ejercicios::all() as $ejercicio) {
            if ($ejercicio->idempresa === $idempresa) {
                $list[] = $ejercicio;
            }
        }
        return $list;
    }

    public function allPeriods(): array
    {
        return [
            'T1' => 'Primer trimestre (enero - marzo)',
            'T2' => 'Segundo trimestre (abril - junio)',
            'T3' => 'Tercer trimestre (julio - septiembre)',
            'T4' => 'Cuarto trimestre (octubre - diciembre)',
            'ANUAL' => 'Resumen anual',
        ];
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->activetab = $this->request->request->get('activetab', $this->activetab);
        $this->period = $this->request->request->get('period', $this->period);

        $exerciseModel = new Ejercicio();
        $codejercicio = null;
        foreach ($exerciseModel->all([], ['fechainicio' => 'DESC'], 0, 0) as $exe) {
            if ($exe->isOpened()) {
                $codejercicio = $exe->codejercicio;
                break;
            }
        }

        $this->codejercicio = $this->request->request->get('codejercicio', $codejercicio);
        $this->loadData();

        $action = $this->request->request->get('action', '');
        if ($action === 'download-349') {
            $this->downloadAction();
        }
    }

    protected function downloadAction(): void
    {
        $this->setTemplate(false);

        $ejercicio = Ejercicios::get($this->codejercicio);
        $company = Empresas::get($ejercicio->idempresa);

        $content = Txt349Export::export(
            $this->codejercicio,
            $this->period,
            $this->purchasesData,
            $this->salesData,
            $company->cifnif,
            $company->nombre,
            $company->telefono1 ?? '',
            $company->administrador ?? $company->nombre
        );

        $fileName = 'modelo_349_' . $this->codejercicio . '_' . $this->period . '.349';
        $this->response
            ->header('Content-Type', 'text/plain; charset=ISO-8859-1')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0')
            ->setContent(mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8'));
    }

    /**
     * NIF starting with "EU" is an OSS registration (non-EU company).
     * Modelo 349 only includes EU member state operators.
     */
    protected function isNonEuNif(string $nif): bool
    {
        $nif = strtoupper(trim($nif));
        // "EU" prefix = One Stop Shop VAT (non-EU company)
        // Also exclude NIFs without a 2-letter EU country prefix
        if (str_starts_with($nif, 'EU') || !preg_match('/^[A-Z]{2}/', $nif)) {
            return true;
        }

        // Valid EU member state codes
        $euCountries = [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
            'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
            'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
        ];
        $prefix = substr($nif, 0, 2);
        return !in_array($prefix, $euCountries);
    }

    protected function getDateRange(): array
    {
        $ejercicio = Ejercicios::get($this->codejercicio);
        $year = date('Y', strtotime($ejercicio->fechainicio));

        switch ($this->period) {
            case 'T1':
                return [$year . '-01-01', $year . '-03-31'];
            case 'T2':
                return [$year . '-04-01', $year . '-06-30'];
            case 'T3':
                return [$year . '-07-01', $year . '-09-30'];
            case 'T4':
                return [$year . '-10-01', $year . '-12-31'];
            default:
                return [$year . '-01-01', $year . '-12-31'];
        }
    }

    protected function loadData(): void
    {
        list($dateFrom, $dateTo) = $this->getDateRange();

        // Adquisiciones intracomunitarias (compras)
        $sql = "SELECT p.codproveedor, p.cifnif, p.nombre, p.neto, p.fecha, prov.tipoidfiscal"
            . " FROM facturasprov p"
            . " LEFT JOIN proveedores prov ON p.codproveedor = prov.codproveedor"
            . " WHERE p.operacion = 'intracomunitaria'"
            . " AND p.codejercicio = " . $this->dataBase->var2str($this->codejercicio)
            . " AND p.fecha >= " . $this->dataBase->var2str($dateFrom)
            . " AND p.fecha <= " . $this->dataBase->var2str($dateTo)
            . " ORDER BY p.fecha ASC";

        $purchases = [];
        foreach ($this->dataBase->select($sql) as $row) {
            // Skip non-EU operators (EU OSS prefix is not an EU member state)
            if ($this->isNonEuNif($row['cifnif'])) {
                continue;
            }
            $key = $row['codproveedor'];
            if (!isset($purchases[$key])) {
                $purchases[$key] = [
                    'cifnif' => $row['cifnif'],
                    'nombre' => $row['nombre'],
                    'tipoidfiscal' => $row['tipoidfiscal'] ?? 'NIF',
                    'clave' => 'A',
                    'base' => 0.0,
                    'num_facturas' => 0,
                ];
            }
            $purchases[$key]['base'] += (float)$row['neto'];
            $purchases[$key]['num_facturas']++;
        }

        $this->purchasesData = $purchases;
        $this->purchasesTotals = ['base' => 0.0, 'num_facturas' => 0];
        foreach ($purchases as $row) {
            $this->purchasesTotals['base'] += $row['base'];
            $this->purchasesTotals['num_facturas'] += $row['num_facturas'];
        }

        // Entregas intracomunitarias (ventas)
        $sql = "SELECT c.codcliente, c.cifnif, c.nombrecliente as nombre, c.neto, c.fecha, cli.tipoidfiscal"
            . " FROM facturascli c"
            . " LEFT JOIN clientes cli ON c.codcliente = cli.codcliente"
            . " WHERE c.operacion = 'intracomunitaria'"
            . " AND c.codejercicio = " . $this->dataBase->var2str($this->codejercicio)
            . " AND c.fecha >= " . $this->dataBase->var2str($dateFrom)
            . " AND c.fecha <= " . $this->dataBase->var2str($dateTo)
            . " ORDER BY c.fecha ASC";

        $sales = [];
        foreach ($this->dataBase->select($sql) as $row) {
            if ($this->isNonEuNif($row['cifnif'])) {
                continue;
            }
            $key = $row['codcliente'];
            if (!isset($sales[$key])) {
                $sales[$key] = [
                    'cifnif' => $row['cifnif'],
                    'nombre' => $row['nombre'],
                    'tipoidfiscal' => $row['tipoidfiscal'] ?? 'NIF',
                    'clave' => 'E',
                    'base' => 0.0,
                    'num_facturas' => 0,
                ];
            }
            $sales[$key]['base'] += (float)$row['neto'];
            $sales[$key]['num_facturas']++;
        }

        $this->salesData = $sales;
        $this->salesTotals = ['base' => 0.0, 'num_facturas' => 0];
        foreach ($sales as $row) {
            $this->salesTotals['base'] += $row['base'];
            $this->salesTotals['num_facturas'] += $row['num_facturas'];
        }
    }
}
