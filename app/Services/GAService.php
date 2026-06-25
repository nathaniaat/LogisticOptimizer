<?php

namespace App\Services;

class GAService
{
    private array $items;
    private array $trucks;
    private array $truckPool;
    private GraphService $graph;
    private ACOService $aco;
    private array $acoCache = [];
    private float $costPerLiter;
    private float $kmPerLiter;
    private int $popSize;
    private int $eliteSize;
    private float $pc;
    private float $pm;

    // ── Kriteria pemberhentian sesuai proposal ──
    // "Proses iterasi akan terus berlanjut dan baru dihentikan apabila sistem
    //  tidak lagi menemukan peningkatan nilai fitness terbaik dalam 10 iterasi
    //  selanjutnya setelah melewati jumlah iterasi yang ditentukan."
    private int $nIter;            // jumlah iterasi minimum sebelum early-stop dievaluasi
    private int $stagnationLimit;  // jumlah generasi stagnan berturut-turut sebelum berhenti (default 10)
    private int $maxIter;          // batas keras (safety cap) agar tidak berjalan tanpa batas jika tidak pernah konvergen

    private array $revenues;

    public function __construct(
        array $items,
        array $trucks,
        GraphService $graph,
        ACOService $aco,
        float $costPerLiter = 6800,
        float $kmPerLiter = 4,
        int $popSize = 50,
        int $eliteSize = 2,
        float $pc = 0.8,
        float $pm = 0.2,
        int $nIter = 100,
        int $stagnationLimit = 10,
        int $maxIter = 300
    ) {
        $this->items           = $items;
        $this->trucks          = $trucks;
        $this->graph            = $graph;
        $this->aco              = $aco;
        $this->costPerLiter     = $costPerLiter;
        $this->kmPerLiter       = $kmPerLiter;
        $this->popSize          = $popSize;
        $this->eliteSize        = $eliteSize;
        $this->pc               = $pc;
        $this->pm               = $pm;
        $this->nIter            = $nIter;
        $this->stagnationLimit  = $stagnationLimit;
        $this->maxIter          = max($maxIter, $nIter); // pastikan cap tidak lebih kecil dari nIter

        // Pool mobil per gudang
        $this->truckPool = [];
        foreach ($trucks as $id => $t) {
            $this->truckPool[$t['origin']][] = $id;
        }

        // Pre-hitung pendapatan per barang.
        // Catatan: pendapatan dihitung dari jarak titik-ke-titik (asal->tujuan barang),
        // BUKAN dari jarak rute gabungan truk — ini konsisten dengan rumus proposal
        // "Pendapatan Kotor = Berat * Jarak * Kategori" dan juga konsisten dengan
        // skrip Python awal (variabel PENDAPATAN dihitung terpisah dari jarak_mobil).
        $this->revenues = [];
        foreach ($items as $id => $item) {
            $d = $graph->getDistance($item['origin'], $item['destination']);
            $this->revenues[$id] = $item['weight'] * $d * $item['category'];
        }
    }

    /** Hitung kategori dari volume (cm³) — sesuai tabel proposal (100/200/300) */
    public static function getCategory(int $volume): int
    {
        if ($volume <= 125000) return 100;
        if ($volume <= 1000000) return 200;
        return 300; // s.d. 4.500.000 cm3 sesuai proposal — validasi batas atas sebaiknya dilakukan di layer input/form
    }

    public static function getCategoryLabel(int $cat): string
    {
        return match ($cat) {
            100 => 'Kecil',
            200 => 'Sedang',
            300 => 'Besar',
            default => 'Unknown'
        };
    }

