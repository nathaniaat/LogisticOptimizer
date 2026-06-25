<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GraphService;
use App\Services\ACOService;
use App\Services\GAService;

class OptimizationController extends Controller
{
    private array $defaultEdges = [
        ["Jakarta",     "Cirebon",     258],
        ["Cirebon",     "Semarang",    237],
        ["Semarang",    "Solo",        100],
        ["Solo",        "Surabaya",    260],
        ["Surabaya",    "Malang",       90],
        ["Semarang",    "Yogyakarta",   65],
        ["Yogyakarta",  "Solo",         60],
        ["Jakarta",     "Bandung",     150],
        ["Bandung",     "Tasikmalaya", 105],
        ["Tasikmalaya", "Yogyakarta",  200],
    ];

    private array $defaultTrucks = [
        1 => ["name" => "Truk JKT-1", "origin" => "Jakarta",  "max_weight" => 1000, "max_volume" => 9000000],
        2 => ["name" => "Truk JKT-2", "origin" => "Jakarta",  "max_weight" => 1000, "max_volume" => 9000000],
        3 => ["name" => "Truk SBY-1", "origin" => "Surabaya", "max_weight" => 1000, "max_volume" => 9000000],
        4 => ["name" => "Truk SBY-2", "origin" => "Surabaya", "max_weight" => 1000, "max_volume" => 9000000],
    ];

    private array $defaultItems = [
        1  => ["name" => "Kulkas 2 Pintu",       "weight" => 80,  "origin" => "Jakarta",  "destination" => "Semarang",    "volume" => 350000],
        2  => ["name" => "TV LED 55 inch",       "weight" => 45,  "origin" => "Jakarta",  "destination" => "Bandung",     "volume" => 900000],
        3  => ["name" => "Pompa Air Industri",   "weight" => 320, "origin" => "Surabaya", "destination" => "Semarang",    "volume" => 1800000],
        4  => ["name" => "Generator Set",        "weight" => 750, "origin" => "Surabaya", "destination" => "Malang",      "volume" => 2800000],
        5  => ["name" => "Sofa 3-Seater",        "weight" => 110, "origin" => "Jakarta",  "destination" => "Yogyakarta",  "volume" => 2000000],
        6  => ["name" => "Lemari Pakaian",       "weight" => 180, "origin" => "Surabaya", "destination" => "Solo",        "volume" => 2400000],
        7  => ["name" => "Karton Mie Instan",    "weight" => 60,  "origin" => "Jakarta",  "destination" => "Semarang",    "volume" => 120000],
        8  => ["name" => "Karton Minuman",       "weight" => 90,  "origin" => "Jakarta",  "destination" => "Cirebon",     "volume" => 180000],
        9  => ["name" => "Bal Kain Batik",       "weight" => 200, "origin" => "Surabaya", "destination" => "Solo",        "volume" => 600000],
        10 => ["name" => "Bal Denim",            "weight" => 250, "origin" => "Jakarta",  "destination" => "Yogyakarta",  "volume" => 750000],
        11 => ["name" => "Box Bearing",          "weight" => 160, "origin" => "Surabaya", "destination" => "Jakarta",     "volume" => 700000],
        12 => ["name" => "Box Elektronik",       "weight" => 480, "origin" => "Surabaya", "destination" => "Malang",      "volume" => 900000],
        13 => ["name" => "Kompor Gas Bulk",      "weight" => 130, "origin" => "Jakarta",  "destination" => "Bandung",     "volume" => 500000],
        14 => ["name" => "Tinta Printing",       "weight" => 55,  "origin" => "Jakarta",  "destination" => "Cirebon",     "volume" => 90000],
        15 => ["name" => "Mesin CNC Mini",       "weight" => 500, "origin" => "Surabaya", "destination" => "Jakarta",     "volume" => 2200000],
        16 => ["name" => "Spare Part Motor",     "weight" => 150, "origin" => "Jakarta",  "destination" => "Yogyakarta",  "volume" => 300000],
        17 => ["name" => "Panel Surya",          "weight" => 200, "origin" => "Jakarta",  "destination" => "Semarang",    "volume" => 800000],
        18 => ["name" => "Kabel Listrik Rol",    "weight" => 80,  "origin" => "Jakarta",  "destination" => "Tasikmalaya", "volume" => 80000],
        19 => ["name" => "Cat Tembok Drum",      "weight" => 300, "origin" => "Jakarta",  "destination" => "Cirebon",     "volume" => 400000],
        20 => ["name" => "Pipa PVC Bundel",      "weight" => 250, "origin" => "Surabaya", "destination" => "Solo",        "volume" => 900000],
    ];

    public function index()
    {
        $graph = new GraphService($this->defaultEdges);
        $cities = $graph->getCities();
        $edges = $graph->getEdges();
        $distMatrix = $graph->getDistanceMatrix();

        // Tambahkan kategori ke default items
        $itemsWithCategory = [];
        foreach ($this->defaultItems as $id => $item) {
            $item['category'] = GAService::getCategory($item['volume']);
            $item['cat_label'] = GAService::getCategoryLabel($item['category']);
            $item['id'] = $id;
            $itemsWithCategory[] = $item;
        }

        return view('welcome', compact('cities', 'edges', 'distMatrix', 'itemsWithCategory'));
    }

    public function optimize(Request $request)
    {
        set_time_limit(180);

        $inputItems = $request->input('items', []);
        $gaParams   = $request->input('ga', []);
        $acoParams  = $request->input('aco', []);

        // Bangun data barang
        $items = [];
        foreach ($inputItems as $idx => $item) {
            $id = $idx + 1;
            $volume = (int)($item['length'] * $item['width'] * $item['height']);
            $items[$id] = [
                'name'        => $item['name'],
                'weight'      => (float)$item['weight'],
                'origin'      => $item['origin'],
                'destination' => $item['destination'],
                'volume'      => $volume,
                'category'    => GAService::getCategory($volume),
            ];
        }

        if (empty($items)) {
            return response()->json(['success' => false, 'message' => 'Tidak ada barang.'], 400);
        }

        // Inisialisasi layanan
        $graph = new GraphService($this->defaultEdges);

        $aco = new ACOService(
            $graph->getDistanceMatrix(),
            (int)($acoParams['num_ants'] ?? 5),
            (int)($acoParams['max_iter'] ?? 15),
            (float)($acoParams['alpha'] ?? 1.0),
            (float)($acoParams['beta'] ?? 2.0),
            (float)($acoParams['rho'] ?? 0.5),
            (float)($acoParams['q0'] ?? 0.7),
            (float)($acoParams['Q'] ?? 100),
            (float)($acoParams['init_pheromone'] ?? 0.1)
        );

        $ga = new GAService(
            $items,
            $this->defaultTrucks,
            $graph,
            $aco,
            6800,
            4,
            (int)($gaParams['pop_size'] ?? 50),
            (int)($gaParams['elite_size'] ?? 2),
            (float)($gaParams['pc'] ?? 0.8),
            (float)($gaParams['pm'] ?? 0.2),
            (int)($gaParams['n_iter'] ?? 100)
        );

        $result = $ga->run();

        // Format items untuk response
        $formattedItems = [];
        foreach ($items as $id => $item) {
            $formattedItems[$id] = array_merge($item, [
                'id'        => $id,
                'cat_label' => GAService::getCategoryLabel($item['category']),
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $result,
            'items'   => $formattedItems,
        ]);
    }
}