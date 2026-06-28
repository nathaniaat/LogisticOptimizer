import random
import math

class ACOService:
    def __init__(self, dist_matrix, num_ants=10, max_iter=50, alpha=1.0, beta=2.0, rho=0.5, q0=0.7, Q=100, init_pheromone=0.1):
        self.dist_matrix = dist_matrix
        self.num_ants = num_ants
        self.max_iter = max_iter
        self.alpha = alpha
        self.beta = beta
        self.rho = rho
        self.q0 = q0
        self.Q = Q
        self.init_pheromone = init_pheromone

    def solve(self, start_city, cities_to_visit, return_to_start=True):
        """
        Mencari rute terbaik dari start_city mengunjungi semua cities_to_visit.
        Jika return_to_start=True (default), truk akan kembali ke kota asal
        (gudang) di akhir rute, dan jarak tempuh tersebut ikut dihitung dalam
        total_distance. Ini merepresentasikan siklus pengiriman yang nyata:
        truk berangkat dari gudang, mengantar semua barang, lalu pulang ke
        gudang yang sama. Tanpa ini, biaya BBM yang dihitung akan lebih
        rendah dari kenyataan karena jarak pulang tidak diperhitungkan.
        """
        if not cities_to_visit:
            return {'route': [start_city], 'distance': 0, 'iterations': []}
            
        cities_to_visit = list(dict.fromkeys(cities_to_visit)) # Unique
        
        all_cities = list(dict.fromkeys([start_city] + cities_to_visit))
        
        # Init pheromone
        pheromone = {}
        for ci in all_cities:
            pheromone[ci] = {}
            for cj in all_cities:
                if ci != cj:
                    pheromone[ci][cj] = self.init_pheromone
                    
        best_route = None
        best_distance = float('inf')
        iter_history = []
        
        for iteration in range(self.max_iter):
            ant_routes = []
            ant_distances = []
            
            for a in range(self.num_ants):
                route = self._build_route(start_city, cities_to_visit, pheromone)
                if return_to_start:
                    route = route + [start_city]
                distance = self._route_distance(route)
                ant_routes.append(route)
                ant_distances.append(distance)
                
                if distance < best_distance:
                    best_distance = distance
                    best_route = route
                    
            # Evaporasi feromon
            for ci in pheromone:
                for cj in pheromone[ci]:
                    pheromone[ci][cj] *= (1 - self.rho)
                    if pheromone[ci][cj] < 1e-10:
                        pheromone[ci][cj] = 1e-10
                        
            # Deposisi feromon (termasuk edge pulang ke gudang, jika
            # return_to_start aktif, sehingga ACO ikut "belajar" memilih
            # kota terakhir yang dekat dengan gudang demi meminimalkan
            # total jarak round-trip, bukan hanya jarak berangkat)
            for a in range(self.num_ants):
                deposit = self.Q / max(ant_distances[a], 1)
                route = ant_routes[a]
                for k in range(len(route) - 1):
                    ci, cj = route[k], route[k+1]
                    if ci in pheromone and cj in pheromone[ci]:
                        pheromone[ci][cj] += deposit
                        
            iter_history.append({
                'iteration': iteration + 1,
                'best_distance': round(best_distance, 2),
                'avg_distance': round(sum(ant_distances) / len(ant_distances), 2)
            })
            
        return {
            'route': best_route,
            'distance': round(best_distance, 2),
            'iterations': iter_history
        }

    def _build_route(self, start, to_visit, phero):
        route = [start]
        unvisited = to_visit[:]
        current = start
        
        while unvisited:
            next_city = self._pick_next(current, unvisited, phero)
            route.append(next_city)
            unvisited.remove(next_city)
            current = next_city
            
        return route

    def _pick_next(self, current, unvisited, phero):
        # Exploitasi
        if random.random() < self.q0:
            best_city = unvisited[0]
            best_val = -1
            for city in unvisited:
                tau = phero.get(current, {}).get(city, self.init_pheromone)
                eta = 1.0 / max(self.dist_matrix.get(current, {}).get(city, 1), 1)
                val = (tau ** self.alpha) * (eta ** self.beta)
                if val > best_val:
                    best_val = val
                    best_city = city
            return best_city
            
        # Eksplorasi (Roulette wheel)
        probs = {}
        total = 0
        for city in unvisited:
            tau = phero.get(current, {}).get(city, self.init_pheromone)
            eta = 1.0 / max(self.dist_matrix.get(current, {}).get(city, 1), 1)
            p = (tau ** self.alpha) * (eta ** self.beta)
            probs[city] = p
            total += p
            
        r = random.random() * total
        cum = 0
        for city in unvisited:
            cum += probs[city]
            if cum >= r:
                return city
        return unvisited[-1]

    def _route_distance(self, route):
        total = 0
        for i in range(len(route) - 1):
            total += self.dist_matrix.get(route[i], {}).get(route[i+1], float('inf'))
        return total