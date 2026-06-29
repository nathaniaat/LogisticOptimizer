import random
from services.aco_service import ACOService

class GAService:
    def __init__(self, items, trucks, graph, aco, aco_final=None, cost_per_liter=6800, km_per_liter=4,
                 pop_size=50, elite_size=2, pc=0.8, pm=0.2, n_iter=100, stagnation_limit=10, max_iter=300):
        self.items = items
        self.trucks = trucks
        self.graph = graph
        self.aco = aco
        self.aco_final = aco_final or aco
        self.aco_cache = {}
        self.cost_per_liter = cost_per_liter
        self.km_per_liter = km_per_liter
        self.pop_size = pop_size
        self.elite_size = elite_size
        self.pc = pc
        self.pm = pm
        self.n_iter = n_iter
        self.stagnation_limit = stagnation_limit
        self.max_iter = n_iter 
        
        self.truck_pool = {}
        for t_id, t in self.trucks.items():
            origin = t['origin']
            if origin not in self.truck_pool:
                self.truck_pool[origin] = []
            self.truck_pool[origin].append(t_id)
            
        self.revenues = {}
        for idx, item in self.items.items():
            d = self.graph.get_distance(item['origin'], item['destination'])
            self.revenues[idx] = item['weight'] * d * item['category']

    @staticmethod
    def get_category(volume):
        if volume <= 125000: return 100
        if volume <= 1000000: return 200
        return 300

    @staticmethod
    def get_category_label(cat):
        if cat == 100: return 'Kecil'
        if cat == 200: return 'Sedang'
        if cat == 300: return 'Besar'
        return 'Unknown'

    def run(self):
        random.seed(98)
        pop = self._init_population()
        fits = [self._decode(c)['fitness'] for c in pop]
        
        history_best = []
        history_avg = []
        history_worst = []
        
        initial_best_fit = max(fits)
        initial_best_idx = fits.index(initial_best_fit)
        initial_best_detail = self._decode(pop[initial_best_idx])
        
        best_ever = initial_best_fit
        stagnant_cnt = 0
        gen = 0
        stopped_early = False
        
        while gen < self.max_iter:
            best_f = max(fits)
            avg_f = sum(fits) / len(fits)
            worst_f = min(fits)
            
            history_best.append(best_f)
            history_avg.append(avg_f)
            history_worst.append(worst_f)
            
            if best_f > best_ever + 1e-9:
                best_ever = best_f
                stagnant_cnt = 0
            else:
                stagnant_cnt += 1
                
            gen += 1
            
            if gen >= self.n_iter and stagnant_cnt >= self.stagnation_limit:
                stopped_early = True
                break
                
            pop, fits = self._new_generation(pop, fits)
            
        best_idx = fits.index(max(fits))
        best_chrom = pop[best_idx]
        
        final_trucks = self._decode_with_final_aco(best_chrom)
        final_best_fit = final_trucks['fitness']
        
        improvement_pct = 0
        if initial_best_fit != 0:
            improvement_pct = round((final_best_fit - initial_best_fit) / abs(initial_best_fit) * 100, 2)
            
        return {
            'best_chromosome': best_chrom,
            'initial_best_fitness': initial_best_fit,
            'initial_total_revenue': initial_best_detail['pendapatan'],
            'initial_fuel_cost': initial_best_detail['biaya'],
            'initial_total_distance': initial_best_detail['total_jarak'],
            'final_best_fitness': final_best_fit,
            'improvement_pct': improvement_pct,
            'total_revenue': final_trucks['pendapatan'],
            'total_fuel_cost': final_trucks['biaya'],
            'total_distance': final_trucks['total_jarak'],
            'profit': final_best_fit,
            'trucks': final_trucks['trucks_detail'],
            'skipped': final_trucks['skipped'],
            'convergence': {
                'best': history_best,
                'avg': history_avg,
                'worst': history_worst,
            },
            'generations_run': gen,
            'min_generations': self.n_iter,
            'stagnation_limit': self.stagnation_limit,
            'stopped_early': stopped_early,
            'stagnation_at_stop': stagnant_cnt,
            'aco_details': final_trucks['aco_details']
        }

    def _decode(self, chromosome):
        return self._decode_internal(chromosome, False)

    def _decode_with_final_aco(self, chromosome):
        return self._decode_internal(chromosome, True)

    def _decode_internal(self, chromosome, final_aco):
        muatan = {m: [] for m in self.trucks.keys()}
        berat_m = {m: 0 for m in self.trucks.keys()}
        vol_m = {m: 0 for m in self.trucks.keys()}
        skipped = []
        ptr = {o: 0 for o in self.truck_pool.keys()}
        
        for bid in chromosome:
            if bid not in self.items:
                continue
            item = self.items[bid]
            asal = item['origin']
            pool = self.truck_pool.get(asal, [])
            placed = False
            
            while ptr.get(asal, 0) < len(pool):
                m = pool[ptr[asal]]
                kb = self.trucks[m]['max_weight']
                kv = self.trucks[m]['max_volume']
                
                if berat_m[m] + item['weight'] <= kb and vol_m[m] + item['volume'] <= kv:
                    muatan[m].append(bid)
                    berat_m[m] += item['weight']
                    vol_m[m] += item['volume']
                    placed = True
                    break
                ptr[asal] += 1
                
            if not placed:
                skipped.append(bid)
                
        jarak_mobil = {}
        rute_mobil = {}
        total_jarak = 0
        total_pend = 0
        trucks_detail = {}
        aco_details = {}
        
        for m, truck in self.trucks.items():
            if not muatan[m]:
                jarak_mobil[m] = 0
                rute_mobil[m] = [truck['origin']]
                trucks_detail[m] = {
                    'id': m,
                    'name': truck['name'],
                    'origin': truck['origin'],
                    'items': [],
                    'total_weight': 0,
                    'total_volume': 0,
                    'route': [truck['origin']],
                    'distance': 0,
                    'revenue': 0,
                    'fuel_cost': 0,
                }
                continue
                
            asal_m = truck['origin']
            dests = list(dict.fromkeys([self.items[bid]['destination'] for bid in muatan[m] if bid in self.items]))
            
            aco_result = self._run_aco(asal_m, dests, final_aco)
            aco_details[m] = aco_result
            
            rute = aco_result['route']
            jarak = aco_result['distance']
            
            # Generate the detailed node-by-node path for the map
            full_path = []
            if len(rute) > 0:
                full_path.append(rute[0])
                for i in range(len(rute) - 1):
                    segment_path = self.graph.get_path(rute[i], rute[i+1])
                    if len(segment_path) > 1:
                        full_path.extend(segment_path[1:])
            
            jarak_mobil[m] = jarak
            rute_mobil[m] = rute
            total_jarak += jarak
            
            pend_m = 0
            item_details = []
            for bid in muatan[m]:
                if bid not in self.items:
                    continue
                pend_m += self.revenues[bid]
                it = self.items[bid]
                item_details.append({
                    'id': bid,
                    'name': it['name'],
                    'weight': it['weight'],
                    'volume': it['volume'],
                    'category': it['category'],
                    'cat_label': self.get_category_label(it['category']),
                    'origin': it['origin'],
                    'destination': it['destination'],
                    'revenue': self.revenues[bid]
                })
            total_pend += pend_m
            
            trucks_detail[m] = {
                'id': m,
                'name': truck['name'],
                'origin': asal_m,
                'items': item_details,
                'total_weight': berat_m[m],
                'total_volume': vol_m[m],
                'route': rute,
                'full_path': full_path,
                'distance': jarak,
                'revenue': pend_m,
                'fuel_cost': (jarak / self.km_per_liter) * self.cost_per_liter
            }
            
        biaya = (total_jarak / self.km_per_liter) * self.cost_per_liter
        fitness = total_pend - biaya
        
        return {
            'muatan': muatan,
            'berat_m': berat_m,
            'vol_m': vol_m,
            'jarak_mobil': jarak_mobil,
            'rute_mobil': rute_mobil,
            'total_jarak': round(total_jarak, 2),
            'pendapatan': round(total_pend, 2),
            'biaya': round(biaya, 2),
            'fitness': round(fitness, 2),
            'skipped': skipped,
            'trucks_detail': trucks_detail,
            'aco_details': aco_details
        }

    def _run_aco(self, origin, dests, final):
        if not dests:
            return {'route': [origin], 'distance': 0, 'iterations': []}
            
        sorted_dests = sorted(dests)
        cache_key = origin + '|' + ','.join(sorted_dests) + ('_final' if final else '')
        
        if not final and cache_key in self.aco_cache:
            return self.aco_cache[cache_key]
            
        if final:
            result = self.aco_final.solve(origin, dests, return_to_start=True)
        else:
            result = self.aco.solve(origin, dests, return_to_start=True)
            
        if not final:
            self.aco_cache[cache_key] = result
            
        return result

    def _init_population(self):
        ids = list(self.items.keys())
        pop = []
        for _ in range(self.pop_size):
            c = ids[:]
            random.shuffle(c)
            pop.append(c)
        return pop

    def _roulette_select(self, pool_chrom, pool_fits):
        def pick_one(chrom_list, fit_list):
            mn = min(fit_list)
            offset = abs(mn) + 1 if mn <= 0 else 0
            adj = [f + offset for f in fit_list]
            total = sum(adj)
            r = random.random() * total
            cum = 0
            for i, val in enumerate(adj):
                cum += val
                if cum >= r:
                    return i
            return len(fit_list) - 1
            
        idx1 = pick_one(pool_chrom, pool_fits)
        p1 = pool_chrom[idx1]
        
        sisa_chrom = pool_chrom[:idx1] + pool_chrom[idx1+1:]
        sisa_fits = pool_fits[:idx1] + pool_fits[idx1+1:]
        
        idx2 = pick_one(sisa_chrom, sisa_fits)
        p2 = sisa_chrom[idx2]
        
        return p1, p2

    def _order_crossover(self, p1, p2):
        n = len(p1)
        if n < 4:
            return p1[:], p2[:]
        pt1 = random.randint(1, n - 3)
        pt2 = random.randint(pt1 + 1, n - 2)
        
        def ox(donor, receiver):
            off = [None] * n
            seg = donor[pt1:pt2]
            off[pt1:pt2] = seg
            used = set(seg)
            
            pos = pt2 % n
            rot = receiver[pt2:] + receiver[:pt2]
            for g in rot:
                if g not in used:
                    off[pos % n] = g
                    used.add(g)
                    pos = (pos + 1) % n
            return off
            
        return ox(p1, p2), ox(p2, p1)

    def _swap_mutation(self, chrom):
        c = chrom[:]
        n = len(c)
        if n < 2:
            return c

        num_swaps = max(1, round(self.pm * n))
        for _ in range(num_swaps):
            if random.random() < self.pm:
                i, j = random.sample(range(n), 2)
                c[i], c[j] = c[j], c[i]
        return c


    def _new_generation(self, pop, fits):
        paired = list(zip(fits, pop))
        paired.sort(key=lambda x: x[0], reverse=True)
        
        elite_chroms = [p[1] for p in paired[:self.elite_size]]
        elite_fits = [p[0] for p in paired[:self.elite_size]]
        
        pool_chrom = [p[1] for p in paired]
        pool_fits = [p[0] for p in paired]
        
        offspring_chroms = []
        
        while len(offspring_chroms) < self.pop_size - self.elite_size:
            p1, p2 = self._roulette_select(pool_chrom, pool_fits)
            
            if random.random() < self.pc:
                o1, o2 = self._order_crossover(p1, p2)
            else:
                o1, o2 = p1[:], p2[:]
                
            offspring_chroms.append(self._swap_mutation(o1))
            if len(offspring_chroms) < self.pop_size - self.elite_size:
                offspring_chroms.append(self._swap_mutation(o2))
                
        new_pop = elite_chroms + offspring_chroms
        new_fits = elite_fits + [self._decode(c)['fitness'] for c in offspring_chroms]
        return new_pop, new_fits