<?php

namespace App\Services;

class ACOService
{
    private int $numAnts;
    private int $maxIter;
    private float $alpha;
    private float $beta;
    private float $rho;
    private float $q0;
    private float $Q;
    private float $initPheromone;
    private array $distMatrix;

    public function __construct(
        array $distMatrix,
        int   $numAnts = 10,
        int   $maxIter = 50,
        float $alpha   = 1.0,
        float $beta    = 2.0,
        float $rho     = 0.5,
        float $q0      = 0.7,
        float $Q       = 100,
        float $initPheromone = 0.1
    ) {
        $this->distMatrix    = $distMatrix;
        $this->numAnts       = $numAnts;
        $this->maxIter       = $maxIter;
        $this->alpha         = $alpha;
        $this->beta          = $beta;
        $this->rho           = $rho;
        $this->q0            = $q0;
        $this->Q             = $Q;
        $this->initPheromone = $initPheromone;
    }

    /**
     * Menyelesaikan TSP untuk kota-kota yang harus dikunjungi
     * dimulai dari startCity. Mengembalikan rute optimal + jarak + histori iterasi.
     */
    public function solve(string $startCity, array $citiesToVisit): array 
{
    if (empty($citiesToVisit)) {
        return ['route' => [$startCity], 'distance' => 0, 'iterations' => []];
    }
    $citiesToVisit = array_values(array_unique($citiesToVisit));

    $allCities = array_unique(array_merge([$startCity], $citiesToVisit));
    $n = count($allCities);

    // Inisialisasi matriks feromon
    $pheromone = [];
    foreach ($allCities as $ci) {
        foreach ($allCities as $cj) {
            if ($ci !== $cj) {
                $pheromone[$ci][$cj] = $this->initPheromone;
            }
        }
    }

    $bestRoute    = null;
    $bestDistance  = INF;
    $iterHistory  = [];

    for ($iter = 0; $iter < $this->maxIter; $iter++) {
        $antRoutes     = [];
        $antDistances  = [];

        for ($a = 0; $a < $this->numAnts; $a++) {
            $route    = $this->buildRoute($startCity, $citiesToVisit, $pheromone);
            $distance = $this->routeDistance($route);
            $antRoutes[]    = $route;
            $antDistances[] = $distance;

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestRoute    = $route;
            }
        }

        // Evaporasi feromon
        foreach ($pheromone as $ci => &$row) {
            foreach ($row as $cj => &$p) {
                $p *= (1 - $this->rho);
                if ($p < 1e-10) $p = 1e-10;
            }
        }
        unset($row, $p);

        // Deposisi feromon
        for ($a = 0; $a < $this->numAnts; $a++) {
            $deposit = $this->Q / max($antDistances[$a], 1);
            $route = $antRoutes[$a];
            for ($k = 0; $k < count($route) - 1; $k++) {
                if (isset($pheromone[$route[$k]][$route[$k + 1]])) {
                    $pheromone[$route[$k]][$route[$k + 1]] += $deposit;
                }
            }
        }

        $iterHistory[] = [
            'iteration'     => $iter + 1,
            'best_distance' => round($bestDistance, 2),
            'avg_distance'  => round(array_sum($antDistances) / count($antDistances), 2),
        ];
    }

    return [
        'route'      => $bestRoute,
        'distance'   => round($bestDistance, 2),
        'iterations' => $iterHistory
    ];
}

    private function buildRoute(string $start, array $toVisit, array &$phero): array
    {
        $route     = [$start];
        $unvisited = $toVisit;
        $current   = $start;

        while (!empty($unvisited)) {
            $next       = $this->pickNext($current, $unvisited, $phero);
            $route[]    = $next;
            $unvisited  = array_values(array_diff($unvisited, [$next]));
            $current    = $next;
        }
        return $route;
    }

    private function pickNext(string $current, array $unvisited, array &$phero): string
    {
        // Exploitasi vs Eksplorasi
        if (mt_rand() / mt_getrandmax() < $this->q0) {
            $bestCity = $unvisited[0];
            $bestVal  = -1;
            foreach ($unvisited as $city) {
                $tau = $phero[$current][$city] ?? $this->initPheromone;
                $eta = 1.0 / max($this->distMatrix[$current][$city] ?? 1, 1);
                $val = pow($tau, $this->alpha) * pow($eta, $this->beta);
                if ($val > $bestVal) {
                    $bestVal  = $val;
                    $bestCity = $city;
                }
            }
            return $bestCity;
        }

        // Roulette wheel
        $probs = [];
        $total = 0;
        foreach ($unvisited as $city) {
            $tau = $phero[$current][$city] ?? $this->initPheromone;
            $eta = 1.0 / max($this->distMatrix[$current][$city] ?? 1, 1);
            $p   = pow($tau, $this->alpha) * pow($eta, $this->beta);
            $probs[$city] = $p;
            $total += $p;
        }

        $r = mt_rand() / mt_getrandmax() * $total;
        $cum = 0;
        foreach ($unvisited as $city) {
            $cum += $probs[$city];
            if ($cum >= $r) return $city;
        }
        return $unvisited[count($unvisited) - 1];
    }

    private function routeDistance(array $route): float
    {
        $total = 0;
        for ($i = 0; $i < count($route) - 1; $i++) {
            $total += $this->distMatrix[$route[$i]][$route[$i + 1]] ?? INF;
        }
        return $total;
    }
}