    /** Jalankan GA lengkap, kembalikan seluruh hasil */
    public function run(): array
    {
        mt_srand(98);
        $pop  = $this->initPopulation();
        $fits = array_map(fn($c) => $this->decode($c)['fitness'], $pop);

        $historyBest  = [];
        $historyAvg   = [];
        $historyWorst = [];

        $initialBestFit    = max($fits);
        $initialBestIdx    = array_keys($fits, $initialBestFit)[0];
        $initialBestDetail = $this->decode($pop[$initialBestIdx]);

        $bestEver     = $initialBestFit;
        $stagnantCnt  = 0;
        $gen          = 0;
        $stoppedEarly = false;

        // Loop generasi: rekam fitness generasi saat ini dahulu, baru cek kriteria
        // berhenti, baru evolusi ke generasi berikutnya bila belum berhenti.
        // Ini menjamin hasil akhir (bestChrom) selalu konsisten dengan entri
        // terakhir pada history konvergensi (tidak ada pergeseran 1 generasi).
        while ($gen < $this->maxIter) {
            $bestF  = max($fits);
            $avgF   = array_sum($fits) / count($fits);
            $worstF = min($fits);

            $historyBest[]  = $bestF;
            $historyAvg[]   = $avgF;
            $historyWorst[] = $worstF;

            if ($bestF > $bestEver + 1e-9) {
                $bestEver    = $bestF;
                $stagnantCnt = 0;
            } else {
                $stagnantCnt++;
            }

            $gen++;

            // Berhenti hanya jika sudah melewati jumlah iterasi minimum ($nIter)
            // DAN tidak ada peningkatan selama $stagnationLimit generasi berturut-turut.
            if ($gen >= $this->nIter && $stagnantCnt >= $this->stagnationLimit) {
                $stoppedEarly = true;
                break;
            }

            $pop  = $this->newGeneration($pop, $fits);
            $fits = array_map(fn($c) => $this->decode($c)['fitness'], $pop);
        }

        // Ambil kromosom terbaik dari generasi terakhir yang dievaluasi
        $bestIdx   = array_keys($fits, max($fits))[0];
        $bestChrom = $pop[$bestIdx];

        // Jalankan ACO final dengan parameter penuh untuk solusi terbaik
        $finalTrucks  = $this->decodeWithFinalACO($bestChrom);
        $finalBestFit = $finalTrucks['fitness'];

        return [
            'best_chromosome'        => $bestChrom,
            'initial_best_fitness'   => $initialBestFit,
            'initial_total_revenue'  => $initialBestDetail['pendapatan'],
            'initial_fuel_cost'      => $initialBestDetail['biaya'],
            'initial_total_distance' => $initialBestDetail['total_jarak'],
            'final_best_fitness'     => $finalBestFit,
            'improvement_pct'        => $initialBestFit != 0
                ? round(($finalBestFit - $initialBestFit) / abs($initialBestFit) * 100, 2)
                : 0,
            'total_revenue'          => $finalTrucks['pendapatan'],
            'total_fuel_cost'        => $finalTrucks['biaya'],
            'total_distance'         => $finalTrucks['total_jarak'],
            'profit'                 => $finalBestFit,
            'trucks'                 => $finalTrucks['trucks_detail'],
            'skipped'                => $finalTrucks['skipped'],
            'convergence'            => [
                'best'  => $historyBest,
                'avg'   => $historyAvg,
                'worst' => $historyWorst,
            ],
            // ── Metadata baru: transparansi kriteria pemberhentian ──
            'generations_run'        => $gen,
            'min_generations'        => $this->nIter,
            'stagnation_limit'       => $this->stagnationLimit,
            'stopped_early'          => $stoppedEarly,
            'stagnation_at_stop'     => $stagnantCnt,
            'aco_details'            => $finalTrucks['aco_details'],
        ];
    }

    /** Decode kromosom dengan ACO cache (digunakan selama evolusi) */
    private function decode(array $chromosome): array
    {
        return $this->decodeInternal($chromosome, false);
    }

    /** Decode dengan ACO final (parameter penuh) untuk solusi terbaik */
    private function decodeWithFinalACO(array $chromosome): array
    {
        return $this->decodeInternal($chromosome, true);
    }

