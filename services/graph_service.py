class GraphService:
    def __init__(self, edges):
        self.graph = {}
        self.cities = []
        self.distance_matrix = {}
        self.path_matrix = {}
        
        for edge in edges:
            u, v, w = edge
            if u not in self.graph:
                self.graph[u] = []
            if v not in self.graph:
                self.graph[v] = []
                
            self.graph[u].append({'city': v, 'weight': w})
            self.graph[v].append({'city': u, 'weight': w})
            
            if u not in self.cities:
                self.cities.append(u)
            if v not in self.cities:
                self.cities.append(v)
                
        self.compute_all_shortest_paths()

    def dijkstra(self, src):
        dist = {}
        prev = {}
        visited = {}
        for c in self.cities:
            dist[c] = float('inf')
            prev[c] = None
            visited[c] = False
        dist[src] = 0

        for _ in range(len(self.cities)):
            min_dist = float('inf')
            u = None
            for c in self.cities:
                if not visited[c] and dist[c] < min_dist:
                    min_dist = dist[c]
                    u = c
            
            if u is None:
                break
                
            visited[u] = True
            for neighbor in self.graph.get(u, []):
                alt = dist[u] + neighbor['weight']
                if alt < dist[neighbor['city']]:
                    dist[neighbor['city']] = alt
                    prev[neighbor['city']] = u
                    
        return {'dist': dist, 'prev': prev}

    def compute_all_shortest_paths(self):
        for a in self.cities:
            result = self.dijkstra(a)
            self.distance_matrix[a] = result['dist']
            self.path_matrix[a] = result['prev']

    def get_distance(self, from_city, to_city):
        if from_city in self.distance_matrix and to_city in self.distance_matrix[from_city]:
            return self.distance_matrix[from_city][to_city]
        return float('inf')

    def get_distance_matrix(self):
        return self.distance_matrix

    def get_cities(self):
        return self.cities

    def get_edges(self):
        edges = []
        seen = {}
        for u, neighbors in self.graph.items():
            for n in neighbors:
                v = n['city']
                w = n['weight']
                key = f"{min(u, v)}|{max(u, v)}"
                if key not in seen:
                    seen[key] = True
                    edges.append({'from': u, 'to': v, 'weight': w})
        return edges

    def get_path(self, src, dst):
        if src == dst:
            return [src]
        path = []
        current = dst
        prev_map = self.path_matrix.get(src, {})
        while current is not None:
            path.insert(0, current)
            if current == src:
                break
            current = prev_map.get(current, None)
        return path
