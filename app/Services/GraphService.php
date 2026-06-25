<?php

namespace App\Services;

class GraphService
{
    private array $graph = [];
    private array $cities = [];
    private array $distanceMatrix = [];
    private array $pathMatrix = [];

    public function __construct(array $edges)
    {
        foreach ($edges as $edge) {
            [$u, $v, $w] = $edge;
            $this->graph[$u][] = ['city' => $v, 'weight' => $w];
            $this->graph[$v][] = ['city' => $u, 'weight' => $w];
            if (!in_array($u, $this->cities)) $this->cities[] = $u;
            if (!in_array($v, $this->cities)) $this->cities[] = $v;
        }
        $this->computeAllShortestPaths();
    }

    /** Dijkstra dari src ke semua kota lain */
    private function dijkstra(string $src): array
    {
        $dist = [];
        $prev = [];
        $visited = [];
        foreach ($this->cities as $c) {
            $dist[$c] = INF;
            $prev[$c] = null;
            $visited[$c] = false;
        }
        $dist[$src] = 0;

        for ($i = 0; $i < count($this->cities); $i++) {
            $min = INF;
            $u = null;
            foreach ($this->cities as $c) {
                if (!$visited[$c] && $dist[$c] < $min) {
                    $min = $dist[$c];
                    $u = $c;
                }
            }
            if ($u === null) break;
            $visited[$u] = true;
            foreach ($this->graph[$u] ?? [] as $neighbor) {
                $alt = $dist[$u] + $neighbor['weight'];
                if ($alt < $dist[$neighbor['city']]) {
                    $dist[$neighbor['city']] = $alt;
                    $prev[$neighbor['city']] = $u;
                }
            }
        }
        return ['dist' => $dist, 'prev' => $prev];
    }

    private function computeAllShortestPaths(): void
    {
        foreach ($this->cities as $a) {
            $result = $this->dijkstra($a);
            $this->distanceMatrix[$a] = $result['dist'];
            $this->pathMatrix[$a] = $result['prev'];
        }
    }

    public function getDistance(string $from, string $to): float
    {
        return $this->distanceMatrix[$from][$to] ?? INF;
    }

    public function getDistanceMatrix(): array { return $this->distanceMatrix; }
    public function getCities(): array { return $this->cities; }

    public function getEdges(): array
    {
        $edges = [];
        $seen = [];
        foreach ($this->graph as $u => $neighbors) {
            foreach ($neighbors as $n) {
                $v = $n['city'];
                $w = $n['weight'];
                $key = min($u, $v) . '|' . max($u, $v);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $edges[] = ['from' => $u, 'to' => $v, 'weight' => $w];
                }
            }
        }
        return $edges;
    }

    /** Rekonstruksi path dari src ke dst */
    public function getPath(string $src, string $dst): array
    {
        if ($src === $dst) return [$src];
        $path = [];
        $current = $dst;
        $prev = $this->pathMatrix[$src] ?? [];
        while ($current !== null) {
            array_unshift($path, $current);
            if ($current === $src) break;
            $current = $prev[$current] ?? null;
        }
        return $path;
    }
}