    private function decodeInternal(array $chromosome, bool $finalACO): array
    {
        $muatan  = array_fill_keys(array_keys($this->trucks), []);
        $beratM  = array_fill_keys(array_keys($this->trucks), 0);
        $volM    = array_fill_keys(array_keys($this->trucks), 0);
        $skipped = [];
        $ptr     = array_fill_keys(array_keys($this->truckPool), 0);

        foreach ($chromosome as $bid) {
            $item   = $this->items[$bid];
            $asal   = $item['origin'];
            $pool   = $this->truckPool[$asal];
            $placed = false;

            while ($ptr[$asal] < count($pool)) {
                $m  = $pool[$ptr[$asal]];
                $kb = $this->trucks[$m]['max_weight'];
                $kv = $this->trucks[$m]['max_volume'];
                if ($beratM[$m] + $item['weight'] <= $kb && $volM[$m] + $item['volume'] <= $kv) {
                    $muatan[$m][] = $bid;
                    $beratM[$m]  += $item['weight'];
                    $volM[$m]    += $item['volume'];
                    $placed = true;
                    break;
                }
                $ptr[$asal]++;
            }
            if (!$placed) $skipped[] = $bid;
        }

        // Hitung rute dengan ACO per mobil
        $jarakMobil   = [];
        $ruteMobil    = [];
        $totalJarak   = 0;
        $totalPend    = 0;
        $trucksDetail = [];
        $acoDetails   = [];

        foreach ($this->trucks as $m => $truck) {
            if (empty($muatan[$m])) {
                $jarakMobil[$m] = 0;
                $ruteMobil[$m]  = [$truck['origin']];
                $trucksDetail[$m] = [
                    'id'           => $m,
                    'name'         => $truck['name'],
                    'origin'       => $truck['origin'],
                    'items'        => [],
                    'total_weight' => 0,
                    'total_volume' => 0,
                    'route'        => [$truck['origin']],
                    'distance'     => 0,
                    'revenue'      => 0,
                    'fuel_cost'    => 0,
                ];
                continue;
            }

            $asalM  = $truck['origin'];
            $dests  = array_values(array_unique(
                array_map(fn($bid) => $this->items[$bid]['destination'], $muatan[$m])
            ));

            // ACO
            $acoResult = $this->runACO($asalM, $dests, $finalACO);
            $acoDetails[$m] = $acoResult;

            $rute        = $acoResult['route'];
            $jarak       = $acoResult['distance'];
            $jarakMobil[$m] = $jarak;
            $ruteMobil[$m]  = $rute;
            $totalJarak    += $jarak;

            $pendM = 0;
            $itemDetails = [];
            foreach ($muatan[$m] as $bid) {
                $pendM += $this->revenues[$bid];
                $itemDetails[] = [
                    'id'          => $bid,
                    'name'        => $this->items[$bid]['name'],
                    'weight'      => $this->items[$bid]['weight'],
                    'volume'      => $this->items[$bid]['volume'],
                    'category'    => $this->items[$bid]['category'],
                    'cat_label'   => self::getCategoryLabel($this->items[$bid]['category']),
                    'origin'      => $this->items[$bid]['origin'],
                    'destination' => $this->items[$bid]['destination'],
                    'revenue'     => $this->revenues[$bid],
                ];
            }
            $totalPend += $pendM;

            $trucksDetail[$m] = [
                'id'           => $m,
                'name'         => $truck['name'],
                'origin'       => $asalM,
                'items'        => $itemDetails,
                'total_weight' => $beratM[$m],
                'total_volume' => $volM[$m],
                'route'        => $rute,
                'distance'     => $jarak,
                'revenue'      => $pendM,
                'fuel_cost'    => ($jarak / $this->kmPerLiter) * $this->costPerLiter,
            ];
        }

        $biaya   = ($totalJarak / $this->kmPerLiter) * $this->costPerLiter;
        $fitness = $totalPend - $biaya;

        return [
            'muatan'        => $muatan,
            'berat_m'       => $beratM,
            'vol_m'         => $volM,
            'jarak_mobil'   => $jarakMobil,
            'rute_mobil'    => $ruteMobil,
            'total_jarak'   => round($totalJarak, 2),
            'pendapatan'    => round($totalPend, 2),
            'biaya'         => round($biaya, 2),
            'fitness'       => round($fitness, 2),
            'skipped'       => $skipped,
            'trucks_detail' => $trucksDetail,
            'aco_details'   => $acoDetails,
        ];
    }

    private function runACO(string $origin, array $dests, bool $final): array
    {
        if (empty($dests)) {
            return ['route' => [$origin], 'distance' => 0, 'iterations' => []];
        }

        $sortedDests = $dests;
        sort($sortedDests);
        $cacheKey = $origin . '|' . implode(',', $sortedDests) . ($final ? '_final' : '');

        if (!$final && isset($this->acoCache[$cacheKey])) {
            return $this->acoCache[$cacheKey];
        }

        if ($final) {
            // ACO dengan parameter penuh untuk solusi akhir
            $fullACO = new ACOService(
                $this->graph->getDistanceMatrix(),
                20, 100, 1.0, 2.0, 0.5, 0.7, 100, 0.1
            );
            $result = $fullACO->solve($origin, $dests);
        } else {
            $result = $this->aco->solve($origin, $dests);
        }

        if (!$final) {
            $this->acoCache[$cacheKey] = $result;
        }

        return $result;
    }

    // ─── Operator Genetika ───

    private function initPopulation(): array
    {
        $ids = array_keys($this->items);
        $pop = [];
        for ($i = 0; $i < $this->popSize; $i++) {
            $c = $ids;
            shuffle($c);
            $pop[] = $c;
        }
        return $pop;
    }

    private function rouletteSelect(array $poolChrom, array $poolFits): array
    {
        $pickOne = function (array $chromList, array $fitList): int {
            $mn     = min($fitList);
            $offset = $mn <= 0 ? abs($mn) + 1 : 0;
            $adj    = array_map(fn($f) => $f + $offset, $fitList);
            $total  = array_sum($adj);
            $r      = mt_rand() / mt_getrandmax() * $total;
            $cum    = 0;
            for ($i = 0; $i < count($adj); $i++) {
                $cum += $adj[$i];
                if ($cum >= $r) return $i;
            }
            return count($fitList) - 1;
        };

        $idx1 = $pickOne($poolChrom, $poolFits);
        $p1   = $poolChrom[$idx1];

        $sisaChrom = array_merge(
            array_slice($poolChrom, 0, $idx1),
            array_slice($poolChrom, $idx1 + 1)
        );
        $sisaFits = array_merge(
            array_slice($poolFits, 0, $idx1),
            array_slice($poolFits, $idx1 + 1)
        );

        $idx2 = $pickOne($sisaChrom, $sisaFits);
        $p2   = $sisaChrom[$idx2];

        return [$p1, $p2];
    }

    private function orderCrossover(array $p1, array $p2): array
    {
        $n   = count($p1);
        $pt1 = mt_rand(1, $n - 2);
        $pt2 = mt_rand($pt1 + 1, $n - 1);

        $ox = function (array $donor, array $receiver) use ($n, $pt1, $pt2): array {
            $off  = array_fill(0, $n, null);
            $seg  = array_slice($donor, $pt1, $pt2 - $pt1);
            array_splice($off, $pt1, $pt2 - $pt1, $seg);
            $used = array_flip($seg);
            $pos  = $pt2 % $n;
            $rot  = array_merge(array_slice($receiver, $pt2), array_slice($receiver, 0, $pt2));
            foreach ($rot as $g) {
                if (!isset($used[$g])) {
                    $off[$pos % $n] = $g;
                    $used[$g] = true;
                    $pos++;
                }
            }
            return $off;
        };

        return [$ox($p1, $p2), $ox($p2, $p1)];
    }

    private function swapMutation(array $chrom): array
    {
        $c = $chrom;
        if (mt_rand() / mt_getrandmax() < $this->pm) {
            $indices = array_keys($c);
            $pair = array_rand($indices, 2);
            $i = $indices[$pair[0]];
            $j = $indices[$pair[1]];
            [$c[$i], $c[$j]] = [$c[$j], $c[$i]];
        }
        return $c;
    }

    private function newGeneration(array $pop, array $fits): array
    {
        // Ranking
        $paired = array_map(null, $fits, $pop);
        usort($paired, fn($a, $b) => $b[0] <=> $a[0]);

        $elite = array_map(fn($p) => $p, array_slice(array_column($paired, 1), 0, $this->eliteSize));
        $poolChrom = array_column($paired, 1);
        $poolFits  = array_column($paired, 0);

        $offspring = [];
        while (count($offspring) < $this->popSize - $this->eliteSize) {
            [$p1, $p2] = $this->rouletteSelect($poolChrom, $poolFits);
            if (mt_rand() / mt_getrandmax() < $this->pc) {
                [$o1, $o2] = $this->orderCrossover($p1, $p2);
            } else {
                $o1 = $p1;
                $o2 = $p2;
            }
            $offspring[] = $this->swapMutation($o1);
            if (count($offspring) < $this->popSize - $this->eliteSize) {
                $offspring[] = $this->swapMutation($o2);
            }
        }

        return array_merge($elite, $offspring);
    }
}